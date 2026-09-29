<?php

/**
 * LeaveAllocationCalculator Unit Tests
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://humaniq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\LeaveAllocationCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Leave draws from the hours that lapse first, carries over by the leave
 * type's rule, and lapses on the date.
 *
 * @covers \OCA\Humaniq\Service\LeaveAllocationCalculator
 */
class LeaveAllocationCalculatorTest extends TestCase {

	/**
	 * A holiday balance.
	 *
	 * @param string $id The balance id.
	 * @param int $year The year.
	 * @param float $statutory Statutory hours.
	 * @param float $bovenwettelijk Bovenwettelijk hours.
	 * @param array<string, mixed> $extra Other fields.
	 *
	 * @return array<string, mixed>
	 */
	private function balance(string $id, int $year, float $statutory, float $bovenwettelijk = 0.0, array $extra = []): array {
		return array_merge(
			[
				'id' => $id,
				'employeeId' => 'emp-1',
				'leaveType' => 'holiday',
				'year' => $year,
				'entitledHours' => $statutory,
				'bovenwettelijkHours' => $bovenwettelijk,
				'expiryDate' => ($year + 1) . '-07-01',
			],
			$extra
		);
	}

	/**
	 * February leave takes last year's statutory hours first, then this year's.
	 *
	 * @return void
	 */
	public function testAFebruaryRequestDrawsLastYearsStatutoryHoursFirst(): void {
		$result = (new LeaveAllocationCalculator())->allocate(
			balances: [$this->balance('b-2025', 2025, 160.0), $this->balance('b-2026', 2026, 160.0)],
			uses: [
				['date' => '2025-06-01', 'year' => 2025, 'hours' => 144.0],
				['date' => '2026-02-09', 'year' => 2026, 'hours' => 24.0],
			],
			leaveType: null,
			today: '2026-03-01'
		);

		self::assertSame(160.0, $result['b-2025']['usedStatutoryHours']);
		self::assertSame(8.0, $result['b-2026']['usedStatutoryHours']);
		self::assertSame(8.0, $result['b-2026']['usedHours']);
		self::assertSame(0.0, $result['b-2025']['expiredHours']);
	}

	/**
	 * After 1 July last year's statutory hours are gone for the request, and lapse.
	 *
	 * @return void
	 */
	public function testARequestAfterFirstJulySkipsLastYearsStatutoryBucket(): void {
		$result = (new LeaveAllocationCalculator())->allocate(
			balances: [$this->balance('b-2025', 2025, 16.0), $this->balance('b-2026', 2026, 160.0)],
			uses: [['date' => '2026-08-03', 'year' => 2026, 'hours' => 24.0]],
			leaveType: null,
			today: '2026-09-01'
		);

		self::assertSame(0.0, $result['b-2025']['usedStatutoryHours']);
		self::assertSame(16.0, $result['b-2025']['expiredHours']);
		self::assertSame(24.0, $result['b-2026']['usedStatutoryHours']);
	}

	/**
	 * Statutory hours go before bovenwettelijk hours under the default rule.
	 *
	 * @return void
	 */
	public function testStatutoryGoesBeforeBovenwettelijk(): void {
		$result = (new LeaveAllocationCalculator())->allocate(
			balances: [$this->balance('b-2026', 2026, 16.0, 40.0)],
			uses: [['date' => '2026-03-02', 'year' => 2026, 'hours' => 24.0]],
			leaveType: null,
			today: '2026-03-03'
		);

		self::assertSame(16.0, $result['b-2026']['usedStatutoryHours']);
		self::assertSame(8.0, $result['b-2026']['usedBovenwettelijkHours']);
		self::assertSame(24.0, $result['b-2026']['usedHours']);
	}

	/**
	 * With carry-over `none` the bovenwettelijk hours lapse on 31 December of their year.
	 *
	 * @return void
	 */
	public function testNoneEndsTheBovenwettelijkBucketOnThirtyFirstDecember(): void {
		$result = (new LeaveAllocationCalculator())->allocate(
			balances: [$this->balance('b-2025', 2025, 0.0, 40.0), $this->balance('b-2026', 2026, 0.0, 40.0)],
			uses: [['date' => '2026-01-12', 'year' => 2026, 'hours' => 8.0]],
			leaveType: ['code' => 'holiday', 'carryOverRule' => 'none'],
			today: '2026-01-13'
		);

		self::assertSame(0.0, $result['b-2025']['usedBovenwettelijkHours']);
		self::assertSame(40.0, $result['b-2025']['expiredHours']);
		self::assertSame(8.0, $result['b-2026']['usedBovenwettelijkHours']);
	}

	/**
	 * A capped carry-over keeps the cap usable and lapses the rest at year end.
	 *
	 * @return void
	 */
	public function testACappedCarryOverKeepsTheCapAndLapsesTheRest(): void {
		$result = (new LeaveAllocationCalculator())->allocate(
			balances: [$this->balance('b-2025', 2025, 0.0, 56.0)],
			uses: [],
			leaveType: ['code' => 'holiday', 'carryOverRule' => 'capped', 'carryOverCapHours' => 40],
			today: '2026-01-02'
		);

		self::assertSame(16.0, $result['b-2025']['expiredHours']);
		self::assertSame('2030-12-31', $result['b-2025']['bovenwettelijkExpiryDate']);
	}

	/**
	 * A waived lapse keeps the hours; the same balance lapses without the waiver.
	 *
	 * @return void
	 */
	public function testAWaivedLapseKeepsTheHours(): void {
		$calc = new LeaveAllocationCalculator();
		$waived = $this->balance('b-2025', 2025, 8.0, 0.0, ['expiryWaived' => true, 'expiryWaivedReason' => 'long-term sickness']);

		$without = $calc->allocate([$this->balance('b-2025', 2025, 8.0)], [], null, '2026-07-02');
		$with = $calc->allocate([$waived], [], null, '2026-07-02');

		self::assertSame(8.0, $without['b-2025']['expiredHours']);
		self::assertSame(0.0, $with['b-2025']['expiredHours']);
	}

	/**
	 * The lapse is on the day after the expiry date, not on it.
	 *
	 * @return void
	 */
	public function testHoursLapseTheDayAfterTheExpiryDate(): void {
		$calc = new LeaveAllocationCalculator();

		self::assertSame(0.0, $calc->allocate([$this->balance('b-2025', 2025, 8.0)], [], null, '2026-07-01')['b-2025']['expiredHours']);
		self::assertSame(8.0, $calc->allocate([$this->balance('b-2025', 2025, 8.0)], [], null, '2026-07-02')['b-2025']['expiredHours']);
	}

	/**
	 * A late-approved request dated before the expiry lowers the lapse.
	 *
	 * @return void
	 */
	public function testALateApprovedRequestBeforeTheExpiryLowersTheLapse(): void {
		$result = (new LeaveAllocationCalculator())->allocate(
			balances: [$this->balance('b-2025', 2025, 8.0), $this->balance('b-2026', 2026, 160.0)],
			uses: [['date' => '2026-06-29', 'year' => 2026, 'hours' => 6.0]],
			leaveType: null,
			today: '2026-07-10'
		);

		self::assertSame(6.0, $result['b-2025']['usedStatutoryHours']);
		self::assertSame(2.0, $result['b-2025']['expiredHours']);
	}

	/**
	 * Hours beyond every bucket land on the leave year's balance as an overdraft.
	 *
	 * @return void
	 */
	public function testHoursBeyondEveryBucketAreAnOverdraftOnTheLeaveYear(): void {
		$result = (new LeaveAllocationCalculator())->allocate(
			balances: [$this->balance('b-2026', 2026, 16.0)],
			uses: [['date' => '2026-03-02', 'year' => 2026, 'hours' => 24.0]],
			leaveType: null,
			today: '2026-03-03'
		);

		self::assertSame(24.0, $result['b-2026']['usedHours']);
		self::assertSame(24.0, $result['b-2026']['usedStatutoryHours']);
	}

	/**
	 * Recomputing gives the same numbers, and used is always the sum of its parts.
	 *
	 * @return void
	 */
	public function testRecomputingIsStable(): void {
		$calc = new LeaveAllocationCalculator();
		$balances = [$this->balance('b-2025', 2025, 16.0, 8.0), $this->balance('b-2026', 2026, 160.0, 20.0)];
		$uses = [['date' => '2026-02-09', 'year' => 2026, 'hours' => 30.0], ['date' => '2025-12-01', 'year' => 2025, 'hours' => 4.0]];

		$first = $calc->allocate($balances, $uses, null, '2026-03-01');
		$second = $calc->allocate($balances, $uses, null, '2026-03-01');

		self::assertSame($first, $second);
		foreach ($first as $row) {
			self::assertSame($row['usedHours'], round($row['usedStatutoryHours'] + $row['usedBovenwettelijkHours'], 2));
		}
	}
}
