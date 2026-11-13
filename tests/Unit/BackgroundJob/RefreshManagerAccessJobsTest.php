<?php

/**
 * Manager access refresh jobs test
 *
 * Both jobs — the six-hourly timed one and the one-off queued one — exist to
 * run the same restamp pass. What is worth pinning is that each reads the day
 * from the injected clock rather than from a real one, and that the timed job
 * stays on its declared schedule and time sensitivity: a job that quietly
 * became time-sensitive would be run during busy hours on every instance.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Humaniq\BackgroundJob\RefreshManagerAccessJob;
use OCA\Humaniq\BackgroundJob\RefreshManagerAccessNowJob;
use OCA\Humaniq\Service\ManagerAccessStamps;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled and one-off manager access refreshes.
 *
 * @covers \OCA\Humaniq\BackgroundJob\RefreshManagerAccessJob
 * @covers \OCA\Humaniq\BackgroundJob\RefreshManagerAccessNowJob
 */
class RefreshManagerAccessJobsTest extends TestCase {

	/**
	 * The timed job restamps for the day the injected clock reports — never
	 * for a day read from a real clock.
	 *
	 * @return void
	 */
	public function testTheTimedJobRestampsForTheInjectedClocksDay(): void {
		$stamps = $this->createMock(ManagerAccessStamps::class);
		$stamps->expects($this->once())->method('refresh')->with('2026-02-17');

		$this->runJob(new RefreshManagerAccessJob($this->clock('2026-02-17'), $stamps));

	}//end testTheTimedJobRestampsForTheInjectedClocksDay()

	/**
	 * The timed job stays on its six-hourly, time-insensitive schedule.
	 *
	 * @return void
	 */
	public function testTheTimedJobKeepsItsSixHourlyInsensitiveSchedule(): void {
		$job = new RefreshManagerAccessJob(
			$this->clock('2026-02-17'),
			$this->createMock(ManagerAccessStamps::class)
		);

		$this->assertSame(21600, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive(), 'time-insensitive: outside busy hours when possible');

	}//end testTheTimedJobKeepsItsSixHourlyInsensitiveSchedule()

	/**
	 * The one-off queued job runs the pass exactly once, for the same day the
	 * clock reports.
	 *
	 * @return void
	 */
	public function testTheQueuedJobRunsThePassOnceForTheClocksDay(): void {
		$stamps = $this->createMock(ManagerAccessStamps::class);
		$stamps->expects($this->once())->method('refresh')->with('2026-12-31');

		$this->runJob(new RefreshManagerAccessNowJob($stamps, $this->clock('2026-12-31')));

	}//end testTheQueuedJobRunsThePassOnceForTheClocksDay()

	/**
	 * A clock reporting a given day.
	 *
	 * @param string $day The day, `Y-m-d`.
	 *
	 * @return ITimeFactory
	 */
	private function clock(string $day): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime($day . ' 04:00:00'));

		return $time;
	}//end clock()

	/**
	 * Invoke the protected run() hook the way the job list does.
	 *
	 * @param RefreshManagerAccessJob|RefreshManagerAccessNowJob $job The job under test.
	 *
	 * @return void
	 */
	private function runJob(RefreshManagerAccessJob|RefreshManagerAccessNowJob $job): void {
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

}//end class
