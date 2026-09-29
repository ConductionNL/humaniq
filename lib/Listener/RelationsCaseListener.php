<?php

/**
 * Humaniq RelationsCaseListener
 *
 * Stamps an employee relations case before it is saved
 * (people-employee-relations-cases D2, D3). The schema's authorization
 * matches on `userId` (the subject) and `managerUserId` (the manager), so
 * both are derived from the employee on every create and update and a
 * hand-set value is put back: nobody can make a case readable to someone
 * else by typing an account. A case that closes without a retention date
 * gets one two years after its closing date; a date HR set is kept.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use DateTimeImmutable;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Stamps accounts and the retention date on a relations case.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-002
 */
class RelationsCaseListener implements IEventListener {

	/**
	 * Lower-cased slug of the case schema.
	 *
	 * @var string
	 */
	public const CASE_SLUG = 'employeerelationscase';

	/**
	 * How long a closed case is kept by default.
	 *
	 * @var string
	 */
	private const DEFAULT_RETENTION = '+2 years';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Resolves the slug, the employee and the manager.
	 * @param InternalWriteMarker  $marker  Tells humaniq's own writes apart.
	 * @param LoggerInterface      $logger  Logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Stamp a case on its way into the register.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-002
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true
			|| (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false)
		) {
			return;
		}

		$entity = $this->entityOf($event);
		if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::CASE_SLUG) {
			return;
		}

		$data = ($entity->getObject() ?? []);
		try {
			$stamps = array_merge($this->accounts($data), $this->retention($data));
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: a relations case could not be stamped', ['exception' => $e->getMessage()]);
			return;
		}

		if ($stamps !== []) {
			$event->setModifiedData($stamps);
		}
	}//end handle()

	/**
	 * The object a create or update is about to save.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return object
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): object {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end entityOf()

	/**
	 * The subject's and the manager's accounts and the administration, from the employee.
	 *
	 * @param array<string, mixed> $data The case as it will be saved.
	 *
	 * @return array<string, string|null>
	 */
	private function accounts(array $data): array {
		$employeeId = (string)($data['employeeId'] ?? '');
		if ($employeeId === '') {
			return [];
		}

		$employee = ($this->gateway->findObjectData($employeeId, 'Employee') ?? []);
		$own = trim((string)($employee['nextcloudUserId'] ?? ''));

		return [
			'userId' => ($own === '' ? null : $own),
			'managerUserId' => $this->gateway->uniqueManagerUserIdFor($employeeId, (string)($data['openedOn'] ?? date('Y-m-d'))),
			'administrationId' => ($employee['administrationId'] ?? ($data['administrationId'] ?? null)),
		];
	}//end accounts()

	/**
	 * The default retention date of a closed case without one.
	 *
	 * @param array<string, mixed> $data The case as it will be saved.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-003
	 */
	private function retention(array $data): array {
		$closedOn = trim((string)($data['closedOn'] ?? ''));
		if (($data['status'] ?? '') !== 'afgesloten' || trim((string)($data['retainedUntil'] ?? '')) !== '' || $closedOn === '') {
			return [];
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $closedOn) !== 1) {
			return [];
		}

		return ['retainedUntil' => (new DateTimeImmutable($closedOn))->modify(self::DEFAULT_RETENTION)->format('Y-m-d')];
	}//end retention()

}//end class
