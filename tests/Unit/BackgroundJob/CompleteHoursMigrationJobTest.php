<?php

/**
 * CompleteHoursMigrationJob unit tests
 *
 * The one-shot background completion of the hours-process migration: the job
 * resolves the repair step lazily from the container, re-runs the idempotent
 * pass via `runDeferred()`, and logs exactly one summary line with the three
 * counters. A failing pass is logged as a warning and NEVER rethrown — a
 * queued job runs once, and the migration's idempotency means the next app
 * upgrade re-attempts (and re-enqueues on maintenance-mode failure).
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\BackgroundJob;

use OCA\Humaniq\BackgroundJob\CompleteHoursMigrationJob;
use OCA\Humaniq\Repair\MigrateHoursProcess;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deferred completion + failure posture of the migration background job.
 */
class CompleteHoursMigrationJobTest extends TestCase {

	/**
	 * Invoke the protected QueuedJob::run() hook the way the job list does.
	 *
	 * @param CompleteHoursMigrationJob $job The job under test.
	 *
	 * @return void
	 */
	private function runJob(CompleteHoursMigrationJob $job): void {
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * The happy path: the repair step's runDeferred() summary is logged as
	 * ONE info line carrying all three counters (warn-once semantics).
	 *
	 * @return void
	 */
	public function testRunLogsTheDeferredSummary(): void {
		$step = $this->createMock(MigrateHoursProcess::class);
		$step->expects($this->once())
			->method('runDeferred')
			->willReturn(['processed' => 3, 'entriesCreated' => 2, 'unresolvableUserLinks' => 1]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with($this->logicalAnd(
				$this->stringContains('3 timesheets processed'),
				$this->stringContains('2 entries created'),
				$this->stringContains('1 rows with unresolvable user link')
			));
		$logger->expects($this->never())->method('warning');

		$job = new CompleteHoursMigrationJob(
			time: $this->createMock(ITimeFactory::class),
			container: new FakeContainer([MigrateHoursProcess::class => $step]),
			logger: $logger
		);

		$this->runJob($job);
	}//end testRunLogsTheDeferredSummary()

	/**
	 * A failing pass is logged as a warning and swallowed — a queued job
	 * runs once, and rethrowing would only mark the job failed without a
	 * retry; the idempotent migration is re-attempted by the next upgrade.
	 *
	 * @return void
	 */
	public function testRunSwallowsAndLogsAFailure(): void {
		$step = $this->createMock(MigrateHoursProcess::class);
		$step->method('runDeferred')->willThrowException(new \RuntimeException('folder gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('background completion failed'),
				$this->callback(static fn (array $context): bool => ($context['exception'] ?? '') === 'folder gone')
			);

		$job = new CompleteHoursMigrationJob(
			time: $this->createMock(ITimeFactory::class),
			container: new FakeContainer([MigrateHoursProcess::class => $step]),
			logger: $logger
		);

		$this->runJob($job);
	}//end testRunSwallowsAndLogsAFailure()

}//end class
