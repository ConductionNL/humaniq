<?php

/**
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
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayTransparencyService;
use OCA\Humaniq\Service\Percentile;
use PHPUnit\Framework\TestCase;

/**
 * The gap indicators against a small, hand-computed administration.
 */
class PayTransparencyServiceTest extends TestCase {

	/**
	 * One employee with one 2026 payslip, a contract in a function.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows       The rows to add to.
	 * @param string                                          $id         The employee id.
	 * @param string                                          $gender     woman, man or ''.
	 * @param float                                           $hourly     Hourly pay.
	 * @param string                                          $function   The normfunctie id.
	 * @param float                                           $salary     The gross monthly salary (0 means equal to the payslip).
	 *
	 * @return void
	 */
	private function person(array &$rows, string $id, string $gender, float $hourly, string $function = 'nf-uit', float $salary = 0.0): void {
		$gross = ($hourly * 100);
		$rows['employees'][] = ['id' => $id, 'gender' => $gender, 'grossMonthlySalary' => ($salary > 0 ? $salary : $gross)];
		$rows['contracts'][] = ['id' => 'c-' . $id, 'employeeId' => $id, 'normfunctieId' => $function, 'hoursPerWeek' => 36, 'startDate' => '2024-01-01'];
		$rows['payslips'][] = ['id' => 'p-' . $id, 'employeeId' => $id, 'period' => '2026-03', 'grossPay' => $gross, 'hoursWorked' => 100];
	}//end person()

	/**
	 * Rows with the three seeded categories.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function rows(): array {
		return [
			'employees' => [],
			'contracts' => [],
			'payslips' => [],
			'normfuncties' => [
				['id' => 'nf-uit', 'payCategory' => 'Uitvoerend'],
				['id' => 'nf-adv', 'payCategory' => 'Adviserend'],
				['id' => 'nf-none'],
			],
		];
	}//end rows()

	/**
	 * Mean and median gap, variable pay and quartiles, by hand: men 30 and 20
	 * an hour (mean 25), women 20 and 15 (mean 17.5), so both gaps are 30%.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
	 */
	public function testTheGapsOfASmallAdministrationByHand(): void {
		$rows = $this->rows();
		$this->person($rows, 'm1', 'man', 30.0, salary: 2500.0);
		$this->person($rows, 'm2', 'man', 20.0);
		$this->person($rows, 'w1', 'woman', 20.0);
		$this->person($rows, 'w2', 'woman', 15.0);

		$report = (new PayTransparencyService(new Percentile()))->report(rows: $rows, year: 2026, threshold: 2);

		$this->assertSame(4, $report['counted']);
		$this->assertSame(false, $report['overall']['tooSmall']);
		$this->assertSame(30.0, $report['overall']['meanGap']);
		$this->assertSame(30.0, $report['overall']['medianGap']);
		$this->assertSame(0.0, $report['overall']['womenReceivingVariable']);
		$this->assertSame(50.0, $report['overall']['menReceivingVariable']);
		$this->assertNull($report['overall']['variableMeanGap'], 'no woman received variable pay, so there is no gap to compute');
		$this->assertSame('Uitvoerend', $report['categories'][0]['category']);
		$this->assertSame(30.0, $report['categories'][0]['meanGap']);
		$this->assertSame(['quartile' => 1, 'women' => 100.0, 'men' => 0.0], $report['quartiles'][0]);
		$this->assertSame(['quartile' => 4, 'women' => 0.0, 'men' => 100.0], $report['quartiles'][3]);
	}//end testTheGapsOfASmallAdministrationByHand()

	/**
	 * A category of four women and twelve men is too small at the default threshold of five.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
	 */
	public function testACategoryOfFourWomenIsTooSmall(): void {
		$rows = $this->rows();
		for ($i = 1; $i <= 4; $i++) {
			$this->person($rows, 'w' . $i, 'woman', 20.0, 'nf-adv');
		}

		for ($i = 1; $i <= 12; $i++) {
			$this->person($rows, 'm' . $i, 'man', 25.0, 'nf-adv');
		}

		$report = (new PayTransparencyService(new Percentile()))->report(rows: $rows, year: 2026, threshold: 5);

		$this->assertSame([['category' => 'Adviserend', 'tooSmall' => true]], $report['categories']);
		$this->assertSame(['tooSmall' => true], $report['overall']);
		$this->assertSame([], $report['quartiles'], 'quartiles of a group too small to report would expose it');
	}//end testACategoryOfFourWomenIsTooSmall()

	/**
	 * Without hoursWorked the contract's hours count, and a function without a
	 * category lands in the uncategorised group, listed last.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
	 */
	public function testHoursFromTheContractAndTheUncategorisedGroup(): void {
		$rows = $this->rows();
		$this->person($rows, 'w1', 'woman', 20.0);
		$this->person($rows, 'm1', 'man', 20.0);
		$this->person($rows, 'w2', 'woman', 10.0, 'nf-none');
		$this->person($rows, 'm2', 'man', 20.0, 'nf-none');
		// 36 h a week is 156 h a month; 3120 over 156 is 20 an hour.
		$rows['payslips'][3] = ['id' => 'p-m2', 'employeeId' => 'm2', 'period' => '2026-04', 'grossPay' => 3120.0];
		$rows['payslips'][] = ['id' => 'p-old', 'employeeId' => 'w2', 'period' => '2025-12', 'grossPay' => 99999.0, 'hoursWorked' => 1];

		$report = (new PayTransparencyService(new Percentile()))->report(rows: $rows, year: 2026, threshold: 1);

		$this->assertSame(['Uitvoerend', ''], array_column($report['categories'], 'category'));
		$this->assertSame(0.0, $report['categories'][0]['meanGap']);
		$this->assertSame(50.0, $report['categories'][1]['meanGap'], 'm2 earns 20 from contract hours, w2 10; the 2025 payslip does not count');
	}//end testHoursFromTheContractAndTheUncategorisedGroup()

	/**
	 * Other and unknown genders count in the totals but not in the gap.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
	 */
	public function testOtherAndUnknownCountInTotalsOnly(): void {
		$rows = $this->rows();
		$this->person($rows, 'w1', 'woman', 18.0);
		$this->person($rows, 'm1', 'man', 20.0);
		$this->person($rows, 'x1', 'other', 99.0);
		$this->person($rows, 'u1', '', 1.0);

		$report = (new PayTransparencyService(new Percentile()))->report(rows: $rows, year: 2026, threshold: 1);

		$this->assertSame([4, 1, 1, 2], [$report['counted'], $report['women'], $report['men'], $report['otherOrUnknown']]);
		$this->assertSame(10.0, $report['overall']['meanGap']);
	}//end testOtherAndUnknownCountInTotalsOnly()

}//end class
