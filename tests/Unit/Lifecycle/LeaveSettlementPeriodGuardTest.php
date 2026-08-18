<?php

/**
 * Unit tests for LeaveSettlementPeriodGuard.
 *
 * Pins the fail-closed contract of the LeaveTransaction `settle` lifecycle
 * guard (leave-buy-sell design.md D5): a present, YYYY-MM-shaped
 * settlementPeriod allows; missing or malformed settlementPeriod denies.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Lifecycle;

use OCA\Hrmq\Lifecycle\LeaveSettlementPeriodGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LeaveSettlementPeriodGuard.
 *
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-004
 */
class LeaveSettlementPeriodGuardTest extends TestCase {

	/**
	 * @return void
	 */
	public function testValidSettlementPeriodAllows(): void {
		$guard = new LeaveSettlementPeriodGuard();
		$result = $guard->check(['settlementPeriod' => '2026-06'], 'settle', 'alice');

		$this->assertTrue($result->isAllowed());

	}//end testValidSettlementPeriodAllows()

	/**
	 * @return void
	 */
	public function testEmptySettlementPeriodDenies(): void {
		$guard = new LeaveSettlementPeriodGuard();
		$result = $guard->check(['settlementPeriod' => ''], 'settle', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testEmptySettlementPeriodDenies()

	/**
	 * @return void
	 */
	public function testMissingSettlementPeriodKeyDenies(): void {
		$guard = new LeaveSettlementPeriodGuard();
		$result = $guard->check([], 'settle', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testMissingSettlementPeriodKeyDenies()

	/**
	 * @return void
	 */
	public function testMalformedSettlementPeriodDenies(): void {
		$guard = new LeaveSettlementPeriodGuard();
		$result = $guard->check(['settlementPeriod' => '2026/06'], 'settle', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testMalformedSettlementPeriodDenies()

	/**
	 * @return void
	 */
	public function testFullDateInsteadOfPeriodDenies(): void {
		$guard = new LeaveSettlementPeriodGuard();
		$result = $guard->check(['settlementPeriod' => '2026-06-15'], 'settle', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testFullDateInsteadOfPeriodDenies()

}//end class
