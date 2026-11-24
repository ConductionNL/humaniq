<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One employee, one pension participation per day (REQ-PPP-002).
 *
 * The payroll run takes the participation in force on the last day of the
 * period. With two overlapping ones that is whichever the store returned
 * first, so a premium would move with query ordering. The refusal sits on the
 * write (OpenRegister's pre-save events), because the participation pages are
 * declarative and write straight through the object API. A lookup that throws
 * refuses the write: an accepted overlap is repaired by hand, a refused write
 * is simply retried.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses a PensionParticipation write that overlaps another participation
 * of the same employee, and names the stored one.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
 */
class PensionParticipationOverlapListener implements IEventListener {

	/**
	 * The lower-cased slug of the guarded schema.
	 *
	 * @var string
	 */
	public const SLUG = 'pensionparticipation';

	/**
	 * The end date an open-ended participation runs to.
	 *
	 * @var string
	 */
	private const OPEN_END = '9999-12-31';

	/**
	 * @param HoursRegisterGateway $gateway Reads the employee's stored participations.
	 * @param LoggerInterface      $logger  Records a lookup that failed.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the create or update when it overlaps; let anything else pass.
	 *
	 * @param Event $event An OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PreSaveEvents::refuse is the shared, stateless way every pre-save guard here stops a write.
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public function handle(Event $event): void {
		$isUpdate = ($event instanceof ObjectUpdatingEvent);
		$entity   = match (true) {
			$isUpdate => $event->getNewObject(),
			($event instanceof ObjectCreatingEvent) => $event->getObject(),
			default => null,
		};
		if ($entity === null || strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::SLUG) {
			return;
		}

		$payload = ($entity->getObject() ?? []);
		try {
			$stored = $this->gateway->findFiltered('PensionParticipation', ['employeeId' => trim((string)($payload['employeeId'] ?? ''))]);
			$clash  = self::firstOverlap(incoming: $payload, stored: $stored, uuid: ($isUpdate === true ? (string)$entity->getUuid() : null));
			$reason = ($clash === null ? null : self::refusal($clash));
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: a PensionParticipation write could not be checked for overlap', ['exception' => $e->getMessage()]);
			$reason = 'De pensioendeelname kon niet worden gecontroleerd op overlap; probeer het later opnieuw.';
		}

		if ($reason !== null) {
			PreSaveEvents::refuse($event, $reason);
		}
	}//end handle()

	/**
	 * The first stored participation of the same employee whose dates overlap
	 * the incoming one, or null.
	 *
	 * Both ends are inclusive and an empty end runs on, so a participation
	 * ending on 30 April and one starting on 1 May do not overlap.
	 *
	 * @param array<string, mixed>             $incoming The participation being written.
	 * @param array<int, array<string, mixed>> $stored   The employee's stored participations.
	 * @param string|null                      $uuid     The uuid being updated, never its own overlap.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public static function firstOverlap(array $incoming, array $stored, ?string $uuid=null): ?array {
		$range = self::rangeOf($incoming);
		if ($range === null) {
			return null;
		}

		foreach ($stored as $row) {
			if (($uuid === null || (string)($row['id'] ?? '') !== $uuid) && self::overlaps(range: $range, row: $row) === true) {
				return $row;
			}
		}

		return null;
	}//end firstOverlap()

	/**
	 * Whether a stored participation is the same employee's and shares a day
	 * with the range.
	 *
	 * @param array{employeeId: string, start: string, end: string} $range The incoming range.
	 * @param array<string, mixed>                                   $row   A stored participation.
	 *
	 * @return bool
	 */
	private static function overlaps(array $range, array $row): bool {
		$other = self::rangeOf($row);
		return ($other !== null && $other['employeeId'] === $range['employeeId'] && $range['start'] <= $other['end'] && $other['start'] <= $range['end']);
	}//end overlaps()

	/**
	 * The refusal, naming the stored participation by id, fund and dates.
	 *
	 * @param array<string, mixed> $clash The stored participation.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-002
	 */
	public static function refusal(array $clash): string {
		$end = trim((string)($clash['endDate'] ?? ''));
		return sprintf(
			'Deze pensioendeelname overlapt met de bestaande deelname %s bij fonds %s van %s %s. Beëindig die deelname eerst.',
			trim((string)($clash['id'] ?? '')),
			trim((string)($clash['fund'] ?? '')),
			trim((string)($clash['startDate'] ?? '')),
			($end === '' ? 'zonder einddatum' : 'tot en met '.$end)
		);
	}//end refusal()

	/**
	 * The employee and inclusive date range of a participation, or null when
	 * it names no employee or start (the schema requires both).
	 *
	 * @param array<string, mixed> $row A participation.
	 *
	 * @return array{employeeId: string, start: string, end: string}|null
	 */
	private static function rangeOf(array $row): ?array {
		$employeeId = trim((string)($row['employeeId'] ?? ''));
		$start      = trim((string)($row['startDate'] ?? ''));
		if ($employeeId === '' || $start === '') {
			return null;
		}

		$end = trim((string)($row['endDate'] ?? ''));
		return ['employeeId' => $employeeId, 'start' => $start, 'end' => ($end === '' ? self::OPEN_END : $end)];
	}//end rangeOf()
}//end class
