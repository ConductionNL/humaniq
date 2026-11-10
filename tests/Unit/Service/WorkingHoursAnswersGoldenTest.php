<?php

/**
 * Golden answers of WorkingHoursService for one day and for a range.
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

use DateTimeImmutable;
use OCA\Humaniq\Service\WorkingHoursService;
use PHPUnit\Framework\TestCase;

/**
 * The whole answer, for every way a day's hours can be reduced.
 */
class WorkingHoursAnswersGoldenTest extends TestCase {

	/**
	 * A 32-hour week, Monday to Thursday, for emp-1 from 2026.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const PATTERNS = [
		['employeeId' => 'emp-2', 'validFrom' => '2026-01-01', 'hoursMonday' => 4],
		['employeeId' => 'emp-1', 'validFrom' => '2026-01-01', 'validUntil' => null, 'hoursMonday' => 8, 'hoursTuesday' => '7.5', 'hoursWednesday' => 8, 'hoursThursday' => 8.5, 'hoursFriday' => 'x'],
	];

	/**
	 * Non-working times: a partial Monday, a partial Tuesday window, a whole
	 * Wednesday window, one without any window, and another employee's.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const NON_WORKING = [
		['employeeId' => 'emp-1', 'recurringWeekday' => 'Monday', 'hoursNotWorked' => 3],
		['employeeId' => 'emp-1', 'recurringWeekday' => 'monday', 'hoursNotWorked' => '2.25'],
		['employeeId' => 'emp-1', 'startDate' => '2026-06-02T00:00:00Z', 'endDate' => '2026-06-02', 'hoursNotWorked' => 10],
		['employeeId' => 'emp-1', 'startDate' => '2026-06-03', 'endDate' => '2026-06-03'],
		['employeeId' => 'emp-1', 'startDate' => '2026-06-03', 'hoursNotWorked' => 1],
		['employeeId' => 'emp-1', 'hoursNotWorked' => 1],
		['employeeId' => 'emp-2', 'recurringWeekday' => 'thursday'],
		['employeeId' => 'emp-1', 'endDate' => '2026-05-31', 'hoursNotWorked' => 1],
	];

	/**
	 * One day's answer.
	 *
	 * @param string             $employeeId The employee.
	 * @param string             $date       The day.
	 * @param array<int, string>|null $dates The calendar's non-working dates.
	 *
	 * @return array<string, mixed>
	 */
	private function on(string $employeeId, string $date, ?array $dates): array {
		return (new WorkingHoursService())->contractedHoursOn(
			employeeId: $employeeId,
			date: new DateTimeImmutable($date),
			patterns: self::PATTERNS,
			nonWorkingTimes: self::NON_WORKING,
			nonWorkingDates: $dates
		);
	}//end on()

	/**
	 * A range's answer.
	 *
	 * @param string             $from  The first day.
	 * @param string             $to    The last day.
	 * @param array<int, string>|null $dates The calendar's non-working dates.
	 *
	 * @return array<string, mixed>
	 */
	private function over(string $from, string $to, ?array $dates): array {
		return (new WorkingHoursService())->contractedHoursOver(
			employeeId: 'emp-1',
			from: new DateTimeImmutable($from),
			to: new DateTimeImmutable($to),
			patterns: self::PATTERNS,
			nonWorkingTimes: self::NON_WORKING,
			nonWorkingDates: $dates
		);
	}//end over()

	/**
	 * Every day of the first week of June 2026, with and without a calendar.
	 *
	 * @return void
	 */
	public function testEachDayOfTheWeek(): void {
		$calendar = ['2026-06-04'];
		$this->assertSame(['hours' => 2.75, 'patternOnly' => false, 'patternFound' => true, 'calendarApplied' => false], $this->on('emp-1', '2026-06-01', $calendar));
		$this->assertSame(['hours' => 0.0, 'patternOnly' => true, 'patternFound' => true, 'calendarApplied' => false], $this->on('emp-1', '2026-06-02', null));
		$this->assertSame(['hours' => 0.0, 'patternOnly' => false, 'patternFound' => true, 'calendarApplied' => false], $this->on('emp-1', '2026-06-03', $calendar));
		$this->assertSame(['hours' => 0.0, 'patternOnly' => false, 'patternFound' => true, 'calendarApplied' => true], $this->on('emp-1', '2026-06-04 13:00', $calendar));
		$this->assertSame(['hours' => 7.5, 'patternOnly' => false, 'patternFound' => true, 'calendarApplied' => false], $this->on('emp-1', '2026-06-04', []));
		$this->assertSame(['hours' => 0.0, 'patternOnly' => true, 'patternFound' => true, 'calendarApplied' => false], $this->on('emp-1', '2026-06-05', null));
		$this->assertSame(['hours' => 0.0, 'patternOnly' => false, 'patternFound' => false, 'calendarApplied' => false], $this->on('emp-9', '2026-06-04', $calendar));
		$this->assertSame(['hours' => 0.0, 'patternOnly' => true, 'patternFound' => false, 'calendarApplied' => false], $this->on('emp-1', '2025-12-31', null));
		$this->assertSame(['hours' => 4.0, 'patternOnly' => true, 'patternFound' => true, 'calendarApplied' => false], $this->on('emp-2', '2026-06-01', null));
	}//end testEachDayOfTheWeek()

	/**
	 * A range counts its days, its worked days and its hours, from midnight
	 * to midnight; a range ending before it starts has no days.
	 *
	 * @return void
	 */
	public function testARange(): void {
		$this->assertSame(['hours' => 10.25, 'patternOnly' => false, 'days' => 7, 'workedDays' => 2], $this->over('2026-06-01 18:00', '2026-06-07 01:00', []));
		$this->assertSame(['hours' => 2.75, 'patternOnly' => false, 'days' => 7, 'workedDays' => 1], $this->over('2026-06-01', '2026-06-07', ['2026-06-04']));
		$this->assertSame(['hours' => 0.0, 'patternOnly' => true, 'days' => 0, 'workedDays' => 0], $this->over('2026-06-07', '2026-06-01', null));
	}//end testARange()
}//end class
