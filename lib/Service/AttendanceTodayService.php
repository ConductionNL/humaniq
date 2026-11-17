<?php

/**
 * Attendance Today Service
 *
 * Who is present, late, away or not clocked in today (REQ-TA-007), for the
 * people the caller may read: HR and the accountant role read everyone
 * placed in a unit of their active administration, anyone else the people
 * placed in the units they lead. Each person reads as:
 *
 * - `present`: clocked in today;
 * - `late`: clocked in after the start of today's roster assignment;
 * - `away`: on approved leave today, or sick (an open sick case);
 * - `not-clocked-in`: none of these.
 *
 * Working patterns hold hours per weekday and no start time, so lateness is
 * judged against the roster only; without a roster assignment a clocked-in
 * person reads as present.
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Today's presence of the caller's people.
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md#requirement-a-manager-shall-see-who-is-present-absent-or-late-today-req-ta-007
 */
final class AttendanceTodayService {

	/**
	 * The administration-scoped schemas the view reads.
	 */
	private const SCHEMAS = ['OrgUnit', 'OrgAssignment', 'Employee', 'AttendanceRecord', 'LeaveRequest', 'SickLeaveCase'];

	/**
	 * The order the statuses are listed in.
	 */
	private const STATUS_ORDER = ['late' => 0, 'not-clocked-in' => 1, 'away' => 2, 'present' => 3];

	/**
	 * Constructor.
	 *
	 * @param AnalyticsService     $analytics   Reads the administration's rows.
	 * @param UnitMembership       $membership  Unit trees, placements and managed units.
	 * @param AnalyticsAccess      $access      Who may read which units.
	 * @param RecordStoreInterface $store       Today's roster assignments, which carry no administration.
	 * @param ITimeFactory         $timeFactory The clock.
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md#requirement-a-manager-shall-see-who-is-present-absent-or-late-today-req-ta-007
	 */
	public function __construct(
		private readonly AnalyticsService $analytics,
		private readonly UnitMembership $membership,
		private readonly AnalyticsAccess $access,
		private readonly RecordStoreInterface $store,
		private readonly ITimeFactory $timeFactory,
	) {

	}//end __construct()

	/**
	 * Today's view for the caller, or null without an administration.
	 *
	 * @param string $userId The caller.
	 *
	 * @return array{date: string, counts: array<string, int>, people: list<array<string, mixed>>}|null
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-attendance/spec.md#requirement-a-manager-shall-see-who-is-present-absent-or-late-today-req-ta-007
	 */
	public function today(string $userId): ?array {
		$administrationId = $this->access->activeAdministration($userId);
		if ($administrationId === null) {
			return null;
		}

		$now = $this->timeFactory->now();
		$date = $now->format('Y-m-d');
		$data = $this->load(administrationId: $administrationId);
		$employeeIds = $this->peopleInScope(userId: $userId, data: $data, day: new DateTimeImmutable($date));

		$people = [];
		foreach ($data['Employee'] as $employee) {
			$employeeId = $this->membership->rowId($employee);
			if (isset($employeeIds[$employeeId]) === false) {
				continue;
			}

			$people[] = $this->row(employee: $employee, employeeId: $employeeId, date: $date, data: $data);
		}

		usort($people, static fn (array $a, array $b): int => [self::STATUS_ORDER[$a['status']], $a['name']] <=> [self::STATUS_ORDER[$b['status']], $b['name']]);

		$counts = array_fill_keys(array_keys(self::STATUS_ORDER), 0);
		foreach ($people as $person) {
			$counts[$person['status']]++;
		}

		return ['date' => $date, 'counts' => $counts, 'people' => $people];
	}//end today()

	/**
	 * One person's row.
	 *
	 * @param array<string, mixed>                      $employee   The Employee.
	 * @param string                                    $employeeId Its id.
	 * @param string                                    $date       Today.
	 * @param array<string, list<array<string, mixed>>> $data       The rows.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $employee, string $employeeId, string $date, array $data): array {
		$clockIn = self::clockInOf(employeeId: $employeeId, date: $date, records: $data['AttendanceRecord']);
		$plannedStart = self::plannedStartOf(employeeId: $employeeId, roster: $data['RosterAssignment']);

		$status = 'not-clocked-in';
		if ($clockIn !== null) {
			$status = (($plannedStart !== null && strtotime($clockIn) > strtotime($plannedStart)) === true) ? 'late' : 'present';
		} else if (self::isAway(employeeId: $employeeId, date: $date, data: $data) === true) {
			$status = 'away';
		}

		return [
			'id' => $employeeId,
			'name' => trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? '')),
			'employeeNumber' => (string)($employee['employeeNumber'] ?? ''),
			'status' => $status,
			'clockIn' => $clockIn,
			'plannedStart' => $plannedStart,
		];
	}//end row()

	/**
	 * The ids of the people the caller may read, placed in a unit today.
	 *
	 * @param string                                    $userId The caller.
	 * @param array<string, list<array<string, mixed>>> $data   The rows.
	 * @param DateTimeImmutable                         $day    Today.
	 *
	 * @return array<string, true>
	 */
	private function peopleInScope(string $userId, array $data, DateTimeImmutable $day): array {
		$unitIds = $this->membership->managedUnitIds($userId, $data['OrgUnit'], $data['Employee']);
		if ($this->access->fullReaderAdministration($userId) !== null) {
			$unitIds = array_map(fn (array $unit): string => $this->membership->rowId($unit), $data['OrgUnit']);
		}

		$subtree = [];
		foreach (array_filter($unitIds) as $unitId) {
			$subtree = array_merge($subtree, $this->membership->subtree($unitId, $data['OrgUnit']));
		}

		$ids = [];
		foreach ($this->membership->sharesInWindow(array_values(array_unique($subtree)), $data['OrgAssignment'], $day, $day) as $employeeId => $share) {
			if ($share > 0.0) {
				$ids[(string)$employeeId] = true;
			}
		}

		return $ids;
	}//end peopleInScope()

	/**
	 * The earliest clock-in of today's records, or null.
	 *
	 * @param string                     $employeeId The employee.
	 * @param string                     $date       Today.
	 * @param list<array<string, mixed>> $records    AttendanceRecords.
	 *
	 * @return string|null
	 */
	private static function clockInOf(string $employeeId, string $date, array $records): ?string {
		$earliest = null;
		foreach ($records as $record) {
			$clockIn = trim((string)($record['clockIn'] ?? ''));
			if ((string)($record['employeeId'] ?? '') !== $employeeId || (string)($record['date'] ?? '') !== $date || $clockIn === '') {
				continue;
			}

			if ($earliest === null || strtotime($clockIn) < strtotime($earliest)) {
				$earliest = $clockIn;
			}
		}

		return $earliest;
	}//end clockInOf()

	/**
	 * The earliest planned start of today's roster assignments, or null.
	 *
	 * @param string                     $employeeId The employee.
	 * @param list<array<string, mixed>> $roster     Today's RosterAssignments.
	 *
	 * @return string|null
	 */
	private static function plannedStartOf(string $employeeId, array $roster): ?string {
		$earliest = null;
		foreach ($roster as $assignment) {
			$start = trim((string)($assignment['plannedStart'] ?? ''));
			if ((string)($assignment['employeeId'] ?? '') === $employeeId && $start !== '' && ($earliest === null || strtotime($start) < strtotime($earliest))) {
				$earliest = $start;
			}
		}

		return $earliest;
	}//end plannedStartOf()

	/**
	 * Whether the employee is on approved leave today or has an open sick case.
	 *
	 * @param string                                    $employeeId The employee.
	 * @param string                                    $date       Today.
	 * @param array<string, list<array<string, mixed>>> $data       The rows.
	 *
	 * @return bool
	 */
	private static function isAway(string $employeeId, string $date, array $data): bool {
		foreach ($data['LeaveRequest'] as $leave) {
			if ((string)($leave['employeeId'] ?? '') === $employeeId
				&& (string)($leave['status'] ?? '') === 'approved'
				&& substr((string)($leave['startDate'] ?? ''), 0, 10) <= $date
				&& substr((string)($leave['endDate'] ?? $leave['startDate'] ?? ''), 0, 10) >= $date
			) {
				return true;
			}
		}

		foreach ($data['SickLeaveCase'] as $case) {
			if ((string)($case['employeeId'] ?? '') === $employeeId
				&& (string)($case['status'] ?? '') === 'gemeld'
				&& substr((string)($case['firstSickDay'] ?? ''), 0, 10) <= $date
			) {
				return true;
			}
		}

		return false;
	}//end isAway()

	/**
	 * The rows the view reads: the administration's, and today's roster.
	 *
	 * @param string $administrationId The authorised administration.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function load(string $administrationId): array {
		$data = [];
		foreach (self::SCHEMAS as $schema) {
			$data[$schema] = array_values($this->analytics->rowsFor($schema, $administrationId));
		}

		$data['RosterAssignment'] = $this->store->findFiltered(schema: 'RosterAssignment', filters: ['date' => $this->timeFactory->now()->format('Y-m-d')]);

		return $data;
	}//end load()
}//end class
