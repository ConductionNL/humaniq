<?php

/**
 * Unit tests for CalculationResult::combined(): the four weeks of an extended
 * period 13 and its week 53 as one payslip result.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
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
 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationLevies;
use OCA\Humaniq\Payroll\CalculationResult;
use OCA\Humaniq\Payroll\CalculationWage;
use OCA\Humaniq\Payroll\InsuranceTariffs;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Payroll\WageTaxElection;
use PHPUnit\Framework\TestCase;

/**
 * Two real engine results, one four-weekly and one weekly, summed.
 */
class CalculationResultCombinedTest extends TestCase {

	/**
	 * Every amount and wage is the sum of both parts; the mode and rates are
	 * the four weeks'.
	 *
	 * @return void
	 */
	public function testAmountsAddUpAndTheFourWeeksKeepTheirRates(): void {
		$fourWeeks = $this->calculate(grossCents: 350769, period: '2026-P13');
		$week = $this->calculate(grossCents: 87692, period: '2026-W53');

		$sum = CalculationResult::combined(fourWeeks: $fourWeeks, week53: $week);

		foreach (['grossPayCents', 'loonheffingCents', 'arbeidskortingCents', 'volksverzekeringenCents', 'zvwCents', 'nettoPayCents', 'vakantiegeldReservedCents', 'awfCents', 'aofCents', 'wkoCents', 'whkCents', 'werknemersverzekeringenCents', 'employerChargesCents', 'taxableWageCents', 'premiumWageCents'] as $field) {
			self::assertSame($fourWeeks->$field + $week->$field, $sum->$field, $field);
		}

		// The pack's P05 and W20 vectors for the same wages: tijdvak 13 and 52.
		self::assertSame(66354 + 16588, $sum->loonheffingCents);
		self::assertSame(350769 + 87692, $sum->grossPayCents);
		self::assertSame([$fourWeeks->zvwMode, $fourWeeks->zvwRate, $fourWeeks->appliedTaxRate, $fourWeeks->vakantiegeldRate], [$sum->zvwMode, $sum->zvwRate, $sum->appliedTaxRate, $sum->vakantiegeldRate]);
		self::assertFalse($sum->aboveLmax);
	}//end testAmountsAddUpAndTheFourWeeksKeepTheirRates()

	/**
	 * The payslip is above the Lmax when either part is.
	 *
	 * @return void
	 */
	public function testEitherPartAboveTheLmaxMarksTheSum(): void {
		$fourWeeks = $this->calculate(grossCents: 350769, period: '2026-P13');
		$week = $this->calculate(grossCents: 2000000, period: '2026-W53');

		self::assertFalse($fourWeeks->aboveLmax);
		self::assertTrue($week->aboveLmax);
		self::assertTrue(CalculationResult::combined(fourWeeks: $fourWeeks, week53: $week)->aboveLmax);
	}//end testEitherPartAboveTheLmaxMarksTheSum()

	/**
	 * The anchor employee's engine result for a wage and period.
	 *
	 * @param int    $grossCents The period's gross wage.
	 * @param string $period     The period id.
	 *
	 * @return CalculationResult
	 */
	private function calculate(int $grossCents, string $period): CalculationResult {
		$input = new CalculationInput(
			wage: new CalculationWage(grossMonthlySalaryCents: $grossCents, dateOfBirth: '1990-04-12', period: $period, jurisdiction: 'NL'),
			levies: new CalculationLevies(
				wageTax: new WageTaxElection(taxTableColor: 'wit', loonheffingskortingToegepast: true, thirtyPercentRulingRate: 0.0),
				insurance: new InsuranceTariffs(awfTariff: 'laag', aofTariff: 'laag', whkPercentage: 1.52, verzekeringsplichtig: true)
			)
		);

		return (new PayrollCalculator())->calculate($input, TaxTables::load('nl-2026'));
	}//end calculate()
}//end class
