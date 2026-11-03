<?php

/**
 * Humaniq OrgResolutionService
 *
 * The ONE implementation of the employee → active OrgAssignment → OrgUnit
 * chain: manager resolution (unit `managerId` → manager Employee's
 * `nextcloudUserId`) and cost-centre resolution (unit `costCenter`). Extracted
 * from the logic `lib/Standards/Checks/NlOrgChecks.php` audits
 * (nl-mss-manager-consistency) so the server-side stamp
 * (TimesheetProcessStampListener / TimeEntryStampListener) and the audit share
 * a single code path and cannot disagree (hours-process-redesign Decision 5).
 *
 * Pure and stateless: every method operates on pre-built index arrays (the
 * exact shapes `RuleAuditService::buildRelatedContext()` produces —
 * `OrgAssignment.byEmployeeId` lists, `OrgUnit.byId`, `Employee.byId`), never
 * on live register queries, so the audit's context arrays and a listener's
 * freshly-queried indexes drive identical logic and the unit tests need no
 * container.
 *
 * Resolution posture ("never guessed"): the audit consumes the FULL resolved
 * manager list (any-match, its historical semantics); a stamp consumes
 * {@see uniqueOrNull()} — a value is stamped only when the chain resolves to
 * exactly one distinct answer, otherwise null (fail-closed: the record simply
 * appears in no team queue / carries no cost centre).
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
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Shared, pure resolution of the org chain (manager / cost centre).
 *
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */
class OrgResolutionService {

	/**
	 * Whether an OrgAssignment is active on the given date.
	 *
	 * Active means: `startDate` absent or on/before the date, AND `endDate`
	 * absent or on/after the date. A missing or empty date is no bound (the
	 * index the audit feeds carries no `startDate`, read as "started"). A
	 * date that is set but cannot be read makes the placement inactive, so
	 * no manager, cost centre or approval is resolved through it.
	 *
	 * @param array<string, mixed> $assignment The OrgAssignment (index row or full object).
	 * @param string $onDate The reference date, `YYYY-MM-DD`.
	 *
	 * @return bool True when the placement is active on that date.
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 * @spec openspec/specs/mss-team-scope/spec.md#REQ-MSS-008
	 */
	public function isActiveOn(array $assignment, string $onDate): bool {
		$reference = strtotime($onDate);
		if ($reference === false) {
			$reference = (new DateTimeImmutable('today'))->getTimestamp();
		}

		$start = $this->boundOf($assignment['startDate'] ?? null);
		$end = $this->boundOf($assignment['endDate'] ?? null);
		if ($start === false || $end === false) {
			return false;
		}

		return ($start ?? PHP_INT_MIN) <= $reference && ($end ?? PHP_INT_MAX) >= $reference;
	}//end isActiveOn()

	/**
	 * A placement date as a timestamp: null when it is not set, false when it
	 * is set but cannot be read.
	 *
	 * @param mixed $value The date.
	 *
	 * @return int|false|null
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#REQ-MSS-008
	 */
	private function boundOf(mixed $value): int|false|null {
		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return null;
		}

		return strtotime($text);
	}//end boundOf()

	/**
	 * Resolve the Nextcloud user ids of every manager reachable through the
	 * employee's active placements — the full list, distinct, in placement
	 * order. The nl-mss-manager-consistency audit consumes this list with its
	 * any-match posture; a stamp reduces it via {@see uniqueOrNull()}.
	 *
	 * A placement in a unit the employee manages themself resolves the
	 * manager of the nearest unit above it managed by someone else
	 * ({@see managerUserIdFrom()}), so nobody is their own manager. The
	 * approver belongs to the boundary administration: the placement
	 * unit's, or the employee's own when the unit has none. A placement in
	 * a unit of another administration than the employee's resolves
	 * nothing, and so does a manager whose own Employee record lies outside
	 * the boundary.
	 *
	 * A hop that dead-ends (no unit, unmanaged unit, unresolvable manager
	 * Employee, manager without a Nextcloud account) contributes nothing —
	 * an empty result means "the chain does not resolve", never an error.
	 *
	 * @param string $employeeId The employee whose managers to resolve.
	 * @param array<string, array<int, array<string, mixed>>> $assignmentsByEmployeeId OrgAssignment index keyed by employeeId.
	 * @param array<string, array<string, mixed>> $unitsById OrgUnit index keyed by id.
	 * @param array<string, array<string, mixed>> $employeesById Employee index keyed by id.
	 * @param string $onDate The reference date, `YYYY-MM-DD`.
	 *
	 * @return array<int, string> Distinct manager Nextcloud user ids (possibly empty).
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function resolveManagerUserIds(
		string $employeeId,
		array $assignmentsByEmployeeId,
		array $unitsById,
		array $employeesById,
		string $onDate,
	): array {
		$employeeId = trim($employeeId);
		$chain = [
			'employeeId'    => $employeeId,
			'ownUserId'     => trim((string)($employeesById[$employeeId]['nextcloudUserId'] ?? '')),
			'ownAdministrationId' => trim((string)($employeesById[$employeeId]['administrationId'] ?? '')),
			'unitsById'     => $unitsById,
			'employeesById' => $employeesById,
		];

		$resolved = [];
		foreach (array_keys($this->activeUnits($employeeId, $assignmentsByEmployeeId, $unitsById, $onDate)) as $unitId) {
			$managerUserId = $this->managerUserIdFrom(unitId: (string)$unitId, chain: $chain);
			if ($managerUserId !== null && in_array($managerUserId, $resolved, true) === false) {
				$resolved[] = $managerUserId;
			}
		}

		return $resolved;
	}//end resolveManagerUserIds()

	/**
	 * The manager's Nextcloud user id for one placement unit, climbing to
	 * the parent unit while the unit is managed by the employee themself.
	 *
	 * Null when the walk dead-ends: an unmanaged unit, an unresolvable
	 * manager Employee, a manager without an account, a root or dangling
	 * parent, a unit already visited (a loop in the tree), a placement
	 * unit in another administration than the employee's, a unit above the
	 * placement unit outside the boundary administration, or a manager
	 * whose own Employee record lies outside it.
	 *
	 * @param string $unitId The placement's unit.
	 * @param array{employeeId: string, ownUserId: string, ownAdministrationId: string, unitsById: array<string, array<string, mixed>>, employeesById: array<string, array<string, mixed>>} $chain The employee and the indexes.
	 *
	 * @return string|null The manager's Nextcloud user id, or null.
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	private function managerUserIdFrom(string $unitId, array $chain): ?string {
		$administrationId = $this->boundaryOf(unit: ($chain['unitsById'][$unitId] ?? null), chain: $chain);
		if ($administrationId === null) {
			return null;
		}

		$visited = [];
		while ($unitId !== '' && isset($visited[$unitId]) === false) {
			$unit = ($chain['unitsById'][$unitId] ?? null);
			if (is_array($unit) === false || ($visited !== [] && $this->leavesAdministration(row: $unit, administrationId: $administrationId) === true)) {
				return null;
			}

			$visited[$unitId] = true;

			$managerId = trim((string)($unit['managerId'] ?? ''));
			if ($this->isSelf(managerId: $managerId, chain: $chain) === false) {
				$manager = ($chain['employeesById'][$managerId] ?? null);

				return $this->leavesAdministration(row: $manager, administrationId: $administrationId) === true ? null : $this->accountOf(employeeId: $managerId, chain: $chain);
			}

			$unitId = trim((string)($unit['parentUnitId'] ?? ''));
		}

		return null;
	}//end managerUserIdFrom()

	/**
	 * The boundary administration of a placement: the placement unit's, or
	 * the employee's own when the unit has none ('' when neither has one).
	 * Null when the unit and the employee name different administrations,
	 * so that placement resolves no manager.
	 *
	 * @param mixed $unit The placement unit row.
	 * @param array{employeeId: string, ownUserId: string, ownAdministrationId: string, unitsById: array<string, array<string, mixed>>, employeesById: array<string, array<string, mixed>>} $chain The employee and the indexes.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/approver-in-employee-administration/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	private function boundaryOf(mixed $unit, array $chain): ?string {
		$unitAdministration = $this->administrationOf($unit);
		if ($unitAdministration === '') {
			return $chain['ownAdministrationId'];
		}

		if ($chain['ownAdministrationId'] !== '' && $chain['ownAdministrationId'] !== $unitAdministration) {
			return null;
		}

		return $unitAdministration;
	}//end boundaryOf()

	/**
	 * Whether a unit or an Employee lies outside the boundary
	 * administration. An empty boundary admits every row; otherwise a row
	 * in another administration, without one, or not resolving is outside
	 * it.
	 *
	 * @param mixed $row The unit the walk reached, or the manager Employee.
	 * @param string $administrationId The boundary administration, '' for none.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/approver-in-employee-administration/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	private function leavesAdministration(mixed $row, string $administrationId): bool {
		return $administrationId !== '' && $this->administrationOf($row) !== $administrationId;
	}//end leavesAdministration()

	/**
	 * A row's administration, '' when the row does not resolve or has none.
	 *
	 * @param mixed $row The unit or Employee row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/manager-climb-stays-in-administration/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	private function administrationOf(mixed $row): string {
		if (is_array($row) === false) {
			return '';
		}

		return trim((string)($row['administrationId'] ?? ''));
	}//end administrationOf()

	/**
	 * Whether a unit manager is the employee themself: the same Employee
	 * record, or another one with the same Nextcloud account.
	 *
	 * @param string $managerId The unit's manager Employee id.
	 * @param array{employeeId: string, ownUserId: string, ownAdministrationId: string, unitsById: array<string, array<string, mixed>>, employeesById: array<string, array<string, mixed>>} $chain The employee and the indexes.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	private function isSelf(string $managerId, array $chain): bool {
		if ($managerId === $chain['employeeId']) {
			return true;
		}

		$account = $this->accountOf(employeeId: $managerId, chain: $chain);

		return $account !== null && $account === $chain['ownUserId'];
	}//end isSelf()

	/**
	 * The Nextcloud account of an Employee in the index, or null when the
	 * Employee does not resolve or has no account.
	 *
	 * @param string $employeeId The Employee id.
	 * @param array{employeeId: string, ownUserId: string, ownAdministrationId: string, unitsById: array<string, array<string, mixed>>, employeesById: array<string, array<string, mixed>>} $chain The employee and the indexes.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	private function accountOf(string $employeeId, array $chain): ?string {
		$employee = ($chain['employeesById'][$employeeId] ?? null);
		if (is_array($employee) === false) {
			return null;
		}

		$account = trim((string)($employee['nextcloudUserId'] ?? ''));

		return $account === '' ? null : $account;
	}//end accountOf()

	/**
	 * Resolve the cost centres reachable through the employee's active
	 * placements — the distinct list. A stamp reduces it via
	 * {@see uniqueOrNull()}: exactly one distinct value or nothing, never a
	 * guess between two teams' cost centres.
	 *
	 * @param string $employeeId The employee whose cost centre to resolve.
	 * @param array<string, array<int, array<string, mixed>>> $assignmentsByEmployeeId OrgAssignment index keyed by employeeId.
	 * @param array<string, array<string, mixed>> $unitsById OrgUnit index keyed by id.
	 * @param string $onDate The reference date, `YYYY-MM-DD`.
	 *
	 * @return array<int, string> Distinct non-empty cost centres (possibly empty).
	 *
	 * @spec openspec/specs/employer-hourly-cost-rate/spec.md#Requirement:-Cost-allocation-references-live-on-the-time-entry-and-are-never-employee-typed
	 */
	public function resolveCostCenters(
		string $employeeId,
		array $assignmentsByEmployeeId,
		array $unitsById,
		string $onDate,
	): array {
		$resolved = [];
		foreach ($this->activeUnits($employeeId, $assignmentsByEmployeeId, $unitsById, $onDate) as $unit) {
			$costCenter = trim((string)($unit['costCenter'] ?? ''));
			if ($costCenter !== '' && in_array($costCenter, $resolved, true) === false) {
				$resolved[] = $costCenter;
			}
		}

		return $resolved;
	}//end resolveCostCenters()

	/**
	 * The stamp reduction: the single distinct resolved value, or null.
	 *
	 * Null when the chain resolved nothing (fail-closed) AND when it resolved
	 * more than one distinct answer (never guessed) — both are "do not
	 * stamp", and both keep the nl-mss-manager-consistency audit green (a
	 * null stamp is its vacuous pass; a unique stamp is by construction in
	 * the audit's any-match list).
	 *
	 * @param array<int, string> $values Distinct resolved values.
	 *
	 * @return string|null The unique value, or null.
	 *
	 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function uniqueOrNull(array $values): ?string {
		if (count($values) !== 1) {
			return null;
		}

		return (string)reset($values);
	}//end uniqueOrNull()

	/**
	 * The OrgUnit rows of the employee's active placements, keyed by unit
	 * id, in placement order. Dead-end hops (empty/dangling `orgUnitId`)
	 * are skipped.
	 *
	 * @param string $employeeId The employee.
	 * @param array<string, array<int, array<string, mixed>>> $assignmentsByEmployeeId OrgAssignment index keyed by employeeId.
	 * @param array<string, array<string, mixed>> $unitsById OrgUnit index keyed by id.
	 * @param string $onDate The reference date, `YYYY-MM-DD`.
	 *
	 * @return array<string, array<string, mixed>> The active placements' units by id.
	 */
	private function activeUnits(
		string $employeeId,
		array $assignmentsByEmployeeId,
		array $unitsById,
		string $onDate,
	): array {
		$employeeId = trim($employeeId);
		if ($employeeId === '') {
			return [];
		}

		$assignments = ($assignmentsByEmployeeId[$employeeId] ?? []);
		if (is_array($assignments) === false) {
			return [];
		}

		$units = [];
		foreach ($assignments as $assignment) {
			if (is_array($assignment) === false || $this->isActiveOn($assignment, $onDate) === false) {
				continue;
			}

			$orgUnitId = trim((string)($assignment['orgUnitId'] ?? ''));
			if ($orgUnitId === '') {
				continue;
			}

			$unit = ($unitsById[$orgUnitId] ?? null);
			if (is_array($unit) === true) {
				$units[$orgUnitId] = $unit;
			}
		}

		return $units;
	}//end activeUnits()

}//end class
