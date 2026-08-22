<?php

/**
 * Sick Pay Calculator
 *
 * The pure, stateless, table-driven loondoorbetaling-bij-ziekte calculator
 * (design.md D2): implements the statutory continued-wage chain over a
 * `SickPayInput` + `TaxTables` parameter set — the non-worked base and
 * continuation on it (samengesteld/aangepast loon composition), the
 * statutory 70%/year-1 WML floor, the once-per-case wachtdag deduction, and
 * the resulting payable gross that `PayrollRunService` feeds into the
 * already-verified `PayrollCalculator` as the gross-to-net input.
 *
 * Zero Nextcloud dependencies (mirrors `PayrollCalculator` exactly): no
 * container, no clock, no IO beyond the `TaxTables` instance passed in —
 * directly unit-testable. Every monetary intermediate is integer cents; the
 * two rounding points (the percentage multiply and the wachtdag divide)
 * round half-up to whole cents (PHP's default `round()` mode, half-away-
 * from-zero, coincides with half-up for the non-negative amounts this
 * calculator ever produces).
 *
 * `PayrollCalculator` is NOT modified: sick pay is a pre-processing step
 * that only substitutes the gross figure fed into it (design.md D4).
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-002
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-003
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * Pure NL loondoorbetaling-bij-ziekte calculator over a `TaxTables`
 * parameter set.
 */
final class SickPayCalculator {

	/**
	 * The statutory continued-wage floor percentage (BW 7:629 lid 1) — always
	 * at least 70% of the reference wage, independent of the case's applied
	 * `loondoorbetalingPercentage` (which may be higher under a CAO). Mirrors
	 * the `nl-loondoorbetaling-floor` corpus rule's `statutoryPercentage`
	 * parameter (design.md D5); kept as a calculator-local constant since
	 * `compute()`'s signature is `(SickPayInput, TaxTables)` only, the same
	 * shape as `PayrollCalculator::calculate()`.
	 *
	 * @var int
	 */
	private const STATUTORY_FLOOR_PERCENTAGE = 70;

	/**
	 * The average working days per month the wachtdag is valued against
	 * (CBS/CAO average, design.md D2 step 6). Mirrors the
	 * `nl-loondoorbetaling-floor` corpus rule's `workingDaysPerMonth`
	 * parameter.
	 *
	 * @var float
	 */
	private const WORKING_DAYS_PER_MONTH = 21.75;

	/**
	 * The full-time hours-per-week basis used when an input omits its own
	 * (e.g. a missing contract), so the WML floor's part-time factor never
	 * divides by zero.
	 *
	 * @var float
	 */
	private const DEFAULT_FULLTIME_HOURS_PER_WEEK = 36.0;

	/**
	 * Compute the loondoorbetaling-bij-ziekte chain for one employee in one
	 * wage period (design.md D2).
	 *
	 * @param SickPayInput $in The calculation input.
	 * @param TaxTables $t The tax-year parameter set (for the WML floor).
	 *
	 * @return SickPayResult
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-002
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-003
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-004
	 */
	public function compute(SickPayInput $in, TaxTables $t): SickPayResult {
		$w = max(0, $in->referenceWageCents);
		$a = max(0, min($w, $in->aangepastLoonCents));
		$p = $in->loondoorbetalingPercentage;

		// Step 1-3 (design.md D2): non-worked base, continuation on it, and
		// the samengesteld/aangepast loon composition (worked wage at 100% +
		// continuation on the rest).
		$b = ($w - $a);
		$c = self::roundHalfUpCents(($b * $p) / 100);
		$l0 = ($a + $c);

		// Step 4 (design.md D2/D3): the statutory floor — always at least
		// 70% of the wage; in year 1 additionally at least the WML monthly
		// floor (capped at the wage itself).
		$m = $this->wmlFloorCents($t, $in);
		$statutoryFloor = self::roundHalfUpCents(($w * self::STATUTORY_FLOOR_PERCENTAGE) / 100);
		$yearOneFloor = ($in->yearOne === true ? min($w, $m) : 0);
		$floor = max($statutoryFloor, $yearOneFloor);

		// Step 5: floored doorbetaald.
		$l = max($l0, $floor);
		$floorApplied = ($l > $l0);

		// Step 6: the wachtdag deduction — once per case at its start.
		$wd = 0;
		if ($in->wachtdag === true && $in->firstSickDayInPeriod === true) {
			$wd = self::roundHalfUpCents($l / self::WORKING_DAYS_PER_MONTH);
		}

		// Step 7: payable gross, fed to PayrollCalculator unchanged.
		$payableGross = ($l - $wd);

		return new SickPayResult(
			doorbetaaldLoonCents: $l,
			wachtdagDeductionCents: $wd,
			payableGrossCents: $payableGross,
			minimumWageFloorCents: $m,
			floorApplied: $floorApplied,
			appliedPercentage: $p,
			yearOne: $in->yearOne,
			referenceWageCents: $w
		);

	}//end compute()

	/**
	 * `M` — the WML monthly floor, derived from the tables' verified
	 * full-time `referentiemaandloon` scaled by the part-time factor
	 * `contractHoursPerWeek / fulltimeHoursPerWeek` (design.md D3). Never a
	 * hard-coded number: a table with a different year's figure changes `M`
	 * automatically. A non-positive `fulltimeHoursPerWeek` falls back to the
	 * full-time basis so the factor never divides by zero.
	 *
	 * @param TaxTables $t The tax-year parameter set.
	 * @param SickPayInput $in The calculation input.
	 *
	 * @return int
	 */
	private function wmlFloorCents(TaxTables $t, SickPayInput $in): int {
		$fulltimeHours = ($in->fulltimeHoursPerWeek > 0.0 ? $in->fulltimeHoursPerWeek : self::DEFAULT_FULLTIME_HOURS_PER_WEEK);
		$contractHours = ($in->contractHoursPerWeek > 0.0 ? $in->contractHoursPerWeek : $fulltimeHours);

		$referentiemaandloonCents = $t->wml()['referentiemaandloonCents'];

		return self::roundHalfUpCents(($referentiemaandloonCents * $contractHours) / $fulltimeHours);
	}//end wmlFloorCents()

	/**
	 * Round a raw cents amount to the nearest whole cent, half-up (design.md
	 * D1 — the two rounding points: the percentage multiply and the wachtdag
	 * divide). PHP's default `round()` mode (half-away-from-zero) coincides
	 * with half-up for the non-negative amounts this calculator ever
	 * produces.
	 *
	 * @param float $rawCents The raw cents amount.
	 *
	 * @return int
	 */
	private static function roundHalfUpCents(float $rawCents): int {
		return (int)round($rawCents);
	}//end roundHalfUpCents()

}//end class
