<?php

/**
 * Hrmq OrgResolutionService
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
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use DateTimeImmutable;

/**
 * Shared, pure resolution of the org chain (manager / cost centre).
 *
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */
class OrgResolutionService {

	/**
	 * Whether an OrgAssignment is active on the given date.
	 *
	 * Active means: `startDate` absent or on/before the date, AND `endDate`
	 * absent or on/after the date. An unparseable date is treated as absent
	 * (fail-open on malformed data, matching the audit's historical
	 * endDate-only leniency — the index the audit feeds carries no
	 * `startDate`, which this method reads as "started").
	 *
	 * @param array<string, mixed> $assignment The OrgAssignment (index row or full object).
	 * @param string $onDate The reference date, `YYYY-MM-DD`.
	 *
	 * @return bool True when the placement is active on that date.
	 *
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function isActiveOn(array $assignment, string $onDate): bool {
		$reference = strtotime($onDate);
		if ($reference === false) {
			$reference = (new DateTimeImmutable('today'))->getTimestamp();
		}

		$start = strtotime(trim((string)($assignment['startDate'] ?? '')));
		if ($start !== false && $start > $reference) {
			return false;
		}

		$end = strtotime(trim((string)($assignment['endDate'] ?? '')));
		if ($end !== false && $end < $reference) {
			return false;
		}

		return true;
	}//end isActiveOn()

	/**
	 * Resolve the Nextcloud user ids of every manager reachable through the
	 * employee's active placements — the full list, distinct, in placement
	 * order. The nl-mss-manager-consistency audit consumes this list with its
	 * any-match posture; a stamp reduces it via {@see uniqueOrNull()}.
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
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function resolveManagerUserIds(
		string $employeeId,
		array $assignmentsByEmployeeId,
		array $unitsById,
		array $employeesById,
		string $onDate,
	): array {
		$resolved = [];
		foreach ($this->activeUnits($employeeId, $assignmentsByEmployeeId, $unitsById, $onDate) as $unit) {
			$unitManagerId = trim((string)($unit['managerId'] ?? ''));
			if ($unitManagerId === '') {
				continue;
			}

			$manager = ($employeesById[$unitManagerId] ?? null);
			if (is_array($manager) === false) {
				continue;
			}

			$managerUserId = trim((string)($manager['nextcloudUserId'] ?? ''));
			if ($managerUserId !== '' && in_array($managerUserId, $resolved, true) === false) {
				$resolved[] = $managerUserId;
			}
		}

		return $resolved;
	}//end resolveManagerUserIds()

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
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/employer-hourly-cost-rate/spec.md#Requirement:-Cost-allocation-references-live-on-the-time-entry-and-are-never-employee-typed
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
	 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	public function uniqueOrNull(array $values): ?string {
		if (count($values) !== 1) {
			return null;
		}

		return (string)reset($values);
	}//end uniqueOrNull()

	/**
	 * The OrgUnit rows of the employee's active placements, in placement
	 * order. Dead-end hops (empty/dangling `orgUnitId`) are skipped.
	 *
	 * @param string $employeeId The employee.
	 * @param array<string, array<int, array<string, mixed>>> $assignmentsByEmployeeId OrgAssignment index keyed by employeeId.
	 * @param array<string, array<string, mixed>> $unitsById OrgUnit index keyed by id.
	 * @param string $onDate The reference date, `YYYY-MM-DD`.
	 *
	 * @return array<int, array<string, mixed>> The active placements' units.
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
				$units[] = $unit;
			}
		}

		return $units;
	}//end activeUnits()

}//end class
