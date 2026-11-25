<?php

/**
 * NL DGA Gebruikelijkloon Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable check for the dga-payroll-mode corpus rule
 * (lib/Standards/rules/payroll.json) -- unenforced until this provider
 * registered its predicate (design.md D5):
 *
 * - `nl-gebruikelijkloon-norm` (Employee): vacuous for a non-DGA employee;
 *   vacuous when a non-empty `gebruikelijkloonJustification` is on file (MVP:
 *   presence-only exemption, content validation is a named follow-up);
 *   vacuous when `grossMonthlySalary` is absent/non-numeric; else satisfied
 *   only when the annualised salary (`grossMonthlySalary x 12`) is at least
 *   the loaded tables' `gebruikelijkloon().jaarnormCents` (Wet LB 1964
 *   art. 12a, EUR 58.000/jaar in 2026).
 *
 * This provider does NOT implement SeedsObjects: dga-payroll-mode ships no
 * seed DGA Employee (design.md D5) -- `seedSpec()` returns [] since the
 * existing seeded Employee (`isDga` absent -> `false` default) stays
 * vacuously compliant with no seed backfill needed.
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): the gebruikelijkloon rule is declared as an OpenRegister decision table via
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
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Payroll\TaxTables;

/**
 * The gebruikelijkloon-norm self-check: a DGA's annualised salary vs the
 * sourced 2026 floor.
 *
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
 */
final class NlDgaChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the check this provider owns is table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * `nl-gebruikelijkloon-norm` as derivation + FIRST-hit decision table: an
	 * unjustified DGA with a salary whose annualised amount is below the
	 * loaded norm fails. For such a DGA, no salary or no norm table derives
	 * null and passes.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
	 */
	public static function tables(): array {
		return [
			'Employee' => [
				'nl-gebruikelijkloon-norm' => [
					'derive' => static fn (array $o): ?array => self::normInputs($o),
					'table' => self::satisfiedTable(
						[['name' => 'dga', 'type' => 'boolean'], ['name' => 'justified', 'type' => 'boolean'], ['name' => 'shortfallCents', 'type' => 'number']],
						[['id' => 'dga-below-norm', 'inputEntries' => ['true', 'false', '> 0'], 'outputEntries' => [false]]]
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
	 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * The `nl-gebruikelijkloon-norm` predicate (spec.md REQ-DGA-004): vacuous
	 * for a non-DGA employee, vacuous when justified, vacuous when there is
	 * no salary to evaluate yet -- else the annualised
	 * `grossMonthlySalary x 12` must reach the loaded tables'
	 * gebruikelijkloon norm.
	 *
	 * single-person-modes (REQ-SPM-006/D5): exposed `public` so the
	 * self-service `PayrollController::dgaStatus()` endpoint can REUSE this
	 * exact predicate for its `met` verdict -- the ONE new caller. The norm
	 * comparison is never reimplemented; only its invocation surface is new.
	 * The predicate itself is unchanged.
	 *
	 * @param array<string, mixed> $o The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
	 * @spec openspec/specs/single-person-modes/spec.md#REQ-SPM-006
	 */
	public static function meetsGebruikelijkloonNorm(array $o): bool {
		if (($o['isDga'] ?? false) !== true) {
			// Vacuous: not a DGA, the norm does not apply.
			return true;
		}

		if (trim((string)($o['gebruikelijkloonJustification'] ?? '')) !== '') {
			// MVP: presence-only exemption (Non-Goal: content validation).
			return true;
		}

		$grossMonthly = ($o['grossMonthlySalary'] ?? null);
		if (is_numeric($grossMonthly) === false) {
			// No salary to evaluate yet -- the hourly/no-salary path is out
			// of scope; never a false violation.
			return true;
		}

		$annualCents = (int)round(((float)$grossMonthly) * 12 * 100);

		$ids = TaxTables::availableIds();
		if ($ids === []) {
			// Defensive: no table loaded, never hit outside a broken install.
			return true;
		}

		$norm = TaxTables::load(max($ids))->gebruikelijkloon()['jaarnormCents'];

		return $annualCents >= $norm;
	}//end meetsGebruikelijkloonNorm()

	/**
	 * The norm table's inputs: whether this is a DGA, whether a justification
	 * is recorded, and how far the annualised salary falls short of the norm
	 * (cents, zero or less when it meets it). Null when there is no numeric
	 * salary or no norm table is loaded.
	 *
	 * @param array<string, mixed> $o The Employee.
	 *
	 * @return array{dga: bool, justified: bool, shortfallCents: int}|null
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function normInputs(array $o): ?array {
		$inputs = [
			'dga' => (($o['isDga'] ?? false) === true),
			'justified' => (trim((string)($o['gebruikelijkloonJustification'] ?? '')) !== ''),
			'shortfallCents' => 0,
		];
		if ($inputs['dga'] === false || $inputs['justified'] === true) {
			// The norm is not in play: no table is loaded for it.
			return $inputs;
		}

		$grossMonthly = ($o['grossMonthlySalary'] ?? null);
		$ids = TaxTables::availableIds();
		if (is_numeric($grossMonthly) === false || $ids === []) {
			return null;
		}

		$norm = TaxTables::load(max($ids))->gebruikelijkloon()['jaarnormCents'];
		$inputs['shortfallCents'] = ($norm - (int)round(((float)$grossMonthly) * 12 * 100));

		return $inputs;

	}//end normInputs()

	/**
	 * The gebruikelijkloon closure from before the table: the oracle
	 * NlDgaTableParityTest compares tables() with. It is never registered;
	 * meetsGebruikelijkloonNorm() itself stays, PayrollController::dgaStatus()
	 * reads it.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
	 */
	public static function legacyChecks(): array {
		return [
			'Employee' => [
				'nl-gebruikelijkloon-norm' => static fn (array $o): bool => self::meetsGebruikelijkloonNorm($o),
			],
		];

	}//end legacyChecks()

}//end class
