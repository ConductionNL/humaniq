<?php

/**
 * RetentionExpiryJob test
 *
 * The job is a thin daily wrapper, so the things worth asserting are the ones
 * a wrapper can get wrong: the day it hands the walk comes from the injected
 * clock, the walk's own summary is returned and logged unchanged (the log line
 * is the only record a nightly run leaves behind), and the schedule stays
 * daily and time-insensitive.
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
 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Humaniq\BackgroundJob\RetentionExpiryJob;
use OCA\Humaniq\Service\RetentionExpiryService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The daily retention expiry walk.
 *
 * @covers \OCA\Humaniq\BackgroundJob\RetentionExpiryJob
 */
class RetentionExpiryJobTest extends TestCase {

	/**
	 * The walk is run for the day the injected clock reports, and its summary
	 * comes back to the caller unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 */
	public function testTheWalkRunsForTheInjectedClocksDayAndItsSummaryIsReturned(): void {
		$summary = [
			'enabled'      => true,
			'released'     => 2,
			'marked'       => 3,
			'wouldRelease' => 0,
			'wouldMark'    => 0,
		];

		$service = $this->createMock(RetentionExpiryService::class);
		$service->expects($this->once())->method('run')
			->with(
				$this->callback(
					static function (DateTimeImmutable $today): bool {
						return $today->format('Y-m-d H:i:s') === '2026-02-17 03:00:00';
					}
				)
			)
			->willReturn($summary);

		$job = new RetentionExpiryJob(
			$this->clock(),
			$service,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame($summary, $job->runExpiry());

	}//end testTheWalkRunsForTheInjectedClocksDayAndItsSummaryIsReturned()

	/**
	 * The summary is logged as one info line carrying every counter — the only
	 * record a nightly run leaves behind.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 */
	public function testTheSummaryIsLoggedAsOneInfoLineCarryingEveryCounter(): void {
		$summary = [
			'enabled'      => false,
			'released'     => 0,
			'marked'       => 0,
			'wouldRelease' => 4,
			'wouldMark'    => 5,
		];

		$service = $this->createMock(RetentionExpiryService::class);
		$service->method('run')->willReturn($summary);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('RetentionExpiryJob: run complete', $summary);

		(new RetentionExpiryJob($this->clock(), $service, $logger))->runExpiry();

	}//end testTheSummaryIsLoggedAsOneInfoLineCarryingEveryCounter()

	/**
	 * The job-list entry point delegates to the same walk, so a cron round and
	 * a manual call cannot drift apart.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 */
	public function testTheJobListEntryPointDelegatesToTheSameWalk(): void {
		$service = $this->createMock(RetentionExpiryService::class);
		$service->expects($this->once())->method('run')->willReturn(
			[
				'enabled'      => true,
				'released'     => 0,
				'marked'       => 0,
				'wouldRelease' => 0,
				'wouldMark'    => 0,
			]
		);

		$job = new RetentionExpiryJob(
			$this->clock(),
			$service,
			$this->createMock(LoggerInterface::class)
		);

		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);

	}//end testTheJobListEntryPointDelegatesToTheSameWalk()

	/**
	 * The job stays on its daily, time-insensitive schedule.
	 *
	 * @return void
	 */
	public function testTheJobKeepsItsDailyInsensitiveSchedule(): void {
		$job = new RetentionExpiryJob(
			$this->clock(),
			$this->createMock(RetentionExpiryService::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(86400, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive(), 'time-insensitive: outside busy hours when possible');

	}//end testTheJobKeepsItsDailyInsensitiveSchedule()

	/**
	 * A clock stopped at 2026-02-17 03:00:00 UTC.
	 *
	 * @return ITimeFactory
	 */
	private function clock(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable('2026-02-17 03:00:00'))->getTimestamp());

		return $time;
	}//end clock()

}//end class
