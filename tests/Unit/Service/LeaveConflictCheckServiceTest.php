<?php

/**
 * Unit tests for LeaveConflictCheckService.
 *
 * The rows are shaped like the register's LeaveRequest (hr-leave) and
 * SickLeaveCase (hr-verzuim) fragments: `status` approved / hersteld,
 * `startDate`/`endDate`, `firstSickDay`/`recoveredDate`.
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\LeaveConflictCheckService;
use PHPUnit\Framework\TestCase;

/**
 * A person rostered on a day they are away is a finding of its own kind.
 */
class LeaveConflictCheckServiceTest extends TestCase {

	/**
	 * One assignment for Jan on the given date.
	 *
	 * @param string $date The ISO date.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function assignmentOn(string $date): array {
		return [['id' => 'ra-1', 'rosterId' => 'roster-w28', 'employeeId' => 'emp-jan', 'shiftId' => 'shift-1', 'date' => $date]];
	}//end assignmentOn()

	/**
	 * Approved leave covering the assignment date is a mandatory `leave` finding
	 * naming the employee, the date and the leave request.
	 *
	 * @return void
	 */
	public function testAnAssignmentInsideApprovedLeaveIsAMandatoryFinding(): void {
		$findings = (new LeaveConflictCheckService())->findings(
			assignments: $this->assignmentOn('2026-07-15'),
			leaveRequests: [['id' => 'lr-1', 'employeeId' => 'emp-jan', 'status' => 'approved', 'startDate' => '2026-07-13', 'endDate' => '2026-07-17', 'leaveType' => 'zorgverlof', 'reason' => 'Mantelzorg moeder']],
			sickLeaveCases: []
		);

		$this->assertCount(1, $findings);
		$this->assertSame(LeaveConflictCheckService::FINDING_KIND, $findings[0]['kind']);
		$this->assertSame('leave', $findings[0]['kind']);
		$this->assertSame('mandatory', $findings[0]['severity']);
		$this->assertSame('leave', $findings[0]['absence']);
		$this->assertSame('ra-1', $findings[0]['objectId']);
		$this->assertSame('emp-jan', $findings[0]['employeeId']);
		$this->assertSame('2026-07-15', $findings[0]['date']);
		$this->assertSame('LeaveRequest', $findings[0]['sourceType']);
		$this->assertSame('lr-1', $findings[0]['sourceId']);
		$this->assertStringContainsString('emp-jan', $findings[0]['statement']);
		$this->assertStringContainsString('2026-07-15', $findings[0]['statement']);

		// The AVG boundary of leave-calendar-nc: kind and person, never why.
		$serialised = json_encode($findings);
		$this->assertStringNotContainsString('zorgverlof', (string)$serialised);
		$this->assertStringNotContainsString('Mantelzorg', (string)$serialised);
	}//end testAnAssignmentInsideApprovedLeaveIsAMandatoryFinding()

	/**
	 * The first and last day of the leave count; the day after does not.
	 *
	 * @return void
	 */
	public function testTheLeaveBoundariesAreInclusive(): void {
		$service = new LeaveConflictCheckService();
		$leave = [['id' => 'lr-1', 'employeeId' => 'emp-jan', 'status' => 'approved', 'startDate' => '2026-07-13', 'endDate' => '2026-07-17']];

		$this->assertCount(1, $service->findings(assignments: $this->assignmentOn('2026-07-13'), leaveRequests: $leave, sickLeaveCases: []));
		$this->assertCount(1, $service->findings(assignments: $this->assignmentOn('2026-07-17'), leaveRequests: $leave, sickLeaveCases: []));
		$this->assertSame([], $service->findings(assignments: $this->assignmentOn('2026-07-18'), leaveRequests: $leave, sickLeaveCases: []));
		$this->assertSame([], $service->findings(assignments: $this->assignmentOn('2026-07-12'), leaveRequests: $leave, sickLeaveCases: []));
	}//end testTheLeaveBoundariesAreInclusive()

	/**
	 * Only approved leave counts: a submitted, rejected or draft request, and a
	 * colleague's approved leave, produce nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheEmployeesOwnApprovedLeaveCounts(): void {
		$findings = (new LeaveConflictCheckService())->findings(
			assignments: $this->assignmentOn('2026-07-15'),
			leaveRequests: [
				['id' => 'lr-s', 'employeeId' => 'emp-jan', 'status' => 'submitted', 'startDate' => '2026-07-15', 'endDate' => '2026-07-15'],
				['id' => 'lr-r', 'employeeId' => 'emp-jan', 'status' => 'rejected', 'startDate' => '2026-07-15', 'endDate' => '2026-07-15'],
				['id' => 'lr-d', 'employeeId' => 'emp-jan', 'status' => 'draft', 'startDate' => '2026-07-15', 'endDate' => '2026-07-15'],
				['id' => 'lr-o', 'employeeId' => 'emp-piet', 'status' => 'approved', 'startDate' => '2026-07-15', 'endDate' => '2026-07-15'],
				['id' => 'lr-x', 'employeeId' => 'emp-jan', 'status' => 'approved', 'startDate' => '', 'endDate' => '2026-07-15'],
			],
			sickLeaveCases: []
		);

		$this->assertSame([], $findings);
	}//end testOnlyTheEmployeesOwnApprovedLeaveCounts()

	/**
	 * Open sick leave is an advisory `leave` finding that says absent and
	 * carries none of the case's content.
	 *
	 * @return void
	 */
	public function testAnOpenSickLeaveCaseIsAnAdvisoryFindingWithoutAReason(): void {
		$findings = (new LeaveConflictCheckService())->findings(
			assignments: $this->assignmentOn('2026-07-15'),
			leaveRequests: [],
			sickLeaveCases: [['id' => 'sick-1', 'employeeId' => 'emp-jan', 'status' => 'gemeld', 'firstSickDay' => '2026-07-01', 'recoveredDate' => null, 'absenceProgression' => 'burn-out', 'currentAbsencePercentage' => 100]]
		);

		$this->assertCount(1, $findings);
		$this->assertSame('leave', $findings[0]['kind']);
		$this->assertSame('advisory', $findings[0]['severity']);
		$this->assertSame('absent', $findings[0]['absence']);
		$this->assertSame('SickLeaveCase', $findings[0]['sourceType']);
		$serialised = (string)json_encode($findings);
		$this->assertStringNotContainsString('burn-out', $serialised);
		$this->assertStringNotContainsString('absenceProgression', $serialised);
	}//end testAnOpenSickLeaveCaseIsAnAdvisoryFindingWithoutAReason()

	/**
	 * A recovered case covers its days up to the recovery date, not after; a
	 * case that starts after the shift covers nothing.
	 *
	 * @return void
	 */
	public function testARecoveredCaseStopsCountingAfterItsRecoveryDate(): void {
		$service = new LeaveConflictCheckService();
		$cases = [['id' => 'sick-1', 'employeeId' => 'emp-jan', 'status' => 'hersteld', 'firstSickDay' => '2026-07-01', 'recoveredDate' => '2026-07-10']];

		$this->assertCount(1, $service->findings(assignments: $this->assignmentOn('2026-07-10'), leaveRequests: [], sickLeaveCases: $cases));
		$this->assertSame([], $service->findings(assignments: $this->assignmentOn('2026-07-11'), leaveRequests: [], sickLeaveCases: $cases));
		$this->assertSame([], $service->findings(assignments: $this->assignmentOn('2026-06-30'), leaveRequests: [], sickLeaveCases: $cases));
		$this->assertSame(
			[],
			$service->findings(
				assignments: $this->assignmentOn('2026-07-15'),
				leaveRequests: [],
				sickLeaveCases: [['id' => 'sick-2', 'employeeId' => 'emp-jan', 'status' => 'gemeld', 'firstSickDay' => '']]
			)
		);
	}//end testARecoveredCaseStopsCountingAfterItsRecoveryDate()

	/**
	 * One finding per assignment: approved leave wins over sick leave on the
	 * same day, so the refusal is never hidden behind an advisory.
	 *
	 * @return void
	 */
	public function testApprovedLeaveWinsOverSickLeaveOnTheSameDay(): void {
		$findings = (new LeaveConflictCheckService())->findings(
			assignments: $this->assignmentOn('2026-07-15'),
			leaveRequests: [['id' => 'lr-1', 'employeeId' => 'emp-jan', 'status' => 'approved', 'startDate' => '2026-07-15', 'endDate' => '2026-07-15']],
			sickLeaveCases: [['id' => 'sick-1', 'employeeId' => 'emp-jan', 'status' => 'gemeld', 'firstSickDay' => '2026-07-01']]
		);

		$this->assertCount(1, $findings);
		$this->assertSame('mandatory', $findings[0]['severity']);
	}//end testApprovedLeaveWinsOverSickLeaveOnTheSameDay()

	/**
	 * An assignment without an employee or a date is skipped, not guessed.
	 *
	 * @return void
	 */
	public function testAnIncompleteAssignmentIsSkipped(): void {
		$findings = (new LeaveConflictCheckService())->findings(
			assignments: [
				['id' => 'ra-1', 'employeeId' => '', 'date' => '2026-07-15'],
				['id' => 'ra-2', 'employeeId' => 'emp-jan', 'date' => ''],
				['@self' => ['id' => 'ra-3'], 'employeeId' => 'emp-jan', 'date' => '2026-07-15T08:00:00'],
			],
			leaveRequests: [['id' => 'lr-1', 'employeeId' => 'emp-jan', 'status' => 'approved', 'startDate' => '2026-07-15', 'endDate' => '2026-07-15']],
			sickLeaveCases: []
		);

		$this->assertCount(1, $findings);
		$this->assertSame('ra-3', $findings[0]['objectId']);
		$this->assertSame('2026-07-15', $findings[0]['date']);
	}//end testAnIncompleteAssignmentIsSkipped()

}//end class
