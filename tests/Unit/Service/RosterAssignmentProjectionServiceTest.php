<?php

/**
 * Unit tests for RosterAssignmentProjectionService.
 *
 * Pins the planned-clock projection (rostering design D2, REQ-ROST-003):
 * plannedStart = date + Shift.startTime, plannedEnd = date + Shift.endTime
 * (rolled to the next calendar day for a night shift whose endTime is not
 * after its startTime), plannedBreakMinutes = Shift.breakMinutes.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\RosterAssignmentProjectionService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RosterAssignmentProjectionService.
 *
 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-003
 */
class RosterAssignmentProjectionServiceTest extends TestCase {

	/**
	 * A day shift 07:00-15:30 break 30 projects onto its date verbatim.
	 *
	 * @return void
	 */
	public function testDayShiftProjectsOntoItsDate(): void {
		$result = RosterAssignmentProjectionService::project(
			['startTime' => '07:00', 'endTime' => '15:30', 'breakMinutes' => 30],
			'2026-07-13'
		);

		$this->assertSame('2026-07-13T07:00:00', $result['plannedStart']);
		$this->assertSame('2026-07-13T15:30:00', $result['plannedEnd']);
		$this->assertSame(30.0, $result['plannedBreakMinutes']);

	}//end testDayShiftProjectsOntoItsDate()

	/**
	 * A night shift 22:00-06:00 rolls plannedEnd to the following day.
	 *
	 * @return void
	 */
	public function testNightShiftRollsPlannedEndToNextDay(): void {
		$result = RosterAssignmentProjectionService::project(
			['startTime' => '22:00', 'endTime' => '06:00', 'breakMinutes' => 0],
			'2026-07-13'
		);

		$this->assertSame('2026-07-13T22:00:00', $result['plannedStart']);
		$this->assertSame('2026-07-14T06:00:00', $result['plannedEnd']);
		$this->assertSame(0.0, $result['plannedBreakMinutes']);

	}//end testNightShiftRollsPlannedEndToNextDay()

	/**
	 * An endTime equal to startTime is a full-24h dienst crossing midnight
	 * (endTime not after startTime → roll forward).
	 *
	 * @return void
	 */
	public function testEqualStartAndEndTimeRollsForwardAFullDay(): void {
		$result = RosterAssignmentProjectionService::project(
			['startTime' => '08:00', 'endTime' => '08:00', 'breakMinutes' => 60],
			'2026-07-13'
		);

		$this->assertSame('2026-07-13T08:00:00', $result['plannedStart']);
		$this->assertSame('2026-07-14T08:00:00', $result['plannedEnd']);

	}//end testEqualStartAndEndTimeRollsForwardAFullDay()

	/**
	 * A missing/unparseable time yields null planned clock fields but still
	 * copies the break — never throws (REQ-ROST-003).
	 *
	 * @return void
	 */
	public function testUnparseableTimeYieldsNullPlannedClock(): void {
		$result = RosterAssignmentProjectionService::project(
			['startTime' => '', 'endTime' => '15:30', 'breakMinutes' => 15],
			'2026-07-13'
		);

		$this->assertNull($result['plannedStart']);
		$this->assertNull($result['plannedEnd']);
		$this->assertSame(15.0, $result['plannedBreakMinutes']);

	}//end testUnparseableTimeYieldsNullPlannedClock()

}//end class
