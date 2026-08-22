<?php

/**
 * NL Retro Adjustment Check Provider
 *
 * Executable check for the retro-adjustments corpus rule
 * (lib/Standards/rules/payroll.json) -- unenforced until this provider
 * registered its predicate (design.md D7):
 *
 * - `nl-retro-adjustment-consistency` (PayrollAdjustment): vacuous when
 *   `engineVersion` is null (not yet computed); else independently
 *   recomputes the recorded `correctedGrossMonthlySalary` with the pure
 *   `PayrollCalculator` against `TaxTables::load(engineVersion)`, diffs the
 *   result against the referenced original Payslip (resolved via the
 *   `retro.payslipsById` audit context -- the `payroll.runsById`/
 *   `nl-engine-output-consistency` precedent), and asserts every recorded
 *   `delta*` field equals recomputed minus stored, cents-exact. A
 *   hand-tampered delta -- or a delta that no longer matches the recorded
 *   corrected input -- fails exactly as a tampered `nettoPay` fails
 *   `nl-engine-output-consistency`: the engine has no private truth.
 *
 * This provider does NOT implement SeedsObjects: retro-adjustments ships no
 * seed PayrollAdjustment (design.md Seed Data) -- the corpus is exercised
 * against a real computed adjustment in the dev-container gate, not fixture
 * data.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;

/**
 * The retro-adjustment delta's self-check: recompute, then compare.
 */
final class NlRetroChecks implements CheckProvider {

	/**
	 * The delta fields checked against `recomputed - stored`, mapped to the
	 * `CalculationResult`/stored-Payslip component each reads from.
	 *
	 * @var array<string, string>
	 */
	private const DELTA_FIELDS = [
		'deltaGross' => 'grossPay',
		'deltaLoonheffing' => 'loonheffing',
		'deltaNet' => 'nettoPay',
		'deltaWerknemersverzekeringen' => 'werknemersverzekeringen',
		'deltaZvw' => 'zvw',
		'deltaVolksverzekeringen' => 'volksverzekeringen',
		'deltaVakantiegeldReserved' => 'vakantiegeldReserved',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
	 */
	public static function checks(): array {
		return [
			'PayrollAdjustment' => [
				'nl-retro-adjustment-consistency' => static fn (array $o, array $context): bool => self::isDeltaConsistent($o, $context),
			],
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * The `nl-retro-adjustment-consistency` predicate (spec.md REQ-RETRO-001):
	 * a PayrollAdjustment that carries `engineVersion` must have every
	 * `delta*` field equal to an independent recompute of
	 * `correctedGrossMonthlySalary` against `engineVersion`'s tables, minus
	 * the referenced original Payslip's stored figures. Vacuous (true) when
	 * `engineVersion` is null, or when the cross-object data it needs
	 * (original payslip / employee) is unresolvable -- an incomplete index
	 * must never manufacture a false violation.
	 *
	 * @param array<string, mixed> $o The PayrollAdjustment object.
	 * @param array<string, mixed> $context Evaluation context; reads `retro.*`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
	 */
	private static function isDeltaConsistent(array $o, array $context): bool {
		$engineVersion = trim((string)($o['engineVersion'] ?? ''));
		if ($engineVersion === '') {
			// Not yet computed -- out of scope (vacuous pass).
			return true;
		}

		$originalPeriod = trim((string)($o['originalPeriod'] ?? ''));
		$employeeId = trim((string)($o['employeeId'] ?? ''));
		$payslipId = trim((string)($o['originalPayslipId'] ?? ''));
		$correctedGross = ($o['correctedGrossMonthlySalary'] ?? null);

		if ($originalPeriod === '' || $employeeId === '' || $payslipId === '' || is_numeric($correctedGross) === false) {
			// engineVersion is present but the data it depends on is not --
			// an inconsistent/tampered record, not vacuous.
			return false;
		}

		$stored = ($context['retro']['payslipsById'][$payslipId] ?? null);
		if (is_array($stored) === false) {
			// Unresolvable original payslip -- cannot verify, never a false
			// violation (the `nl-engine-output-consistency` precedent).
			return true;
		}

		$employee = ($context['retro']['employeesById'][$employeeId] ?? null);
		if (is_array($employee) === false) {
			return true;
		}

		try {
			$tables = TaxTables::load($engineVersion);
		} catch (\Throwable $e) {
			// The recorded engineVersion no longer resolves to a real table
			// file -- stale/tampered, not vacuous.
			return false;
		}

		$contract = ($context['retro']['contractsByEmployeeId'][$employeeId] ?? null);
		$aofTariff = (string)($context['retro']['aofTariff'] ?? 'laag');
		$whkOverride = ($context['retro']['whkPercentageOverride'] ?? null);
		$whkPercentage = is_numeric($whkOverride) === true ? (float)$whkOverride : (float)($tables->werknemersverzekeringen()['whkDefault'] ?? 0.0);

		$input = new CalculationInput(
			grossMonthlySalaryCents: (int)round(((float)$correctedGross) * 100),
			taxTableColor: ((string)($employee['taxTableColor'] ?? '') !== '' ? (string)$employee['taxTableColor'] : 'wit'),
			loonheffingskortingToegepast: (($employee['loonheffingskortingToegepast'] ?? true) === true),
			dateOfBirth: (($employee['dateOfBirth'] ?? null) !== null ? (string)$employee['dateOfBirth'] : null),
			period: $originalPeriod,
			awfTariff: self::awfTariffFor($contract),
			aofTariff: $aofTariff,
			whkPercentage: $whkPercentage
		);

		$result = (new PayrollCalculator())->calculate($input, $tables);

		$recomputedCents = [
			'grossPay' => $result->grossPayCents,
			'loonheffing' => $result->loonheffingCents,
			'nettoPay' => $result->nettoPayCents,
			'werknemersverzekeringen' => $result->werknemersverzekeringenCents,
			'zvw' => $result->zvwCents,
			'volksverzekeringen' => $result->volksverzekeringenCents,
			'vakantiegeldReserved' => $result->vakantiegeldReservedCents,
		];

		foreach (self::DELTA_FIELDS as $deltaField => $component) {
			$expectedCents = ($recomputedCents[$component] - self::centsOf($stored, $component));
			if (self::numeric($o, $deltaField) === false || self::centsOf($o, $deltaField) !== $expectedCents) {
				return false;
			}
		}

		return true;
	}//end isDeltaConsistent()

	/**
	 * The contract's Awf tariff (`low`/`high`), the PayrollRunService
	 * Wab-derived fallback precedent -- defaults to `high` when no contract
	 * resolves.
	 *
	 * @param array<string, mixed>|null $contract The employee's contract row, or null.
	 *
	 * @return string
	 */
	private static function awfTariffFor(?array $contract): string {
		if ($contract === null) {
			return 'high';
		}

		$tariff = trim((string)($contract['awfTariff'] ?? ''));
		if (in_array($tariff, ['low', 'high'], true) === true) {
			return $tariff;
		}

		$permanent = ((string)($contract['type'] ?? '') === 'permanent');
		$written = (($contract['writtenContract'] ?? false) === true);
		return ($permanent === true && $written === true) ? 'low' : 'high';
	}//end awfTariffFor()

	/**
	 * True when an object field holds a present, numeric value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return bool
	 */
	private static function numeric(array $o, string $key): bool {
		return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;
	}//end numeric()

	/**
	 * A field's value converted to integer cents (round-half-away-from-zero),
	 * defensively 0 for a missing/non-numeric value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return int
	 */
	private static function centsOf(array $o, string $key): int {
		$value = ($o[$key] ?? null);
		return is_numeric($value) === true ? (int)round(((float)$value) * 100) : 0;
	}//end centsOf()

}//end class
