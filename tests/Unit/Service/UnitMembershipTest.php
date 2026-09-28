<?php

/**
 * Unit tests for UnitMembership: which units sit under a unit, who was
 * placed there in a period and for what share of it, and which units a
 * manager leads.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\UnitMembership;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UnitMembership.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
class UnitMembershipTest extends TestCase {

	/**
	 * The org tree of the tests: Gemeente with Burgerzaken (and its team
	 * Balie) and Belastingen.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function units(): array {
		return [
			['id' => 'gemeente', 'name' => 'Gemeente', 'parentUnitId' => null, 'managerId' => 'emp-director'],
			['id' => 'burgerzaken', 'name' => 'Burgerzaken', 'parentUnitId' => 'gemeente', 'managerId' => 'emp-lead'],
			['id' => 'balie', 'name' => 'Balie', 'parentUnitId' => 'burgerzaken', 'managerId' => null],
			['id' => 'belastingen', 'name' => 'Belastingen', 'parentUnitId' => 'gemeente', 'managerId' => 'emp-other'],
		];
	}//end units()

	/**
	 * A unit's figures include its children, and a unit that loops back on
	 * itself does not hang the walk.
	 *
	 * @return void
	 */
	public function testTheSubtreeHoldsTheUnitAndItsChildren(): void {
		$membership = new UnitMembership();

		$this->assertSame(['burgerzaken', 'balie'], $membership->subtree('burgerzaken', $this->units()));
		$this->assertSame(['belastingen'], $membership->subtree('belastingen', $this->units()));

		$loop = [
			['id' => 'a', 'parentUnitId' => 'b'],
			['id' => 'b', 'parentUnitId' => 'a'],
		];
		$this->assertSame(['a', 'b'], $membership->subtree('a', $loop));
	}//end testTheSubtreeHoldsTheUnitAndItsChildren()

	/**
	 * An employee placed in a child unit counts for the parent, and one who
	 * moved in halfway through June counts for the days they were there.
	 *
	 * @return void
	 */
	public function testAMoverMidPeriodCountsForTheDaysPlaced(): void {
		$membership = new UnitMembership();
		$assignments = [
			['employeeId' => 'emp-1', 'orgUnitId' => 'balie', 'startDate' => '2025-01-01', 'endDate' => null],
			['employeeId' => 'emp-2', 'orgUnitId' => 'belastingen', 'startDate' => '2025-01-01', 'endDate' => '2026-06-15'],
			['employeeId' => 'emp-2', 'orgUnitId' => 'burgerzaken', 'startDate' => '2026-06-16', 'endDate' => null],
			['employeeId' => 'emp-3', 'orgUnitId' => 'belastingen', 'startDate' => '2026-07-01', 'endDate' => null],
		];

		$shares = $membership->sharesInWindow(
			['burgerzaken', 'balie'],
			$assignments,
			new DateTimeImmutable('2026-06-01'),
			new DateTimeImmutable('2026-06-30')
		);

		$this->assertSame(['emp-1' => 1.0, 'emp-2' => 0.5], $shares);

		$belastingen = $membership->sharesInWindow(
			['belastingen'],
			$assignments,
			new DateTimeImmutable('2026-06-01'),
			new DateTimeImmutable('2026-06-30')
		);
		$this->assertSame(['emp-2' => 0.5], $belastingen);
	}//end testAMoverMidPeriodCountsForTheDaysPlaced()

	/**
	 * Two overlapping placements in the same subtree do not count one person
	 * twice.
	 *
	 * @return void
	 */
	public function testOverlappingPlacementsCountOnce(): void {
		$membership = new UnitMembership();
		$assignments = [
			['employeeId' => 'emp-1', 'orgUnitId' => 'burgerzaken', 'startDate' => '2026-01-01', 'endDate' => null],
			['employeeId' => 'emp-1', 'orgUnitId' => 'balie', 'startDate' => '2026-06-01', 'endDate' => null],
		];

		$shares = $membership->sharesInWindow(
			['burgerzaken', 'balie'],
			$assignments,
			new DateTimeImmutable('2026-06-01'),
			new DateTimeImmutable('2026-06-30')
		);

		$this->assertSame(['emp-1' => 1.0], $shares);
	}//end testOverlappingPlacementsCountOnce()

	/**
	 * Without units, the population of a period is everyone with a contract
	 * in it, for the share of the period the contract ran.
	 *
	 * @return void
	 */
	public function testContractSharesCoverThePeriodTheContractRan(): void {
		$membership = new UnitMembership();
		$contracts = [
			['employeeId' => 'emp-1', 'startDate' => '2020-01-01', 'endDate' => null],
			['employeeId' => 'emp-2', 'startDate' => '2026-06-16', 'endDate' => null],
			['employeeId' => 'emp-3', 'startDate' => '2020-01-01', 'endDate' => '2026-05-31'],
		];

		$shares = $membership->contractShares($contracts, new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'));

		$this->assertSame(['emp-1' => 1.0, 'emp-2' => 0.5], $shares);
	}//end testContractSharesCoverThePeriodTheContractRan()

	/**
	 * A manager leads the units whose manager is their employee record; a
	 * user who manages nothing leads nothing.
	 *
	 * @return void
	 */
	public function testAManagerLeadsTheUnitsNamingThem(): void {
		$membership = new UnitMembership();
		$employees = [
			['id' => 'emp-lead', 'nextcloudUserId' => 'lead'],
			['id' => 'emp-other', 'nextcloudUserId' => 'other'],
			['id' => 'emp-1', 'nextcloudUserId' => 'jansen'],
		];

		$this->assertSame(['burgerzaken'], $membership->managedUnitIds('lead', $this->units(), $employees));
		$this->assertSame(['belastingen'], $membership->managedUnitIds('other', $this->units(), $employees));
		$this->assertSame([], $membership->managedUnitIds('jansen', $this->units(), $employees));
		$this->assertSame([], $membership->managedUnitIds('', $this->units(), $employees));
	}//end testAManagerLeadsTheUnitsNamingThem()

}//end class
