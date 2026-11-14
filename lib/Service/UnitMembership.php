<?php

/**
 * Unit Membership
 *
 * department-figures: who belongs to an org unit in a period. A unit's
 * figures cover the unit and every unit under it, and a person counts for
 * the share of the period they were placed there, so an employee who moved
 * halfway through a month counts half in each unit (design.md D1). Pure:
 * the caller loads the records.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Resolves unit trees, placements per period and the units a manager leads.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
class UnitMembership {

	/**
	 * The unit and every unit under it, the unit first. A parent chain that
	 * loops back is walked once.
	 *
	 * @param string                     $unitId The unit.
	 * @param list<array<string, mixed>> $units  OrgUnit records.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function subtree(string $unitId, array $units): array {
		$children = [];
		foreach ($units as $unit) {
			$parent = trim((string)($unit['parentUnitId'] ?? ''));
			if ($parent !== '') {
				$children[$parent][] = $this->rowId($unit);
			}
		}

		$found = [];
		$queue = [$unitId];
		while ($queue !== []) {
			$current = array_shift($queue);
			if ($current === '' || in_array($current, $found, true) === true) {
				continue;
			}

			$found[] = $current;
			$queue = array_merge($queue, ($children[$current] ?? []));
		}

		return $found;
	}//end subtree()

	/**
	 * Per employee, the share of the window's days they were placed in one
	 * of the units. Overlapping placements count a day once.
	 *
	 * @param list<string>               $unitIds     The units (a subtree).
	 * @param list<array<string, mixed>> $assignments OrgAssignment records.
	 * @param DateTimeImmutable          $start       First day.
	 * @param DateTimeImmutable          $end         Last day.
	 *
	 * @return array<string, float> Employee id to share (0, 1].
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function sharesInWindow(array $unitIds, array $assignments, DateTimeImmutable $start, DateTimeImmutable $end): array {
		$ranges = [];
		foreach ($assignments as $assignment) {
			if (in_array(trim((string)($assignment['orgUnitId'] ?? '')), $unitIds, true) === false) {
				continue;
			}

			$ranges[trim((string)($assignment['employeeId'] ?? ''))][] = $assignment;
		}

		return $this->shares(rangesByEmployee: $ranges, window: $this->window(start: $start, end: $end));
	}//end sharesInWindow()

	/**
	 * Per employee, the share of the window's days a contract of theirs ran:
	 * the population of the whole administration.
	 *
	 * @param list<array<string, mixed>> $contracts EmploymentContract records.
	 * @param DateTimeImmutable          $start     First day.
	 * @param DateTimeImmutable          $end       Last day.
	 *
	 * @return array<string, float> Employee id to share (0, 1].
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function contractShares(array $contracts, DateTimeImmutable $start, DateTimeImmutable $end): array {
		$ranges = [];
		foreach ($contracts as $contract) {
			$ranges[trim((string)($contract['employeeId'] ?? ''))][] = $contract;
		}

		return $this->shares(rangesByEmployee: $ranges, window: $this->window(start: $start, end: $end));
	}//end contractShares()

	/**
	 * The units whose manager is the employee record of this Nextcloud user.
	 *
	 * @param string                     $userId    The Nextcloud user id.
	 * @param list<array<string, mixed>> $units     OrgUnit records.
	 * @param list<array<string, mixed>> $employees Employee records.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function managedUnitIds(string $userId, array $units, array $employees): array {
		if (trim($userId) === '') {
			return [];
		}

		$employeeIds = [];
		foreach ($employees as $employee) {
			if (trim((string)($employee['nextcloudUserId'] ?? '')) === $userId) {
				$employeeIds[] = $this->rowId($employee);
			}
		}

		$managed = [];
		foreach ($units as $unit) {
			$managerId = trim((string)($unit['managerId'] ?? ''));
			if ($managerId !== '' && in_array($managerId, $employeeIds, true) === true) {
				$managed[] = $this->rowId($unit);
			}
		}

		return $managed;
	}//end managedUnitIds()

	/**
	 * The id of a register row, whichever of the usual places it sits in.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function rowId(array $row): string {
		$self = (is_array($row['@self'] ?? null) === true) ? $row['@self'] : [];

		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end rowId()

	/**
	 * Keep the rows of the people in the set: an Employee by its id, any
	 * other row by its employeeId. Rows without an employee (a payroll run)
	 * are kept as they are.
	 *
	 * @param array<string, list<array<string, mixed>>> $rowsBySchema Rows per schema.
	 * @param array<string, float>                      $shares       The set.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function restrict(array $rowsBySchema, array $shares): array {
		$out = [];
		foreach ($rowsBySchema as $schema => $rows) {
			$out[$schema] = [];
			foreach ($rows as $row) {
				$employeeId = ($schema === 'Employee') ? $this->rowId($row) : ($row['employeeId'] ?? null);
				if ($employeeId === null || isset($shares[trim((string)$employeeId)]) === true) {
					$out[$schema][] = $row;
				}
			}
		}

		return $out;
	}//end restrict()

	/**
	 * The whole days from the first to the last day.
	 *
	 * @param DateTimeImmutable $start First day.
	 * @param DateTimeImmutable $end   Last day.
	 *
	 * @return ShareWindow
	 */
	private function window(DateTimeImmutable $start, DateTimeImmutable $end): ShareWindow {
		$window = new ShareWindow();
		$window->start = $start->setTime(0, 0);
		$window->end = $end->setTime(0, 0);

		return $window;
	}//end window()

	/**
	 * Shares from date ranges per employee.
	 *
	 * @param array<string, list<array<string, mixed>>> $rangesByEmployee Records with startDate/endDate per employee.
	 * @param ShareWindow                               $window           The whole days measured over.
	 *
	 * @return array<string, float>
	 */
	private function shares(array $rangesByEmployee, ShareWindow $window): array {
		$total = ((int)$window->start->diff($window->end)->days + 1);

		$shares = [];
		foreach ($rangesByEmployee as $employeeId => $ranges) {
			if ($employeeId === '') {
				continue;
			}

			$days = $this->coveredDays(ranges: $ranges, window: $window);
			if ($days > 0) {
				$shares[(string)$employeeId] = round(($days / $total), 4);
			}
		}

		return $shares;
	}//end shares()

	/**
	 * The number of days in the window covered by at least one range.
	 *
	 * @param list<array<string, mixed>> $ranges Records with startDate/endDate.
	 * @param ShareWindow                $window The whole days measured over.
	 *
	 * @return int
	 */
	private function coveredDays(array $ranges, ShareWindow $window): int {
		$covered = [];
		foreach ($ranges as $range) {
			$from = max($window->start, ($this->date($range['startDate'] ?? null) ?? $window->start));
			$until = min($window->end, ($this->date($range['endDate'] ?? null) ?? $window->end));
			for ($day = $from; $day <= $until; $day = $day->modify('+1 day')) {
				$covered[$day->format('Y-m-d')] = true;
			}
		}

		return count($covered);
	}//end coveredDays()

	/**
	 * Parse a stored date.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTime(0, 0);
		} catch (\Exception) {
			return null;
		}
	}//end date()

}//end class
