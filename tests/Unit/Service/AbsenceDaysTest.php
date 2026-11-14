<?php

/**
 * AbsenceDays unit tests
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
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\AbsenceDays;
use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\EmployeeWorkingTime;
use OCA\Humaniq\Service\WorkingHoursService;
use OCA\Humaniq\Service\WorkingTimeSources;
use PHPUnit\Framework\TestCase;

/**
 * Leave and sickness spread over days from the working pattern.
 */
class AbsenceDaysTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var AbsenceDays
	 */
	private AbsenceDays $days;

	/**
	 * A part-timer: six hours Monday, Tuesday and Thursday.
	 *
	 * @var EmployeeWorkingTime
	 */
	private EmployeeWorkingTime $time;

	/**
	 * The week of Monday 2 November 2026.
	 *
	 * @var array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	private array $week;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->days = new AbsenceDays(new WorkingHoursService(), new AbsenceProgression());
		$this->time = new EmployeeWorkingTime(
			employeeId: 'emp-1',
			sources: new WorkingTimeSources(workingPatterns: [[
				'employeeId' => 'emp-1',
				'validFrom' => '2026-01-01',
				'hoursMonday' => 6,
				'hoursTuesday' => 6,
				'hoursThursday' => 6,
			]])
		);
		$this->week = [new DateTimeImmutable('2026-11-02'), new DateTimeImmutable('2026-11-08')];
	}//end setUp()

	/**
	 * The contracted hours of every day of the range.
	 *
	 * @return void
	 */
	public function testContracted(): void {
		self::assertSame(
			['2026-11-02' => 6.0, '2026-11-03' => 6.0, '2026-11-04' => 0.0, '2026-11-05' => 6.0, '2026-11-06' => 0.0, '2026-11-07' => 0.0, '2026-11-08' => 0.0],
			$this->days->contracted($this->time, $this->week)
		);
	}//end testContracted()

	/**
	 * Leave without hours takes each working day whole, clipped to the range.
	 *
	 * @return void
	 */
	public function testLeaveWithoutHoursTakesTheWorkingDaysInTheRange(): void {
		$request = ['status' => 'approved', 'startDate' => '2026-10-29', 'endDate' => '2026-11-04'];

		self::assertSame(['2026-11-02' => 6.0, '2026-11-03' => 6.0], $this->days->leave($this->time, $request, $this->week));
	}//end testLeaveWithoutHoursTakesTheWorkingDaysInTheRange()

	/**
	 * Requested hours spread over all the request's working days, also those
	 * outside the range.
	 *
	 * @return void
	 */
	public function testRequestedHoursSpreadOverTheWholeRequest(): void {
		// Thursday 29 Oct, Monday and Tuesday: 18 contracted hours for 9 requested.
		$request = ['status' => 'approved', 'startDate' => '2026-10-29', 'endDate' => '2026-11-03', 'hours' => 9];

		self::assertSame(['2026-11-02' => 3.0, '2026-11-03' => 3.0], $this->days->leave($this->time, $request, $this->week));
	}//end testRequestedHoursSpreadOverTheWholeRequest()

	/**
	 * Requests that are not approved, undated or outside the range take nothing.
	 *
	 * @return void
	 */
	public function testLeaveThatDoesNotCount(): void {
		self::assertSame([], $this->days->leave($this->time, ['status' => 'submitted', 'startDate' => '2026-11-02'], $this->week));
		self::assertSame([], $this->days->leave($this->time, ['status' => 'approved'], $this->week));
		self::assertSame([], $this->days->leave($this->time, ['status' => 'approved', 'startDate' => '2026-11-10', 'endDate' => '2026-11-12'], $this->week));
		self::assertSame([], $this->days->leave($this->time, ['status' => 'approved', 'startDate' => '2026-11-03', 'endDate' => '2026-11-02'], $this->week));
	}//end testLeaveThatDoesNotCount()

	/**
	 * Sickness follows the absence progression, day by day.
	 *
	 * @return void
	 */
	public function testSicknessFollowsTheProgression(): void {
		$contract = $this->days->contracted($this->time, $this->week);
		$case = [
			'status' => 'gemeld',
			'firstSickDay' => '2026-10-20',
			'absenceProgression' => [
				['effectiveFrom' => '2026-10-20', 'absencePercentage' => 100],
				['effectiveFrom' => '2026-11-03', 'absencePercentage' => 25],
			],
		];

		$hours = $this->days->sickness($case, $this->week, $contract);

		self::assertSame(6.0, $hours['2026-11-02']);
		self::assertSame(1.5, $hours['2026-11-03']);
		self::assertSame(0.0, $hours['2026-11-04']);
		self::assertSame(1.5, $hours['2026-11-05']);
		self::assertCount(7, $hours, 'an open case runs to the end of the range');
	}//end testSicknessFollowsTheProgression()

	/**
	 * A recovered case ends on its recovered date; a case without a first
	 * sick day takes nothing.
	 *
	 * @return void
	 */
	public function testSicknessWindow(): void {
		$contract = $this->days->contracted($this->time, $this->week);

		self::assertSame(
			['2026-11-02' => 6.0, '2026-11-03' => 6.0],
			$this->days->sickness(['status' => 'hersteld', 'firstSickDay' => '2026-11-01', 'recoveredDate' => '2026-11-03'], $this->week, $contract)
		);
		self::assertSame([], $this->days->sickness(['status' => 'gemeld'], $this->week, $contract));
	}//end testSicknessWindow()

}//end class
