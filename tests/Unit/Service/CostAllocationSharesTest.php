<?php

/**
 * The edge paths of the cost allocation share arithmetic.
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CostAllocationShares;
use PHPUnit\Framework\TestCase;

/**
 * Fixed splits, hours shares and cent splits at their edges.
 */
class CostAllocationSharesTest extends TestCase {

	/**
	 * A fixed split skips rows without a cost centre or a numeric percentage.
	 *
	 * @return void
	 */
	public function testFixedSharesSkipsIncompleteRows(): void {
		$shares = (new CostAllocationShares())->fixedShares(
			[
				'splits' => [
					['costCenter' => ' KP-100 ', 'projectId' => ' ', 'percentage' => '60'],
					['costCenter' => '', 'percentage' => 20],
					['costCenter' => 'KP-200', 'percentage' => 'veel'],
					['costCenter' => 'KP-300', 'projectId' => 'PRJ-1', 'percentage' => 40],
				],
			]
		);

		$this->assertSame(
			[
				['costCenter' => 'KP-100', 'projectId' => null, 'percentage' => 60.0, 'allocationSource' => 'fixed'],
				['costCenter' => 'KP-300', 'projectId' => 'PRJ-1', 'percentage' => 40.0, 'allocationSource' => 'fixed'],
			],
			$shares
		);
		$this->assertSame([], (new CostAllocationShares())->fixedShares([]));
	}//end testFixedSharesSkipsIncompleteRows()

	/**
	 * Hours shares group per cost centre and project, use the fallback for
	 * entries without a cost centre, and ignore zero or non-numeric hours.
	 *
	 * @return void
	 */
	public function testHoursSharesGroupAndFallBack(): void {
		$shares = (new CostAllocationShares())->hoursShares(
			entries: [
				['hours' => 6, 'costCenter' => 'KP-100'],
				['hours' => '2', 'costCenter' => null],
				['hours' => 0, 'costCenter' => 'KP-900'],
				['hours' => 'x', 'costCenter' => 'KP-900'],
				['hours' => 2, 'costCenter' => 'KP-100', 'projectId' => 'PRJ-7'],
			],
			fallbackCostCenter: 'KP-500'
		);

		$this->assertSame(
			[
				['costCenter' => 'KP-100', 'projectId' => null, 'percentage' => 60.0, 'allocationSource' => 'hours'],
				['costCenter' => 'KP-500', 'projectId' => null, 'percentage' => 20.0, 'allocationSource' => 'hours'],
				['costCenter' => 'KP-100', 'projectId' => 'PRJ-7', 'percentage' => 20.0, 'allocationSource' => 'hours'],
			],
			$shares
		);
	}//end testHoursSharesGroupAndFallBack()

	/**
	 * No positive hours means no hours shares, so the caller falls back.
	 *
	 * @return void
	 */
	public function testHoursSharesEmptyWithoutHours(): void {
		$this->assertSame([], (new CostAllocationShares())->hoursShares(entries: [['hours' => 0]], fallbackCostCenter: null));
		$this->assertSame([], (new CostAllocationShares())->hoursShares(entries: [], fallbackCostCenter: 'KP-1'));
	}//end testHoursSharesEmptyWithoutHours()

	/**
	 * The cent split always adds up; the remainder lands on the largest
	 * weight, and zero weights split equally.
	 *
	 * @return void
	 */
	public function testSplitCentsAddsUpAndHandlesZeroWeights(): void {
		$shares = new CostAllocationShares();

		$parts = $shares->splitCents(total: 10001, weights: [33.33, 33.33, 33.34]);
		$this->assertSame(10001, array_sum($parts));
		$this->assertSame([3333, 3333, 3335], $parts);

		$equal = $shares->splitCents(total: 100, weights: [0.0, 0.0, 0.0]);
		$this->assertSame(100, array_sum($equal));
		$this->assertSame([34, 33, 33], $equal);
	}//end testSplitCentsAddsUpAndHandlesZeroWeights()

	/**
	 * A blank or null code is null; a code is trimmed.
	 *
	 * @return void
	 */
	public function testCodeOrNull(): void {
		$shares = new CostAllocationShares();
		$this->assertNull($shares->codeOrNull(null));
		$this->assertNull($shares->codeOrNull('  '));
		$this->assertSame('KP-1', $shares->codeOrNull(' KP-1 '));
	}//end testCodeOrNull()

}//end class
