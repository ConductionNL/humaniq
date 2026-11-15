<?php

/**
 * PayPeriod: monthly, four-weekly and weekly period ids, and ISO week 53.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use InvalidArgumentException;
use OCA\Humaniq\Payroll\PayPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayPeriod::class)]
class PayPeriodTest extends TestCase {

	/**
	 * A period's tijdvak, dates, share and week-53 flag in one comparable row.
	 *
	 * @param PayPeriod $period The period.
	 *
	 * @return array<int, mixed>
	 */
	private function row(PayPeriod $period): array {
		return [$period->tijdvak, $period->firstDay->format('Y-m-d'), $period->lastDay->format('Y-m-d'), $period->periodsPerYear(), $period->week53];
	}//end row()

	public function testAMonth(): void {
		$period = PayPeriod::parse('2026-05');

		$this->assertSame(['maand', '2026-05-01', '2026-05-31', 12, false], $this->row($period));
		$this->assertEqualsWithDelta(1 / 12, $period->shareOfYear(), 1e-12);
	}//end testAMonth()

	public function testFebruaryOfALeapYearEndsOnThe29th(): void {
		$this->assertSame('2028-02-29', PayPeriod::parse('2028-02')->lastDay->format('Y-m-d'));
	}//end testFebruaryOfALeapYearEndsOnThe29th()

	public function testFourWeeklyPeriodOneStartsOnTheMondayOfIsoWeekOne(): void {
		// 1 January 2026 is a Thursday, so ISO week 1 starts on Monday 29 December 2025.
		$period = PayPeriod::parse('2026-P01');

		$this->assertSame(['vierweken', '2025-12-29', '2026-01-25', 13, false], $this->row($period));
		$this->assertEqualsWithDelta(4 / 52, $period->shareOfYear(), 1e-12);
	}//end testFourWeeklyPeriodOneStartsOnTheMondayOfIsoWeekOne()

	public function testPeriodThirteenOfAYearWithWeek53HoldsIt(): void {
		$this->assertTrue(PayPeriod::yearHasWeek53(2026));
		// Weeks 49 to 52 are the period's own four-weekly tijdvak; week 53 is flagged for the run.
		$this->assertSame(['vierweken', '2026-11-30', '2026-12-27', 13, true], $this->row(PayPeriod::parse('2026-P13')));
	}//end testPeriodThirteenOfAYearWithWeek53HoldsIt()

	public function testPeriodThirteenOfAYearWithoutWeek53DoesNot(): void {
		$this->assertFalse(PayPeriod::yearHasWeek53(2025));
		$this->assertFalse(PayPeriod::parse('2025-P13')->week53);
	}//end testPeriodThirteenOfAYearWithoutWeek53DoesNot()

	public function testPeriodFourteenIsWeek53AloneAsAOneWeekTijdvak(): void {
		$period = PayPeriod::parse('2026-P14');

		$this->assertSame(['week', '2026-12-28', '2027-01-03', 52, true], $this->row($period));
		$this->assertEqualsWithDelta(1 / 52, $period->shareOfYear(), 1e-12);
	}//end testPeriodFourteenIsWeek53AloneAsAOneWeekTijdvak()

	public function testWeek53(): void {
		$this->assertSame(['week', '2026-12-28', '2027-01-03', 52, true], $this->row(PayPeriod::parse('2026-W53')));
		$this->assertSame(['week', '2026-03-02', '2026-03-08', 52, false], $this->row(PayPeriod::parse('2026-W10')));
	}//end testWeek53()

	public static function refused(): array {
		return [
			'period 14 in a year without week 53' => ['2025-P14', 'has no ISO week 53'],
			'week 53 in a year without one' => ['2025-W53', 'has no ISO week 53'],
			'month 13' => ['2026-13', 'is not a pay period'],
			'month 0' => ['2026-00', 'is not a pay period'],
			'period 0' => ['2026-P00', 'is not a pay period'],
			'period 15' => ['2026-P15', 'is not a pay period'],
			'week 0' => ['2026-W00', 'is not a pay period'],
			'week 54' => ['2026-W54', 'is not a pay period'],
			'lower case p' => ['2026-p05', 'is not a pay period'],
			'a date' => ['2026-05-01', 'is not a pay period'],
			'empty' => ['', 'is not a pay period'],
		];
	}//end refused()

	#[DataProvider('refused')]
	public function testAnIdThatIsNoPeriodIsRefusedByName(string $id, string $reason): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/^"' . preg_quote($id, '/') . '" .*' . preg_quote($reason, '/') . '/');

		PayPeriod::parse($id);
	}//end testAnIdThatIsNoPeriodIsRefusedByName()

	public function testTheIdIsKeptAsGiven(): void {
		$this->assertSame('2026-P05', PayPeriod::parse('2026-P05')->id);
	}//end testTheIdIsKeptAsGiven()
	/**
	 * A monthly administration takes months only; a four-weekly one takes
	 * four-week periods only; a weekly one weeks only. No frequency is monthly.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
	 */
	public function testForFrequencyAcceptsOnlyThatFrequencysPeriods(): void {
		self::assertSame('maand', PayPeriod::forFrequency(id: '2026-05', frequency: 'monthly')->tijdvak);
		self::assertSame('maand', PayPeriod::forFrequency(id: '2026-05', frequency: '')->tijdvak);
		self::assertSame('vierweken', PayPeriod::forFrequency(id: '2026-P05', frequency: 'four-weekly')->tijdvak);
		self::assertSame('week', PayPeriod::forFrequency(id: '2026-W53', frequency: 'weekly')->tijdvak);

		foreach ([['2026-P05', 'monthly'], ['2026-P05', ''], ['2026-05', 'four-weekly'], ['2026-W05', 'four-weekly'], ['2026-05', 'weekly']] as [$id, $frequency]) {
			try {
				PayPeriod::forFrequency(id: $id, frequency: $frequency);
				self::fail($id . ' was accepted for ' . ($frequency === '' ? 'no frequency' : $frequency));
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString('"' . $id . '" is not a ' . ($frequency === '' ? 'monthly' : $frequency) . ' period', $e->getMessage());
			}
		}
	}//end testForFrequencyAcceptsOnlyThatFrequencysPeriods()

	/**
	 * Period 14 exists only for a four-weekly administration that pays week
	 * 53 separately, and only in a year that has a week 53.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-002
	 */
	public function testPeriod14NeedsTheSeparateWeek53Setting(): void {
		$p14 = PayPeriod::forFrequency(id: '2026-P14', frequency: 'four-weekly', week53Handling: 'separate-period-14');
		self::assertSame('week', $p14->tijdvak);
		self::assertTrue($p14->week53);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('"2026-P14" does not exist for this administration: it pays week 53 on the period 13 payslip.');
		PayPeriod::forFrequency(id: '2026-P14', frequency: 'four-weekly');
	}//end testPeriod14NeedsTheSeparateWeek53Setting()

	/**
	 * An unknown frequency is refused by name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
	 */
	public function testAnUnknownFrequencyIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('"daily" is not a pay frequency: use monthly, four-weekly or weekly.');
		PayPeriod::forFrequency(id: '2026-05', frequency: 'daily');
	}//end testAnUnknownFrequencyIsRefused()

}//end class
