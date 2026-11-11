<?php

/**
 * TimeEntryHoursDeriver unit tests
 *
 * The one place that decides what a booking MEANS, in all three shapes it can
 * arrive in: clocked (a start and an end), booked to a day (a date and a number
 * of hours), and running (a start, no end, and the timer marker).
 *
 * The class had no test at all before the running shape was added, which is the
 * reason every refusal in it is asserted here and not only the new ones: a
 * change to the running branch that broke the clocked one would otherwise have
 * been invisible.
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
 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Listener\HoursWriteRefusedException;
use OCA\Humaniq\Service\TimeEntryHoursDeriver;
use PHPUnit\Framework\TestCase;

/**
 * The three booking shapes and every refusal between them.
 */
class TimeEntryHoursDeriverTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var TimeEntryHoursDeriver
	 */
	private TimeEntryHoursDeriver $deriver;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->deriver = new TimeEntryHoursDeriver();
	}//end setUp()

	/**
	 * A start and an end derive the span, minus the break.
	 *
	 * @return void
	 */
	public function testClockedShapeDerivesTheSpanMinusTheBreak(): void {
		[$start, $hours] = $this->deriver->derive(
			[
				'startedAt' => '2026-09-10T09:00:00+00:00',
				'endedAt' => '2026-09-10T12:30:00+00:00',
				'breakMinutes' => 30,
			],
			null
		);

		$this->assertSame(strtotime('2026-09-10T09:00:00+00:00'), $start);
		$this->assertSame(3.0, $hours);
	}//end testClockedShapeDerivesTheSpanMinusTheBreak()

	/**
	 * An end at or before the start is refused.
	 *
	 * @return void
	 */
	public function testClockedShapeRefusesAnEndBeforeTheStart(): void {
		$this->expectException(HoursWriteRefusedException::class);

		$this->deriver->derive(
			[
				'startedAt' => '2026-09-10T12:00:00+00:00',
				'endedAt' => '2026-09-10T09:00:00+00:00',
			],
			null
		);
	}//end testClockedShapeRefusesAnEndBeforeTheStart()

	/**
	 * A date and an explicit number of hours stand in for the clock.
	 *
	 * @return void
	 */
	public function testDayShapeTakesTheExplicitHours(): void {
		[$day, $hours] = $this->deriver->derive(['date' => '2026-09-10', 'hours' => 2.5], null);

		$this->assertSame(strtotime('2026-09-10'), $day);
		$this->assertSame(2.5, $hours);
	}//end testDayShapeTakesTheExplicitHours()

	/**
	 * A day booking of more than a day is a data error, not a long shift.
	 *
	 * @return void
	 */
	public function testDayShapeRefusesMoreThanTwentyFourHours(): void {
		$this->expectException(HoursWriteRefusedException::class);

		$this->deriver->derive(['date' => '2026-09-10', 'hours' => 25], null);
	}//end testDayShapeRefusesMoreThanTwentyFourHours()

	/**
	 * A start with no end and the timer marker is a RUNNING timer: accepted,
	 * and worth zero hours until it stops.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
	 */
	public function testRunningTimerIsAcceptedAndWorthZeroHours(): void {
		[$start, $hours] = $this->deriver->derive(
			[
				'startedAt' => '2026-09-10T09:00:00+00:00',
				'origin' => TimeEntryHoursDeriver::ORIGIN_TIMER,
			],
			null
		);

		$this->assertSame(strtotime('2026-09-10T09:00:00+00:00'), $start);
		$this->assertSame(0.0, $hours);
	}//end testRunningTimerIsAcceptedAndWorthZeroHours()

	/**
	 * Stopping a timer carries an end and no origin, and the marker is read off
	 * the STORED row, so the write derives real hours rather than zero.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
	 */
	public function testStoppingATimerDerivesTheRealHours(): void {
		[, $hours] = $this->deriver->derive(
			['endedAt' => '2026-09-10T11:00:00+00:00'],
			[
				'startedAt' => '2026-09-10T09:00:00+00:00',
				'origin' => TimeEntryHoursDeriver::ORIGIN_TIMER,
			]
		);

		$this->assertSame(2.0, $hours);
	}//end testStoppingATimerDerivesTheRealHours()

	/**
	 * A start with no end and NO marker is a booking that cannot say how long
	 * it lasted, and is refused exactly as it was before timers existed.
	 *
	 * This is the assertion that makes the marker load-bearing. Drop the
	 * `origin` check from the deriver and this is the test that reddens.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
	 */
	public function testAStartWithNoEndAndNoMarkerIsStillRefused(): void {
		$this->expectException(HoursWriteRefusedException::class);

		$this->deriver->derive(
			['startedAt' => '2026-09-10T09:00:00+00:00', 'origin' => 'manual'],
			null
		);
	}//end testAStartWithNoEndAndNoMarkerIsStillRefused()

	/**
	 * An unparseable start is refused even when the write says it is a timer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
	 */
	public function testARunningTimerWithAnUnparseableStartIsRefused(): void {
		$this->expectException(HoursWriteRefusedException::class);

		$this->deriver->derive(
			['startedAt' => 'not a moment', 'origin' => TimeEntryHoursDeriver::ORIGIN_TIMER],
			null
		);
	}//end testARunningTimerWithAnUnparseableStartIsRefused()

	/**
	 * The running-timer predicate the STAMP LISTENER shares, in both answers.
	 *
	 * This is the assertion that keeps the marker on the stored row. `origin`
	 * is readOnly on the schema and a client value for it does not survive the
	 * ObjectService write path, so a timer started with `origin: timer` came
	 * back stored as `manual` and matched no timer lookup: running, invisible,
	 * and reported as a success. The listener now stamps it, and it asks this
	 * method rather than re-deriving the rule from its own copy.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-an-entry-without-an-end-is-a-running-timer-not-a-defective-booking
	 */
	public function testTheRunningTimerPredicateAnswersBothWays(): void {
		$timer = [
			'startedAt' => '2026-09-10T09:00:00+00:00',
			'origin' => TimeEntryHoursDeriver::ORIGIN_TIMER,
		];

		$this->assertTrue($this->deriver->isRunningTimerWrite($timer, null));

		$this->assertFalse(
			$this->deriver->isRunningTimerWrite(['startedAt' => $timer['startedAt'], 'origin' => 'manual'], null),
			'No marker is a booking that lost its end, not a timer.'
		);
		$this->assertFalse(
			$this->deriver->isRunningTimerWrite(['endedAt' => '2026-09-10T11:00:00+00:00'], $timer),
			'A write carrying an end STOPS the timer, so it must not be re-stamped as running.'
		);
		$this->assertFalse(
			$this->deriver->isRunningTimerWrite(['date' => '2026-09-10', 'hours' => 2], null),
			'A day booking has no start, so it is not a timer whatever its origin says.'
		);
	}//end testTheRunningTimerPredicateAnswersBothWays()

	/**
	 * The whole outcome of a day booking without clock times, accepted and
	 * refused: the day and hours, or the refusal.
	 *
	 * @return void
	 */
	public function testTheWholeDayBookingOutcome(): void {
		$cases = [
			'plain'           => [['date' => '2026-09-10', 'hours' => 2.5], null],
			'rounded'         => [['date' => '2026-09-10', 'hours' => '7.456'], null],
			'from-stored'     => [[], ['date' => '2026-09-11', 'hours' => 8]],
			'date-stored'     => [['hours' => 4], ['date' => '2026-09-12', 'hours' => 8]],
			'full-day'        => [['date' => '2026-09-10', 'hours' => 24], null],
			'too-many'        => [['date' => '2026-09-10', 'hours' => 24.004], null],
			'just-over'       => [['date' => '2026-09-10', 'hours' => 24.01], null],
			'zero'            => [['date' => '2026-09-10', 'hours' => 0], null],
			'rounds-to-zero'  => [['date' => '2026-09-10', 'hours' => 0.004], null],
			'negative'        => [['date' => '2026-09-10', 'hours' => -1], null],
			'no-hours'        => [['date' => '2026-09-10'], null],
			'text-hours'      => [['date' => '2026-09-10', 'hours' => 'acht'], null],
			'no-date'         => [['hours' => 2], null],
			'bad-date'        => [['date' => 'gisteren', 'hours' => 2], null],
			'bad-both'        => [['date' => 'gisteren', 'hours' => 'acht'], null],
		];
		$got = [];
		foreach ($cases as $name => [$incoming, $stored]) {
			try {
				[$day, $hours] = $this->deriver->derive($incoming, $stored);
				$got[$name] = ['day' => date('Y-m-d H:i', $day), 'hours' => $hours];
			} catch (HoursWriteRefusedException $e) {
				$got[$name] = ['refused' => $e->getMessage()];
			}
		}

		self::assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/time-entry-day-booking.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheWholeDayBookingOutcome()

}//end class
