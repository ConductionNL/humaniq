<?php

/**
 * NL Administration Scope Consistency Check Provider
 *
 * multi-administratie (design.md D6): a recommended-severity data-quality
 * lamp keeping the denormalized `administrationId` rollout (REQ-MULTI-001)
 * honest. Riding `RuleAuditService::buildRelatedContext()` (the
 * `nl-mss-manager-consistency` precedent — one shared predicate style,
 * registered under many object types), it checks that a child object's own
 * `administrationId` equals the one derivable from its parent:
 *
 * - `Payslip` -> its `payrollRunId`'s `PayrollRun.administrationId` (the
 *   `context['payroll']['runsById']` index payroll-core-engine already
 *   builds — reused as-is, no new index needed since PayrollRun has carried
 *   `administrationId` since before this change).
 * - Every other Employee-anchored schema this change denormalizes onto
 *   (EmploymentContract, Timesheet, Expense, LeaveRequest, LeaveBalance,
 *   SickLeaveCase, Onboarding, OrgAssignment, AttendanceRecord,
 *   AssetAssignment, PerformanceReview) -> its `employeeId`'s
 *   `Employee.administrationId` (the `context['related']['Employee']['byId']`
 *   index, extended by this change to carry `administrationId`).
 *
 * **Violates** only when both sides resolve to non-empty, differing values.
 * **Vacuous** (passes) whenever either side is absent/unresolvable — an
 * un-backfilled record, a dangling parent reference, or a parent/employee
 * with no administrationId of its own — so a mid-migration register never
 * turns red; it reports only *provable* inconsistency, the
 * `nl-mss-manager-consistency` posture applied to the tenant axis. Vacancy,
 * Application, OrgUnit, Asset and ReviewCycle also carry the denormalized
 * field (REQ-MULTI-001) but have no single owning employee/parent to derive
 * an expected value from, so this provider does not register a check for
 * them — nothing to compare against, not merely "not yet implemented".
 *
 * @category Standards
 * @package  OCA\Hrmq\Standards\Checks
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Cross-object administratie-scope consistency checks.
 */
final class NlAdministratieChecks implements CheckProvider {

	/**
	 * The shared rule id every registration below evaluates.
	 *
	 * @var string
	 */
	private const RULE_ID = 'nl-administratie-scope-consistency';

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		$viaEmployee = static fn (array $o, array $c): bool => self::consistentViaEmployee($o, $c);

		return [
			'Payslip' => [
				self::RULE_ID => static fn (array $o, array $c): bool => self::consistentViaPayrollRun($o, $c),
			],
			'EmploymentContract' => [self::RULE_ID => $viaEmployee],
			'Timesheet' => [self::RULE_ID => $viaEmployee],
			'Expense' => [self::RULE_ID => $viaEmployee],
			'LeaveRequest' => [self::RULE_ID => $viaEmployee],
			'LeaveBalance' => [self::RULE_ID => $viaEmployee],
			'SickLeaveCase' => [self::RULE_ID => $viaEmployee],
			'Onboarding' => [self::RULE_ID => $viaEmployee],
			'OrgAssignment' => [self::RULE_ID => $viaEmployee],
			'AttendanceRecord' => [self::RULE_ID => $viaEmployee],
			'AssetAssignment' => [self::RULE_ID => $viaEmployee],
			'PerformanceReview' => [self::RULE_ID => $viaEmployee],
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True (satisfied/vacuous) unless the Payslip's own `administrationId`
	 * and its producing PayrollRun's `administrationId` both resolve to
	 * non-empty values that differ. Vacuous when either is absent/empty, or
	 * `payrollRunId` is empty/unresolvable (a hand-entered payslip, or the
	 * run not yet loaded).
	 *
	 * @param array<string, mixed> $o The Payslip.
	 * @param array<string, mixed> $c Evaluation context (carries `payroll.runsById`).
	 *
	 * @return bool
	 */
	private static function consistentViaPayrollRun(array $o, array $c): bool {
		$own = trim((string)($o['administrationId'] ?? ''));
		if ($own === '') {
			return true;
		}

		$runId = trim((string)($o['payrollRunId'] ?? ''));
		if ($runId === '') {
			return true;
		}

		$run = (self::runsById($c)[$runId] ?? null);
		if (is_array($run) === false) {
			return true;
		}

		$parent = trim((string)($run['administrationId'] ?? ''));
		if ($parent === '') {
			return true;
		}

		return $own === $parent;
	}//end consistentViaPayrollRun()

	/**
	 * True (satisfied/vacuous) unless the record's own `administrationId` and
	 * its `employeeId`'s Employee `administrationId` both resolve to
	 * non-empty values that differ. Vacuous when either is absent/empty, or
	 * `employeeId` is empty/unresolvable.
	 *
	 * @param array<string, mixed> $o The Employee-anchored record.
	 * @param array<string, mixed> $c Evaluation context (carries `related.Employee.byId`).
	 *
	 * @return bool
	 */
	private static function consistentViaEmployee(array $o, array $c): bool {
		$own = trim((string)($o['administrationId'] ?? ''));
		if ($own === '') {
			return true;
		}

		$employeeId = trim((string)($o['employeeId'] ?? ''));
		if ($employeeId === '') {
			return true;
		}

		$employee = (self::employeesById($c)[$employeeId] ?? null);
		if (is_array($employee) === false) {
			return true;
		}

		$parent = trim((string)($employee['administrationId'] ?? ''));
		if ($parent === '') {
			return true;
		}

		return $own === $parent;
	}//end consistentViaEmployee()

	/**
	 * The `payroll.runsById` index from the context (payroll-core-engine's
	 * full-PayrollRun-row index, reused as-is), or an empty array when the
	 * pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function runsById(array $c): array {
		$byId = ($c['payroll']['runsById'] ?? []);
		return is_array($byId) === true ? $byId : [];
	}//end runsById()

	/**
	 * The `related.Employee.byId` index from the context, or an empty array
	 * when the pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function employeesById(array $c): array {
		$byId = ($c['related']['Employee']['byId'] ?? []);
		return is_array($byId) === true ? $byId : [];
	}//end employeesById()

}//end class
