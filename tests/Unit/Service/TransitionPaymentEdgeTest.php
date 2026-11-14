<?php

/**
 * Transition payment edge tests
 *
 * Pins TransitionPaymentCalculator::calculate where the main suite does not
 * look: a last working day that is not a date, a contract that starts after
 * it, an hourly employee's base wage from the latest contract, and the
 * holiday rate of the most recent payslip.
 *
 * @category Test
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Humaniq\Service\TransitionPaymentCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the transition payment.
 */
class TransitionPaymentEdgeTest extends TestCase {

	private const PARAMETERS = ['capEur' => 102000, 'dismissalInitiatedReasons' => ['opzegging-werkgever']];

	/**
	 * A last working day that is not a date is refused.
	 *
	 * @return void
	 */
	public function testALastWorkingDayThatIsNotADateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The last working day is not a date.');

		(new TransitionPaymentCalculator())->calculate(
			employee: ['grossMonthlySalary' => 3000.0],
			history: ['contracts' => [], 'payslips' => []],
			parameters: self::PARAMETERS,
			departure: ['endDate' => 'soon', 'reason' => 'opzegging-werkgever']
		);
	}

	/**
	 * Without a salary the base wage is the hourly wage times the hours of
	 * the contract that starts last (even one after the last working day,
	 * which does not count as service); the holiday rate is the newest
	 * payslip's; a year's wage above the rule's cap is the cap.
	 *
	 * @return void
	 */
	public function testAnHourlyEmployeeAndTheNewestPayslipsRate(): void {
		$result = (new TransitionPaymentCalculator())->calculate(
			employee: ['startDate' => '2015-01-01'],
			history: [
				'contracts' => [
					['startDate' => '2020-01-01', 'endDate' => '2022-12-31', 'hoursPerWeek' => 20, 'hourlyWage' => 15.0],
					['startDate' => '2023-01-01', 'endDate' => null, 'hoursPerWeek' => 30, 'hourlyWage' => 20.0],
					['startDate' => '2026-03-01', 'endDate' => null, 'hoursPerWeek' => 40, 'hourlyWage' => 99.0],
				],
				'payslips' => [
					['period' => '2025-11', 'grossPay' => 2700.0, 'vakantiegeldRate' => 0.05],
					['period' => '2025-12', 'grossPay' => 2600.0, 'vakantiegeldRate' => 0.1],
				],
			],
			parameters: self::PARAMETERS,
			departure: ['endDate' => '2025-12-31', 'reason' => 'opzegging-werkgever']
		);

		self::assertSame(
			[
				'amountEur' => 37752.0,
				'serviceStart' => '2020-01-01',
				'serviceYears' => 6,
				'serviceRemainderMonths' => 0,
				'serviceRemainderDays' => 0,
				'monthlyWage' => 18876.0,
				'monthlyWageParts' => ['base' => 17160.0, 'holidayAllowance' => 1716.0, 'variableAverage' => 0.0, 'variableMonthsRead' => 2],
				'capEur' => 226512.0,
				'capApplied' => false,
				'endDate' => '2025-12-31',
				'ruleId' => 'nl-offboarding-transitievergoeding',
			],
			$result
		);
	}
}
