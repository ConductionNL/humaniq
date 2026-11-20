<?php

/**
 * ReleaseSurveyResponsesJobTest
 *
 * The hourly release of held survey answers.
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
 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Humaniq\BackgroundJob\ReleaseSurveyResponsesJob;
use OCA\Humaniq\Service\SurveyResponseRelease;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\BackgroundJob\ReleaseSurveyResponsesJob
 *
 * @spec openspec/specs/engagement-surveys/spec.md#REQ-SRV-002
 */
class ReleaseSurveyResponsesJobTest extends TestCase {

	/**
	 * The job releases for the day the injected clock reports.
	 *
	 * @return void
	 */
	public function testTheJobReleasesForTheClocksDay(): void {
		$release = $this->createMock(SurveyResponseRelease::class);
		$release->expects($this->once())->method('release')->with('2026-10-06')->willReturn(3);

		$job = new ReleaseSurveyResponsesJob($this->clock('2026-10-06'), $release);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end testTheJobReleasesForTheClocksDay()

	/**
	 * The job runs hourly and is not time-sensitive.
	 *
	 * @return void
	 */
	public function testTheJobRunsHourly(): void {
		$job = new ReleaseSurveyResponsesJob($this->clock('2026-10-06'), $this->createMock(SurveyResponseRelease::class));

		self::assertSame(3600, $job->getInterval());
		self::assertFalse($job->isTimeSensitive());
	}//end testTheJobRunsHourly()

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

}//end class
