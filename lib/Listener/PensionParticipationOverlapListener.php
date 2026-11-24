<?php

/**
 * Pension Participation Overlap Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` for `PensionParticipation` writes. It refuses a
 * participation whose date range overlaps one the same employee already has,
 * and names the stored one in the refusal.
 *
 * The payroll run takes the participation in force on the last day of the
 * period. With two overlapping participations that is whichever the store
 * returned first, so an employee's premium would move with query ordering.
 * A refused write is visible; a silently chosen scheme is not (REQ-PPP-002).
 * The refusal sits on the write itself because the participation pages are
 * declarative and write straight through OpenRegister's object API.
 *
 * FAIL CLOSED: a lookup that throws refuses the write. An accepted overlap is
 * repaired by hand; a refused write is retried.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses a PensionParticipation write that would put one employee in two
 * schemes, or twice in one, on the same day.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
 */
class PensionParticipationOverlapListener implements IEventListener {

	/**
	 * Lower-cased slug of the schema this listener guards.
	 *
	 * @var string
	 */
	public const PENSIONPARTICIPATION_SLUG = 'pensionparticipation';

	/**
	 * The end date an open-ended participation runs to.
	 *
	 * @var string
	 */
	private const OPEN_END = '9999-12-31';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The shared register plumbing.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a pre-save event for a PensionParticipation write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function handle(Event $event): void {
		$incoming = $this->incoming($event);
		if ($incoming === null) {
			return;
		}

		[$payload, $uuid] = $incoming;

		try {
			$clash = $this->clashFor(payload: $payload, uuid: $uuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: PensionParticipationOverlapListener could not check a PensionParticipation write',
				['exception' => $e->getMessage()]
			);
			PreSaveEvents::refuse(
				$event,
				'De pensioendeelname kon niet worden gecontroleerd op overlap; probeer het later opnieuw.'
			);
			return;
		}

		if ($clash !== null) {
			PreSaveEvents::refuse($event, self::refusal($clash));
		}

	}//end handle()

	/**
	 * The first stored participation of the same employee whose date range
	 * overlaps the incoming one, or null when none does.
	 *
	 * Ranges are inclusive at both ends; an empty end date runs on. A
	 * participation ending on 30 April and one starting on 1 May do not
	 * overlap.
	 *
	 * @param array<string, mixed> $incoming The participation being written.
	 * @param array<int, array<string, mixed>> $stored The employee's stored participations.
	 * @param string|null $uuid The uuid being updated, which is not its own overlap.
	 *
	 * @return array<string, mixed>|null The clashing participation.
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public static function firstOverlap(array $incoming, array $stored, ?string $uuid=null): ?array {
		$employeeId = trim((string)($incoming['employeeId'] ?? ''));
		$start      = trim((string)($incoming['startDate'] ?? ''));
		if ($employeeId === '' || $start === '') {
			return null;
		}

		$end = self::endOf($incoming);
		foreach ($stored as $row) {
			$rowId = trim((string)($row['id'] ?? ''));
			if (($uuid !== null && $rowId === $uuid)
				|| trim((string)($row['employeeId'] ?? '')) !== $employeeId
				|| trim((string)($row['startDate'] ?? '')) === ''
			) {
				continue;
			}

			if ($start <= self::endOf($row) && (string)$row['startDate'] <= $end) {
				return $row;
			}
		}

		return null;
	}//end firstOverlap()

	/**
	 * The refusal text, naming the stored participation.
	 *
	 * @param array<string, mixed> $clash The stored participation.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public static function refusal(array $clash): string {
		$end = trim((string)($clash['endDate'] ?? ''));
		return sprintf(
			'Deze pensioendeelname overlapt met de bestaande deelname %s bij fonds %s van %s %s. Beëindig die deelname eerst.',
			trim((string)($clash['id'] ?? '')),
			trim((string)($clash['fund'] ?? '')),
			trim((string)$clash['startDate']),
			($end === '' ? 'zonder einddatum' : 'tot en met ' . $end)
		);
	}//end refusal()

	/**
	 * The stored participation the incoming one clashes with.
	 *
	 * @param array<string, mixed> $payload The incoming payload.
	 * @param string|null $uuid The uuid being updated.
	 *
	 * @return array<string, mixed>|null The clash.
	 */
	private function clashFor(array $payload, ?string $uuid): ?array {
		$employeeId = trim((string)($payload['employeeId'] ?? ''));
		if ($employeeId === '') {
			// The schema requires the employee; nothing to compare without one.
			return null;
		}

		$stored = $this->gateway->findFiltered('PensionParticipation', ['employeeId' => $employeeId]);
		return self::firstOverlap(incoming: $payload, stored: $stored, uuid: $uuid);
	}//end clashFor()

	/**
	 * The inclusive end date of a participation; open-ended runs on.
	 *
	 * @param array<string, mixed> $row The participation.
	 *
	 * @return string The end date.
	 */
	private static function endOf(array $row): string {
		$end = trim((string)($row['endDate'] ?? ''));
		return ($end === '' ? self::OPEN_END : $end);
	}//end endOf()

	/**
	 * The payload and uuid of a PensionParticipation write, or null for any
	 * other event or schema.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: array<string, mixed>, 1: string|null}|null The payload and the uuid being updated.
	 */
	private function incoming(Event $event): ?array {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			$entity = $event->getObject();
			if ($this->isParticipation($entity) === false) {
				return null;
			}

			return [($entity->getObject() ?? []), null];
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			if ($this->isParticipation($entity) === false) {
				return null;
			}

			return [($entity->getObject() ?? []), (string)$entity->getUuid()];
		}

		return null;
	}//end incoming()

	/**
	 * Whether the entity is a PensionParticipation (slug gate, defence in
	 * depth under the subscription-level filter).
	 *
	 * @param object $entity The ObjectEntity.
	 *
	 * @return bool True for the PensionParticipation schema.
	 */
	private function isParticipation(object $entity): bool {
		return (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::PENSIONPARTICIPATION_SLUG);
	}//end isParticipation()

}//end class
