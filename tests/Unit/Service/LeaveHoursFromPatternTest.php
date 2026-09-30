<?php

/**
 * A leave request costs what the person would have worked: the working
 * pattern per day, their non-working times, and the days openregister's
 * working calendar marks non-working. Each cost states its basis.
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
 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-001
 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\LeaveAllocationCalculator;
use OCA\Humaniq\Service\LeaveHoursCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pattern, calendar, and the stated basis.
 */
class LeaveHoursFromPatternTest extends TestCase {

	private const EMPLOYEE = 'emp-part';

	/**
	 * A Monday to Wednesday pattern of eight hours a day.
	 *
	 * @return array<string, mixed>
	 */
	private static function monToWed(): array {
		return [
			'employeeId' => self::EMPLOYEE,
			'validFrom' => '2026-01-01',
			'hoursMonday' => 8,
			'hoursTuesday' => 8,
			'hoursWednesday' => 8,
			'hoursThursday' => 0,
			'hoursFriday' => 0,
			'hoursSaturday' => 0,
			'hoursSunday' => 0,
		];
	}//end monToWed()

	/**
	 * A request of this employee.
	 *
	 * @param string $start The first day.
	 * @param string $end   The last day.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(string $start, string $end): array {
		return ['id' => 'lr-1', 'employeeId' => self::EMPLOYEE, 'leaveType' => 'holiday', 'status' => 'approved', 'startDate' => $start, 'endDate' => $end];
	}//end request()

	/**
	 * Monday and Tuesday cost 16 hours for a Monday to Wednesday worker, not
	 * 9.6; Thursday and Friday, days they never work, cost nothing.
	 *
	 * @return void
	 */
	public function testThePatternDecidesTheHours(): void {
		$time = ['patterns' => [self::monToWed()], 'nonWorkingTimes' => [], 'nonWorkingDates' => []];

		$monTue = LeaveHoursCalculator::requestHours(self::request('2026-03-02', '2026-03-03'), 24.0, 2026, $time);
		self::assertSame(16.0, $monTue['hours']);
		self::assertSame('pattern', $monTue['basis']);
		self::assertTrue($monTue['derivable']);

		$thuFri = LeaveHoursCalculator::requestHours(self::request('2026-03-05', '2026-03-06'), 24.0, 2026, $time);
		self::assertSame(0.0, $thuFri['hours']);
		self::assertSame(['vrije-dag', 'vrije-dag'], array_column($thuFri['days'], 'reason'));
	}//end testThePatternDecidesTheHours()

	/**
	 * A feestdag in openregister's calendar costs nothing and is named.
	 *
	 * @return void
	 */
	public function testACalendarFeestdagCostsNothing(): void {
		$time = ['patterns' => [self::monToWed()], 'nonWorkingTimes' => [], 'nonWorkingDates' => ['2026-04-06']];

		$result = LeaveHoursCalculator::requestHours(self::request('2026-04-06', '2026-04-07'), 24.0, 2026, $time);

		self::assertSame(8.0, $result['hours']);
		self::assertSame('pattern', $result['basis']);
		self::assertSame([['date' => '2026-04-06', 'hours' => 0.0, 'reason' => 'feestdag'], ['date' => '2026-04-07', 'hours' => 8.0, 'reason' => 'pattern']], $result['days']);
	}//end testACalendarFeestdagCostsNothing()

	/**
	 * An unread calendar is said, not guessed: the pattern alone decides.
	 *
	 * @return void
	 */
	public function testAnUnreadCalendarIsPatternOnly(): void {
		$time = ['patterns' => [self::monToWed()], 'nonWorkingTimes' => [], 'nonWorkingDates' => null];

		$result = LeaveHoursCalculator::requestHours(self::request('2026-04-06', '2026-04-07'), 24.0, 2026, $time);

		self::assertSame(16.0, $result['hours']);
		self::assertSame('pattern-only', $result['basis']);
	}//end testAnUnreadCalendarIsPatternOnly()

	/**
	 * A standing free day recorded as a non-working time costs nothing.
	 *
	 * @return void
	 */
	public function testANonWorkingTimeCostsNothing(): void {
		$time = [
			'patterns' => [self::monToWed()],
			'nonWorkingTimes' => [['employeeId' => self::EMPLOYEE, 'recurringWeekday' => 'tuesday', 'startDate' => '2026-01-01']],
			'nonWorkingDates' => [],
		];

		self::assertSame(8.0, LeaveHoursCalculator::requestHours(self::request('2026-03-02', '2026-03-03'), 24.0, 2026, $time)['hours']);
	}//end testANonWorkingTimeCostsNothing()

	/**
	 * Without a pattern the contract average still applies, now skipping a
	 * feestdag the calendar marks; unread, it is the old figure.
	 *
	 * @return void
	 */
	public function testNoPatternIsTheContractAverage(): void {
		$read = LeaveHoursCalculator::requestHours(self::request('2026-04-06', '2026-04-07'), 24.0, 2026, ['patterns' => [], 'nonWorkingTimes' => [], 'nonWorkingDates' => ['2026-04-06']]);
		self::assertSame(4.8, $read['hours']);
		self::assertSame('contract-average', $read['basis']);
		self::assertSame('feestdag', $read['days'][0]['reason']);

		$unread = LeaveHoursCalculator::requestHours(self::request('2026-04-06', '2026-04-07'), 24.0, 2026, ['patterns' => [], 'nonWorkingTimes' => [], 'nonWorkingDates' => null]);
		self::assertSame(9.6, $unread['hours']);
		self::assertSame('contract-average', $unread['basis']);

		self::assertFalse(LeaveHoursCalculator::requestHours(self::request('2026-04-06', '2026-04-07'), null, 2026, ['patterns' => [], 'nonWorkingDates' => []])['derivable']);
	}//end testNoPatternIsTheContractAverage()

	/**
	 * A request's own hours win and say so.
	 *
	 * @return void
	 */
	public function testExplicitHoursWin(): void {
		$request = array_merge(self::request('2026-03-02', '2026-03-03'), ['hours' => 12]);

		$result = LeaveHoursCalculator::requestHours($request, 24.0, 2026, ['patterns' => [self::monToWed()], 'nonWorkingDates' => []]);

		self::assertSame(12.0, $result['hours']);
		self::assertSame('explicit', $result['basis']);
	}//end testExplicitHoursWin()

	/**
	 * The balance allocation uses the pattern when it is given one.
	 *
	 * @return void
	 */
	public function testTheAllocationUsesThePattern(): void {
		$balances = [['id' => 'bal-2026', 'employeeId' => self::EMPLOYEE, 'leaveType' => 'holiday', 'year' => 2026, 'contractHoursPerWeek' => 24]];
		$time = ['patterns' => [self::monToWed()], 'nonWorkingTimes' => [], 'nonWorkingDates' => []];

		$found = (new LeaveAllocationCalculator())->usesFrom(
			requests: [self::request('2026-03-02', '2026-03-03')],
			balances: $balances,
			employeeId: self::EMPLOYEE,
			leaveType: 'holiday',
			workingTime: $time
		);

		self::assertSame(16.0, $found['uses'][0]['hours']);
	}//end testTheAllocationUsesThePattern()

}//end class
