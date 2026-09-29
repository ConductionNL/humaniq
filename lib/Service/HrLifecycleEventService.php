<?php

/**
 * Humaniq HrLifecycleEventService
 *
 * Emits one event per HR moment (platform-hr-lifecycle-events D1 to D3): an
 * employee joins, leaves or changes job, leave is approved or withdrawn,
 * sickness is reported or ends. Each moment is detected on the edge that
 * causes it, by comparing the stored and the saved record, so saving again
 * sends nothing. Each goes out twice: as a CloudEvent through OpenRegister's
 * WebhookService, to whatever endpoint an administrator subscribed, and as a
 * typed Nextcloud event for a sibling app in the same request. Both are
 * fire-and-forget and neither stops the other.
 *
 * Payloads are minimal (D2): the employee, their account, the administration,
 * the day and the moment's dates. Never a leave type, a reason, a percentage,
 * salary, BSN or medical data.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Event\EmployeeJobChangedEvent;
use OCA\Humaniq\Event\EmployeeJoinedEvent;
use OCA\Humaniq\Event\EmployeeLeftEvent;
use OCA\Humaniq\Event\LeaveApprovedEvent;
use OCA\Humaniq\Event\SicknessReportedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects HR moments and sends them.
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */
class HrLifecycleEventService {

	public const TYPE_PREFIX = 'nl.conduction.hrmq.';

	public const SOURCE = '/apps/humaniq/employees';

	/**
	 * The schemas whose writes can mark a moment, by slug.
	 */
	public const SLUGS = ['employmentcontract', 'onboarding', 'offboarding', 'orgassignment', 'leaverequest', 'sickleavecase'];

	/**
	 * The typed event class per moment.
	 */
	private const TYPED = [
		'employee.joined' => EmployeeJoinedEvent::class,
		'employee.left' => EmployeeLeftEvent::class,
		'employee.jobchanged' => EmployeeJobChangedEvent::class,
		'leave.approved' => LeaveApprovedEvent::class,
		'leave.withdrawn' => LeaveApprovedEvent::class,
		'sickness.reported' => SicknessReportedEvent::class,
		'sickness.recovered' => SicknessReportedEvent::class,
	];

	/**
	 * Constructor.
	 *
	 * @param HrLifecycleMoments $moments Detects the moments.
	 * @param HoursRegisterGateway $gateway Reads the employee.
	 * @param ContainerInterface $container Resolves OpenRegister's WebhookService lazily.
	 * @param IEventDispatcher $eventDispatcher Dispatches the typed events.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly HrLifecycleMoments $moments,
		private readonly HoursRegisterGateway $gateway,
		private readonly ContainerInterface $container,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Detect the moments a write marks and send each.
	 *
	 * @param string $slug The schema slug.
	 * @param array<string, mixed>|null $old The stored record, null on create.
	 * @param array<string, mixed> $new The saved record, with its id.
	 *
	 * @return int How many CloudEvents were handed to the webhook service.
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function emit(string $slug, ?array $old, array $new): int {
		$sent = 0;
		foreach ($this->moments->moments(slug: $slug, old: $old, new: $new, today: gmdate('Y-m-d')) as $moment) {
			$employee = ($this->gateway->findObjectData($moment['employeeId'], 'Employee') ?? []);
			$envelope = $this->envelope(moment: $moment, employee: $employee);
			$this->dispatchTyped(moment: $moment, envelope: $envelope);
			if ($this->send(envelope: $envelope) === true) {
				$sent++;
			}
		}

		return $sent;
	}//end emit()

	/**
	 * The CloudEvent for one moment.
	 *
	 * @param array{type: string, employeeId: string, occurredOn: string, subjectId: string, data: array<string, mixed>} $moment The moment.
	 * @param array<string, mixed> $employee The employee record, [] when unknown.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-002
	 */
	public function envelope(array $moment, array $employee): array {
		$data = array_merge(
			[
				'employeeId' => $moment['employeeId'],
				'nextcloudUserId' => $this->text(value: $employee['nextcloudUserId'] ?? null),
				'administrationId' => $this->text(value: $employee['administrationId'] ?? null),
				'occurredOn' => $moment['occurredOn'],
			],
			$moment['data']
		);

		return (new CloudEventEnvelope())->build(
			type: self::TYPE_PREFIX . $moment['type'],
			source: self::SOURCE,
			eventId: $this->eventId(moment: $moment),
			time: gmdate('Y-m-d\TH:i:s\Z'),
			data: $data,
			subject: $moment['employeeId']
		);
	}//end envelope()

	/**
	 * A deterministic event id: the same moment always gets the same id, so a
	 * joined signal from both the contract and the onboarding case is one.
	 *
	 * @param array{type: string, employeeId: string, occurredOn: string, subjectId: string, data: array<string, mixed>} $moment The moment.
	 *
	 * @return string
	 */
	private function eventId(array $moment): string {
		$hash = md5($moment['type'] . '|' . $moment['employeeId'] . '|' . $moment['occurredOn'] . '|' . $moment['subjectId']);

		return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
	}//end eventId()

	/**
	 * Dispatch the typed event; a failure is logged and the webhook still goes.
	 *
	 * @param array{type: string, employeeId: string, occurredOn: string, subjectId: string, data: array<string, mixed>} $moment The moment.
	 * @param array<string, mixed> $envelope The CloudEvent.
	 *
	 * @return void
	 */
	private function dispatchTyped(array $moment, array $envelope): void {
		$class = self::TYPED[$moment['type']];
		$data = $moment['data'];
		if ($moment['type'] === 'leave.withdrawn') {
			$data['withdrawn'] = true;
		}

		if ($moment['type'] === 'sickness.recovered') {
			$data['recovered'] = true;
		}

		try {
			$event = new $class(
				eventId: (string)$envelope['id'],
				employeeId: $moment['employeeId'],
				nextcloudUserId: (string)($envelope['data']['nextcloudUserId'] ?? ''),
				administrationId: (string)($envelope['data']['administrationId'] ?? ''),
				occurredOn: $moment['occurredOn'],
				data: $data
			);
			$this->eventDispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: typed HR lifecycle event not dispatched (the webhook is still sent)', ['exception' => $e->getMessage(), 'type' => $moment['type']]);
		}
	}//end dispatchTyped()

	/**
	 * Hand the CloudEvent to OpenRegister's WebhookService.
	 *
	 * @param array<string, mixed> $envelope The CloudEvent.
	 *
	 * @return bool Whether it was handed over.
	 */
	private function send(array $envelope): bool {
		try {
			$this->container->get('OCA\OpenRegister\Service\WebhookService')->dispatchEvent(
				_event: new Event(),
				eventName: (string)$envelope['type'],
				payload: $envelope
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: HR lifecycle CloudEvent not sent (no subscriber or OpenRegister unavailable)', ['exception' => $e->getMessage(), 'eventName' => $envelope['type']]);
			return false;
		}
	}//end send()

	/**
	 * A scalar as text, or null when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if ($value === null || is_array($value) === true) {
			return null;
		}

		$text = trim((string)$value);

		return $text === '' ? null : $text;
	}//end text()

}//end class
