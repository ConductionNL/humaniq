<?php

/**
 * Travel Allowance Calculator
 *
 * The arithmetic of expenses-travel-calculation, pure so every figure the
 * spec names is one unit test: a mileage claim (distance times the rate,
 * split into a tax-free part at the corpus rate and a taxable remainder),
 * the fixed monthly commuting allowance under the 214-day rule, and the
 * commuting kilometres a year of an arrangement stands for.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Claim, monthly allowance and yearly kilometre arithmetic.
 *
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
 */
class TravelAllowanceCalculator {

	/**
	 * The fiscal number of commuting days in a year for a five-day week.
	 *
	 * @var int
	 */
	public const WORKING_DAYS_PER_YEAR = 214;

	/**
	 * A mileage claim: the amount at the employer's rate, split into its
	 * tax-free and taxable parts.
	 *
	 * @param float $distanceKm       The kilometres claimed.
	 * @param float $ratePerKm        The employer's rate per kilometre.
	 * @param float $taxFreeRatePerKm The tax-free rate per kilometre.
	 *
	 * @return array{amount: float, taxFreeAmount: float, taxableAmount: float}
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
	 */
	public function claim(float $distanceKm, float $ratePerKm, float $taxFreeRatePerKm): array {
		return $this->split(amount: ($distanceKm * $ratePerKm), taxFree: ($distanceKm * $taxFreeRatePerKm));
	}//end claim()

	/**
	 * The fixed monthly commuting allowance under the 214-day rule.
	 *
	 * @param float $distanceKmOneWay The distance from home to work.
	 * @param float $daysPerWeek      The commuting days per week.
	 * @param float $ratePerKm        The employer's rate per kilometre.
	 * @param float $taxFreeRatePerKm The tax-free rate per kilometre.
	 *
	 * @return array{monthlyAllowance: float, taxFreeMonthly: float, taxableMonthly: float}
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-002
	 */
	public function monthly(float $distanceKmOneWay, float $daysPerWeek, float $ratePerKm, float $taxFreeRatePerKm): array {
		$kmPerMonth = ($this->yearlyCommuteKm(distanceKmOneWay: $distanceKmOneWay, daysPerWeek: $daysPerWeek, monthsActive: 12, rounded: false) / 12);
		$split = $this->split(amount: ($kmPerMonth * $ratePerKm), taxFree: ($kmPerMonth * $taxFreeRatePerKm));

		return [
			'monthlyAllowance' => $split['amount'],
			'taxFreeMonthly' => $split['taxFreeAmount'],
			'taxableMonthly' => $split['taxableAmount'],
		];
	}//end monthly()

	/**
	 * The commuting kilometres an arrangement stands for in a year: return
	 * trips on 214 days scaled by the days per week, pro rata for the months
	 * it was active.
	 *
	 * @param float $distanceKmOneWay The distance from home to work.
	 * @param float $daysPerWeek      The commuting days per week.
	 * @param int   $monthsActive     The months of the year it was active, 0 to 12.
	 * @param bool  $rounded          Round to one decimal, as the report shows it.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
	 */
	public function yearlyCommuteKm(float $distanceKmOneWay, float $daysPerWeek, int $monthsActive, bool $rounded=true): float {
		$months = max(0, min(12, $monthsActive));
		$kilometres = ($distanceKmOneWay * 2 * self::WORKING_DAYS_PER_YEAR * ($daysPerWeek / 5) * ($months / 12));

		return $rounded === true ? round($kilometres, 1) : $kilometres;
	}//end yearlyCommuteKm()

	/**
	 * Round an amount and its tax-free part to cents, the tax-free part never
	 * above the amount.
	 *
	 * @param float $amount  The amount paid.
	 * @param float $taxFree The amount at the tax-free rate.
	 *
	 * @return array{amount: float, taxFreeAmount: float, taxableAmount: float}
	 */
	private function split(float $amount, float $taxFree): array {
		$paid = round($amount, 2);
		$free = min($paid, round($taxFree, 2));

		return [
			'amount' => $paid,
			'taxFreeAmount' => $free,
			'taxableAmount' => round(($paid - $free), 2),
		];
	}//end split()

}//end class
