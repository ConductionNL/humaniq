<?php

/**
 * PensionBaseTest
 *
 * The pension base of design D2 for a full-timer, a part-timer, a salary
 * above the maximum and a wage under the franchise.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Pension
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
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Pension;

use OCA\Humaniq\Payroll\Pension\PensionBase;
use PHPUnit\Framework\TestCase;

/**
 * The pension figures of one employee for one month.
 *
 * @covers \OCA\Humaniq\Payroll\Pension\PensionBase
 *
 * @spec openspec/changes/filings-pension-upa-message/specs/pension-upa-message/spec.md#REQ-PUM-001
 */
class PensionBaseTest extends TestCase {

	/**
	 * A scheme with a franchise of 12,000 and a maximum of 120,000 a year:
	 * 1,000 and 10,000 a month.
	 *
	 * @var array<string, mixed>
	 */
	private const SCHEME = [
		'franchise' => 12000,
		'maxPensionableSalary' => 120000,
		'employerPercentage' => 20,
		'employeePercentage' => 7.5,
	];

	/**
	 * A full timer pays over the wage above the whole franchise.
	 *
	 * @return void
	 */
	public function testAFullTimerPaysOverTheWageAboveTheWholeFranchise(): void {
		$this->assertSame(
			[
				'partTimeFactor' => 1.0,
				'pensionableWage' => 4000.0,
				'pensionBase' => 3000.0,
				'employerContribution' => 600.0,
				'employeeContribution' => 225.0,
			],
			PensionBase::of(wage: 4000.0, hoursPerWeek: 40.0, fullTimeHoursWeek: 40.0, scheme: self::SCHEME)
		);
	}

	/**
	 * A part timer has the franchise in proportion to their hours.
	 *
	 * @return void
	 */
	public function testAPartTimerHasTheFranchiseInProportionToTheirHours(): void {
		$figures = PensionBase::of(wage: 2400.0, hoursPerWeek: 24.0, fullTimeHoursWeek: 40.0, scheme: self::SCHEME);

		$this->assertSame(0.6, $figures['partTimeFactor']);
		$this->assertSame(2400.0, $figures['pensionableWage']);
		$this->assertSame(1800.0, $figures['pensionBase']);
		$this->assertSame(360.0, $figures['employerContribution']);
		$this->assertSame(135.0, $figures['employeeContribution']);
	}

	/**
	 * A salary above the maximum is capped before the franchise comes off.
	 *
	 * @return void
	 */
	public function testASalaryAboveTheMaximumIsCappedBeforeTheFranchiseComesOff(): void {
		$figures = PensionBase::of(wage: 15000.0, hoursPerWeek: 40.0, fullTimeHoursWeek: 40.0, scheme: self::SCHEME);

		$this->assertSame(10000.0, $figures['pensionableWage']);
		$this->assertSame(9000.0, $figures['pensionBase']);
		$this->assertSame(1800.0, $figures['employerContribution']);
	}

	/**
	 * A wage under the franchise has no base and no contribution.
	 *
	 * @return void
	 */
	public function testAWageUnderTheFranchiseHasNoBaseAndNoContribution(): void {
		$figures = PensionBase::of(wage: 800.0, hoursPerWeek: 40.0, fullTimeHoursWeek: 40.0, scheme: self::SCHEME);

		$this->assertSame(0.0, $figures['pensionBase']);
		$this->assertSame(0.0, $figures['employerContribution']);
		$this->assertSame(0.0, $figures['employeeContribution']);
	}

	/**
	 * More hours than a full time week count as full time.
	 *
	 * @return void
	 */
	public function testMoreHoursThanAFullTimeWeekCountAsFullTime(): void {
		$this->assertSame(1.0, PensionBase::of(wage: 4000.0, hoursPerWeek: 45.0, fullTimeHoursWeek: 40.0, scheme: self::SCHEME)['partTimeFactor']);
	}

	/**
	 * A missing figure counts as zero.
	 *
	 * @return void
	 */
	public function testAMissingFigureCountsAsZero(): void {
		$figures = PensionBase::of(wage: 3000.0, hoursPerWeek: 40.0, fullTimeHoursWeek: 40.0, scheme: ['maxPensionableSalary' => 120000, 'franchise' => 'n/a']);

		$this->assertSame(3000.0, $figures['pensionBase']);
		$this->assertSame(0.0, $figures['employerContribution']);
	}

	/**
	 * No hours per week has no part time factor.
	 *
	 * @return void
	 */
	public function testNoHoursPerWeekHasNoPartTimeFactor(): void {
		$this->expectException(\InvalidArgumentException::class);

		PensionBase::of(wage: 3000.0, hoursPerWeek: 0.0, fullTimeHoursWeek: 40.0, scheme: self::SCHEME);
	}
}//end class
