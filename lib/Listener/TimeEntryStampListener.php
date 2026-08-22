<?php

/**
 * Humaniq TimeEntryStampListener
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` / `ObjectDeletingEvent` for the `TimeEntry` schema
 * (hours-process-redesign Decisions 3 + 5). One listener, two concerns:
 *
 * 1. **Mutability guard (Decision 3, REQ-TEC-005)**: any create, update or
 *    delete of an entry whose parent Timesheet is not in `draft`/`rejected`
 *    is refused with a structured 422 — approved history stays immutable;
 *    the `reopen` transition is the sanctioned route to correction.
 * 2. **Stamping (Decision 5)**: resolves `employeeId` from the signed-in
 *    user when absent (the self-service form carries no identity fields),
 *    derives `hours` from `startedAt`/`endedAt`/`breakMinutes` (refusing
 *    impossible spans), stamps `userId`/`administrationId` from the Employee
 *    and `costCenter` from the org chain (shared OrgResolutionService), and
 *    resolves/creates the parent Timesheet for the entry's month
 *    (`YYYY-MM`, fixed grain per design resolution 2).
 *
 * Mutations travel through the event's `setModifiedData()` channel, which
 * MagicMapper merges into the entity before persistence — proven by
 * OpenRegisterPreSaveMutationContractTest (task V1) with a must-fail
 * control. humaniq's own internal writes (migration synthesis) are exempted
 * wholesale via the request-scoped {@see InternalWriteMarker} — the internal
 * writer takes full responsibility for the values it writes.
 *
 * Failure posture: a write this listener cannot stamp is REFUSED, never let
 * through half-stamped — an unstamped `userId` would silently hide the entry
 * from every `@me` page, which is the exact defect this change removes.
 *
 * All register plumbing lives in {@see HoursRegisterGateway} (which clones
 * ObjectService per use — the outer-save context-poisoning hazard); this
 * class carries only the decisions.
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/employer-hourly-cost-rate/spec.md#Requirement:-Cost-allocation-references-live-on-the-time-entry-and-are-never-employee-typed
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Pre-save mutability guard + stamping for TimeEntry writes.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
 */
class TimeEntryStampListener implements IEventListener {

	/**
	 * Lower-cased slug of the schema this listener stamps.
	 *
	 * @var string
	 */
	public const TIMEENTRY_SLUG = 'timeentry';

	/**
	 * Timesheet states whose entries are mutable.
	 *
	 * @var array<int, string>
	 */
	public const MUTABLE_PARENT_STATES = ['draft', 'rejected'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The shared register plumbing + org-chain lookups.
	 * @param IUserSession $userSession The user session (acting uid for self-service resolution).
	 * @param InternalWriteMarker $marker The request-scoped internal-writer marker.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly IUserSession $userSession,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a pre-save event for a TimeEntry write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			// humaniq's own internal write (migration synthesis) — the internal
			// writer owns its values.
			return;
		}

		try {
			$this->dispatch($event);
		} catch (HoursWriteRefusedException $e) {
			$this->refuse($event, $e->getMessage());
		} catch (\Throwable $e) {
			// Fail CLOSED: a half-stamped entry (no userId) silently vanishes
			// from every @me page, which is worse than a refused write.
			$this->logger->warning(
				'humaniq: TimeEntryStampListener could not stamp a TimeEntry write',
				['exception' => $e->getMessage()]
			);
			$this->refuse($event, 'De urenboeking kon niet worden verwerkt; probeer het later opnieuw.');
		}//end try

	}//end handle()

	/**
	 * Route the event to its guard/stamp path (TimeEntry schema only).
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @throws HoursWriteRefusedException On any deliberate refusal.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
	 */
	private function dispatch(Event $event): void {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectDeletingEvent) {
			if ($this->isTimeEntry($event->getObject()) === true) {
				$this->guardDelete($event->getObject());
			}

			return;
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			if ($this->isTimeEntry($event->getObject()) === true) {
				$incoming = ($event->getObject()->getObject() ?? []);
				$event->setModifiedData($this->stamp(incoming: $incoming, stored: null, isCreate: true));
			}

			return;
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			if ($this->isTimeEntry($event->getNewObject()) === false) {
				return;
			}

			$incoming = ($event->getNewObject()->getObject() ?? []);
			$stored = $event->getOldObject()?->getObject();
			$event->setModifiedData($this->stamp(incoming: $incoming, stored: $stored, isCreate: false));
		}
	}//end dispatch()

	/**
	 * Whether the entity is a TimeEntry (slug gate, defence in depth under
	 * the subscription-level filter).
	 *
	 * @param object $entity The ObjectEntity.
	 *
	 * @return bool True for the TimeEntry schema.
	 */
	private function isTimeEntry(object $entity): bool {
		return strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::TIMEENTRY_SLUG;
	}//end isTimeEntry()

	/**
	 * Compute the stamped values for a create/update write.
	 *
	 * @param array<string, mixed> $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored The stored payload (update only).
	 * @param bool $isCreate Whether this is a create write.
	 *
	 * @return array<string, mixed> The modified-data map to merge into the write.
	 *
	 * @throws HoursWriteRefusedException On any deliberate refusal.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
	 */
	private function stamp(array $incoming, ?array $stored, bool $isCreate): array {
		// 1. Employee resolution (Decision 5.1): explicit id (HR entry) or
		// the acting user's own Employee (self-service).
		[$employeeId, $employee] = $this->resolveEmployee($incoming, $stored);

		// 2. Hours derivation (Decision 5.5) — refuses impossible spans.
		[$startedAt, $hours] = $this->deriveHours($incoming, $stored);

		// 3. Parent timesheet resolution + mutability guard (Decisions 5.6 + 3).
		$timesheetId = $this->resolveTimesheet($incoming, $stored, $employeeId, $startedAt);

		// 4. Identity + cost-centre stamps (Decision 5.2-5.4).
		$modified = [
			'employeeId' => $employeeId,
			'timesheetId' => $timesheetId,
			'hours' => $hours,
			'userId' => $this->nullableTrim($employee['nextcloudUserId'] ?? null),
			'administrationId' => $this->nullableTrim($employee['administrationId'] ?? null),
			// The entry's own start date is the chain's reference date; null
			// when it does not resolve to exactly one answer (never guessed).
			'costCenter' => $this->gateway->uniqueCostCenterFor($employeeId, gmdate('Y-m-d', $startedAt)),
		];

		if ($isCreate === true) {
			$origin = trim((string)($incoming['origin'] ?? ''));
			$modified['origin'] = in_array($origin, ['manual', 'migration', 'import'], true) === true ? $origin : 'manual';
		}

		return $modified;
	}//end stamp()

	/**
	 * Resolve the employee for this booking: the write's explicit id (HR
	 * entry) wins; otherwise the acting user's own Employee (self-service).
	 *
	 * @param array<string, mixed> $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored The stored payload (update only).
	 *
	 * @return array{0: string, 1: array<string, mixed>} Employee id + payload ([] when dangling).
	 *
	 * @throws HoursWriteRefusedException When no employee resolves at all.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	private function resolveEmployee(array $incoming, ?array $stored): array {
		$employeeId = trim((string)($incoming['employeeId'] ?? ''));
		if ($employeeId === '' && $stored !== null) {
			$employeeId = trim((string)($stored['employeeId'] ?? ''));
		}

		if ($employeeId !== '') {
			return [$employeeId, ($this->gateway->findObjectData($employeeId, 'Employee') ?? [])];
		}

		$uid = trim((string)($this->userSession->getUser()?->getUID() ?? ''));
		$employee = ($this->gateway->findEmployeeByUserId($uid) ?? []);
		$employeeId = trim((string)($employee['id'] ?? $employee['@self']['id'] ?? ''));
		if ($employeeId === '') {
			throw new HoursWriteRefusedException(
				'Er is geen medewerker gekoppeld aan uw account; uren boeken is niet mogelijk. '
				. 'Vraag HR om uw medewerkersprofiel te koppelen.'
			);
		}

		return [$employeeId, $employee];
	}//end resolveEmployee()

	/**
	 * Derive `hours` from the span, refusing impossible input (REQ-TEC-001).
	 *
	 * @param array<string, mixed> $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored The stored payload (update only).
	 *
	 * @return array{0: int, 1: float} The UTC start timestamp and the derived hours.
	 *
	 * @throws HoursWriteRefusedException When the span is impossible.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
	 */
	private function deriveHours(array $incoming, ?array $stored): array {
		// Plain strtotime: timestamps are timezone-agnostic, and the two
		// derived date strings below are formatted with gmdate() — no
		// DateTime machinery needed.
		$start = strtotime((string)($incoming['startedAt'] ?? ($stored['startedAt'] ?? '')));
		$end = strtotime((string)($incoming['endedAt'] ?? ($stored['endedAt'] ?? '')));
		if ($start === false || $end === false) {
			throw new HoursWriteRefusedException('De start- of eindtijd van de urenboeking is ongeldig.');
		}

		if ($end <= $start) {
			throw new HoursWriteRefusedException('De eindtijd van een urenboeking moet na de starttijd liggen.');
		}

		$breakMinutes = $incoming['breakMinutes'] ?? ($stored['breakMinutes'] ?? 0);
		if (is_numeric($breakMinutes) === false || (int)$breakMinutes < 0) {
			throw new HoursWriteRefusedException('De pauze van een urenboeking moet nul minuten of meer zijn.');
		}

		$spanMinutes = ($end - $start) / 60;
		if ((int)$breakMinutes >= $spanMinutes) {
			throw new HoursWriteRefusedException('De pauze is even lang als of langer dan de geboekte tijd.');
		}

		$hours = round(($spanMinutes - (int)$breakMinutes) / 60, 2);

		return [$start, $hours];
	}//end deriveHours()

	/**
	 * Resolve the parent Timesheet (find-or-create on the month grain) and
	 * enforce the mutability guard on every parent involved.
	 *
	 * @param array<string, mixed> $incoming The incoming payload.
	 * @param array<string, mixed>|null $stored The stored payload (update only).
	 * @param string $employeeId The resolved employee id.
	 * @param int $startedAt The UTC start timestamp of the booking.
	 *
	 * @return string The parent timesheet uuid.
	 *
	 * @throws HoursWriteRefusedException When a parent is immutable or conflicts.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
	 */
	private function resolveTimesheet(
		array $incoming,
		?array $stored,
		string $employeeId,
		int $startedAt,
	): string {
		// Editing an entry that already belongs to a locked timesheet is
		// refused regardless of what the write tries to change.
		$storedParentId = $stored === null ? '' : trim((string)($stored['timesheetId'] ?? ''));
		if ($storedParentId !== '') {
			$this->assertParentMutable($storedParentId);
		}

		$timesheetId = trim((string)($incoming['timesheetId'] ?? ''));
		if ($timesheetId === '') {
			$timesheetId = $storedParentId;
		}

		if ($timesheetId !== '') {
			if ($timesheetId !== $storedParentId) {
				// Explicit (re)parent — the target must be mutable too.
				$this->assertParentMutable($timesheetId);
			}

			return $timesheetId;
		}

		return $this->findOrCreateTimesheet($employeeId, gmdate('Y-m', $startedAt));
	}//end resolveTimesheet()

	/**
	 * Find the employee's draft/rejected Timesheet for a period, creating a
	 * draft one when none exists. Refuses when the only timesheet(s) for the
	 * period are submitted/approved — never silently books into a second
	 * timesheet for the same period.
	 *
	 * @param string $employeeId The employee id.
	 * @param string $period The month, `YYYY-MM`.
	 *
	 * @return string The timesheet uuid.
	 *
	 * @throws HoursWriteRefusedException When the period's timesheet is locked.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-humaniq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
	 */
	private function findOrCreateTimesheet(string $employeeId, string $period): string {
		$candidates = $this->gateway->findFiltered('Timesheet', ['employeeId' => $employeeId, 'period' => $period]);

		foreach ($candidates as $candidate) {
			$status = strtolower(trim((string)($candidate['status'] ?? '')));
			if (in_array($status, self::MUTABLE_PARENT_STATES, true) === true) {
				return (string)($candidate['id'] ?? $candidate['@self']['id'] ?? '');
			}
		}

		if (count($candidates) > 0) {
			throw new HoursWriteRefusedException(
				'De urenstaat voor deze periode is al ingediend of goedgekeurd. '
				. 'Laat de urenstaat heropenen voordat u uren voor deze periode boekt.'
			);
		}

		$saved = $this->gateway->save(
			payload: [
				'employeeId' => $employeeId,
				'period' => $period,
				'status' => 'draft',
				'hours' => 0,
				'entryCount' => 0,
			],
			schema: 'Timesheet'
		);

		return (string)$saved->getUuid();
	}//end findOrCreateTimesheet()

	/**
	 * Refuse the write when the given parent Timesheet is not draft/rejected.
	 *
	 * A missing parent does not refuse — a dangling reference is a data
	 * problem for the aggregate recompute, not a lock.
	 *
	 * @param string $timesheetId The parent timesheet uuid.
	 *
	 * @return void
	 *
	 * @throws HoursWriteRefusedException When the parent is locked.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
	 */
	private function assertParentMutable(string $timesheetId): void {
		$parent = $this->gateway->findObjectData($timesheetId, 'Timesheet');
		if ($parent === null) {
			return;
		}

		$status = strtolower(trim((string)($parent['status'] ?? '')));
		if ($status !== '' && in_array($status, self::MUTABLE_PARENT_STATES, true) === false) {
			throw new HoursWriteRefusedException(
				sprintf(
					'De urenstaat van deze boeking heeft de status "%s"; urenboekingen kunnen niet worden gewijzigd. '
					. 'Laat de urenstaat heropenen om te corrigeren.',
					$status
				)
			);
		}
	}//end assertParentMutable()

	/**
	 * Guard a delete: entries of a locked timesheet cannot be removed.
	 *
	 * @param object $entity The ObjectEntity being deleted.
	 *
	 * @return void
	 *
	 * @throws HoursWriteRefusedException When the parent is locked.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
	 */
	private function guardDelete(object $entity): void {
		$data = ($entity->getObject() ?? []);
		$parentId = trim((string)($data['timesheetId'] ?? ''));
		if ($parentId !== '') {
			$this->assertParentMutable($parentId);
		}
	}//end guardDelete()

	/**
	 * Refuse the write with a structured, user-facing error.
	 *
	 * @param object $event The pre-save event.
	 * @param string $message The Dutch user-facing message.
	 *
	 * @return void
	 */
	private function refuse(object $event, string $message): void {
		if (method_exists($event, 'setErrors') === false || method_exists($event, 'stopPropagation') === false) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * Trim to a non-empty string or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The trimmed value, or null.
	 */
	private function nullableTrim(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end nullableTrim()

}//end class
