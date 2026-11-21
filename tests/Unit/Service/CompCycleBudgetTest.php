<?php

/**
 * Unit tests for CompCycleBudget.
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
 * @spec openspec/specs/comp-cycles/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CompCycleBudget;
use OCA\Humaniq\Service\RecordStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * A cycle's raises against its budget.
 *
 * @covers \OCA\Humaniq\Service\CompCycleBudget
 */
class CompCycleBudgetTest extends TestCase {

	/**
	 * The budget service over a fixed set of adjustments for cycle-1.
	 *
	 * @param array<int, array<string, mixed>> $adjustments The cycle's adjustments.
	 *
	 * @return CompCycleBudget
	 */
	private function budget(array $adjustments): CompCycleBudget {
		$gateway = $this->createMock(RecordStoreInterface::class);
		$gateway->expects($this->once())
			->method('findFiltered')
			->with('CompAdjustment', ['cycleId' => 'cycle-1'])
			->willReturn($adjustments);

		return new CompCycleBudget($gateway);
	}//end budget()

	/**
	 * A 10,000 euro budget with raises of 2,500 and 3,000 euros: 5,500 used, 4,500 left.
	 *
	 * @return void
	 */
	public function testTheBudgetIsPartlyUsed(): void {
		$result = $this->budget(
			[
				['status' => 'proposed', 'currentSalary' => 400000, 'proposedSalary' => 650000],
				['status' => 'approved', 'currentSalary' => 500000, 'proposedSalary' => 800000],
			]
		)->forCycle(['id' => 'cycle-1', 'budgetAmount' => 1000000]);

		$this->assertSame(1000000, $result['budgetAmount']);
		$this->assertSame(550000, $result['used']);
		$this->assertSame(450000, $result['left']);
		$this->assertSame(0, $result['overBudget']);
		$this->assertFalse($result['isOverBudget']);
		$this->assertSame(2, $result['adjustments']);
		$this->assertSame(['budget' => 10000.0, 'used' => 5500.0, 'left' => 4500.0, 'overBudget' => 0.0], $result['euro']);
	}//end testTheBudgetIsPartlyUsed()

	/**
	 * With 1,000 euros left, a 1,500 euro proposal puts the cycle 500 euros over budget.
	 *
	 * @return void
	 */
	public function testAProposalOverBudgetIsFlagged(): void {
		$result = $this->budget(
			[
				['status' => 'effective', 'currentSalary' => 300000, 'proposedSalary' => 1200000],
				['status' => 'proposed', 'currentSalary' => 300000, 'proposedSalary' => 450000],
			]
		)->forCycle(['id' => 'cycle-1', 'budgetAmount' => 1000000]);

		$this->assertSame(1050000, $result['used']);
		$this->assertSame(0, $result['left']);
		$this->assertSame(50000, $result['overBudget']);
		$this->assertTrue($result['isOverBudget']);
		$this->assertSame(500.0, $result['euro']['overBudget']);
	}//end testAProposalOverBudgetIsFlagged()

	/**
	 * Drafts and refused proposals do not spend the budget, a cut is not a
	 * negative cost, and a proposal without a salary snapshot adds nothing.
	 *
	 * @return void
	 */
	public function testOnlySpendingRaisesCount(): void {
		$result = $this->budget(
			[
				['status' => 'draft', 'currentSalary' => 100000, 'proposedSalary' => 900000],
				['status' => 'refused', 'currentSalary' => 100000, 'proposedSalary' => 900000],
				['status' => 'approved', 'currentSalary' => 500000, 'proposedSalary' => 450000],
				['status' => 'proposed', 'currentSalary' => null, 'proposedSalary' => 450000],
				['status' => 'proposed', 'currentSalary' => '200000', 'proposedSalary' => '210000'],
			]
		)->forCycle(['id' => 'cycle-1', 'budgetAmount' => '50000']);

		$this->assertSame(10000, $result['used']);
		$this->assertSame(3, $result['adjustments']);
		$this->assertSame(40000, $result['left']);
	}//end testOnlySpendingRaisesCount()

	/**
	 * A cycle without a budget reports what is used, and no left or over figure.
	 *
	 * @return void
	 */
	public function testACycleWithoutABudget(): void {
		$result = $this->budget(
			[['status' => 'proposed', 'currentSalary' => 100000, 'proposedSalary' => 110000]]
		)->forCycle(['id' => 'cycle-1']);

		$this->assertNull($result['budgetAmount']);
		$this->assertSame(10000, $result['used']);
		$this->assertNull($result['left']);
		$this->assertSame(0, $result['overBudget']);
		$this->assertFalse($result['isOverBudget']);
		$this->assertNull($result['euro']['budget']);
		$this->assertNull($result['euro']['left']);
	}//end testACycleWithoutABudget()
}//end class
