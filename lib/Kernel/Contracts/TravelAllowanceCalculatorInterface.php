<?php

/**
 * Contract of TravelAllowanceCalculator
 *
 * The public surface of TravelAllowanceCalculator that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of TravelAllowanceCalculator.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TravelAllowanceCalculatorInterface {
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
	public function claim(float $distanceKm, float $ratePerKm, float $taxFreeRatePerKm): array;

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
	public function monthly(float $distanceKmOneWay, float $daysPerWeek, float $ratePerKm, float $taxFreeRatePerKm): array;
}//end interface
