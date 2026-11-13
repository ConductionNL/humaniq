<?php

/**
 * ChecksPeriodCoverage test
 *
 * Whether a contract covers a wage period decides which employees a payroll
 * run includes, so every edge of the range matters: both period boundaries are
 * inclusive, an open end covers everything from its start, and an
 * unparseable date must not quietly include or exclude somebody.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Support
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

namespace OCA\Humaniq\Tests\Unit\Support;

use OCA\Humaniq\Support\ChecksPeriodCoverage;
use PHPUnit\Framework\TestCase;

/**
 * Period coverage of a start/end date range.
 *
 * @covers \OCA\Humaniq\Support\ChecksPeriodCoverage
 */
class ChecksPeriodCoverageTest extends TestCase {

	/**
	 * A range spanning the whole month covers it, and so does one that starts
	 * or ends exactly on a period boundary: both ends are inclusive.
	 *
	 * @return void
	 */
	public function testARangeTouchingThePeriodCoversIt(): void {
		$covers = $this->subject();

		$this->assertTrue($covers->check('2025-01-01', '2027-01-01', '2026-02'), 'spanning the month');
		$this->assertTrue($covers->check('2026-02-28', '2027-01-01', '2026-02'), 'starting on the last day');
		$this->assertTrue($covers->check('2025-01-01', '2026-02-01', '2026-02'), 'ending on the first day');
		$this->assertTrue($covers->check('2026-02-10', '2026-02-11', '2026-02'), 'a few days inside the month');

	}//end testARangeTouchingThePeriodCoversIt()

	/**
	 * A range entirely before or entirely after the period does not cover it.
	 *
	 * @return void
	 */
	public function testARangeOutsideThePeriodDoesNotCoverIt(): void {
		$covers = $this->subject();

		$this->assertFalse($covers->check('2026-03-01', '2026-12-31', '2026-02'), 'starting after the month');
		$this->assertFalse($covers->check('2025-01-01', '2026-01-31', '2026-02'), 'ending before the month');

	}//end testARangeOutsideThePeriodDoesNotCoverIt()

	/**
	 * An end date that is blank, whitespace or absent means the range is still
	 * open, so it covers every period from its start onward.
	 *
	 * @return void
	 */
	public function testAnOpenEndCoversEveryPeriodFromItsStart(): void {
		$covers = $this->subject();

		$this->assertTrue($covers->check('2025-01-01', '', '2026-02'));
		$this->assertTrue($covers->check('2025-01-01', '   ', '2026-02'));
		$this->assertFalse($covers->check('2026-03-01', '', '2026-02'), 'still not before it started');

	}//end testAnOpenEndCoversEveryPeriodFromItsStart()

	/**
	 * An unparseable END date is treated as open rather than as "before the
	 * period": an employee is not dropped from a run over a malformed field.
	 *
	 * @return void
	 */
	public function testAnUnparseableEndDateIsTreatedAsOpen(): void {
		$this->assertTrue($this->subject()->check('2025-01-01', 'onbekend', '2026-02'));

	}//end testAnUnparseableEndDateIsTreatedAsOpen()

	/**
	 * An unparseable START date covers nothing: without a start there is no
	 * evidence the range had begun, so the range is not counted.
	 *
	 * @return void
	 */
	public function testAnUnparseableStartDateCoversNothing(): void {
		$covers = $this->subject();

		$this->assertFalse($covers->check('onbekend', '2027-01-01', '2026-02'));
		$this->assertFalse($covers->check('', '2027-01-01', '2026-02'));

	}//end testAnUnparseableStartDateCoversNothing()

	/**
	 * A period string the calendar cannot read at all covers nothing: the
	 * month to compare against cannot be built, so no range is accepted
	 * against it.
	 *
	 * @return void
	 */
	public function testAnUnreadablePeriodCoversNothing(): void {
		$covers = $this->subject();

		foreach (['februari', '2026-13', '2026-99'] as $period) {
			$this->assertFalse(
				$covers->check('2025-01-01', '2027-01-01', $period),
				'period "' . $period . '" cannot be read as a month'
			);
		}

	}//end testAnUnreadablePeriodCoversNothing()

	/**
	 * A period that carries a day as well is not read as the whole month: the
	 * window runs from that day to the month's end, because only the end of
	 * the month is derived while the start stays as given. A caller handing
	 * this method a date instead of a `YYYY-MM` period therefore asks a
	 * narrower question than it looks like it is asking.
	 *
	 * @return void
	 */
	public function testAPeriodCarryingADayRunsFromThatDayToTheMonthsEnd(): void {
		$covers = $this->subject();

		$this->assertTrue($covers->check('2026-02-20', '2026-02-25', '2026-02-15'), 'inside the 15th-to-28th window');
		$this->assertFalse($covers->check('2026-02-01', '2026-02-05', '2026-02-15'), 'early February is before the window');

	}//end testAPeriodCarryingADayRunsFromThatDayToTheMonthsEnd()

	/**
	 * A blank period falls back to whatever month the calendar is in, rather
	 * than being refused. That is clock-dependent, so the only thing asserted
	 * is the range-independent part: a range spanning every plausible month
	 * covers it.
	 *
	 * @return void
	 */
	public function testABlankPeriodFallsBackToTheCurrentMonth(): void {
		$this->assertTrue($this->subject()->check('2000-01-01', '2100-01-01', ''));

	}//end testABlankPeriodFallsBackToTheCurrentMonth()

	/**
	 * A February in a leap year keeps its 29th as the last day, so a contract
	 * starting then still covers the month.
	 *
	 * @return void
	 */
	public function testALeapFebruaryKeepsItsTwentyNinth(): void {
		$covers = $this->subject();

		$this->assertTrue($covers->check('2024-02-29', '2027-01-01', '2024-02'));
		$this->assertFalse($covers->check('2025-03-01', '2027-01-01', '2025-02'), 'a non-leap February ends on the 28th');

	}//end testALeapFebruaryKeepsItsTwentyNinth()

	/**
	 * A holder exposing the trait's private check.
	 *
	 * @return object
	 */
	private function subject(): object {
		return new class {

			use ChecksPeriodCoverage;

			/**
			 * @param string $startDate ISO start date.
			 * @param string $endDate   ISO end date, or '' while open.
			 * @param string $period    Wage period (YYYY-MM).
			 *
			 * @return bool
			 */
			public function check(string $startDate, string $endDate, string $period): bool {
				return $this->coversPeriod($startDate, $endDate, $period);
			}//end check()

		};

	}//end subject()

}//end class
