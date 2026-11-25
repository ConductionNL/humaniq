<?php

/**
 * NL Single-Person-Mode Headcount Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * single-person-modes (design.md D4): a recommended-severity, auto-discovered
 * data-quality lamp over the `Administration.mode` toggle (REQ-SPM-001) --
 * the `nl-administratie-scope-consistency` posture (NlAdministratieChecks)
 * applied to headcount instead of tenant-scope drift, never a write-time
 * block:
 *
 * - `nl-single-person-mode-employee-count` (Administration): VACUOUS unless
 *   `mode` is `dga_single_person`; else counts active `Employee` rows
 *   (soft-deleted/offboarded excluded via the existing `endDate`-empty
 *   active-status convention -- the schema documents `endDate` as "null while
 *   active") whose `administrationId` equals this `Administration`'s own
 *   `administrationId`, and is satisfied only when that count is exactly 1 AND
 *   the one matching Employee has `isDga: true`. A drifted administratie
 *   (0, 2+, or 1-but-not-DGA) surfaces on the next `occ humaniq:rules:audit`
 *   run -- visible, traceable, never silently wrong -- but never blocks a
 *   save (design.md D4: this codebase has no precedent for a cross-object
 *   count validation enforced at write time on a SETTING-style record).
 *
 * The Employee corpus is read from the shared `payroll.employeesById` index
 * (`RuleAuditService::buildPayrollContext()`, the FULL Employee row keyed by
 * id) so this predicate stays a pure `fn(array $o, array $context): bool`
 * that never re-queries the register -- the NlAdministratieChecks /
 * NlAbpChecks context-index precedent.
 *
 * This provider does NOT implement SeedsObjects: it reads the existing
 * Administration/Employee corpus and is vacuous for every standard-mode
 * administratie, so no seed backfill is needed for a green baseline.
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): the single-person employee-count rule is declared as an OpenRegister decision table via
 * the ProvidesTables capability. The derivations stay here; the shared
 * Dmn\DecisionTableEvaluator decides. The closure predicate remains
 * available as legacyChecks() -- the parity oracle, NOT registered in the
 * engine -- until staged retirement (REQ-RULE-009).
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
 * @spec openspec/specs/single-person-modes/spec.md#REQ-SPM-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * DGA-single-person headcount drift check (recommended-severity lamp).
 *
 * @spec openspec/specs/single-person-modes/spec.md#REQ-SPM-005
 */
final class NlSinglePersonChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the check this provider owns is table-declared (see tables()).
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
	 * `nl-single-person-mode-employee-count` as derivation + FIRST-hit
	 * decision table: in `dga_single_person` mode the administration needs
	 * exactly one active employee, and that one is the DGA. An
	 * administration without an id derives null and passes, as before.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/single-person-modes/spec.md#REQ-SPM-005
	 */
	public static function tables(): array {
		return [
			'Administration' => [
				'nl-single-person-mode-employee-count' => [
					'derive' => static fn (array $object, array $context): ?array => self::employeeCountInputs($object, $context),
					'table' => self::satisfiedTable(
						[
							['name' => 'singlePersonMode', 'type' => 'boolean'],
							['name' => 'activeEmployees', 'type' => 'number'],
							['name' => 'soleEmployeeIsDga', 'type' => 'boolean'],
						],
						[
							['id' => 'not-exactly-one-employee', 'inputEntries' => ['true', '!= 1', '-'], 'outputEntries' => [false]],
							['id' => 'sole-employee-not-dga', 'inputEntries' => ['true', '-', 'false'], 'outputEntries' => [false]],
						]
					),
				],
			],
		];

	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/single-person-modes/spec.md#REQ-SPM-005
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * The `nl-single-person-mode-employee-count` predicate (spec.md
	 * REQ-SPM-005): VACUOUS unless the Administration's `mode` is
	 * `dga_single_person`; else satisfied only when EXACTLY one active
	 * Employee (`endDate` empty/null) is scoped to this Administration's
	 * `administrationId` AND that one Employee has `isDga: true`. Never blocks
	 * a write -- surfaces only through the audit report.
	 *
	 * @param array<string, mixed> $object The Administration object.
	 * @param array<string, mixed> $context Evaluation context (carries `payroll.employeesById`).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/single-person-modes/spec.md#REQ-SPM-005
	 */
	private static function exactlyOneDgaEmployee(array $object, array $context): bool {
		if ((string)($object['mode'] ?? 'standard') !== 'dga_single_person') {
			// Vacuous: only a dga_single_person administratie expects a
			// one-DGA headcount (standard/eenmanszaak_no_payroll are out of
			// scope for this rule).
			return true;
		}

		$administrationId = trim((string)($object['administrationId'] ?? ''));
		if ($administrationId === '') {
			// No business key to group the employee corpus by -- nothing
			// provable, so vacuous (never a false violation).
			return true;
		}

		$matched = [];
		foreach (self::employees($context) as $employee) {
			if (trim((string)($employee['administrationId'] ?? '')) !== $administrationId) {
				continue;
			}

			if (trim((string)($employee['endDate'] ?? '')) !== '') {
				// Soft-deleted/offboarded: an ended employment is not an
				// active headcount (the existing endDate-empty convention).
				continue;
			}

			$matched[] = $employee;
		}

		if (count($matched) !== 1) {
			return false;
		}

		return (($matched[0]['isDga'] ?? false) === true);
	}//end exactlyOneDgaEmployee()

	/**
	 * The FULL Employee-row corpus from the shared `payroll.employeesById`
	 * index (`RuleAuditService::buildPayrollContext()`), or an empty list when
	 * the pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $context Evaluation context.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function employees(array $context): array {
		$byId = ($context['payroll']['employeesById'] ?? []);
		return is_array($byId) === true ? array_values($byId) : [];
	}//end employees()

	/**
	 * The employee-count table's inputs: the mode, how many active employees
	 * the administration has, and whether the only one is the DGA. Null when
	 * the administration has no id.
	 *
	 * @param array<string, mixed> $object  The Administration.
	 * @param array<string, mixed> $context Evaluation context; reads `payroll.employeesById`.
	 *
	 * @return array{singlePersonMode: bool, activeEmployees: int, soleEmployeeIsDga: bool}|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function employeeCountInputs(array $object, array $context): ?array {
		$administrationId = trim((string)($object['administrationId'] ?? ''));
		if ($administrationId === '') {
			return null;
		}

		$active = array_values(
			array_filter(
				self::employees($context),
				static fn (mixed $employee): bool => is_array($employee) === true
					&& trim((string)($employee['administrationId'] ?? '')) === $administrationId
					&& trim((string)($employee['endDate'] ?? '')) === ''
			)
		);

		return [
			'singlePersonMode' => ((string)($object['mode'] ?? 'standard') === 'dga_single_person'),
			'activeEmployees' => count($active),
			'soleEmployeeIsDga' => (count($active) === 1 && ($active[0]['isDga'] ?? false) === true),
		];

	}//end employeeCountInputs()

	/**
	 * Oracle for the employee-count table: the closure the rule ran on
	 * before, compared with tables() by NlSinglePersonTableParityTest and
	 * never registered; it retires after the OpenRegister audit run.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/single-person-modes/spec.md#REQ-SPM-005
	 */
	public static function legacyChecks(): array {
		return [
			'Administration' => [
				'nl-single-person-mode-employee-count' => static fn (array $object, array $context): bool => self::exactlyOneDgaEmployee($object, $context),
			],
		];

	}//end legacyChecks()

}//end class
