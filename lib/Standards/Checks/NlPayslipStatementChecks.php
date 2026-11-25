<?php

/**
 * NL Payslip Statement Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Four statement rules over Payslip, moved out of NlPayrollChecks: the
 * anoniementarief consistent with its flag, the WKR vrije ruimte covered or
 * its excess recorded, an 80% eindheffing on any WKR excess, and the
 * loonstrook showing what BW 7:626 lid 1 requires.
 *
 * Declared as OpenRegister decision tables via the ProvidesTables
 * capability (rules-onto-or-decision-tables, next conversion wave): the
 * field reading stays here as the derivations; the shared
 * Dmn\DecisionTableEvaluator decides. The pre-table closures, moved here
 * from NlPayrollChecks, stay as legacyChecks(), the oracle
 * NlPayrollTableParityTest compares with, never registered in the engine,
 * until staged retirement (REQ-RULE-009). The Payslip seed sample stays in
 * NlPayrollChecks::seedObjects().
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * The payslip statement rules, as decision tables.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
final class NlPayslipStatementChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;
	use ReadsRecordedFields;

	/**
	 * {@inheritDoc}
	 *
	 * A numeric applied rate of exactly 52% with the anoniementarief flag, or
	 * below 52% without it; a numeric remaining vrije ruimte that is not
	 * negative unless an excess is recorded; any WKR excess at a numeric 80%
	 * eindheffing rate; a loonstrook showing gross wage, deduction basis,
	 * minimum wage and the employer and employee identification.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function tables(): array {
		return [
			'Payslip' => [
				// Wet LB 1964 art. 26b — the anoniementarief is exactly 52% when applied.
				'nl-anoniementarief' => [
					'derive' => static fn (array $payslip): array => [
						'rateRecorded' => self::numeric($payslip, 'appliedTaxRate'),
						'applied' => (($payslip['anoniementariefApplied'] ?? false) === true),
						'rateIsAnonymous' => self::ratesEqual((float)($payslip['appliedTaxRate'] ?? 0), 52.0),
						'rate' => (float)($payslip['appliedTaxRate'] ?? 0),
					],
					'table' => self::satisfiedTable(
						[['name' => 'rateRecorded', 'type' => 'boolean'], ['name' => 'applied', 'type' => 'boolean'], ['name' => 'rateIsAnonymous', 'type' => 'boolean'], ['name' => 'rate', 'type' => 'number']],
						[
							['id' => 'applied-rate-missing', 'inputEntries' => ['false', '-', '-', '-'], 'outputEntries' => [false]],
							['id' => 'anonymous-rate-not-52', 'inputEntries' => ['true', 'true', 'false', '-'], 'outputEntries' => [false]],
							['id' => 'anonymous-rate-without-flag', 'inputEntries' => ['true', 'false', '-', '>= 52'], 'outputEntries' => [false]],
						]
					),
				],
				// Werkkostenregeling — the remaining vrije ruimte stays covered, or the excess is recorded.
				'nl-wkr-vrije-ruimte' => [
					'derive' => static fn (array $payslip): array => [
						'remainingRecorded' => self::numeric($payslip, 'wkrVrijeRuimteRemaining'),
						'remaining' => (float)($payslip['wkrVrijeRuimteRemaining'] ?? 0),
						'excessRecorded' => self::numeric($payslip, 'wkrExcess'),
					],
					'table' => self::satisfiedTable(
						[['name' => 'remainingRecorded', 'type' => 'boolean'], ['name' => 'remaining', 'type' => 'number'], ['name' => 'excessRecorded', 'type' => 'boolean']],
						[
							['id' => 'vrije-ruimte-missing', 'inputEntries' => ['false', '-', '-'], 'outputEntries' => [false]],
							['id' => 'vrije-ruimte-overrun-unrecorded', 'inputEntries' => ['true', '< 0', 'false'], 'outputEntries' => [false]],
						]
					),
				],
				// Wet LB 1964 art. 31a — any WKR excess carries an 80% eindheffing.
				'nl-wkr-eindheffing-80' => [
					'derive' => static fn (array $payslip): array => [
						'excess' => (float)($payslip['wkrExcess'] ?? 0),
						'rateAsStatutory' => (self::numeric($payslip, 'wkrEindheffingRate') && self::ratesEqual((float)$payslip['wkrEindheffingRate'], 80.0)),
					],
					'table' => self::satisfiedTable(
						[['name' => 'excess', 'type' => 'number'], ['name' => 'rateAsStatutory', 'type' => 'boolean']],
						[['id' => 'excess-without-80-percent', 'inputEntries' => ['> 0', 'false'], 'outputEntries' => [false]]]
					),
				],
				// BW 7:626 lid 1 — the payslip states gross wage, deduction basis, minimum wage and identification.
				'nl-loonstrook-inhoud' => self::loonstrookContent(),
			],
		];

	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the four statement rules are table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * The loonstrook-content check: every one of the four statements BW 7:626
	 * lid 1 requires is shown.
	 *
	 * @return array{derive: callable, table: array<string, mixed>}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function loonstrookContent(): array {
		return [
			'derive' => static fn (array $payslip): array => [
				'grossWage' => (($payslip['showsGrossWage'] ?? false) === true),
				'deductionBasis' => (($payslip['showsDeductionBasis'] ?? false) === true),
				'minimumWage' => (($payslip['showsMinimumWage'] ?? false) === true),
				'identification' => (($payslip['showsEmployerEmployeeIds'] ?? false) === true),
			],
			'table' => self::satisfiedTable(
				[['name' => 'grossWage', 'type' => 'boolean'], ['name' => 'deductionBasis', 'type' => 'boolean'], ['name' => 'minimumWage', 'type' => 'boolean'], ['name' => 'identification', 'type' => 'boolean']],
				[
					['id' => 'gross-wage-not-shown', 'inputEntries' => ['false', '-', '-', '-'], 'outputEntries' => [false]],
					['id' => 'deduction-basis-not-shown', 'inputEntries' => ['-', 'false', '-', '-'], 'outputEntries' => [false]],
					['id' => 'minimum-wage-not-shown', 'inputEntries' => ['-', '-', 'false', '-'], 'outputEntries' => [false]],
					['id' => 'identification-not-shown', 'inputEntries' => ['-', '-', '-', 'false'], 'outputEntries' => [false]],
				]
			),
		];

	}//end loonstrookContent()

	/**
	 * Whether the applied tax rate agrees with the anoniementarief flag.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 *
	 * @return bool
	 */
	private static function anoniementariefConsistent(array $payslip): bool {
		return self::numeric($payslip, 'appliedTaxRate')
			&& ((($payslip['anoniementariefApplied'] ?? false) === true)
			? self::ratesEqual((float)$payslip['appliedTaxRate'], 52.0)
			: ((float)$payslip['appliedTaxRate']) < 52.0);

	}//end anoniementariefConsistent()

	/**
	 * Whether the remaining vrije ruimte is non-negative or the excess is recorded.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 *
	 * @return bool
	 */
	private static function wkrVrijeRuimteCovered(array $payslip): bool {
		return self::numeric($payslip, 'wkrVrijeRuimteRemaining')
			&& (((float)$payslip['wkrVrijeRuimteRemaining']) >= 0.0 || self::numeric($payslip, 'wkrExcess'));

	}//end wkrVrijeRuimteCovered()

	/**
	 * Whether a WKR excess carries the 80% eindheffing.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 *
	 * @return bool
	 */
	private static function wkrEindheffingAtRate(array $payslip): bool {
		return (((float)($payslip['wkrExcess'] ?? 0)) <= 0.0)
			|| (self::numeric($payslip, 'wkrEindheffingRate') && self::ratesEqual((float)$payslip['wkrEindheffingRate'], 80.0));

	}//end wkrEindheffingAtRate()

	/**
	 * Whether the payslip states every mandatory item.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 *
	 * @return bool
	 */
	private static function loonstrookComplete(array $payslip): bool {
		return (($payslip['showsGrossWage'] ?? false) === true)
			&& (($payslip['showsDeductionBasis'] ?? false) === true)
			&& (($payslip['showsMinimumWage'] ?? false) === true)
			&& (($payslip['showsEmployerEmployeeIds'] ?? false) === true);

	}//end loonstrookComplete()

	/**
	 * The four statement closures, exactly as NlPayrollChecks registered them
	 * until tables(); kept for NlPayrollTableParityTest only.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 */
	public static function legacyChecks(): array {
		return [
			'Payslip' => [
				'nl-anoniementarief' => self::anoniementariefConsistent(...),
				'nl-wkr-vrije-ruimte' => self::wkrVrijeRuimteCovered(...),
				'nl-wkr-eindheffing-80' => self::wkrEindheffingAtRate(...),
				'nl-loonstrook-inhoud' => self::loonstrookComplete(...),
			],
		];

	}//end legacyChecks()

	/**
	 * {@inheritDoc}
	 *
	 * No seed of its own; NlPayrollChecks seeds the sample Payslip.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function seedSpec(): array {
		return [];

	}//end seedSpec()
}//end class
