<?php

/**
 * Leave allocation edge tests
 *
 * Pins LeaveAllocationCalculator::allocate where the main suite does not
 * look: the whole result row, a balance without an id, an overdraft with no
 * balance in the leave year, and carried-over hours that lapse on the leave
 * type's date.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\LeaveAllocationCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the leave allocation.
 *
 * @covers \OCA\Humaniq\Service\LeaveAllocationCalculator
 */
class LeaveAllocationEdgeTest extends TestCase {

	/**
	 * A balance without an id takes no part; an overdraft in a year without
	 * a balance goes nowhere; the result row is complete and rounded.
	 *
	 * @return void
	 */
	public function testAnUnidentifiedBalanceAndAnOverdraftWithoutABalance(): void {
		$result = (new LeaveAllocationCalculator())->allocate(
			balances: [
				['year' => 2026, 'entitledHours' => 40.0, 'expiryDate' => '2027-07-01'],
				['id' => 'b-2025', 'year' => 2025, 'entitledHours' => 10.0, 'bovenwettelijkHours' => 0.0, 'expiryDate' => '2026-07-01'],
			],
			uses: [['date' => '2026-03-02', 'year' => 2026, 'hours' => 12.333]],
			leaveType: null,
			today: '2026-03-03'
		);

		self::assertSame(
			[
				'b-2025' => [
					'usedStatutoryHours' => 10.0,
					'usedBovenwettelijkHours' => 0.0,
					'usedHours' => 10.0,
					'expiredHours' => 0.0,
					'bovenwettelijkExpiryDate' => '2030-12-31',
				],
			],
			$result
		);
	}

	/**
	 * Bovenwettelijk hours split into the part that lapses on 31 December and
	 * the capped part carried over, drawn soonest lapse first, and lapsing on
	 * their own dates.
	 *
	 * @return void
	 */
	public function testCarriedHoursLapseOnTheirOwnDate(): void {
		$result = (new LeaveAllocationCalculator())->allocate(
			balances: [
				['id' => 'b-2025', 'year' => 2025, 'entitledHours' => 0.0, 'bovenwettelijkHours' => 20.0, 'expiryDate' => '2026-07-01'],
			],
			uses: [['date' => '2025-12-01', 'year' => 2025, 'hours' => 4.0]],
			leaveType: ['carryOverRule' => 'capped', 'carryOverCapHours' => 6.0],
			today: '2026-01-02'
		);

		self::assertSame(4.0, $result['b-2025']['usedBovenwettelijkHours']);
		self::assertSame(10.0, $result['b-2025']['expiredHours']);
		self::assertSame(4.0, $result['b-2025']['usedHours']);
	}
}
