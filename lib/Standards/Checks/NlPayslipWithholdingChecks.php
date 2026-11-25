<?php

/**
 * NL Payslip Withholding Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The five withholding rules over Payslip, moved out of NlPayrollChecks: a
 * non-negative loonheffing withheld, the volksverzekeringen premium inside
 * it, the employer werknemersverzekeringen present, and each Zvw mode at its
 * 2026 rate with an amount.
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * The payslip withholding rules, as decision tables.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
final class NlPayslipWithholdingChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;
	use ReadsRecordedFields;

	/**
	 * {@inheritDoc}
	 *
	 * The Payslip sample these rules read is NlPayrollChecks' own.
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * {@inheritDoc}
	 *
	 * A loonheffing and an employer werknemersverzekeringen amount that are
	 * numeric and not negative; a numeric, non-negative volksverzekeringen
	 * premium no larger than the loonheffing; under each Zvw mode the 2026
	 * rate (6,10% employer levy, 4,85% withheld) and a numeric amount.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function tables(): array {
		return [
			'Payslip' => [
				// Wet LB 1964 art. 27 — a loonheffing must be withheld at each payment.
				'nl-loonheffingen-inhouding' => self::nonNegativeAmount('loonheffing'),
				// Wfsv / art. 27b — premie volksverzekeringen is levied inside the combined loonheffing.
				'nl-loonheffingen-volksverzekeringen' => [
					'derive' => static fn (array $payslip): array => [
						'recorded' => (self::numeric($payslip, 'volksverzekeringen') && self::numeric($payslip, 'loonheffing')),
						'volksverzekeringen' => (float)($payslip['volksverzekeringen'] ?? 0),
						'headroom' => ((float)($payslip['loonheffing'] ?? 0) - (float)($payslip['volksverzekeringen'] ?? 0)),
					],
					'table' => self::satisfiedTable(
						[['name' => 'recorded', 'type' => 'boolean'], ['name' => 'volksverzekeringen', 'type' => 'number'], ['name' => 'headroom', 'type' => 'number']],
						[
							['id' => 'premium-or-loonheffing-missing', 'inputEntries' => ['false', '-', '-'], 'outputEntries' => [false]],
							['id' => 'premium-negative', 'inputEntries' => ['true', '< 0', '-'], 'outputEntries' => [false]],
							['id' => 'premium-above-loonheffing', 'inputEntries' => ['true', '-', '< 0'], 'outputEntries' => [false]],
						]
					),
				],
				// Wfsv — employer-borne werknemersverzekeringen (WW/WIA/ZW) over the wage.
				'nl-werknemersverzekeringen-werkgever' => self::nonNegativeAmount('werknemersverzekeringen'),
				// Zvw werkgeversheffing — when the employer-levy mode applies, the 2026 rate is 6,10%.
				'nl-zvw-werkgeversheffing' => self::zvwAtRate('werkgeversheffing', 6.10),
				// Zvw ingehouden bijdrage — when the withheld mode applies, the 2026 rate is 4,85%.
				'nl-zvw-inhouding' => self::zvwAtRate('inhouding', 4.85),
			],
		];

	}//end tables()

	/**
	 * A table-declared check that the field holds a numeric amount of at
	 * least zero.
	 *
	 * @param string $field The Payslip amount field.
	 *
	 * @return array{derive: callable, table: array<string, mixed>}
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function nonNegativeAmount(string $field): array {
		return [
			'derive' => static fn (array $payslip): array => ['recorded' => self::numeric($payslip, $field), 'amount' => (float)($payslip[$field] ?? 0)],
			'table' => self::satisfiedTable(
				[['name' => 'recorded', 'type' => 'boolean'], ['name' => 'amount', 'type' => 'number']],
				[
					['id' => $field . '-missing', 'inputEntries' => ['false', '-'], 'outputEntries' => [false]],
					['id' => $field . '-negative', 'inputEntries' => ['true', '< 0'], 'outputEntries' => [false]],
				]
			),
		];

	}//end nonNegativeAmount()

	/**
	 * A table-declared check that, under the given Zvw mode, the payslip
	 * carries the statutory rate and a numeric Zvw amount.
	 *
	 * @param string $mode The `zvwMode` the rule applies to.
	 * @param float  $rate The statutory rate, in percent.
	 *
	 * @return array{derive: callable, table: array<string, mixed>}
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function zvwAtRate(string $mode, float $rate): array {
		return [
			'derive' => static fn (array $payslip): array => [
				'modeApplies' => ((string)($payslip['zvwMode'] ?? '') === $mode),
				'rateAsStatutory' => (self::numeric($payslip, 'zvwRate') && self::ratesEqual((float)$payslip['zvwRate'], $rate)),
				'amountRecorded' => self::numeric($payslip, 'zvw'),
			],
			'table' => self::satisfiedTable(
				[['name' => 'modeApplies', 'type' => 'boolean'], ['name' => 'rateAsStatutory', 'type' => 'boolean'], ['name' => 'amountRecorded', 'type' => 'boolean']],
				[
					['id' => 'zvw-rate-not-statutory', 'inputEntries' => ['true', 'false', '-'], 'outputEntries' => [false]],
					['id' => 'zvw-amount-missing', 'inputEntries' => ['true', '-', 'false'], 'outputEntries' => [false]],
				]
			),
		];

	}//end zvwAtRate()

	/**
	 * {@inheritDoc}
	 *
	 * Nothing registers as a closure: tables() declares all five.
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [];
	}//end checks()

	/**
	 * Whether a non-negative loonheffing is withheld.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 *
	 * @return bool
	 */
	private static function loonheffingWithheld(array $payslip): bool {
		return self::numeric($payslip, 'loonheffing') && ((float)$payslip['loonheffing']) >= 0.0;

	}//end loonheffingWithheld()

	/**
	 * Whether the volksverzekeringen premium is present and within the loonheffing.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 *
	 * @return bool
	 */
	private static function volksverzekeringenWithinLoonheffing(array $payslip): bool {
		return self::numeric($payslip, 'volksverzekeringen')
			&& self::numeric($payslip, 'loonheffing')
			&& ((float)$payslip['volksverzekeringen']) >= 0.0
			&& ((float)$payslip['volksverzekeringen']) <= ((float)$payslip['loonheffing']);

	}//end volksverzekeringenWithinLoonheffing()

	/**
	 * Whether a non-negative employer werknemersverzekeringen amount is present.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 *
	 * @return bool
	 */
	private static function werknemersverzekeringenPresent(array $payslip): bool {
		return self::numeric($payslip, 'werknemersverzekeringen')
			&& ((float)$payslip['werknemersverzekeringen']) >= 0.0;

	}//end werknemersverzekeringenPresent()

	/**
	 * Whether the employer-levy Zvw mode, when it applies, uses the 6,10% rate.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 *
	 * @return bool
	 */
	private static function zvwWerkgeversheffingAtRate(array $payslip): bool {
		return ((string)($payslip['zvwMode'] ?? '') !== 'werkgeversheffing')
			|| (self::numeric($payslip, 'zvwRate') && self::ratesEqual((float)$payslip['zvwRate'], 6.10) && self::numeric($payslip, 'zvw'));

	}//end zvwWerkgeversheffingAtRate()

	/**
	 * Whether the withheld Zvw mode, when it applies, uses the 4,85% rate.
	 *
	 * @param array<string, mixed> $payslip The Payslip.
	 *
	 * @return bool
	 */
	private static function zvwInhoudingAtRate(array $payslip): bool {
		return ((string)($payslip['zvwMode'] ?? '') !== 'inhouding')
			|| (self::numeric($payslip, 'zvwRate') && self::ratesEqual((float)$payslip['zvwRate'], 4.85) && self::numeric($payslip, 'zvw'));

	}//end zvwInhoudingAtRate()

	/**
	 * The withholding closures NlPayrollChecks registered before tables();
	 * NlPayrollTableParityTest holds the tables to them. Not registered.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 */
	public static function legacyChecks(): array {
		return [
			'Payslip' => [
				'nl-loonheffingen-inhouding' => self::loonheffingWithheld(...),
				'nl-loonheffingen-volksverzekeringen' => self::volksverzekeringenWithinLoonheffing(...),
				'nl-werknemersverzekeringen-werkgever' => self::werknemersverzekeringenPresent(...),
				'nl-zvw-werkgeversheffing' => self::zvwWerkgeversheffingAtRate(...),
				'nl-zvw-inhouding' => self::zvwInhoudingAtRate(...),
			],
		];

	}//end legacyChecks()
}//end class
