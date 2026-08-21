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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\OrgResolutionService;
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

}//end class
