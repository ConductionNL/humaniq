<?php

/**
 * CaoComponentCalculator: the component lines of one payslip.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CaoComponentCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Percentages of wage, fixed amounts and premiums per hour in a window.
 */
class CaoComponentCalculatorTest extends TestCase {

	/**
	 * Weekdays.
	 *
	 * @var list<string>
	 */
	private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

	/**
	 * A night premium of 40% from 00:00 to 06:00 on weekdays, 100% on a public holiday.
	 *
	 * @return array<string, mixed>
	 */
	private static function night(): array {
		return ['key' => 'nachttoeslag', 'kind' => 'hourly-surcharge', 'windows' => [['days' => self::WEEKDAYS, 'from' => '00:00', 'to' => '06:00', 'pct' => 40.0]], 'holidayPct' => 100.0, 'source' => 'cao'];
	}//end night()

	/**
	 * A 13.3% shift allowance on a wage of 3000.00 is 399.00.
	 *
	 * @return void
	 */
	public function testAShiftAllowanceIsAPercentageOfWage(): void {
		$result = (new CaoComponentCalculator())->compute(
			components: [['key' => 'ploegentoeslag', 'kind' => 'percentage-of-wage', 'pct' => 13.3, 'source' => 'cao']],
			regularWageCents: 300000,
			hourlyRate: null,
			entries: []
		);

		$this->assertSame(39900, $result['totalCents']);
		$this->assertSame(['key' => 'ploegentoeslag', 'kind' => 'percentage-of-wage', 'basis' => '13.3% of 3000.00', 'hours' => null, 'pct' => 13.3, 'amountCents' => 39900, 'source' => 'cao'], $result['lines'][0]);
	}//end testAShiftAllowanceIsAPercentageOfWage()

	/**
	 * A Tuesday 22:00 to Wednesday 06:00 span earns 6 premium hours at 40%
	 * (48.00 at 20.00 an hour); the two hours before midnight earn nothing
	 * from that window.
	 *
	 * @return void
	 */
	public function testANightSpanCrossingMidnight(): void {
		$result = (new CaoComponentCalculator())->compute(
			components: [self::night()],
			regularWageCents: 0,
			hourlyRate: 20.0,
			entries: [['startedAt' => '2026-05-12T22:00:00+02:00', 'endedAt' => '2026-05-13T06:00:00+02:00', 'breakMinutes' => 0, 'hours' => 8]]
		);

		$this->assertCount(1, $result['lines']);
		$this->assertSame(6.0, $result['lines'][0]['hours']);
		$this->assertSame(40.0, $result['lines'][0]['pct']);
		$this->assertSame(4800, $result['lines'][0]['amountCents']);
		$this->assertSame(4800, $result['totalCents']);
	}//end testANightSpanCrossingMidnight()

	/**
	 * A window crossing midnight (22:00 to 06:00 on the start day) pays the
	 * whole night; a break is taken off the end of the span.
	 *
	 * @return void
	 */
	public function testAWindowCrossingMidnightAndABreak(): void {
		$component = ['key' => 'ort', 'kind' => 'hourly-surcharge', 'windows' => [['days' => ['tuesday'], 'from' => '22:00', 'to' => '06:00', 'pct' => 50.0]], 'holidayPct' => null, 'source' => 'cao'];
		$result = (new CaoComponentCalculator())->compute(
			components: [$component],
			regularWageCents: 0,
			hourlyRate: 20.0,
			entries: [['startedAt' => '2026-05-12T22:00:00+02:00', 'endedAt' => '2026-05-13T07:00:00+02:00', 'breakMinutes' => 60, 'hours' => 8]]
		);

		$this->assertSame(8.0, $result['lines'][0]['hours']);
		$this->assertSame(8000, $result['totalCents']);
	}//end testAWindowCrossingMidnightAndABreak()

	/**
	 * Hours on a public holiday earn the holiday percentage.
	 *
	 * @return void
	 */
	public function testAHolidayEarnsTheHolidayPercentage(): void {
		$result = (new CaoComponentCalculator())->compute(
			components: [self::night()],
			regularWageCents: 0,
			hourlyRate: 20.0,
			entries: [['startedAt' => '2026-05-14T09:00:00+02:00', 'endedAt' => '2026-05-14T13:00:00+02:00', 'breakMinutes' => 0, 'hours' => 4]],
			nonWorkingDates: ['2026-05-14']
		);

		$this->assertSame(4.0, $result['lines'][0]['hours']);
		$this->assertSame(100.0, $result['lines'][0]['pct']);
		$this->assertSame('public holiday', $result['lines'][0]['basis']);
		$this->assertSame(8000, $result['totalCents']);
	}//end testAHolidayEarnsTheHolidayPercentage()

	/**
	 * An entry without times earns no premium and is listed as no-times.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutTimesIsListed(): void {
		$result = (new CaoComponentCalculator())->compute(
			components: [self::night()],
			regularWageCents: 0,
			hourlyRate: 20.0,
			entries: [['date' => '2026-05-12', 'hours' => 7.5], ['startedAt' => '2026-05-12T09:00:00+02:00', 'endedAt' => '2026-05-12T17:00:00+02:00', 'hours' => 8]]
		);

		$this->assertSame(0, $result['totalCents']);
		$this->assertCount(1, $result['lines']);
		$this->assertSame('no-times', $result['lines'][0]['basis']);
		$this->assertSame(7.5, $result['lines'][0]['hours']);
	}//end testAnEntryWithoutTimesIsListed()

	/**
	 * A fixed monthly amount is paid pro rata for a part month, a minimum
	 * lifts a small percentage, and a premium without an hourly rate is
	 * listed without an amount.
	 *
	 * @return void
	 */
	public function testFixedAmountsMinimumsAndNoRate(): void {
		$calculator = new CaoComponentCalculator();
		$fixed = $calculator->compute(components: [['key' => 'bhv', 'kind' => 'fixed-monthly', 'amountCents' => 5000, 'source' => 'cao']], regularWageCents: 300000, hourlyRate: null, entries: [], monthFraction: 0.5);
		$this->assertSame(2500, $fixed['totalCents']);

		$minimum = $calculator->compute(components: [['key' => 'ikb', 'kind' => 'percentage-of-wage', 'pct' => 16.5, 'minAmountCents' => 45200, 'source' => 'cao']], regularWageCents: 200000, hourlyRate: null, entries: []);
		$this->assertSame(45200, $minimum['totalCents']);

		$noRate = $calculator->compute(components: [self::night()], regularWageCents: 0, hourlyRate: null, entries: [['startedAt' => '2026-05-13T01:00:00+02:00', 'endedAt' => '2026-05-13T03:00:00+02:00', 'hours' => 2]]);
		$this->assertSame(0, $noRate['totalCents']);
		$this->assertSame('no-hourly-rate', $noRate['lines'][0]['basis']);
	}//end testFixedAmountsMinimumsAndNoRate()

}//end class
