<?php

/**
 * OrgResolutionService unit tests
 *
 * The shared employee → active-assignment → unit chain (hours-process-
 * redesign Decision 5): manager resolution, cost-centre resolution, the
 * activeness window, and the never-guessed stamp reduction. These are the
 * SAME index shapes RuleAuditService::buildRelatedContext() feeds the
 * nl-mss-manager-consistency audit, so a behaviour drift here is a
 * stamp/audit disagreement.
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
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\OrgResolutionService;
use PHPUnit\Framework\TestCase;

/**
 * Chain resolution, vacuous hops, activeness and the stamp reduction.
 */
class OrgResolutionServiceTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var OrgResolutionService
	 */
	private OrgResolutionService $service;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new OrgResolutionService();
	}//end setUp()

	/**
	 * A complete, single-placement chain: assignment → unit → manager
	 * Employee → nextcloudUserId, plus the unit's cost centre.
	 *
	 * @return void
	 */
	public function testResolvesManagerAndCostCenterThroughFullChain(): void {
		$assignments = ['emp-1' => [['orgUnitId' => 'unit-1', 'endDate' => '']]];
		$units = ['unit-1' => ['id' => 'unit-1', 'managerId' => 'emp-mgr', 'costCenter' => 'CC-100']];
		$employees = ['emp-mgr' => ['id' => 'emp-mgr', 'nextcloudUserId' => 'admin']];

		$managers = $this->service->resolveManagerUserIds('emp-1', $assignments, $units, $employees, '2026-08-21');
		$this->assertSame(['admin'], $managers);
		$this->assertSame('admin', $this->service->uniqueOrNull($managers));

		$costCenters = $this->service->resolveCostCenters('emp-1', $assignments, $units, '2026-08-21');
		$this->assertSame(['CC-100'], $costCenters);
		$this->assertSame('CC-100', $this->service->uniqueOrNull($costCenters));
	}//end testResolvesManagerAndCostCenterThroughFullChain()

	/**
	 * Every missing hop resolves to NOTHING, never to an error or a guess:
	 * no assignments, dangling unit, unmanaged unit, unresolvable manager,
	 * manager without an account.
	 *
	 * @return void
	 */
	public function testVacuousWhenAnyHopIsMissing(): void {
		$date = '2026-08-21';

		// No assignments at all.
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-1', [], [], [], $date));

		// Dangling unit.
		$assignments = ['emp-1' => [['orgUnitId' => 'unit-gone', 'endDate' => '']]];
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-1', $assignments, [], [], $date));

		// Unmanaged unit.
		$units = ['unit-1' => ['id' => 'unit-1', 'managerId' => '']];
		$assignments = ['emp-1' => [['orgUnitId' => 'unit-1', 'endDate' => '']]];
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-1', $assignments, $units, [], $date));

		// Manager Employee unresolvable.
		$units = ['unit-1' => ['id' => 'unit-1', 'managerId' => 'emp-mgr']];
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-1', $assignments, $units, [], $date));

		// Manager without a Nextcloud account.
		$employees = ['emp-mgr' => ['id' => 'emp-mgr', 'nextcloudUserId' => '']];
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-1', $assignments, $units, $employees, $date));

		// Unit without a cost centre.
		$this->assertSame([], $this->service->resolveCostCenters('emp-1', $assignments, $units, $date));
	}//end testVacuousWhenAnyHopIsMissing()

	/**
	 * An expired placement contributes nothing; an open-ended or future-
	 * ending one does. A placement that has not started yet is inactive.
	 *
	 * @return void
	 */
	public function testActivenessWindowFiltersPlacements(): void {
		$units = [
			'unit-old' => ['id' => 'unit-old', 'managerId' => 'emp-old-mgr', 'costCenter' => 'CC-OLD'],
			'unit-new' => ['id' => 'unit-new', 'managerId' => 'emp-new-mgr', 'costCenter' => 'CC-NEW'],
		];
		$employees = [
			'emp-old-mgr' => ['id' => 'emp-old-mgr', 'nextcloudUserId' => 'old-manager'],
			'emp-new-mgr' => ['id' => 'emp-new-mgr', 'nextcloudUserId' => 'new-manager'],
		];
		$assignments = [
			'emp-1' => [
				['orgUnitId' => 'unit-old', 'startDate' => '2020-01-01', 'endDate' => '2024-12-31'],
				['orgUnitId' => 'unit-new', 'startDate' => '2025-01-01', 'endDate' => ''],
			],
		];

		$this->assertSame(
			['new-manager'],
			$this->service->resolveManagerUserIds('emp-1', $assignments, $units, $employees, '2026-08-21'),
			'An expired placement must not resolve a manager.'
		);

		// On a date inside the OLD placement, the old chain resolves instead
		// (the new one has not started).
		$this->assertSame(
			['old-manager'],
			$this->service->resolveManagerUserIds('emp-1', $assignments, $units, $employees, '2024-06-01'),
			'A placement that has not started yet must not resolve.'
		);

		$this->assertSame(
			['CC-NEW'],
			$this->service->resolveCostCenters('emp-1', $assignments, $units, '2026-08-21')
		);
	}//end testActivenessWindowFiltersPlacements()

	/**
	 * Two concurrent placements with two managers: the audit keeps the full
	 * any-match list; the stamp reduction refuses to guess (null).
	 *
	 * @return void
	 */
	public function testMultiplePlacementsResolveAllButStampNothing(): void {
		$units = [
			'unit-a' => ['id' => 'unit-a', 'managerId' => 'emp-a', 'costCenter' => 'CC-A'],
			'unit-b' => ['id' => 'unit-b', 'managerId' => 'emp-b', 'costCenter' => 'CC-B'],
		];
		$employees = [
			'emp-a' => ['id' => 'emp-a', 'nextcloudUserId' => 'manager-a'],
			'emp-b' => ['id' => 'emp-b', 'nextcloudUserId' => 'manager-b'],
		];
		$assignments = [
			'emp-1' => [
				['orgUnitId' => 'unit-a', 'endDate' => ''],
				['orgUnitId' => 'unit-b', 'endDate' => ''],
			],
		];

		$managers = $this->service->resolveManagerUserIds('emp-1', $assignments, $units, $employees, '2026-08-21');
		$this->assertSame(['manager-a', 'manager-b'], $managers);
		$this->assertNull($this->service->uniqueOrNull($managers), 'Two distinct managers must stamp NOTHING (never guessed).');
		$this->assertNull($this->service->uniqueOrNull([]), 'An empty resolution must stamp nothing (fail-closed).');

		// Two placements in the SAME unit collapse to one distinct manager —
		// that IS stampable.
		$assignments = [
			'emp-1' => [
				['orgUnitId' => 'unit-a', 'endDate' => ''],
				['orgUnitId' => 'unit-a', 'endDate' => ''],
			],
		];
		$managers = $this->service->resolveManagerUserIds('emp-1', $assignments, $units, $employees, '2026-08-21');
		$this->assertSame('manager-a', $this->service->uniqueOrNull($managers));
	}//end testMultiplePlacementsResolveAllButStampNothing()

	/**
	 * Malformed dates fail open (treated as absent), matching the audit's
	 * historical leniency.
	 *
	 * @return void
	 */
	public function testMalformedDatesAreTreatedAsAbsent(): void {
		$this->assertTrue($this->service->isActiveOn(['startDate' => 'not-a-date', 'endDate' => 'also-not'], '2026-08-21'));
		$this->assertTrue($this->service->isActiveOn([], '2026-08-21'));
		$this->assertFalse($this->service->isActiveOn(['endDate' => '2020-01-01'], '2026-08-21'));
	}//end testMalformedDatesAreTreatedAsAbsent()

	/**
	 * An unparseable REFERENCE date falls back to today — a caller handing a
	 * malformed onDate gets today's activeness, never a crash or vacuous no.
	 *
	 * @return void
	 */
	public function testUnparseableReferenceDateFallsBackToToday(): void {
		$this->assertTrue($this->service->isActiveOn(['startDate' => '2020-01-01', 'endDate' => ''], 'geen-datum'));
		$this->assertFalse($this->service->isActiveOn(['endDate' => '2020-01-01'], 'geen-datum'), 'A long-ended placement is inactive against today.');
	}//end testUnparseableReferenceDateFallsBackToToday()

	/**
	 * Malformed index shapes resolve to nothing, never to an error: an empty
	 * employeeId, a non-array assignments entry, and a placement without an
	 * orgUnitId all contribute zero hops.
	 *
	 * @return void
	 */
	public function testMalformedIndexShapesResolveNothing(): void {
		$unitsById = ['unit-1' => ['managerId' => 'employee-m', 'costCenter' => 'CC-1']];
		$employeesById = ['employee-m' => ['nextcloudUserId' => 'manager1']];

		$this->assertSame([], $this->service->resolveManagerUserIds(
			employeeId: '',
			assignmentsByEmployeeId: ['employee-x' => [['orgUnitId' => 'unit-1']]],
			unitsById: $unitsById,
			employeesById: $employeesById,
			onDate: '2026-08-21'
		), 'An empty employeeId resolves nothing.');

		$this->assertSame([], $this->service->resolveManagerUserIds(
			employeeId: 'employee-x',
			assignmentsByEmployeeId: ['employee-x' => 'not-an-array'],
			unitsById: $unitsById,
			employeesById: $employeesById,
			onDate: '2026-08-21'
		), 'A non-array assignments entry resolves nothing.');

		$this->assertSame([], $this->service->resolveManagerUserIds(
			employeeId: 'employee-x',
			assignmentsByEmployeeId: ['employee-x' => [['orgUnitId' => '', 'endDate' => '']]],
			unitsById: $unitsById,
			employeesById: $employeesById,
			onDate: '2026-08-21'
		), 'A placement without an orgUnitId contributes no hop.');
	}//end testMalformedIndexShapesResolveNothing()

	/**
	 * Sanne manages Consultancy and sits in it; Directie above is managed
	 * by Pieter. Her manager is Pieter, never herself.
	 *
	 * @return void
	 */
	public function testASelfManagedPlacementClimbsToTheParentUnitManager(): void {
		$assignments = ['emp-sanne' => [['orgUnitId' => 'unit-consultancy', 'endDate' => '']]];
		$units = [
			'unit-consultancy' => ['id' => 'unit-consultancy', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-directie'],
			'unit-directie' => ['id' => 'unit-directie', 'managerId' => 'emp-pieter', 'parentUnitId' => ''],
		];
		$employees = [
			'emp-sanne' => ['id' => 'emp-sanne', 'nextcloudUserId' => 'sanne'],
			'emp-pieter' => ['id' => 'emp-pieter', 'nextcloudUserId' => 'pieter'],
		];

		$this->assertSame(['pieter'], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));

		// The same Employee record is self even without an account.
		$employees['emp-sanne']['nextcloudUserId'] = '';
		$this->assertSame(['pieter'], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));
		$employees['emp-sanne']['nextcloudUserId'] = 'sanne';

		// A team member of Consultancy still gets Sanne: only self climbs.
		$assignments['emp-tim'] = [['orgUnitId' => 'unit-consultancy', 'endDate' => '']];
		$this->assertSame(['sanne'], $this->service->resolveManagerUserIds('emp-tim', $assignments, $units, $employees, '2026-11-02'));

		// An unmanaged unit does not climb.
		$units['unit-consultancy']['managerId'] = '';
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-tim', $assignments, $units, $employees, '2026-11-02'));
	}//end testASelfManagedPlacementClimbsToTheParentUnitManager()

	/**
	 * The climb repeats while each unit above is also self-managed.
	 *
	 * @return void
	 */
	public function testTheClimbRepeatsOverSeveralLevels(): void {
		$assignments = ['emp-sanne' => [['orgUnitId' => 'unit-noord', 'endDate' => '']]];
		$units = [
			'unit-noord' => ['id' => 'unit-noord', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-consultancy'],
			'unit-consultancy' => ['id' => 'unit-consultancy', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-directie'],
			'unit-directie' => ['id' => 'unit-directie', 'managerId' => 'emp-pieter', 'parentUnitId' => ''],
		];
		$employees = [
			'emp-sanne' => ['id' => 'emp-sanne', 'nextcloudUserId' => 'sanne'],
			'emp-pieter' => ['id' => 'emp-pieter', 'nextcloudUserId' => 'pieter'],
		];

		$this->assertSame(['pieter'], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));
	}//end testTheClimbRepeatsOverSeveralLevels()

	/**
	 * Another Employee record with the employee's own account is the
	 * employee themself, so the walk climbs past it too.
	 *
	 * @return void
	 */
	public function testTheSameAccountOnAnotherEmployeeRecordCountsAsSelf(): void {
		$assignments = ['emp-sanne' => [['orgUnitId' => 'unit-consultancy', 'endDate' => '']]];
		$units = [
			'unit-consultancy' => ['id' => 'unit-consultancy', 'managerId' => 'emp-sanne-2', 'parentUnitId' => 'unit-directie'],
			'unit-directie' => ['id' => 'unit-directie', 'managerId' => 'emp-pieter', 'parentUnitId' => ''],
		];
		$employees = [
			'emp-sanne' => ['id' => 'emp-sanne', 'nextcloudUserId' => 'sanne'],
			'emp-sanne-2' => ['id' => 'emp-sanne-2', 'nextcloudUserId' => 'sanne'],
			'emp-pieter' => ['id' => 'emp-pieter', 'nextcloudUserId' => 'pieter'],
		];

		$this->assertSame(['pieter'], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));

		// Two people without an account are not the same person.
		$employees['emp-sanne']['nextcloudUserId'] = '';
		$employees['emp-sanne-2']['nextcloudUserId'] = '';
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));
	}//end testTheSameAccountOnAnotherEmployeeRecordCountsAsSelf()

	/**
	 * Self-managed up to a root, or up to a dangling parent: no manager.
	 *
	 * @return void
	 */
	public function testNoOtherManagerUpToTheRootResolvesNothing(): void {
		$assignments = ['emp-sanne' => [['orgUnitId' => 'unit-consultancy', 'endDate' => '']]];
		$units = [
			'unit-consultancy' => ['id' => 'unit-consultancy', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-directie'],
			'unit-directie' => ['id' => 'unit-directie', 'managerId' => 'emp-sanne', 'parentUnitId' => ''],
		];
		$employees = ['emp-sanne' => ['id' => 'emp-sanne', 'nextcloudUserId' => 'sanne']];

		$managers = $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02');
		$this->assertSame([], $managers);
		$this->assertNull($this->service->uniqueOrNull($managers));

		$units['unit-directie']['parentUnitId'] = 'unit-gone';
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));
	}//end testNoOtherManagerUpToTheRootResolvesNothing()

	/**
	 * A loop in the unit tree is walked once and resolves no manager.
	 *
	 * @return void
	 */
	public function testALoopInTheUnitTreeEndsTheClimb(): void {
		$assignments = ['emp-sanne' => [['orgUnitId' => 'unit-consultancy', 'endDate' => '']]];
		$units = [
			'unit-consultancy' => ['id' => 'unit-consultancy', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-directie'],
			'unit-directie' => ['id' => 'unit-directie', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-consultancy'],
		];
		$employees = ['emp-sanne' => ['id' => 'emp-sanne', 'nextcloudUserId' => 'sanne']];

		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));

		$units['unit-consultancy']['parentUnitId'] = 'unit-consultancy';
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));
	}//end testALoopInTheUnitTreeEndsTheClimb()

	/**
	 * The climb stays inside the placement unit's administration: a parent
	 * in another administration, or one without an administration, ends it
	 * with no manager.
	 *
	 * @return void
	 */
	public function testTheClimbStopsAtTheAdministrationBoundary(): void {
		$assignments = ['emp-sanne' => [['orgUnitId' => 'unit-consultancy', 'endDate' => '']]];
		$units = [
			'unit-consultancy' => ['id' => 'unit-consultancy', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-directie', 'administrationId' => 'ADM-1'],
			'unit-directie' => ['id' => 'unit-directie', 'managerId' => 'emp-pieter', 'parentUnitId' => '', 'administrationId' => 'ADM-2'],
		];
		$employees = [
			'emp-sanne' => ['id' => 'emp-sanne', 'nextcloudUserId' => 'sanne'],
			'emp-pieter' => ['id' => 'emp-pieter', 'nextcloudUserId' => 'pieter'],
		];

		$managers = $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02');
		$this->assertSame([], $managers);
		$this->assertNull($this->service->uniqueOrNull($managers));

		$units['unit-directie']['administrationId'] = '';
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));

		unset($units['unit-directie']['administrationId']);
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));

		$units['unit-directie']['administrationId'] = ' ADM-1 ';
		$this->assertSame(['pieter'], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));

		// Without a placement unit administration and without an employee
		// administration there is no boundary.
		$units['unit-consultancy']['administrationId'] = '';
		$units['unit-directie']['administrationId'] = 'ADM-2';
		$this->assertSame(['pieter'], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));
	}//end testTheClimbStopsAtTheAdministrationBoundary()

	/**
	 * A placement unit without an administration takes the employee's own
	 * administration as the boundary.
	 *
	 * @return void
	 */
	public function testAPlacementUnitWithoutAnAdministrationUsesTheEmployeeAdministration(): void {
		$assignments = [
			'emp-sanne' => [['orgUnitId' => 'unit-consultancy', 'endDate' => '']],
			'emp-tim' => [['orgUnitId' => 'unit-consultancy', 'endDate' => '']],
		];
		$units = [
			'unit-consultancy' => ['id' => 'unit-consultancy', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-directie'],
			'unit-directie' => ['id' => 'unit-directie', 'managerId' => 'emp-pieter', 'parentUnitId' => '', 'administrationId' => 'ADM-2'],
		];
		$employees = [
			'emp-sanne' => ['id' => 'emp-sanne', 'nextcloudUserId' => 'sanne', 'administrationId' => 'ADM-1'],
			'emp-tim' => ['id' => 'emp-tim', 'nextcloudUserId' => 'tim', 'administrationId' => 'ADM-1'],
			'emp-pieter' => ['id' => 'emp-pieter', 'nextcloudUserId' => 'pieter', 'administrationId' => 'ADM-2'],
		];

		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));

		// The placement unit itself is never outside the boundary: a team
		// member still gets the unit's own manager.
		$this->assertSame(['sanne'], $this->service->resolveManagerUserIds('emp-tim', $assignments, $units, $employees, '2026-11-02'));

		$employees['emp-sanne']['administrationId'] = ' ADM-2 ';
		$this->assertSame(['pieter'], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));

		// The placement unit's own administration wins over the employee's.
		$units['unit-consultancy']['administrationId'] = 'ADM-1';
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));
	}//end testAPlacementUnitWithoutAnAdministrationUsesTheEmployeeAdministration()

	/**
	 * The boundary is the placement unit's administration at every level,
	 * and it never applies to a placement that does not climb.
	 *
	 * @return void
	 */
	public function testTheAdministrationBoundaryHoldsOverSeveralLevels(): void {
		$assignments = [
			'emp-sanne' => [['orgUnitId' => 'unit-noord', 'endDate' => '']],
			'emp-tim' => [['orgUnitId' => 'unit-noord', 'endDate' => '']],
		];
		$units = [
			'unit-noord' => ['id' => 'unit-noord', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-consultancy', 'administrationId' => 'ADM-1'],
			'unit-consultancy' => ['id' => 'unit-consultancy', 'managerId' => 'emp-sanne', 'parentUnitId' => 'unit-directie', 'administrationId' => 'ADM-1'],
			'unit-directie' => ['id' => 'unit-directie', 'managerId' => 'emp-pieter', 'parentUnitId' => '', 'administrationId' => 'ADM-2'],
		];
		$employees = [
			'emp-sanne' => ['id' => 'emp-sanne', 'nextcloudUserId' => 'sanne'],
			'emp-pieter' => ['id' => 'emp-pieter', 'nextcloudUserId' => 'pieter'],
		];

		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));
		$this->assertSame(['sanne'], $this->service->resolveManagerUserIds('emp-tim', $assignments, $units, $employees, '2026-11-02'));

		// A middle unit in another administration ends the climb even when
		// the unit above it is back in the placement's administration.
		$units['unit-consultancy']['administrationId'] = 'ADM-2';
		$units['unit-directie']['administrationId'] = 'ADM-1';
		$this->assertSame([], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));

		$units['unit-consultancy']['administrationId'] = 'ADM-1';
		$this->assertSame(['pieter'], $this->service->resolveManagerUserIds('emp-sanne', $assignments, $units, $employees, '2026-11-02'));
	}//end testTheAdministrationBoundaryHoldsOverSeveralLevels()

}//end class
