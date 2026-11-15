<?php

/**
 * Humaniq HrLifecycleMoments
 *
 * Detects the HR moments a save marks (platform-hr-lifecycle-events D1): an
 * employee joins, leaves or changes job, leave is approved or withdrawn,
 * sickness is reported or ends. Each moment is found on the edge that causes
 * it, by comparing the stored and the saved record, so saving again finds
 * nothing. HrLifecycleEventService sends what this class finds.
 *
 * Each moment carries only its own dates (D2): never a leave type, a reason,
 * a percentage, salary, BSN or medical data.
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

/**
 * Detects HR moments on the edge of a save.
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */
class HrLifecycleMoments {

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway Reads contracts and placements.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
	) {

	}//end __construct()

	/**
	 * The moments a write marks: `[{type, employeeId, occurredOn, subjectId, data}]`.
	 *
	 * @param string $slug The schema slug.
	 * @param array<string, mixed>|null $old The stored record, null on create.
	 * @param array<string, mixed> $new The saved record, with its id.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array<int, array{type: string, employeeId: string, occurredOn: string, subjectId: string, data: array<string, mixed>}>
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-002
	 */
	public function moments(string $slug, ?array $old, array $new, string $today): array {
		$employeeId = trim((string)($new['employeeId'] ?? ''));
		if ($employeeId === '') {
			return [];
		}

		$change = new LifecycleChange();
		$change->old = $old;
		$change->new = $new;
		$change->today = $today;
		$change->employeeId = (string)$new['employeeId'];
		$change->subjectId = (string)($new['id'] ?? '');

		return match ($slug) {
			'onboarding' => $this->onboarding(change: $change),
			'offboarding' => $this->offboarding(change: $change),
			'employmentcontract' => $this->contract(change: $change),
			'orgassignment' => $this->placement(change: $change),
			'leaverequest' => $this->leave(change: $change),
			'sickleavecase' => $this->sickness(change: $change),
			default => [],
		};
	}//end moments()

	/**
	 * Joining through a completed onboarding case.
	 *
	 * @param LifecycleChange $change The write.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function onboarding(LifecycleChange $change): array {
		if ($this->entered(change: $change, status: 'afgerond') === false) {
			return [];
		}

		$start = ($this->day(value: $change->new['startDate'] ?? null) ?? $change->today);

		return [$this->joined(change: $change, start: $start)];
	}//end onboarding()

	/**
	 * Leaving through a completed offboarding case.
	 *
	 * @param LifecycleChange $change The write.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function offboarding(LifecycleChange $change): array {
		if ($this->entered(change: $change, status: 'afgerond') === false) {
			return [];
		}

		$last = ($this->day(value: $change->new['lastWorkingDay'] ?? null) ?? $change->today);

		return [$this->left(change: $change, last: $last)];
	}//end offboarding()

	/**
	 * A contract: the first one joins, a new function is a job change, the last one ending leaves.
	 *
	 * @param LifecycleChange $change The write.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contract(LifecycleChange $change): array {
		$others = $this->others(change: $change, schema: 'EmploymentContract');
		if ($change->old === null) {
			return ($others === []) ? [$this->joined(change: $change, start: ($this->day(value: $change->new['startDate'] ?? null) ?? $change->today))] : [];
		}

		return array_merge($this->functionChange(change: $change), $this->contractEnded(change: $change, others: $others));
	}//end contract()

	/**
	 * A contract whose function changed.
	 *
	 * @param LifecycleChange $change The write, with its stored contract.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function functionChange(LifecycleChange $change): array {
		$oldFunction = $this->text(value: $change->old['normfunctieId'] ?? null);
		$newFunction = $this->text(value: $change->new['normfunctieId'] ?? null);
		if ($oldFunction === null || $newFunction === null || $oldFunction === $newFunction) {
			return [];
		}

		$unit = $this->unitOn(employeeId: $change->employeeId, day: $change->today);
		$data = ['from' => ['orgUnitId' => $unit, 'normfunctieId' => $oldFunction], 'to' => ['orgUnitId' => $unit, 'normfunctieId' => $newFunction]];

		return [array_merge($this->moment(change: $change, type: 'employee.jobchanged'), ['data' => $data])];
	}//end functionChange()

	/**
	 * A contract that ended before today, when no other contract runs on.
	 *
	 * @param LifecycleChange $change The write, with its stored contract.
	 * @param array<int, array<string, mixed>> $others The employee's other contracts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contractEnded(LifecycleChange $change, array $others): array {
		$end = $this->day(value: $change->new['endDate'] ?? null);
		if ($end === null || $end >= $change->today || $end === $this->day(value: $change->old['endDate'] ?? null)) {
			return [];
		}

		foreach ($others as $other) {
			if (($this->day(value: $other['endDate'] ?? null) ?? '9999-12-31') >= $change->today) {
				return [];
			}
		}

		return [$this->left(change: $change, last: $end)];
	}//end contractEnded()

	/**
	 * A new placement after an earlier one is a job change.
	 *
	 * @param LifecycleChange $change The write.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function placement(LifecycleChange $change): array {
		if ($change->old !== null) {
			return [];
		}

		$others = $this->others(change: $change, schema: 'OrgAssignment');
		if ($others === []) {
			return [];
		}

		usort($others, fn (array $a, array $b): int => (string)($b['startDate'] ?? '') <=> (string)($a['startDate'] ?? ''));
		$data = ['from' => ['orgUnitId' => $this->text(value: $others[0]['orgUnitId'] ?? null), 'normfunctieId' => null], 'to' => ['orgUnitId' => $this->text(value: $change->new['orgUnitId'] ?? null), 'normfunctieId' => null]];

		return [array_merge($this->moment(change: $change, type: 'employee.jobchanged'), ['occurredOn' => ($this->day(value: $change->new['startDate'] ?? null) ?? $change->today), 'data' => $data])];
	}//end placement()

	/**
	 * A leave request approved, or no longer approved.
	 *
	 * @param LifecycleChange $change The write.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function leave(LifecycleChange $change): array {
		$type = $this->leaveType(change: $change);
		if ($type === null) {
			return [];
		}

		$new = $change->new;
		$data = ['startDate' => $this->day(value: $new['startDate'] ?? null), 'endDate' => $this->day(value: $new['endDate'] ?? null), 'hours' => (isset($new['hours']) === true ? (float)$new['hours'] : null)];

		return [array_merge($this->moment(change: $change, type: $type), ['data' => $data])];
	}//end leave()

	/**
	 * The leave moment a write marks, or null.
	 *
	 * @param LifecycleChange $change The write.
	 *
	 * @return string|null
	 */
	private function leaveType(LifecycleChange $change): ?string {
		if ($this->entered(change: $change, status: 'approved') === true) {
			return 'leave.approved';
		}

		if ((string)($change->old['status'] ?? '') === 'approved' && (string)($change->new['status'] ?? '') !== 'approved') {
			return 'leave.withdrawn';
		}

		return null;
	}//end leaveType()

	/**
	 * A sickness reported, or recovered from.
	 *
	 * @param LifecycleChange $change The write.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sickness(LifecycleChange $change): array {
		$from = $this->day(value: $change->new['firstSickDay'] ?? null);
		if ($change->old === null) {
			return [array_merge($this->moment(change: $change, type: 'sickness.reported'), ['occurredOn' => ($from ?? $change->today), 'data' => ['from' => $from, 'to' => null]])];
		}

		if ($this->entered(change: $change, status: 'hersteld') === false) {
			return [];
		}

		$to = $this->day(value: $change->new['recoveredDate'] ?? null);

		return [array_merge($this->moment(change: $change, type: 'sickness.recovered'), ['occurredOn' => ($to ?? $change->today), 'data' => ['from' => $from, 'to' => $to]])];
	}//end sickness()

	/**
	 * A joined moment, with the unit the employee is placed in.
	 *
	 * @param LifecycleChange $change The write.
	 * @param string $start The first day.
	 *
	 * @return array<string, mixed>
	 */
	private function joined(LifecycleChange $change, string $start): array {
		$unit = $this->unitOn(employeeId: $change->employeeId, day: max($start, $change->today));

		return array_merge($this->moment(change: $change, type: 'employee.joined'), ['occurredOn' => $start, 'subjectId' => '', 'data' => ['startDate' => $start, 'orgUnitId' => $unit]]);
	}//end joined()

	/**
	 * A left moment on the last working day.
	 *
	 * @param LifecycleChange $change The write.
	 * @param string $last The last working day.
	 *
	 * @return array<string, mixed>
	 */
	private function left(LifecycleChange $change, string $last): array {
		return array_merge($this->moment(change: $change, type: 'employee.left'), ['occurredOn' => $last, 'subjectId' => '', 'data' => ['lastWorkingDay' => $last]]);
	}//end left()

	/**
	 * One moment about the written record, on the day of the write, with no fields yet.
	 *
	 * @param LifecycleChange $change The write.
	 * @param string $type The type after the prefix.
	 *
	 * @return array{type: string, employeeId: string, occurredOn: string, subjectId: string, data: array<string, mixed>}
	 */
	private function moment(LifecycleChange $change, string $type): array {
		return ['type' => $type, 'employeeId' => $change->employeeId, 'occurredOn' => $change->today, 'subjectId' => $change->subjectId, 'data' => []];
	}//end moment()

	/**
	 * The employee's other records of a schema.
	 *
	 * @param LifecycleChange $change The write, whose record is left out.
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function others(LifecycleChange $change, string $schema): array {
		return array_values(
			array_filter(
				$this->gateway->findFiltered($schema, ['employeeId' => $change->employeeId]),
				static fn (array $row): bool => (string)($row['id'] ?? '') !== $change->subjectId
			)
		);
	}//end others()

	/**
	 * The unit the employee is placed in on a day, or null.
	 *
	 * @param string $employeeId The employee.
	 * @param string $day The day.
	 *
	 * @return string|null
	 */
	private function unitOn(string $employeeId, string $day): ?string {
		foreach ($this->gateway->findFiltered('OrgAssignment', ['employeeId' => $employeeId]) as $placement) {
			$start = ($this->day(value: $placement['startDate'] ?? null) ?? '0000-01-01');
			$end = ($this->day(value: $placement['endDate'] ?? null) ?? '9999-12-31');
			if ($start <= $day && $day <= $end) {
				return $this->text(value: $placement['orgUnitId'] ?? null);
			}
		}

		return null;
	}//end unitOn()

	/**
	 * Whether the status took this value on this write.
	 *
	 * @param LifecycleChange $change The write.
	 * @param string $status The status.
	 *
	 * @return bool
	 */
	private function entered(LifecycleChange $change, string $status): bool {
		return (string)($change->new['status'] ?? '') === $status && (string)(($change->old ?? [])['status'] ?? '') !== $status;
	}//end entered()

	/**
	 * The date part of a value, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function day(mixed $value): ?string {
		$text = substr(trim((string)($value ?? '')), 0, 10);

		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null;
	}//end day()

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
