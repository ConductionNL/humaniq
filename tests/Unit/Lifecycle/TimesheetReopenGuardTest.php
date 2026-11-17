<?php

/**
 * @category Test
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/humaniq-timesheet-approval/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\TimesheetReopenGuard;
use OCA\Humaniq\Service\RecordStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * An approved timesheet reopens until a final payroll run has paid its hours.
 *
 * @covers \OCA\Humaniq\Lifecycle\TimesheetReopenGuard
 */
class TimesheetReopenGuardTest extends TestCase {

	/**
	 * Stored objects by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $stored = [
		'Timesheet' => [
			'ts-free' => ['id' => 'ts-free', 'status' => 'approved', 'payrollRunId' => ''],
			'ts-paid' => ['id' => 'ts-paid', 'status' => 'approved', 'payrollRunId' => 'run-may'],
			'ts-draft-run' => ['id' => 'ts-draft-run', 'status' => 'approved', 'payrollRunId' => 'run-june'],
			'ts-orphan' => ['id' => 'ts-orphan', 'status' => 'approved', 'payrollRunId' => 'run-deleted'],
		],
		'PayrollRun' => [
			'run-may' => ['id' => 'run-may', 'period' => '2026-05', 'status' => 'approved'],
			'run-june' => ['id' => 'run-june', 'period' => '2026-06', 'status' => 'draft'],
		],
	];

	/**
	 * The guard over the stored objects above.
	 *
	 * @return TimesheetReopenGuard
	 */
	private function guard(): TimesheetReopenGuard {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findObjectData')->willReturnCallback(
			fn (string $uuid, string $schema): ?array => ($this->stored[$schema][$uuid] ?? null)
		);

		return new TimesheetReopenGuard($store);
	}//end guard()

	/**
	 * Scenario: HR reopens an approved timesheet no payroll run has used.
	 *
	 * @return void
	 */
	public function testAnUnusedTimesheetReopens(): void {
		self::assertTrue($this->guard()->check(['id' => 'ts-free'], 'reopen', 'ilse')->isAllowed());
	}//end testAnUnusedTimesheetReopens()

	/**
	 * Scenario: payroll already paid the hours. The refusal names the run.
	 *
	 * @return void
	 */
	public function testAPaidTimesheetIsRefusedNamingTheRun(): void {
		$result = $this->guard()->check(['id' => 'ts-paid'], 'reopen', 'ilse');

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('2026-05', (string)$result->getMessage());
		self::assertStringContainsString('run-may', (string)$result->getMessage());
	}//end testAPaidTimesheetIsRefusedNamingTheRun()

	/**
	 * The stored timesheet decides: a payload that leaves out the run does
	 * not get past it.
	 *
	 * @return void
	 */
	public function testThePayloadCannotHideThePaidRun(): void {
		self::assertFalse($this->guard()->check(['id' => 'ts-paid', 'payrollRunId' => ''], 'reopen', 'ilse')->isAllowed());
		self::assertFalse($this->guard()->check(['@self' => ['id' => 'ts-paid']], 'reopen', 'ilse')->isAllowed());
	}//end testThePayloadCannotHideThePaidRun()

	/**
	 * A draft run recalculates and a deleted run no longer counts, so
	 * neither stops a correction.
	 *
	 * @return void
	 */
	public function testADraftOrDeletedRunDoesNotStopTheReopen(): void {
		self::assertTrue($this->guard()->check(['id' => 'ts-draft-run'], 'reopen', 'ilse')->isAllowed());
		self::assertTrue($this->guard()->check(['id' => 'ts-orphan'], 'reopen', 'ilse')->isAllowed());
	}//end testADraftOrDeletedRunDoesNotStopTheReopen()

	/**
	 * An unreadable timesheet is refused rather than reopened on a guess.
	 *
	 * @return void
	 */
	public function testAnUnreadableTimesheetIsRefused(): void {
		self::assertFalse($this->guard()->check(['id' => 'ts-missing'], 'reopen', 'ilse')->isAllowed());
		self::assertFalse($this->guard()->check([], 'reopen', 'ilse')->isAllowed());
	}//end testAnUnreadableTimesheetIsRefused()
}//end class
