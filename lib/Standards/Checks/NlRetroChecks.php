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
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;


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
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
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
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
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
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
	 */
	private static function isDeltaConsistent(array $o, array $context): bool {
		$engineVersion = trim((string)($o['engineVersion'] ?? ''));
		if ($engineVersion === '') {
			// Not yet computed -- out of scope (vacuous pass).
			return true;
		}

		$recompute = new RetroRecompute($o, $context);
		if ($recompute->lacksInput() === true) {
			// engineVersion is present but the data it depends on is not --
			// an inconsistent/tampered record, not vacuous.
			return false;
		}

		if ($recompute->isVerifiable() === false) {
			// Unresolvable original payslip or employee -- cannot verify, never
			// a false violation (the `nl-engine-output-consistency` precedent).
			return true;
		}

		// Null when the recorded engineVersion no longer resolves to a real
		// table file -- stale/tampered, not vacuous.
		$expectedCents = $recompute->expectedDeltaCents($engineVersion);
		return $expectedCents !== null && self::deltasMatch($o, $expectedCents);
	}//end isDeltaConsistent()

	/**
	 * Whether every delta field is numeric and equals the expected cents of its component.
	 *
	 * @param array<string, mixed> $o             The PayrollAdjustment object.
	 * @param array<string, int>   $expectedCents Per component: recomputed minus stored cents.
	 *
	 * @return bool
	 */
	private static function deltasMatch(array $o, array $expectedCents): bool {
		foreach (self::DELTA_FIELDS as $deltaField => $component) {
			if (self::numeric($o, $deltaField) === false || RetroRecompute::cents($o, $deltaField) !== $expectedCents[$component]) {
				return false;
			}
		}

		return true;

	}//end deltasMatch()

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

}//end class
