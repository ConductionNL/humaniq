<?php

/**
 * RestampTimeEntryApprovalState repair step unit tests
 *
 * The repair step that re-stamps every time entry's approval state from its
 * timesheet (time-entry-approval-state D3): it skips without OpenRegister,
 * reports what it stamped, warns about failed entries, and turns a crash
 * into a warning instead of failing the upgrade.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Repair
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
 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\RestampTimeEntryApprovalState;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TimeEntryApprovalStamper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the approval-state restamp repair step.
 */
class RestampTimeEntryApprovalStateTest extends TestCase {

	/**
	 * Build the step.
	 *
	 * @param TimeEntryApprovalStamper $stamper The stamper.
	 * @param bool $openRegister Whether OpenRegister is available.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return RestampTimeEntryApprovalState
	 */
	private function step(TimeEntryApprovalStamper $stamper, bool $openRegister = true, ?LoggerInterface $logger = null): RestampTimeEntryApprovalState {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegister);

		return new RestampTimeEntryApprovalState(stamper: $stamper, settingsService: $settings, logger: ($logger ?? new NullLogger()));
	}//end step()

	/**
	 * Without OpenRegister nothing is read or written.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepSkips(): void {
		$stamper = $this->createMock(TimeEntryApprovalStamper::class);
		$stamper->expects($this->never())->method('restampAll');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$this->step(stamper: $stamper, openRegister: false)->run($output);
	}//end testWithoutOpenRegisterTheStepSkips()

	/**
	 * A clean pass reports its counts and no warning.
	 *
	 * @return void
	 */
	public function testACleanPassReportsItsCounts(): void {
		$stamper = $this->createMock(TimeEntryApprovalStamper::class);
		$stamper->expects($this->once())->method('restampAll')->willReturn(['inspected' => 12, 'changed' => 3, 'failed' => 0]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('TimeEntry approval state: 3 of 12 stamped, 0 failed');
		$output->expects($this->never())->method('warning');

		$this->step(stamper: $stamper)->run($output);
	}//end testACleanPassReportsItsCounts()

	/**
	 * Failed entries are reported as a warning.
	 *
	 * @return void
	 */
	public function testFailedEntriesAreWarnedAbout(): void {
		$stamper = $this->createMock(TimeEntryApprovalStamper::class);
		$stamper->method('restampAll')->willReturn(['inspected' => 5, 'changed' => 3, 'failed' => 2]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('TimeEntry approval state: 3 of 5 stamped, 2 failed');
		$output->expects($this->once())->method('warning')->with($this->stringStartsWith('2 time entries could not be stamped'));

		$this->step(stamper: $stamper)->run($output);
	}//end testFailedEntriesAreWarnedAbout()

	/**
	 * A crash is logged and warned about, never thrown into the upgrade.
	 *
	 * @return void
	 */
	public function testACrashBecomesAWarning(): void {
		$stamper = $this->createMock(TimeEntryApprovalStamper::class);
		$stamper->method('restampAll')->willThrowException(new \RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('restamp failed'), ['exception' => 'register down']);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('The approval state of the time entries was not stamped: register down');
		$output->expects($this->never())->method('info');

		$this->step(stamper: $stamper, logger: $logger)->run($output);
	}//end testACrashBecomesAWarning()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		$this->assertSame(
			'Stamp the approval state of each timesheet on its time entries',
			$this->step(stamper: $this->createMock(TimeEntryApprovalStamper::class))->getName()
		);
	}//end testTheStepNamesWhatItDoes()

}//end class
