<?php

/**
 * Agenda Org View
 *
 * One request's view of the organisation for the agenda access rules: the
 * viewer, their Employee records, the registered units and today's
 * placements. Built once from rows the caller loaded, so the rules ask it
 * many questions without reading the register again.
 *
 * A placement counts when it is active today, and a placement whose start or
 * end date is set but cannot be read does not count. A placement in a unit
 * that is not registered makes nobody a colleague.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Answers who is who to the viewer: self, manager, colleague, unit manager.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
 */
final class AgendaOrgView {

	/**
	 * Employee rows by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $employees;

	/**
	 * OrgUnit rows by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $units;

	/**
	 * OrgAssignment rows by employee id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $placements = [];

	/**
	 * The viewer's own Employee ids.
	 *
	 * @var array<int, string>
	 */
	private array $own;

	/**
	 * Today, `YYYY-MM-DD`.
	 *
	 * @var string
	 */
	private string $today;

	/**
	 * Active placements and an employee's managers.
	 *
	 * @var OrgResolutionService
	 */
	private OrgResolutionService $orgResolution;

	/**
	 * Constructor.
	 *
	 * @param string $viewer The session user.
	 * @param array<string, array<int, array<string, mixed>>> $rows Employee, OrgUnit and OrgAssignment rows, keyed by schema.
	 */
	public function __construct(
		private readonly string $viewer,
		array $rows,
	) {
		$this->orgResolution = new OrgResolutionService();
		$this->today = (new DateTimeImmutable('today'))->format('Y-m-d');
		$this->employees = $this->byId($rows['Employee'] ?? []);
		$this->units = $this->byId($rows['OrgUnit'] ?? []);
		foreach (($rows['OrgAssignment'] ?? []) as $assignment) {
			$this->placements[trim((string)($assignment['employeeId'] ?? ''))][] = $assignment;
		}

		$this->own = $this->employeeIdsOf([$viewer]);

	}//end __construct()

	/**
	 * Today, `YYYY-MM-DD`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function today(): string {
		return $this->today;
	}//end today()

	/**
	 * Every employee id.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function employeeIds(): array {
		return array_map('strval', array_keys($this->employees));
	}//end employeeIds()

	/**
	 * Whether an Employee record exists.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function hasEmployee(string $employeeId): bool {
		return isset($this->employees[$employeeId]);
	}//end hasEmployee()

	/**
	 * Whether the viewer is the employee or one of the employee's managers.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function isSelfOrManagerOf(string $employeeId): bool {
		if (in_array($employeeId, $this->own, true) === true) {
			return true;
		}

		$managers = $this->orgResolution->resolveManagerUserIds(
			employeeId: $employeeId,
			assignmentsByEmployeeId: $this->placements,
			unitsById: $this->units,
			employeesById: $this->employees,
			onDate: $this->today
		);

		return in_array($this->viewer, $managers, true);
	}//end isSelfOrManagerOf()

	/**
	 * Whether the viewer shares a registered unit with the employee today.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function isColleagueOf(string $employeeId): bool {
		return array_intersect($this->unitsOf([$employeeId]), $this->unitsOf($this->own)) !== [];
	}//end isColleagueOf()

	/**
	 * Whether the viewer is placed in the registered unit today.
	 *
	 * @param string $orgUnitId The unit.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function isMemberOf(string $orgUnitId): bool {
		return in_array($orgUnitId, $this->unitsOf($this->own), true);
	}//end isMemberOf()

	/**
	 * Whether one of these accounts manages the registered unit or a unit
	 * above it. The climb stays in the unit's administration, and a manager
	 * whose own Employee record lies outside it does not count, as in
	 * {@see OrgResolutionService::resolveManagerUserIds()}. A parent chain
	 * that loops is walked once.
	 *
	 * @param string $orgUnitId The unit.
	 * @param array<int, string> $accounts Nextcloud user ids.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function isManagedBy(string $orgUnitId, array $accounts): bool {
		$boundary = $this->orgResolution->administrationOf($this->units[$orgUnitId] ?? null);
		$managers = [];
		foreach ($this->employeeIdsOf($accounts) as $employeeId) {
			if ($this->orgResolution->leavesAdministration($this->employees[$employeeId], $boundary) === false) {
				$managers[] = $employeeId;
			}
		}

		$visited = [];
		while (isset($this->units[$orgUnitId]) === true && isset($visited[$orgUnitId]) === false) {
			$unit = $this->units[$orgUnitId];
			if ($this->orgResolution->leavesAdministration($unit, $boundary) === true) {
				return false;
			}

			$visited[$orgUnitId] = true;
			if (in_array(trim((string)($unit['managerId'] ?? '')), $managers, true) === true) {
				return true;
			}

			$orgUnitId = trim((string)($unit['parentUnitId'] ?? ''));
		}

		return false;
	}//end isManagedBy()

	/**
	 * The employees placed in the registered unit today.
	 *
	 * @param string $orgUnitId The unit.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function placedInToday(string $orgUnitId): array {
		$placed = [];
		foreach (array_keys($this->placements) as $employeeId) {
			if (in_array($orgUnitId, $this->unitsOf([(string)$employeeId]), true) === true) {
				$placed[] = (string)$employeeId;
			}
		}

		return $placed;
	}//end placedInToday()

	/**
	 * The registered units the employees are placed in today.
	 *
	 * @param array<int, string> $employeeIds The employees.
	 *
	 * @return array<int, string>
	 */
	private function unitsOf(array $employeeIds): array {
		$units = [];
		foreach ($employeeIds as $employeeId) {
			foreach (($this->placements[$employeeId] ?? []) as $assignment) {
				$unitId = trim((string)($assignment['orgUnitId'] ?? ''));
				if (isset($this->units[$unitId]) === true && $this->placedToday($assignment) === true) {
					$units[$unitId] = $unitId;
				}
			}
		}

		return array_values($units);
	}//end unitsOf()

	/**
	 * Whether a placement is active today; a start or end date that is set
	 * but cannot be read makes it inactive.
	 *
	 * @param array<string, mixed> $assignment The OrgAssignment.
	 *
	 * @return bool
	 */
	private function placedToday(array $assignment): bool {
		foreach (['startDate', 'endDate'] as $key) {
			$date = trim((string)($assignment[$key] ?? ''));
			if ($date !== '' && strtotime($date) === false) {
				return false;
			}
		}

		return $this->orgResolution->isActiveOn($assignment, $this->today);
	}//end placedToday()

	/**
	 * The Employee ids whose Nextcloud account is one of these.
	 *
	 * @param array<int, string> $accounts Nextcloud user ids.
	 *
	 * @return array<int, string>
	 */
	private function employeeIdsOf(array $accounts): array {
		$ids = [];
		foreach ($this->employees as $id => $employee) {
			$account = trim((string)($employee['nextcloudUserId'] ?? ''));
			if ($account !== '' && in_array($account, $accounts, true) === true) {
				$ids[] = (string)$id;
			}
		}

		return $ids;
	}//end employeeIdsOf()

	/**
	 * Key rows by their id, dropping rows without one.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function byId(array $rows): array {
		$index = [];
		foreach ($rows as $row) {
			$index[trim((string)($row['id'] ?? ''))] = $row;
		}

		unset($index['']);

		return $index;
	}//end byId()

}//end class
