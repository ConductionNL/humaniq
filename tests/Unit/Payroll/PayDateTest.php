<?php

/**
 * Tests for PayDate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\PayDate;
use PHPUnit\Framework\TestCase;

/**
 * The pay date of a monthly wage period.
 *
 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-001
 */
class PayDateTest extends TestCase {

	/**
	 * A month is paid on the administration's pay day.
	 *
	 * @return void
	 */
	public function testAMonthIsPaidOnThePayDay(): void {
		$this->assertSame('2026-11-25', (new PayDate())->forPeriod('2026-11', 25));
		$this->assertSame('2026-11-05', (new PayDate())->forPeriod('2026-11', '5'));
	}//end testAMonthIsPaidOnThePayDay()

	/**
	 * In a shorter month the last day is the pay day.
	 *
	 * @return void
	 */
	public function testAShorterMonthPaysOnItsLastDay(): void {
		$this->assertSame('2026-02-28', (new PayDate())->forPeriod('2026-02', 31));
		$this->assertSame('2028-02-29', (new PayDate())->forPeriod('2028-02', 30));
		$this->assertSame('2026-04-30', (new PayDate())->forPeriod('2026-04', 31));
	}//end testAShorterMonthPaysOnItsLastDay()

	/**
	 * No pay day, or one that is no day of a month, means no pay date.
	 *
	 * @return void
	 */
	public function testWithoutAUsablePayDayThereIsNoPayDate(): void {
		foreach ([null, '', 0, 32, -1, '25.5', 25.5, 'vijf', true] as $day) {
			$this->assertNull((new PayDate())->forPeriod('2026-11', $day), var_export($day, true));
		}
	}//end testWithoutAUsablePayDayThereIsNoPayDate()

	/**
	 * A four-week period or a week has no day of the month to pay on.
	 *
	 * @return void
	 */
	public function testOnlyACalendarMonthHasAPayDate(): void {
		foreach (['2026-P13', '2026-W53', '2026-13', '2026-00', '26-11', ''] as $period) {
			$this->assertNull((new PayDate())->forPeriod($period, 25), $period);
		}
	}//end testOnlyACalendarMonthHasAPayDate()

}//end class
