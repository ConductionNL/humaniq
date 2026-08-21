<?php

/**
 * Unit tests for the NL organisational-structure checks.
 *
 * Pins the two org-chart-basic predicates: assignment consistency
 * (nl-org-assignment-consistency, cross-object via the context's OrgUnit
 * index) and unit-cycle freedom (nl-org-unit-cycle, a visited-set parent
 * walk over the same index), plus the mss-team-scope
 * nl-mss-manager-consistency predicate shared across
 * Timesheet/Expense/LeaveRequest (the consistent path, the mismatch
 * violation, each vacuous hop, the multiple-active-assignments any-match
 * pass, and the inactive-assignment skip).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/org-chart-basic/specs/org-chart-basic/spec.md#REQ-ORG-005
 * @spec openspec/changes/mss-team-scope/specs/mss-team-scope/spec.md#REQ-MSS-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlOrgChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlOrgChecks.
 *
 * @spec openspec/changes/org-chart-basic/specs/org-chart-basic/spec.md#REQ-ORG-005
 */
class NlOrgChecksTest extends TestCase {

	/**
	 * The registered OrgAssignment predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $assignmentChecks;

	/**
	 * The registered OrgUnit predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $unitChecks;

	/**
	 * The registered Timesheet predicates, keyed by rule id (mss-team-scope
	 * — shared with Expense/LeaveRequest, so any one of the three suffices
	 * to exercise the shared predicate).
	 *
	 * @var array<string, callable>
	 */
	private array $timesheetChecks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$checks = NlOrgChecks::checks();
		$this->assignmentChecks = $checks['OrgAssignment'];
		$this->unitChecks = $checks['OrgUnit'];
		$this->timesheetChecks = $checks['Timesheet'];

	}//end setUp()

	/**
	 * A minimal OrgAssignment fixture; each test overrides the fields it exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function assignment(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => 'employee-jansen',
				'orgUnitId' => 'unit-consultancy',
				'role' => 'Consultant',
				'startDate' => '2024-01-01',
				'endDate' => null,
			],
			$overrides
		);

	}//end assignment()

	/**
	 * A minimal `context['related']['OrgUnit']['byId']` fixture matching
	 * RuleAuditService's pre-pass shape.
	 *
	 * @param array<string, array<string, mixed>> $unitsById OrgUnit index by id.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $unitsById = []): array {
		return [
			'related' => [
				'OrgUnit' => ['byId' => $unitsById],
			],
		];

	}//end context()

	// -- nl-org-assignment-consistency --

	/**
	 * @return void
	 */
	public function testConsistentOpenEndedAssignmentOnActiveUnitSatisfied(): void {
		$assignment = $this->assignment(['orgUnitId' => 'unit-consultancy', 'endDate' => null]);
		$context = $this->context(['unit-consultancy' => ['id' => 'unit-consultancy', 'parentUnitId' => '', 'active' => true]]);

		$this->assertTrue(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $context));

	}//end testConsistentOpenEndedAssignmentOnActiveUnitSatisfied()

	/**
	 * @return void
	 */
	public function testIncoherentDatesViolate(): void {
		$assignment = $this->assignment(['startDate' => '2026-06-01', 'endDate' => '2026-05-01']);
		$context = $this->context(['unit-consultancy' => ['id' => 'unit-consultancy', 'parentUnitId' => '', 'active' => true]]);

		$this->assertFalse(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $context));

	}//end testIncoherentDatesViolate()

	/**
	 * @return void
	 */
	public function testSameDayStartAndEndIsCoherentAndSatisfiedWithoutNeedingAnActiveUnit(): void {
		// endDate === startDate is coherent (not "earlier than"), and since
		// the placement is a single day fully in the past it is not
		// "currently active" either, so the active-unit lookup never runs —
		// an empty context index still satisfies the rule.
		$assignment = $this->assignment(['orgUnitId' => 'unit-retired', 'startDate' => '2020-01-01', 'endDate' => '2020-01-01']);

		$this->assertTrue(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $this->context()));

	}//end testSameDayStartAndEndIsCoherentAndSatisfiedWithoutNeedingAnActiveUnit()

	/**
	 * @return void
	 */
	public function testActiveAssignmentOnInactiveUnitViolates(): void {
		$assignment = $this->assignment(['orgUnitId' => 'unit-retired', 'endDate' => null]);
		$context = $this->context(['unit-retired' => ['id' => 'unit-retired', 'parentUnitId' => '', 'active' => false]]);

		$this->assertFalse(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $context));

	}//end testActiveAssignmentOnInactiveUnitViolates()

	/**
	 * @return void
	 */
	public function testUnparseableEndDateReadsAsStillActiveAndChecksTheUnit(): void {
		// An endDate that parses to nothing cannot prove the placement ended,
		// so the placement reads as ACTIVE — and an active placement on an
		// inactive unit violates.
		$assignment = $this->assignment(['orgUnitId' => 'unit-x', 'endDate' => 'niet-een-datum']);

		$activeUnit = $this->context(['unit-x' => ['id' => 'unit-x', 'parentUnitId' => '', 'active' => true]]);
		$this->assertTrue(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $activeUnit));

		$inactiveUnit = $this->context(['unit-x' => ['id' => 'unit-x', 'parentUnitId' => '', 'active' => false]]);
		$this->assertFalse(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $inactiveUnit));

	}//end testUnparseableEndDateReadsAsStillActiveAndChecksTheUnit()

	/**
	 * @return void
	 */
	public function testEndedAssignmentOnInactiveUnitSatisfied(): void {
		$assignment = $this->assignment(
			[
				'orgUnitId' => 'unit-retired',
				'startDate' => '2020-01-01',
				'endDate' => '2020-06-01',
			]
		);
		$context = $this->context(['unit-retired' => ['id' => 'unit-retired', 'parentUnitId' => '', 'active' => false]]);

		$this->assertTrue(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $context));

	}//end testEndedAssignmentOnInactiveUnitSatisfied()

	/**
	 * @return void
	 */
	public function testActiveAssignmentWithFutureEndDateOnInactiveUnitViolates(): void {
		$assignment = $this->assignment(
			[
				'orgUnitId' => 'unit-retired',
				'startDate' => '2020-01-01',
				'endDate' => date('Y-m-d', strtotime('+30 days')),
			]
		);
		$context = $this->context(['unit-retired' => ['id' => 'unit-retired', 'parentUnitId' => '', 'active' => false]]);

		$this->assertFalse(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $context));

	}//end testActiveAssignmentWithFutureEndDateOnInactiveUnitViolates()

	/**
	 * @return void
	 */
	public function testDanglingOrgUnitReferenceFailsClosed(): void {
		$assignment = $this->assignment(['orgUnitId' => 'no-such-unit', 'endDate' => null]);
		$context = $this->context(['unit-consultancy' => ['id' => 'unit-consultancy', 'parentUnitId' => '', 'active' => true]]);

		$this->assertFalse(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $context));

	}//end testDanglingOrgUnitReferenceFailsClosed()

	/**
	 * @return void
	 */
	public function testEmptyOrgUnitReferenceFailsClosed(): void {
		$assignment = $this->assignment(['orgUnitId' => '', 'endDate' => null]);

		$this->assertFalse(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, $this->context()));

	}//end testEmptyOrgUnitReferenceFailsClosed()

	/**
	 * @return void
	 */
	public function testActiveAssignmentWithEmptyContextIndexFailsClosed(): void {
		$assignment = $this->assignment(['orgUnitId' => 'unit-consultancy', 'endDate' => null]);

		// No `related.OrgUnit` index at all (schema not yet imported) — the
		// active-unit lookup degrades to empty, and an active assignment
		// that cannot resolve its unit fails closed.
		$this->assertFalse(($this->assignmentChecks['nl-org-assignment-consistency'])($assignment, []));

	}//end testActiveAssignmentWithEmptyContextIndexFailsClosed()

	// -- nl-org-unit-cycle --

	/**
	 * A minimal OrgUnit fixture; each test overrides the fields it exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function unit(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'unit-root',
				'name' => 'Root',
				'type' => 'afdeling',
				'parentUnitId' => '',
				'active' => true,
			],
			$overrides
		);

	}//end unit()

	/**
	 * @return void
	 */
	public function testRootUnitWithNoParentIsAcyclic(): void {
		$unit = $this->unit(['id' => 'unit-root', 'parentUnitId' => '']);
		$context = $this->context();

		$this->assertTrue(($this->unitChecks['nl-org-unit-cycle'])($unit, $context));

	}//end testRootUnitWithNoParentIsAcyclic()

	/**
	 * @return void
	 */
	public function testDeepAcyclicChainPasses(): void {
		$context = $this->context(
			[
				'unit-directie' => ['id' => 'unit-directie', 'parentUnitId' => '', 'active' => true],
				'unit-consultancy' => ['id' => 'unit-consultancy', 'parentUnitId' => 'unit-directie', 'active' => true],
				'unit-backoffice' => ['id' => 'unit-backoffice', 'parentUnitId' => 'unit-directie', 'active' => true],
			]
		);

		$this->assertTrue(($this->unitChecks['nl-org-unit-cycle'])($this->unit(['id' => 'unit-directie', 'parentUnitId' => '']), $context));
		$this->assertTrue(($this->unitChecks['nl-org-unit-cycle'])($this->unit(['id' => 'unit-consultancy', 'parentUnitId' => 'unit-directie']), $context));
		$this->assertTrue(($this->unitChecks['nl-org-unit-cycle'])($this->unit(['id' => 'unit-backoffice', 'parentUnitId' => 'unit-directie']), $context));

	}//end testDeepAcyclicChainPasses()

	/**
	 * @return void
	 */
	public function testDanglingParentEndsWalkWithoutViolation(): void {
		$unit = $this->unit(['id' => 'unit-orphan', 'parentUnitId' => 'no-such-unit']);
		$context = $this->context();

		$this->assertTrue(($this->unitChecks['nl-org-unit-cycle'])($unit, $context));

	}//end testDanglingParentEndsWalkWithoutViolation()

	/**
	 * @return void
	 */
	public function testSelfParentedUnitViolates(): void {
		$unit = $this->unit(['id' => 'unit-self', 'parentUnitId' => 'unit-self']);
		$context = $this->context(['unit-self' => ['id' => 'unit-self', 'parentUnitId' => 'unit-self', 'active' => true]]);

		$this->assertFalse(($this->unitChecks['nl-org-unit-cycle'])($unit, $context));

	}//end testSelfParentedUnitViolates()

	/**
	 * @return void
	 */
	public function testTwoNodeCycleViolatesForBothMembers(): void {
		$context = $this->context(
			[
				'unit-a' => ['id' => 'unit-a', 'parentUnitId' => 'unit-b', 'active' => true],
				'unit-b' => ['id' => 'unit-b', 'parentUnitId' => 'unit-a', 'active' => true],
			]
		);

		$this->assertFalse(($this->unitChecks['nl-org-unit-cycle'])($this->unit(['id' => 'unit-a', 'parentUnitId' => 'unit-b']), $context));
		$this->assertFalse(($this->unitChecks['nl-org-unit-cycle'])($this->unit(['id' => 'unit-b', 'parentUnitId' => 'unit-a']), $context));

	}//end testTwoNodeCycleViolatesForBothMembers()

	/**
	 * @return void
	 */
	public function testUnitCycleCheckWithEmptyContextIndexDoesNotFalselyViolate(): void {
		$unit = $this->unit(['id' => 'unit-directie', 'parentUnitId' => 'unit-consultancy']);

		// An empty/absent related-context index cannot resolve any parent, so
		// the walk ends immediately (a missing-node, not a cycle).
		$this->assertTrue(($this->unitChecks['nl-org-unit-cycle'])($unit, []));

	}//end testUnitCycleCheckWithEmptyContextIndexDoesNotFalselyViolate()

	// -- nl-mss-manager-consistency (mss-team-scope) --

	/**
	 * A minimal Timesheet-shaped record fixture; each test overrides the
	 * fields it exercises. Shared shape across Timesheet/Expense/LeaveRequest
	 * — all three carry the same `employeeId`/`managerUserId` fields.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function managerScopedRecord(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => 'employee-jansen',
				'managerUserId' => 'admin',
			],
			$overrides
		);

	}//end managerScopedRecord()

	/**
	 * A `context['related']` fixture matching RuleAuditService's pre-pass
	 * shape for the manager-consistency predicate: OrgAssignment.byEmployeeId,
	 * OrgUnit.byId (with managerId) and Employee.byId (with nextcloudUserId).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $assignmentsByEmployeeId OrgAssignment index.
	 * @param array<string, array<string, mixed>> $unitsById OrgUnit index.
	 * @param array<string, array<string, mixed>> $employeesById Employee index.
	 *
	 * @return array<string, mixed>
	 */
	private function managerContext(array $assignmentsByEmployeeId = [], array $unitsById = [], array $employeesById = []): array {
		return [
			'related' => [
				'OrgAssignment' => ['byEmployeeId' => $assignmentsByEmployeeId],
				'OrgUnit' => ['byId' => $unitsById],
				'Employee' => ['byId' => $employeesById],
			],
		];

	}//end managerContext()

	/**
	 * @return void
	 */
	public function testMatchingStampOnActivePlacementIsSatisfied(): void {
		$record = $this->managerScopedRecord(['managerUserId' => 'admin']);
		$context = $this->managerContext(
			['employee-jansen' => [['orgUnitId' => 'orgunit-consultancy', 'endDate' => '']]],
			['orgunit-consultancy' => ['id' => 'orgunit-consultancy', 'managerId' => 'employee-jansen']],
			['employee-jansen' => ['id' => 'employee-jansen', 'nextcloudUserId' => 'admin']]
		);

		$this->assertTrue(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $context));

	}//end testMatchingStampOnActivePlacementIsSatisfied()

	/**
	 * @return void
	 */
	public function testMismatchingStampOnFullyResolvedChainViolates(): void {
		$record = $this->managerScopedRecord(['managerUserId' => 'someone-else']);
		$context = $this->managerContext(
			['employee-jansen' => [['orgUnitId' => 'orgunit-consultancy', 'endDate' => '']]],
			['orgunit-consultancy' => ['id' => 'orgunit-consultancy', 'managerId' => 'employee-devries']],
			['employee-devries' => ['id' => 'employee-devries', 'nextcloudUserId' => 'admin']]
		);

		$this->assertFalse(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $context));

	}//end testMismatchingStampOnFullyResolvedChainViolates()

	/**
	 * @return void
	 */
	public function testAbsentManagerUserIdStampIsVacuous(): void {
		$record = $this->managerScopedRecord(['managerUserId' => '']);

		$this->assertTrue(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $this->managerContext()));

	}//end testAbsentManagerUserIdStampIsVacuous()

	/**
	 * @return void
	 */
	public function testEmptyEmployeeIdIsVacuous(): void {
		$record = $this->managerScopedRecord(['employeeId' => '', 'managerUserId' => 'admin']);

		$this->assertTrue(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $this->managerContext()));

	}//end testEmptyEmployeeIdIsVacuous()

	/**
	 * @return void
	 */
	public function testNoActiveAssignmentIsVacuous(): void {
		$record = $this->managerScopedRecord();

		// No OrgAssignment index entry at all for this employee.
		$this->assertTrue(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $this->managerContext()));

	}//end testNoActiveAssignmentIsVacuous()

	/**
	 * @return void
	 */
	public function testUnmanagedUnitIsVacuous(): void {
		$record = $this->managerScopedRecord();
		$context = $this->managerContext(
			['employee-jansen' => [['orgUnitId' => 'orgunit-backoffice', 'endDate' => '']]],
			['orgunit-backoffice' => ['id' => 'orgunit-backoffice', 'managerId' => '']]
		);

		$this->assertTrue(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $context));

	}//end testUnmanagedUnitIsVacuous()

	/**
	 * @return void
	 */
	public function testUnresolvableManagerEmployeeIsVacuous(): void {
		$record = $this->managerScopedRecord();
		$context = $this->managerContext(
			['employee-jansen' => [['orgUnitId' => 'orgunit-consultancy', 'endDate' => '']]],
			['orgunit-consultancy' => ['id' => 'orgunit-consultancy', 'managerId' => 'no-such-employee']]
		);

		$this->assertTrue(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $context));

	}//end testUnresolvableManagerEmployeeIsVacuous()

	/**
	 * @return void
	 */
	public function testManagerWithoutNextcloudAccountIsVacuous(): void {
		$record = $this->managerScopedRecord();
		$context = $this->managerContext(
			['employee-jansen' => [['orgUnitId' => 'orgunit-consultancy', 'endDate' => '']]],
			['orgunit-consultancy' => ['id' => 'orgunit-consultancy', 'managerId' => 'employee-devries']],
			['employee-devries' => ['id' => 'employee-devries', 'nextcloudUserId' => '']]
		);

		$this->assertTrue(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $context));

	}//end testManagerWithoutNextcloudAccountIsVacuous()

	/**
	 * @return void
	 */
	public function testInactiveAssignmentIsSkippedLeavingTheChainUnresolvedAndVacuous(): void {
		// A single, already-ended placement is skipped entirely (not
		// "no active assignment", but the loop finds zero active rows to
		// resolve through) — the overall predicate still passes vacuously.
		$record = $this->managerScopedRecord();
		$context = $this->managerContext(
			['employee-jansen' => [['orgUnitId' => 'orgunit-consultancy', 'endDate' => '2020-01-01']]],
			['orgunit-consultancy' => ['id' => 'orgunit-consultancy', 'managerId' => 'employee-devries']],
			['employee-devries' => ['id' => 'employee-devries', 'nextcloudUserId' => 'someone-else']]
		);

		$this->assertTrue(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $context));

	}//end testInactiveAssignmentIsSkippedLeavingTheChainUnresolvedAndVacuous()

	/**
	 * @return void
	 */
	public function testMultipleActiveAssignmentsAnyMatchPasses(): void {
		// Two concurrent active placements: the first resolves to a manager
		// that does NOT match the stamp, the second resolves to one that
		// does — any-match passes.
		$record = $this->managerScopedRecord(['managerUserId' => 'admin']);
		$context = $this->managerContext(
			[
				'employee-jansen' => [
					['orgUnitId' => 'orgunit-backoffice', 'endDate' => ''],
					['orgUnitId' => 'orgunit-consultancy', 'endDate' => ''],
				],
			],
			[
				'orgunit-backoffice' => ['id' => 'orgunit-backoffice', 'managerId' => 'employee-devries'],
				'orgunit-consultancy' => ['id' => 'orgunit-consultancy', 'managerId' => 'employee-jansen'],
			],
			[
				'employee-devries' => ['id' => 'employee-devries', 'nextcloudUserId' => 'someone-else'],
				'employee-jansen' => ['id' => 'employee-jansen', 'nextcloudUserId' => 'admin'],
			]
		);

		$this->assertTrue(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $context));

	}//end testMultipleActiveAssignmentsAnyMatchPasses()

	/**
	 * @return void
	 */
	public function testMultipleActiveAssignmentsNoMatchViolates(): void {
		// Two concurrent active placements, both resolving to managers that
		// do NOT match the stamp — a provable mismatch across every chain.
		$record = $this->managerScopedRecord(['managerUserId' => 'admin']);
		$context = $this->managerContext(
			[
				'employee-jansen' => [
					['orgUnitId' => 'orgunit-backoffice', 'endDate' => ''],
					['orgUnitId' => 'orgunit-consultancy', 'endDate' => ''],
				],
			],
			[
				'orgunit-backoffice' => ['id' => 'orgunit-backoffice', 'managerId' => 'employee-devries'],
				'orgunit-consultancy' => ['id' => 'orgunit-consultancy', 'managerId' => 'employee-visser'],
			],
			[
				'employee-devries' => ['id' => 'employee-devries', 'nextcloudUserId' => 'someone-else'],
				'employee-visser' => ['id' => 'employee-visser', 'nextcloudUserId' => 'someone-else-too'],
			]
		);

		$this->assertFalse(($this->timesheetChecks['nl-mss-manager-consistency'])($record, $context));

	}//end testMultipleActiveAssignmentsNoMatchViolates()

	/**
	 * @return void
	 */
	public function testManagerConsistencySharedAcrossExpenseAndLeaveRequest(): void {
		$checks = NlOrgChecks::checks();

		$this->assertArrayHasKey('nl-mss-manager-consistency', $checks['Expense']);
		$this->assertArrayHasKey('nl-mss-manager-consistency', $checks['LeaveRequest']);

		$record = $this->managerScopedRecord(['managerUserId' => 'admin']);
		$context = $this->managerContext(
			['employee-jansen' => [['orgUnitId' => 'orgunit-consultancy', 'endDate' => '']]],
			['orgunit-consultancy' => ['id' => 'orgunit-consultancy', 'managerId' => 'employee-jansen']],
			['employee-jansen' => ['id' => 'employee-jansen', 'nextcloudUserId' => 'admin']]
		);

		$this->assertTrue(($checks['Expense']['nl-mss-manager-consistency'])($record, $context));
		$this->assertTrue(($checks['LeaveRequest']['nl-mss-manager-consistency'])($record, $context));

	}//end testManagerConsistencySharedAcrossExpenseAndLeaveRequest()

}//end class
