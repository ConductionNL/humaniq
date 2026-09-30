<?php

/**
 * Golden fixtures for the statutory transition payment (BW 7:673), each
 * computed by hand in the docblock of its test.
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
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\TransitionPaymentCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The calculator over stored inputs.
 */
class TransitionPaymentCalculatorTest extends TestCase {

	private const PARAMETERS = [
		'wageFractionPerServiceYear' => '1/3',
		'capEur' => 102000,
		'dismissalInitiatedReasons' => ['opzegging-werkgever', 'einde-contract'],
	];

	/**
	 * A contract row.
	 *
	 * @param string      $start The start date.
	 * @param string|null $end   The end date.
	 * @param string      $type  The type.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(string $start, ?string $end, string $type='permanent'): array {
		return ['id' => 'c-' . $start, 'type' => $type, 'startDate' => $start, 'endDate' => $end, 'hoursPerWeek' => 36, 'hourlyWage' => 25.0];
	}//end contract()

	/**
	 * Twelve payslips of one gross amount at 8% holiday allowance.
	 *
	 * @param float $grossPay The gross pay each month.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function payslips(float $grossPay): array {
		$rows = [];
		for ($month = 1; $month <= 12; $month++) {
			$rows[] = ['period' => sprintf('2025-%02d', $month), 'grossPay' => $grossPay, 'vakantiegeldRate' => 0.08];
		}

		return $rows;
	}//end payslips()

	/**
	 * Calculate.
	 *
	 * @param float                             $salary    The base monthly salary.
	 * @param array<int, array<string, mixed>>  $contracts The contracts.
	 * @param array<int, array<string, mixed>>  $payslips  The payslips.
	 * @param string                            $endDate   The last working day.
	 * @param string                            $reason    The departure reason.
	 *
	 * @return array<string, mixed>
	 */
	private function calc(float $salary, array $contracts, array $payslips, string $endDate, string $reason='opzegging-werkgever'): array {
		return (new TransitionPaymentCalculator())->calculate(
			employee: ['grossMonthlySalary' => $salary, 'startDate' => ($contracts[0]['startDate'] ?? '2020-01-01')],
			contracts: $contracts,
			payslips: $payslips,
			parameters: self::PARAMETERS,
			endDate: $endDate,
			reason: $reason
		);
	}//end calc()

	/**
	 * Seven years, 4,000 base plus 8% = 4,320 a month: 4,320 / 3 x 7 = 10,080.00.
	 *
	 * @return void
	 */
	public function testSevenYearsOfService(): void {
		$result = $this->calc(4000.0, [$this->contract('2019-01-01', null)], $this->payslips(4000.0), '2025-12-31');

		self::assertSame(10080.00, $result['amountEur']);
		self::assertSame(7, $result['serviceYears']);
		self::assertSame(0, $result['serviceRemainderMonths']);
		self::assertSame('2019-01-01', $result['serviceStart']);
		self::assertSame(4320.00, $result['monthlyWage']);
		self::assertSame(['base' => 4000.00, 'holidayAllowance' => 320.00, 'variableAverage' => 0.00, 'variableMonthsRead' => 12], $result['monthlyWageParts']);
		self::assertFalse($result['capApplied']);
		self::assertSame('nl-offboarding-transitievergoeding', $result['ruleId']);
	}//end testSevenYearsOfService()

	/**
	 * A four-month gap joins the contracts, and only the contract months count:
	 * 12 months + 2019-05-01..2025-12-31 (6 y 8 m) = 7 y 8 m.
	 * 3,000 + 240 = 3,240 a month; 1,080 x 7 + 1,080 x 8/12 = 7,560 + 720 = 8,280.00.
	 *
	 * @return void
	 */
	public function testAFourMonthGapJoinsTheChain(): void {
		$result = $this->calc(3000.0, [$this->contract('2018-01-01', '2018-12-31', 'temporary'), $this->contract('2019-05-01', null)], $this->payslips(3000.0), '2025-12-31');

		self::assertSame('2018-01-01', $result['serviceStart']);
		self::assertSame(7, $result['serviceYears']);
		self::assertSame(8, $result['serviceRemainderMonths']);
		self::assertSame(8280.00, $result['amountEur']);
	}//end testAFourMonthGapJoinsTheChain()

	/**
	 * An eight-month gap restarts the count at the current contract:
	 * 2017-09-01..2025-12-31 = 8 y 4 m; 1,080 x 8 + 1,080 x 4/12 = 8,640 + 360 = 9,000.00.
	 *
	 * @return void
	 */
	public function testAnEightMonthGapRestartsTheCount(): void {
		$result = $this->calc(3000.0, [$this->contract('2016-01-01', '2016-12-31', 'temporary'), $this->contract('2017-09-01', null)], $this->payslips(3000.0), '2025-12-31');

		self::assertSame('2017-09-01', $result['serviceStart']);
		self::assertSame(8, $result['serviceYears']);
		self::assertSame(4, $result['serviceRemainderMonths']);
		self::assertSame(9000.00, $result['amountEur']);
	}//end testAnEightMonthGapRestartsTheCount()

	/**
	 * 45 years at 7,000 + 560 = 7,560: 2,520 x 45 = 113,400; the annual
	 * salary 90,720 is below 102,000, so the cap is 102,000.00.
	 *
	 * @return void
	 */
	public function testTheCapApplies(): void {
		$result = $this->calc(7000.0, [$this->contract('1980-01-01', null)], $this->payslips(7000.0), '2024-12-31');

		self::assertSame(102000.00, $result['amountEur']);
		self::assertTrue($result['capApplied']);
		self::assertSame(102000.00, $result['capEur']);
	}//end testTheCapApplies()

	/**
	 * 40 years at 8,000 + 640 = 8,640: 2,880 x 40 = 115,200; the annual salary
	 * 103,680 is above 102,000, so it is the cap: 103,680.00.
	 *
	 * @return void
	 */
	public function testAnAnnualSalaryAboveTheCapIsTheCap(): void {
		$result = $this->calc(8000.0, [$this->contract('1985-01-01', null)], $this->payslips(8000.0), '2024-12-31');

		self::assertSame(103680.00, $result['amountEur']);
		self::assertTrue($result['capApplied']);
		self::assertSame(103680.00, $result['capEur']);
	}//end testAnAnnualSalaryAboveTheCapIsTheCap()

	/**
	 * Variable pay is the average gross above the base over the payslips read:
	 * 3,000 + 240 + 300 = 3,540 a month; 1,180 x 2 = 2,360.00 for two years.
	 *
	 * @return void
	 */
	public function testVariablePayIsAveraged(): void {
		$result = $this->calc(3000.0, [$this->contract('2024-01-01', null)], $this->payslips(3300.0), '2025-12-31');

		self::assertSame(['base' => 3000.00, 'holidayAllowance' => 240.00, 'variableAverage' => 300.00, 'variableMonthsRead' => 12], $result['monthlyWageParts']);
		self::assertSame(2360.00, $result['amountEur']);
	}//end testVariablePayIsAveraged()

	/**
	 * A voluntary leaver gets zero with the reason.
	 *
	 * @return void
	 */
	public function testAVoluntaryLeaverGetsNothing(): void {
		$result = $this->calc(4000.0, [$this->contract('2019-01-01', null)], $this->payslips(4000.0), '2025-12-31', 'opzegging-werknemer');

		self::assertSame(0.00, $result['amountEur']);
		self::assertSame('The departure was not initiated by the employer, so no transition payment is due.', $result['reason']);
	}//end testAVoluntaryLeaverGetsNothing()

	/**
	 * Without a cap in the rule parameters the calculator refuses rather than
	 * inventing one.
	 *
	 * @return void
	 */
	public function testAMissingCapIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new TransitionPaymentCalculator())->calculate(
			employee: ['grossMonthlySalary' => 4000.0],
			contracts: [$this->contract('2019-01-01', null)],
			payslips: [],
			parameters: ['dismissalInitiatedReasons' => ['opzegging-werkgever']],
			endDate: '2025-12-31',
			reason: 'opzegging-werkgever'
		);
	}//end testAMissingCapIsRefused()

}//end class
