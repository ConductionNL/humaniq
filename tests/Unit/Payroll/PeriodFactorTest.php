<?php

/**
 * Period factor tests
 *
 * Pins PeriodFactor: the calendar-day share of a period for starters,
 * leavers and whole periods, and the cents scaling.
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
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-partial-period/spec.md#REQ-PPR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use DateTimeImmutable;
use OCA\Humaniq\Payroll\PayPeriod;
use OCA\Humaniq\Payroll\PeriodFactor;
use PHPUnit\Framework\TestCase;

/**
 * The calendar-day factor (payroll-engine-port-from-shillinq O2).
 */
class PeriodFactorTest extends TestCase {

	/**
	 * The factor of an employment in a period id.
	 *
	 * @param string $start  The employment start.
	 * @param string $end    The employment end, or ''.
	 * @param string $period The period id.
	 *
	 * @return PeriodFactor
	 */
	private function factor(string $start, string $end, string $period): PeriodFactor {
		$pay = PayPeriod::parse($period);

		return PeriodFactor::of(startDate: $start, endDate: $end, periodStart: $pay->firstDay, periodEnd: $pay->lastDay);
	}//end factor()

	/**
	 * A starter on 16 June: 15 of 30 days, 3,000.00 becomes 1,500.00.
	 *
	 * @return void
	 */
	public function testAStarterOnTheSixteenthOfJuneGetsHalf(): void {
		$factor = $this->factor('2026-06-16', '', '2026-06');

		$this->assertTrue($factor->isPartial());
		$this->assertSame(0.5, $factor->factor);
		$this->assertSame(150000, $factor->scale(300000));
		$this->assertSame('2026-06-16', $factor->employedFrom);
		$this->assertSame('2026-06-30', $factor->employedUntil);
	}//end testAStarterOnTheSixteenthOfJuneGetsHalf()

	/**
	 * A leaver on 10 March: 10 of 31 days, scaled on the exact share.
	 *
	 * @return void
	 */
	public function testALeaverOnTheTenthOfMarch(): void {
		$factor = $this->factor('2020-01-01', '2026-03-10', '2026-03');

		$this->assertSame(0.3226, $factor->factor);
		$this->assertSame(100000, $factor->scale(310000));
		$this->assertSame('2026-03-01', $factor->employedFrom);
		$this->assertSame('2026-03-10', $factor->employedUntil);
	}//end testALeaverOnTheTenthOfMarch()

	/**
	 * A whole month is factor 1 and scales nothing.
	 *
	 * @return void
	 */
	public function testAWholeMonthIsUnchanged(): void {
		$factor = $this->factor('2022-01-01', '', '2026-05');

		$this->assertFalse($factor->isPartial());
		$this->assertSame(1.0, $factor->factor);
		$this->assertSame(380000, $factor->scale(380000));
	}//end testAWholeMonthIsUnchanged()

	/**
	 * Starting and leaving in the same week counts the days between, both included.
	 *
	 * @return void
	 */
	public function testAStartAndEndInOneWeek(): void {
		$factor = PeriodFactor::of(startDate: '2026-03-03', endDate: '2026-03-05T00:00:00Z', periodStart: new DateTimeImmutable('2026-03-02'), periodEnd: new DateTimeImmutable('2026-03-08'));

		$this->assertSame(3, $factor->daysEmployed);
		$this->assertSame(7, $factor->daysInPeriod);
		$this->assertSame(0.4286, $factor->factor);
	}//end testAStartAndEndInOneWeek()

	/**
	 * Unreadable or empty dates never lower the pay.
	 *
	 * @return void
	 */
	public function testUnreadableDatesCountAsThePeriodEdges(): void {
		$factor = $this->factor('not a date', '', '2026-02');

		$this->assertFalse($factor->isPartial());
		$this->assertSame('2026-02-01', $factor->employedFrom);
		$this->assertSame('2026-02-28', $factor->employedUntil);
	}//end testUnreadableDatesCountAsThePeriodEdges()

	/**
	 * An employment outside the period is factor 0.
	 *
	 * @return void
	 */
	public function testAnEmploymentOutsideThePeriodIsZero(): void {
		$factor = $this->factor('2026-07-01', '', '2026-06');

		$this->assertSame(0.0, $factor->factor);
		$this->assertSame(0, $factor->scale(300000));
	}//end testAnEmploymentOutsideThePeriodIsZero()
}//end class
