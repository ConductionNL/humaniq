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
	 * @param HoursRegisterGateway $gateway Reads contracts and placements.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
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

		return match ($slug) {
			'onboarding' => $this->onboarding(old: $old, new: $new, today: $today),
			'offboarding' => $this->offboarding(old: $old, new: $new, today: $today),
			'employmentcontract' => $this->contract(old: $old, new: $new, today: $today),
			'orgassignment' => $this->placement(old: $old, new: $new, today: $today),
			'leaverequest' => $this->leave(old: $old, new: $new, today: $today),
			'sickleavecase' => $this->sickness(old: $old, new: $new, today: $today),
			default => [],
		};
	}//end moments()

	/**
	 * Joining through a completed onboarding case.
	 *
	 * @param array<string, mixed>|null $old The stored case.
	 * @param array<string, mixed> $new The saved case.
	 * @param string $today The day.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function onboarding(?array $old, array $new, string $today): array {
		if ($this->entered(old: $old, new: $new, field: 'status', value: 'afgerond') === false) {
			return [];
		}

		$start = ($this->day(value: $new['startDate'] ?? null) ?? $today);

		return [$this->joined(employeeId: (string)$new['employeeId'], start: $start, today: $today)];
	}//end onboarding()

	/**
	 * Leaving through a completed offboarding case.
	 *
	 * @param array<string, mixed>|null $old The stored case.
	 * @param array<string, mixed> $new The saved case.
	 * @param string $today The day.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function offboarding(?array $old, array $new, string $today): array {
		if ($this->entered(old: $old, new: $new, field: 'status', value: 'afgerond') === false) {
			return [];
		}

		$last = ($this->day(value: $new['lastWorkingDay'] ?? null) ?? $today);

		return [$this->moment(type: 'employee.left', employeeId: (string)$new['employeeId'], occurredOn: $last, subjectId: '', data: ['lastWorkingDay' => $last])];
	}//end offboarding()

	/**
	 * A contract: the first one joins, a new function is a job change, the last one ending leaves.
	 *
	 * @param array<string, mixed>|null $old The stored contract.
	 * @param array<string, mixed> $new The saved contract.
	 * @param string $today The day.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contract(?array $old, array $new, string $today): array {
		$employeeId = (string)$new['employeeId'];
		$others = $this->others(schema: 'EmploymentContract', employeeId: $employeeId, id: (string)($new['id'] ?? ''));
		if ($old === null) {
			return ($others === []) ? [$this->joined(employeeId: $employeeId, start: ($this->day(value: $new['startDate'] ?? null) ?? $today), today: $today)] : [];
		}

		return array_merge($this->functionChange(old: $old, new: $new, today: $today), $this->contractEnded(old: $old, new: $new, others: $others, today: $today));
	}//end contract()

	/**
	 * A contract whose function changed.
	 *
	 * @param array<string, mixed> $old The stored contract.
	 * @param array<string, mixed> $new The saved contract.
	 * @param string $today The day.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function functionChange(array $old, array $new, string $today): array {
		$oldFunction = $this->text(value: $old['normfunctieId'] ?? null);
		$newFunction = $this->text(value: $new['normfunctieId'] ?? null);
		if ($oldFunction === null || $newFunction === null || $oldFunction === $newFunction) {
			return [];
		}

		$employeeId = (string)$new['employeeId'];
		$unit = $this->unitOn(employeeId: $employeeId, day: $today);

		return [
			$this->moment(
				type: 'employee.jobchanged',
				employeeId: $employeeId,
				occurredOn: $today,
				subjectId: (string)($new['id'] ?? ''),
				data: ['from' => ['orgUnitId' => $unit, 'normfunctieId' => $oldFunction], 'to' => ['orgUnitId' => $unit, 'normfunctieId' => $newFunction]]
			),
		];
	}//end functionChange()

	/**
	 * A contract that got an end date in the past while no other contract still runs.
	 *
	 * @param array<string, mixed> $old The stored contract.
	 * @param array<string, mixed> $new The saved contract.
	 * @param array<int, array<string, mixed>> $others The employee's other contracts.
	 * @param string $today The day.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contractEnded(array $old, array $new, array $others, string $today): array {
		$end = $this->day(value: $new['endDate'] ?? null);
		if ($end === null || $end >= $today || $end === $this->day(value: $old['endDate'] ?? null)) {
			return [];
		}

		foreach ($others as $other) {
			if (($this->day(value: $other['endDate'] ?? null) ?? '9999-12-31') >= $today) {
				return [];
			}
		}

		return [$this->moment(type: 'employee.left', employeeId: (string)$new['employeeId'], occurredOn: $end, subjectId: '', data: ['lastWorkingDay' => $end])];
	}//end contractEnded()

	/**
	 * A new placement for someone who already had one is a job change.
	 *
	 * @param array<string, mixed>|null $old The stored placement.
	 * @param array<string, mixed> $new The saved placement.
	 * @param string $today The day.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function placement(?array $old, array $new, string $today): array {
		if ($old !== null) {
			return [];
		}

		$employeeId = (string)$new['employeeId'];
		$others = $this->others(schema: 'OrgAssignment', employeeId: $employeeId, id: (string)($new['id'] ?? ''));
		if ($others === []) {
			return [];
		}

		usort($others, fn (array $a, array $b): int => (string)($b['startDate'] ?? '') <=> (string)($a['startDate'] ?? ''));

		return [
			$this->moment(
				type: 'employee.jobchanged',
				employeeId: $employeeId,
				occurredOn: ($this->day(value: $new['startDate'] ?? null) ?? $today),
				subjectId: (string)($new['id'] ?? ''),
				data: ['from' => ['orgUnitId' => $this->text(value: $others[0]['orgUnitId'] ?? null), 'normfunctieId' => null], 'to' => ['orgUnitId' => $this->text(value: $new['orgUnitId'] ?? null), 'normfunctieId' => null]]
			),
		];
	}//end placement()

	/**
	 * Leave approved, or an approved request no longer approved.
	 *
	 * @param array<string, mixed>|null $old The stored request.
	 * @param array<string, mixed> $new The saved request.
	 * @param string $today The day.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function leave(?array $old, array $new, string $today): array {
		$type = null;
		if ($this->entered(old: $old, new: $new, field: 'status', value: 'approved') === true) {
			$type = 'leave.approved';
		} else if ($old !== null && (string)($old['status'] ?? '') === 'approved' && (string)($new['status'] ?? '') !== 'approved') {
			$type = 'leave.withdrawn';
		}

		if ($type === null) {
			return [];
		}

		return [
			$this->moment(
				type: $type,
				employeeId: (string)$new['employeeId'],
				occurredOn: $today,
				subjectId: (string)($new['id'] ?? ''),
				data: ['startDate' => $this->day(value: $new['startDate'] ?? null), 'endDate' => $this->day(value: $new['endDate'] ?? null), 'hours' => (isset($new['hours']) === true ? (float)$new['hours'] : null)]
			),
		];
	}//end leave()

	/**
	 * A sickness case reported, or recovered.
	 *
	 * @param array<string, mixed>|null $old The stored case.
	 * @param array<string, mixed> $new The saved case.
	 * @param string $today The day.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sickness(?array $old, array $new, string $today): array {
		$from = $this->day(value: $new['firstSickDay'] ?? null);
		if ($old === null) {
			return [$this->moment(type: 'sickness.reported', employeeId: (string)$new['employeeId'], occurredOn: ($from ?? $today), subjectId: (string)($new['id'] ?? ''), data: ['from' => $from, 'to' => null])];
		}

		if ($this->entered(old: $old, new: $new, field: 'status', value: 'hersteld') === false) {
			return [];
		}

		$to = $this->day(value: $new['recoveredDate'] ?? null);

		return [$this->moment(type: 'sickness.recovered', employeeId: (string)$new['employeeId'], occurredOn: ($to ?? $today), subjectId: (string)($new['id'] ?? ''), data: ['from' => $from, 'to' => $to])];
	}//end sickness()

	/**
	 * A joined moment, with the unit the employee is placed in.
	 *
	 * @param string $employeeId The employee.
	 * @param string $start The first day.
	 * @param string $today The day.
	 *
	 * @return array<string, mixed>
	 */
	private function joined(string $employeeId, string $start, string $today): array {
		$unit = $this->unitOn(employeeId: $employeeId, day: max($start, $today));

		return $this->moment(type: 'employee.joined', employeeId: $employeeId, occurredOn: $start, subjectId: '', data: ['startDate' => $start, 'orgUnitId' => $unit]);
	}//end joined()

	/**
	 * One moment.
	 *
	 * @param string $type The type after the prefix.
	 * @param string $employeeId The employee.
	 * @param string $occurredOn The day.
	 * @param string $subjectId The record, '' for moments one person has once a day.
	 * @param array<string, mixed> $data The moment's own fields.
	 *
	 * @return array{type: string, employeeId: string, occurredOn: string, subjectId: string, data: array<string, mixed>}
	 */
	private function moment(string $type, string $employeeId, string $occurredOn, string $subjectId, array $data): array {
		return ['type' => $type, 'employeeId' => $employeeId, 'occurredOn' => $occurredOn, 'subjectId' => $subjectId, 'data' => $data];
	}//end moment()

	/**
	 * The employee's other records of a schema.
	 *
	 * @param string $schema The schema.
	 * @param string $employeeId The employee.
	 * @param string $id The record to leave out.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function others(string $schema, string $employeeId, string $id): array {
		return array_values(
			array_filter(
				$this->gateway->findFiltered($schema, ['employeeId' => $employeeId]),
				static fn (array $row): bool => (string)($row['id'] ?? '') !== $id
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
	 * Whether a field took this value on this write.
	 *
	 * @param array<string, mixed>|null $old The stored record.
	 * @param array<string, mixed> $new The saved record.
	 * @param string $field The field.
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private function entered(?array $old, array $new, string $field, string $value): bool {
		return (string)($new[$field] ?? '') === $value && (string)(($old ?? [])[$field] ?? '') !== $value;
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
