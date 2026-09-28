<?php

/**
 * Unit tests for DepartmentFigures: the absence frequency and the wage cost
 * of a set of people.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\DepartmentFigures;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DepartmentFigures.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
class DepartmentFiguresTest extends TestCase {

	/**
	 * Hand count: four people for all of 2025 and two sick reports in it is
	 * a frequency of 0.5 reports per employee per year. A report of someone
	 * outside the set, and one outside the window, do not count.
	 *
	 * @return void
	 */
	public function testFrequencyIsReportsPerEmployeePerYear(): void {
		$figures = new DepartmentFigures();
		$shares = ['a' => 1.0, 'b' => 1.0, 'c' => 1.0, 'd' => 1.0];
		$cases = [
			['employeeId' => 'a', 'firstSickDay' => '2025-02-03'],
			['employeeId' => 'b', 'firstSickDay' => '2025-11-17'],
			['employeeId' => 'x', 'firstSickDay' => '2025-05-05'],
			['employeeId' => 'a', 'firstSickDay' => '2024-12-30'],
		];

		$frequency = $figures->frequency($cases, $shares, new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'));

		$this->assertSame(0.5, $frequency);
	}//end testFrequencyIsReportsPerEmployeePerYear()

	/**
	 * A month is annualised: one report among two people in a 30-day month
	 * is about 6.08 reports per employee per year.
	 *
	 * @return void
	 */
	public function testAMonthIsAnnualised(): void {
		$figures = new DepartmentFigures();

		$frequency = $figures->frequency(
			[['employeeId' => 'a', 'firstSickDay' => '2026-06-10']],
			['a' => 1.0, 'b' => 1.0],
			new DateTimeImmutable('2026-06-01'),
			new DateTimeImmutable('2026-06-30')
		);

		$this->assertSame(6.08, $frequency);
	}//end testAMonthIsAnnualised()

	/**
	 * A period with nobody in it has no frequency: null, not zero.
	 *
	 * @return void
	 */
	public function testNoMembersIsNullNotZero(): void {
		$figures = new DepartmentFigures();

		$frequency = $figures->frequency([], [], new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'));

		$this->assertNull($frequency);
	}//end testNoMembersIsNullNotZero()

	/**
	 * The wage cost of a set is its payslips' gross plus the run's employer
	 * charge share, weighted by the share of the month each person counts;
	 * a draft run and another month do not count.
	 *
	 * @return void
	 */
	public function testWageCostAppliesTheRunsEmployerChargeRatio(): void {
		$figures = new DepartmentFigures();
		$runs = [
			['id' => 'run-june', 'period' => '2026-06', 'status' => 'posted', 'totalGross' => 10000.0, 'totalEmployerCharges' => 2500.0],
			['id' => 'run-draft', 'period' => '2026-06', 'status' => 'draft', 'totalGross' => 5000.0, 'totalEmployerCharges' => 1000.0],
			['id' => 'run-may', 'period' => '2026-05', 'status' => 'paid', 'totalGross' => 10000.0, 'totalEmployerCharges' => 2500.0],
		];
		$payslips = [
			['employeeId' => 'a', 'payrollRunId' => 'run-june', 'grossPay' => 4000.0],
			['employeeId' => 'b', 'payrollRunId' => 'run-june', 'grossPay' => 6000.0],
			['employeeId' => 'a', 'payrollRunId' => 'run-draft', 'grossPay' => 5000.0],
			['employeeId' => 'a', 'payrollRunId' => 'run-may', 'grossPay' => 4000.0],
		];

		$this->assertSame(5000.0, $figures->wageCost($runs, $payslips, ['a' => 1.0], '2026-06'));
		$this->assertSame(8750.0, $figures->wageCost($runs, $payslips, ['a' => 1.0, 'b' => 0.5], '2026-06'));
		$this->assertSame(12500.0, $figures->wageCost($runs, $payslips, null, '2026-06'));
		$this->assertNull($figures->wageCost($runs, $payslips, ['c' => 1.0], '2026-06'));
		$this->assertNull($figures->wageCost($runs, $payslips, null, '2026-04'));
	}//end testWageCostAppliesTheRunsEmployerChargeRatio()

}//end class
