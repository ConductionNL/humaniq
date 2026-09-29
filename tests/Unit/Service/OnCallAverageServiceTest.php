<?php

/**
 * Unit tests for the on-call average hours.
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
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\OnCallAverageService;
use PHPUnit\Framework\TestCase;

/**
 * Averages approved hours per on-call contract over a chosen window.
 */
class OnCallAverageServiceTest extends TestCase {

	/**
	 * Fifty-two weeks of 18 approved hours read 18 a week; a week still
	 * waiting for approval, an entry outside any timesheet and another
	 * employee's hours are left out.
	 *
	 * @return void
	 */
	public function testTwelveMonthsOfApprovedHoursAverageEighteenAWeek(): void {
		$entries = [];
		$monday = new \DateTimeImmutable('2025-09-01');
		for ($week = 0; $week < 52; $week++) {
			$entries[] = ['employeeId' => 'emp-a', 'timesheetId' => 'ts-ok', 'date' => $monday->modify('+' . $week . ' weeks')->format('Y-m-d'), 'hours' => 18];
		}

		$entries[] = ['employeeId' => 'emp-a', 'timesheetId' => 'ts-waiting', 'date' => '2026-03-03', 'hours' => 18];
		$entries[] = ['employeeId' => 'emp-a', 'date' => '2026-03-04', 'hours' => 8];
		$entries[] = ['employeeId' => 'emp-b', 'timesheetId' => 'ts-ok', 'date' => '2026-03-05', 'hours' => 40];
		$contracts = [
			['id' => 'c-a', 'employeeId' => 'emp-a', 'type' => 'oproep', 'startDate' => '2025-06-01'],
			['id' => 'c-p', 'employeeId' => 'emp-b', 'type' => 'permanent', 'startDate' => '2020-01-01'],
		];

		$rows = (new OnCallAverageService())->averages(
			contracts: $contracts,
			entries: $entries,
			approvedTimesheetIds: ['ts-ok'],
			from: '2025-09-01',
			to: '2026-08-30'
		);

		self::assertCount(1, $rows);
		self::assertSame('c-a', $rows[0]['contractId']);
		self::assertSame(936.0, $rows[0]['hours']);
		self::assertSame(18.0, $rows[0]['hoursPerWeek']);
		self::assertSame(78.27, $rows[0]['hoursPerMonth']);
		self::assertTrue($rows[0]['offerDue']);
	}//end testTwelveMonthsOfApprovedHoursAverageEighteenAWeek()

	/**
	 * The window is cut to the contract: a contract that started halfway
	 * averages over the weeks it ran, and a recorded offer is not due.
	 *
	 * @return void
	 */
	public function testTheWindowIsCutToTheContract(): void {
		$contracts = [
			['id' => 'c-a', 'employeeId' => 'emp-a', 'type' => 'oproep', 'startDate' => '2026-01-05', 'vasteUrenAanbodOp' => '2026-02-01', 'vasteUrenAanbodUren' => 10],
			['id' => 'c-old', 'employeeId' => 'emp-c', 'type' => 'oproep', 'startDate' => '2023-01-01', 'endDate' => '2024-12-31'],
		];
		$entries = [
			['employeeId' => 'emp-a', 'timesheetId' => 'ts', 'date' => '2026-01-06', 'hours' => 20],
			['employeeId' => 'emp-a', 'timesheetId' => 'ts', 'date' => '2025-12-01', 'hours' => 99],
		];

		$rows = (new OnCallAverageService())->averages(contracts: $contracts, entries: $entries, approvedTimesheetIds: ['ts'], from: '2025-12-01', to: '2026-01-18');

		self::assertCount(1, $rows);
		self::assertSame(20.0, $rows[0]['hours']);
		self::assertSame(10.0, $rows[0]['hoursPerWeek']);
		self::assertFalse($rows[0]['offerDue']);
		self::assertSame('2026-01-05', $rows[0]['countedFrom']);
	}//end testTheWindowIsCutToTheContract()
}//end class
