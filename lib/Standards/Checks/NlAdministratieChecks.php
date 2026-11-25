<?php

/**
 * NL Administration Scope Consistency Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): the rule is declared as an OpenRegister decision
 * table on every registered type via the ProvidesTables capability. Resolving
 * the parent's administratie stays here as the derivation (null when either
 * side is missing, so the rule stays vacuous there); the shared
 * Dmn\DecisionTableEvaluator decides. The pre-table closures stay as
 * legacyChecks(), the oracle NlAdministratieTableParityTest compares with,
 * never registered in the engine, until staged retirement (REQ-RULE-009).
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Cross-object administratie-scope consistency checks.
 */
final class NlAdministratieChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * The shared rule id every registration below evaluates.
	 *
	 * @var string
	 */
	private const RULE_ID = 'nl-administratie-scope-consistency';

	/**
	 * The Employee-anchored types the rule is registered on, besides Payslip.
	 *
	 * @var array<int, string>
	 */
	private const EMPLOYEE_ANCHORED = [
		'EmploymentContract',
		'Timesheet',
		'Expense',
		'LeaveRequest',
		'LeaveBalance',
		'SickLeaveCase',
		'Onboarding',
		'OrgAssignment',
		'AttendanceRecord',
		'AssetAssignment',
		'PerformanceReview',
	];

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the rule this provider owns is table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * `nl-administratie-scope-consistency` on Payslip (against its PayrollRun)
	 * and on every Employee-anchored type (against its Employee): a record
	 * whose administratie differs from its parent's fails. A missing own
	 * value, reference, parent or parent value derives null and passes.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-007
	 */
	public static function tables(): array {
		$table = self::satisfiedTable(
			[['name' => 'sameAdministration', 'type' => 'boolean']],
			[['id' => 'administratie-differs-from-parent', 'inputEntries' => ['false'], 'outputEntries' => [false]]]
		);
		$viaEmployee = [
			'derive' => static fn (array $o, array $c): ?array => self::scopeInputs($o, 'employeeId', self::employeesById($c)),
			'table' => $table,
		];

		$tables = [
			'Payslip' => [
				self::RULE_ID => [
					'derive' => static fn (array $o, array $c): ?array => self::scopeInputs($o, 'payrollRunId', self::runsById($c)),
					'table' => $table,
				],
			],
		];
		foreach (self::EMPLOYEE_ANCHORED as $type) {
			$tables[$type] = [self::RULE_ID => $viaEmployee];
		}

		return $tables;

	}//end tables()

	/**
	 * The scope table's input: whether the record's own administratie equals
	 * its parent's. Null when the record has no administratie or no parent
	 * reference, the parent is not in the index, or the parent has no
	 * administratie.
	 *
	 * @param array<string, mixed>                $o        The record.
	 * @param string                              $refField The field naming the parent.
	 * @param array<string, array<string, mixed>> $parents  The parent index, by id.
	 *
	 * @return array{sameAdministration: bool}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function scopeInputs(array $o, string $refField, array $parents): ?array {
		$own = trim((string)($o['administrationId'] ?? ''));
		$ref = trim((string)($o[$refField] ?? ''));
		$parent = ($parents[$ref] ?? null);
		if ($own === '' || $ref === '' || is_array($parent) === false) {
			return null;
		}

		$parentAdministration = trim((string)($parent['administrationId'] ?? ''));
		if ($parentAdministration === '') {
			return null;
		}

		return ['sameAdministration' => ($own === $parentAdministration)];

	}//end scopeInputs()

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

	/**
	 * The scope closures as registered before the tables: Payslip against
	 * its run, the rest against their Employee. Kept only so
	 * NlAdministratieTableParityTest can compare tables() with them.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-007
	 */
	public static function legacyChecks(): array {
		$viaEmployee = static fn (array $o, array $c): bool => self::consistentViaEmployee($o, $c);
		$checks = ['Payslip' => [self::RULE_ID => static fn (array $o, array $c): bool => self::consistentViaPayrollRun($o, $c)]];
		foreach (self::EMPLOYEE_ANCHORED as $type) {
			$checks[$type] = [self::RULE_ID => $viaEmployee];
		}

		return $checks;

	}//end legacyChecks()

}//end class
