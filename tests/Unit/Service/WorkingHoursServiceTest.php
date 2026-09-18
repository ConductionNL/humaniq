<?php

/**
 * WorkingHoursService tests
 *
 * Pins the four things that make a working pattern different from an fte
 * fraction: hours land on weekdays, a dated pattern keeps a past figure
 * dividing by the past contract, a non-working time subtracts without becoming
 * leave, and a calendar that could not be read is said rather than guessed.
 *
 * The full-week control comes first on purpose. Without it, a "the feestdag
 * was subtracted" assertion cannot tell "the calendar was applied" from "the
 * calendar was ignored and the number happened to match".
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
use OCA\Humaniq\Service\OverlappingWorkingPatternException;
use OCA\Humaniq\Service\WorkingHoursService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkingHoursService.
 */
class WorkingHoursServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var WorkingHoursService
	 */
	private WorkingHoursService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new WorkingHoursService();
	}//end setUp()

	/**
	 * A 0.6 fte working Monday to Wednesday, from 1 January 2026, open-ended.
	 *
	 * @return array<string, mixed> The pattern.
	 */
	private function threeDayPattern(): array {
		return [
			'employeeId' => 'emp-fatima',
			'validFrom' => '2026-01-01',
			'validUntil' => null,
			'hoursMonday' => 8,
			'hoursTuesday' => 8,
			'hoursWednesday' => 8,
			'hoursThursday' => 0,
			'hoursFriday' => 0,
			'hoursSaturday' => 0,
			'hoursSunday' => 0,
		];
	}//end threeDayPattern()

	/**
	 * A part-timer's days are recorded, not their fraction: the hours land on
	 * the weekdays worked and answer zero on the ones that are not.
	 *
	 * @return void
	 */
	public function testHoursLandOnTheWeekdaysWorked(): void {
		$patterns = [$this->threeDayPattern()];

		// 2026-06-01 is a Monday, 2026-06-04 a Thursday.
		$monday = $this->service->contractedHoursOn(
			employeeId: 'emp-fatima',
			date: new DateTimeImmutable('2026-06-01'),
			patterns: $patterns,
			nonWorkingTimes: [],
			nonWorkingDates: []
		);
		$thursday = $this->service->contractedHoursOn(
			employeeId: 'emp-fatima',
			date: new DateTimeImmutable('2026-06-04'),
			patterns: $patterns,
			nonWorkingTimes: [],
			nonWorkingDates: []
		);

		$this->assertSame(8.0, $monday['hours'], 'Monday is a contracted day and must answer its hours');
		$this->assertSame(0.0, $thursday['hours'], 'Thursday is not contracted and must answer zero');
		$this->assertTrue($monday['patternFound']);
	}//end testHoursLandOnTheWeekdaysWorked()

	/**
	 * An employee with no pattern at all answers zero and says the pattern was
	 * not found, rather than answering a default working day.
	 *
	 * @return void
	 */
	public function testNoPatternAnswersZeroAndSaysSo(): void {
		$answer = $this->service->contractedHoursOn(
			employeeId: 'emp-unknown',
			date: new DateTimeImmutable('2026-06-01'),
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [],
			nonWorkingDates: []
		);

		$this->assertSame(0.0, $answer['hours']);
		$this->assertFalse($answer['patternFound'], 'A missing pattern must be reported, not filled in');
	}//end testNoPatternAnswersZeroAndSaysSo()

	/**
	 * Last quarter still divides by last quarter's contract: a 24-hour pattern
	 * ending on 30 June and a 32-hour pattern starting on 1 July both answer
	 * for their own dates, and writing the second does not move the first.
	 *
	 * @return void
	 */
	public function testAPastPeriodDividesByThePatternInForceThen(): void {
		$june = [
			'employeeId' => 'emp-jansen',
			'validFrom' => '2026-01-01',
			'validUntil' => '2026-06-30',
			'hoursMonday' => 8,
			'hoursTuesday' => 8,
			'hoursWednesday' => 8,
		];
		$july = [
			'employeeId' => 'emp-jansen',
			'validFrom' => '2026-07-01',
			'validUntil' => null,
			'hoursMonday' => 8,
			'hoursTuesday' => 8,
			'hoursWednesday' => 8,
			'hoursThursday' => 8,
		];

		// 2026-06-25 and 2026-07-02 are both Thursdays.
		$beforeTheChange = $this->service->contractedHoursOn(
			employeeId: 'emp-jansen',
			date: new DateTimeImmutable('2026-06-25'),
			patterns: [$june, $july],
			nonWorkingTimes: [],
			nonWorkingDates: []
		);
		$afterTheChange = $this->service->contractedHoursOn(
			employeeId: 'emp-jansen',
			date: new DateTimeImmutable('2026-07-02'),
			patterns: [$june, $july],
			nonWorkingTimes: [],
			nonWorkingDates: []
		);

		$this->assertSame(0.0, $beforeTheChange['hours'], 'June must still answer the 24-hour contract');
		$this->assertSame(8.0, $afterTheChange['hours'], 'July answers the 32-hour contract');
	}//end testAPastPeriodDividesByThePatternInForceThen()

	/**
	 * Two answers for one Tuesday are refused: a second open-ended pattern
	 * written without ending the running one throws.
	 *
	 * @return void
	 */
	public function testOverlappingPatternsAreRefused(): void {
		$running = [
			'employeeId' => 'emp-jansen',
			'validFrom' => '2026-01-01',
			'validUntil' => null,
		];
		$second = [
			'employeeId' => 'emp-jansen',
			'validFrom' => '2026-03-01',
			'validUntil' => null,
		];

		$this->expectException(OverlappingWorkingPatternException::class);
		$this->service->assertPatternsDoNotOverlap(patterns: [$running, $second]);
	}//end testOverlappingPatternsAreRefused()

	/**
	 * Two patterns that meet without overlapping are accepted, and two people
	 * sharing a date are not each other's overlap.
	 *
	 * @return void
	 */
	public function testAdjacentPatternsAndOtherPeopleAreNotOverlaps(): void {
		$ended = [
			'employeeId' => 'emp-jansen',
			'validFrom' => '2026-01-01',
			'validUntil' => '2026-06-30',
		];
		$next = [
			'employeeId' => 'emp-jansen',
			'validFrom' => '2026-07-01',
			'validUntil' => null,
		];
		$somebodyElse = [
			'employeeId' => 'emp-fatima',
			'validFrom' => '2026-01-01',
			'validUntil' => null,
		];

		$this->service->assertPatternsDoNotOverlap(patterns: [$ended, $next, $somebodyElse]);
		$this->addToAssertionCount(1);
	}//end testAdjacentPatternsAndOtherPeopleAreNotOverlaps()

	/**
	 * A standing free Wednesday answers zero hours on Wednesdays and leaves
	 * every other contracted day untouched.
	 *
	 * @return void
	 */
	public function testARecurringNonWorkingDayZeroesOnlyThatWeekday(): void {
		$nonWorking = [
			'employeeId' => 'emp-fatima',
			'reason' => 'vaste-vrije-dag',
			'recurringWeekday' => 'wednesday',
		];

		// 2026-06-03 is a Wednesday, 2026-06-02 a Tuesday.
		$wednesday = $this->service->contractedHoursOn(
			employeeId: 'emp-fatima',
			date: new DateTimeImmutable('2026-06-03'),
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [$nonWorking],
			nonWorkingDates: []
		);
		$tuesday = $this->service->contractedHoursOn(
			employeeId: 'emp-fatima',
			date: new DateTimeImmutable('2026-06-02'),
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [$nonWorking],
			nonWorkingDates: []
		);

		$this->assertSame(0.0, $wednesday['hours']);
		$this->assertSame(8.0, $tuesday['hours'], 'Only the named weekday is affected');
	}//end testARecurringNonWorkingDayZeroesOnlyThatWeekday()

	/**
	 * A reduced re-integration schedule subtracts part of a day, inside its
	 * window and not outside it.
	 *
	 * @return void
	 */
	public function testAPartDayNonWorkingTimeSubtractsInsideItsWindow(): void {
		$reintegration = [
			'employeeId' => 'emp-fatima',
			'reason' => 're-integratie',
			'recurringWeekday' => null,
			'startDate' => '2026-06-01',
			'endDate' => '2026-06-07',
			'hoursNotWorked' => 4,
		];

		$inside = $this->service->contractedHoursOn(
			employeeId: 'emp-fatima',
			date: new DateTimeImmutable('2026-06-02'),
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [$reintegration],
			nonWorkingDates: []
		);
		$outside = $this->service->contractedHoursOn(
			employeeId: 'emp-fatima',
			date: new DateTimeImmutable('2026-06-09'),
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [$reintegration],
			nonWorkingDates: []
		);

		$this->assertSame(4.0, $inside['hours'], 'Half the contracted day is worked');
		$this->assertSame(8.0, $outside['hours'], 'The window has passed');
	}//end testAPartDayNonWorkingTimeSubtractsInsideItsWindow()

	/**
	 * A non-working time belonging to somebody else never touches this
	 * person's hours.
	 *
	 * @return void
	 */
	public function testAnotherPersonsNonWorkingTimeIsIgnored(): void {
		$answer = $this->service->contractedHoursOn(
			employeeId: 'emp-fatima',
			date: new DateTimeImmutable('2026-06-01'),
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [
				[
					'employeeId' => 'emp-jansen',
					'reason' => 'vaste-vrije-dag',
					'recurringWeekday' => 'monday',
				],
			],
			nonWorkingDates: []
		);

		$this->assertSame(8.0, $answer['hours']);
	}//end testAnotherPersonsNonWorkingTimeIsIgnored()

	/**
	 * A national holiday on a contracted day answers zero, and says the
	 * calendar is what did it.
	 *
	 * @return void
	 */
	public function testAFeestdagOnAContractedDayAnswersZero(): void {
		// 2026-05-25 is a Monday (second Whitsun).
		$answer = $this->service->contractedHoursOn(
			employeeId: 'emp-fatima',
			date: new DateTimeImmutable('2026-05-25'),
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [],
			nonWorkingDates: ['2026-05-25']
		);

		$this->assertSame(0.0, $answer['hours']);
		$this->assertTrue($answer['calendarApplied'], 'The calendar, not the pattern, is what zeroed it');
		$this->assertFalse($answer['patternOnly']);
	}//end testAFeestdagOnAContractedDayAnswersZero()

	/**
	 * A range sums what the pattern and the calendar leave: 24 contracted
	 * hours in a week, minus the eight of a feestdag that falls on a
	 * contracted Monday.
	 *
	 * @return void
	 */
	public function testARangeSumsWhatThePatternAndCalendarLeave(): void {
		$week = [new DateTimeImmutable('2026-05-25'), new DateTimeImmutable('2026-05-31')];

		$control = $this->service->contractedHoursOver(
			employeeId: 'emp-fatima',
			from: $week[0],
			to: $week[1],
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [],
			nonWorkingDates: []
		);
		$withFeestdag = $this->service->contractedHoursOver(
			employeeId: 'emp-fatima',
			from: $week[0],
			to: $week[1],
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [],
			nonWorkingDates: ['2026-05-25']
		);

		$this->assertSame(24.0, $control['hours'], 'The control: a full contracted week');
		$this->assertSame(16.0, $withFeestdag['hours'], '24 minus that Monday');
		$this->assertSame(7, $control['days']);
		$this->assertSame(2, $withFeestdag['workedDays']);
	}//end testARangeSumsWhatThePatternAndCalendarLeave()

	/**
	 * An answer with no calendar is marked pattern-only, so a caller cannot
	 * report a guess as a read.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutACalendarIsMarkedPatternOnly(): void {
		$answer = $this->service->contractedHoursOn(
			employeeId: 'emp-fatima',
			date: new DateTimeImmutable('2026-05-25'),
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [],
			nonWorkingDates: null
		);
		$range = $this->service->contractedHoursOver(
			employeeId: 'emp-fatima',
			from: new DateTimeImmutable('2026-05-25'),
			to: new DateTimeImmutable('2026-05-31'),
			patterns: [$this->threeDayPattern()],
			nonWorkingTimes: [],
			nonWorkingDates: null
		);

		$this->assertTrue($answer['patternOnly'], 'No calendar means the answer must say so');
		$this->assertSame(8.0, $answer['hours'], 'The pattern still answers; only its standing changes');
		$this->assertTrue($range['patternOnly']);
	}//end testAnAnswerWithoutACalendarIsMarkedPatternOnly()

	/**
	 * Day-equivalents divide contracted hours by the full-time day, which is
	 * the basis AbsenceRateService measures availability on.
	 *
	 * @return void
	 */
	public function testDayEquivalentsUseTheFullTimeWeek(): void {
		$this->assertSame(7.0, $this->service->dayEquivalents(hours: 40.0, fullTimeHoursWeek: 40.0));
		$this->assertSame(0.0, $this->service->dayEquivalents(hours: 40.0, fullTimeHoursWeek: 0.0));
	}//end testDayEquivalentsUseTheFullTimeWeek()
}//end class
