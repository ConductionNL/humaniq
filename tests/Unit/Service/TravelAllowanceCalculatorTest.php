<?php

/**
 * TravelAllowanceCalculatorTest
 *
 * The claim and 214-day arithmetic of expenses-travel-calculation, with the
 * figures the spec scenarios name.
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\TravelAllowanceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Claim amounts, the monthly allowance and yearly commuting kilometres.
 */
class TravelAllowanceCalculatorTest extends TestCase {

	/**
	 * 150 km at the tax-free rate of 0.23 is 34.50, all of it tax free.
	 *
	 * @return void
	 */
	public function testAClaimAtTheTaxFreeRateIsAllTaxFree(): void {
		$claim = (new TravelAllowanceCalculator())->claim(distanceKm: 150.0, ratePerKm: 0.23, taxFreeRatePerKm: 0.23);

		self::assertSame(['amount' => 34.5, 'taxFreeAmount' => 34.5, 'taxableAmount' => 0.0], $claim);
	}//end testAClaimAtTheTaxFreeRateIsAllTaxFree()

	/**
	 * 150 km at an employer rate of 0.30 is 45.00, of which 10.50 is taxable.
	 *
	 * @return void
	 */
	public function testAClaimAboveTheTaxFreeRateSplitsOffATaxablePart(): void {
		$claim = (new TravelAllowanceCalculator())->claim(distanceKm: 150.0, ratePerKm: 0.30, taxFreeRatePerKm: 0.23);

		self::assertSame(['amount' => 45.0, 'taxFreeAmount' => 34.5, 'taxableAmount' => 10.5], $claim);
	}//end testAClaimAboveTheTaxFreeRateSplitsOffATaxablePart()

	/**
	 * An employer paying below the tax-free rate pays nothing taxable, and the
	 * tax-free part never exceeds what is paid.
	 *
	 * @return void
	 */
	public function testTheTaxFreePartIsCappedAtTheAmount(): void {
		$claim = (new TravelAllowanceCalculator())->claim(distanceKm: 100.0, ratePerKm: 0.19, taxFreeRatePerKm: 0.23);

		self::assertSame(['amount' => 19.0, 'taxFreeAmount' => 19.0, 'taxableAmount' => 0.0], $claim);
	}//end testTheTaxFreePartIsCappedAtTheAmount()

	/**
	 * The seeded commute: 18 km one way, 4 days a week at 0.23 is 118.13 a month.
	 *
	 * @return void
	 */
	public function testTheSeededCommuteIs11813AMonth(): void {
		$monthly = (new TravelAllowanceCalculator())->monthly(distanceKmOneWay: 18.0, daysPerWeek: 4.0, ratePerKm: 0.23, taxFreeRatePerKm: 0.23);

		self::assertSame(['monthlyAllowance' => 118.13, 'taxFreeMonthly' => 118.13, 'taxableMonthly' => 0.0], $monthly);
	}//end testTheSeededCommuteIs11813AMonth()

	/**
	 * Commuting kilometres for a year: 18 km one way, 4 days, a full year is
	 * 6163.2 km; six months of it is half.
	 *
	 * @return void
	 */
	public function testYearlyCommuteKilometresArePaidProRata(): void {
		$calculator = new TravelAllowanceCalculator();

		self::assertSame(6163.2, $calculator->yearlyCommuteKm(distanceKmOneWay: 18.0, daysPerWeek: 4.0, monthsActive: 12));
		self::assertSame(3081.6, $calculator->yearlyCommuteKm(distanceKmOneWay: 18.0, daysPerWeek: 4.0, monthsActive: 6));
	}//end testYearlyCommuteKilometresArePaidProRata()

}//end class
