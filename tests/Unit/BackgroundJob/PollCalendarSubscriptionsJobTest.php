<?php

/**
 * PollCalendarSubscriptionsJob test
 *
 * The failure posture is the point of this job: busy time feeds booking
 * decisions, so the poll runs often — and a poller that throws must not take
 * the whole cron round down with it. That swallow-and-warn path has no other
 * witness, since nothing downstream observes a skipped round.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\BackgroundJob
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\BackgroundJob;

use OCA\Humaniq\BackgroundJob\PollCalendarSubscriptionsJob;
use OCA\Humaniq\Service\CalendarSubscriptionPoller;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The quarter-hourly busy-time refresh.
 *
 * @covers \OCA\Humaniq\BackgroundJob\PollCalendarSubscriptionsJob
 */
class PollCalendarSubscriptionsJobTest extends TestCase {

	/**
	 * A completed round is logged as one info line carrying the three
	 * counters, and nothing is warned about.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	public function testACompletedRoundIsLoggedAsOneInfoLineWithTheThreeCounters(): void {
		$poller = $this->createMock(CalendarSubscriptionPoller::class);
		$poller->expects($this->once())->method('pollAll')->willReturn(
			[
				'polled'   => 7,
				'ok'       => 6,
				'degraded' => 1,
				'details'  => ['anything else the poller reports'],
			]
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with(
				'PollCalendarSubscriptionsJob: run complete',
				[
					'polled'   => 7,
					'ok'       => 6,
					'degraded' => 1,
				]
			);
		$logger->expects($this->never())->method('warning');

		$this->runJob($poller, $logger);

	}//end testACompletedRoundIsLoggedAsOneInfoLineWithTheThreeCounters()

	/**
	 * A poller that throws is swallowed into a warning carrying the message:
	 * the cron round survives, and no completion line is logged for a round
	 * that did not complete.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	public function testAThrowingPollerIsWarnedAboutAndTheRoundSurvives(): void {
		$poller = $this->createMock(CalendarSubscriptionPoller::class);
		$poller->method('pollAll')->willThrowException(new RuntimeException('ICS endpoint unreachable'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('ICS endpoint unreachable'));
		$logger->expects($this->never())->method('info');

		$this->runJob($poller, $logger);

	}//end testAThrowingPollerIsWarnedAboutAndTheRoundSurvives()

	/**
	 * An Error — not just an Exception — is caught too, so a type error inside
	 * a subscription cannot end the round either.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-005
	 */
	public function testAnErrorIsCaughtAsWell(): void {
		$poller = $this->createMock(CalendarSubscriptionPoller::class);
		$poller->method('pollAll')->willThrowException(new \TypeError('busy time was not an array'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('busy time was not an array'));

		$this->runJob($poller, $logger);

	}//end testAnErrorIsCaughtAsWell()

	/**
	 * The job stays on its quarter-hourly, time-insensitive schedule: busy
	 * time decides whether somebody can be booked this afternoon, so an
	 * hourly cache would be answering about this morning.
	 *
	 * @return void
	 */
	public function testTheJobKeepsItsQuarterHourlyInsensitiveSchedule(): void {
		$job = new PollCalendarSubscriptionsJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(CalendarSubscriptionPoller::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(900, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive());

	}//end testTheJobKeepsItsQuarterHourlyInsensitiveSchedule()

	/**
	 * Invoke the protected run() hook the way the job list does.
	 *
	 * @param CalendarSubscriptionPoller $poller The poller.
	 * @param LoggerInterface            $logger The logger.
	 *
	 * @return void
	 */
	private function runJob(CalendarSubscriptionPoller $poller, LoggerInterface $logger): void {
		$job = new PollCalendarSubscriptionsJob($this->createMock(ITimeFactory::class), $poller, $logger);

		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

}//end class
