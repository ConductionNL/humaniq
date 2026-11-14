<?php

/**
 * Humaniq LeaveAllocationCalculator.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Allocates leave to the hours that lapse first, and works out what has lapsed.
 *
 * Pure: plain arrays in, plain arrays out. Every balance of one employee and
 * leave type is split into buckets: its statutory hours (lapsing on
 * `expiryDate`, 1 July of the next year) and its bovenwettelijk hours (lapsing
 * by the leave type's carry-over rule). The hours of the approved requests are
 * walked in date order and each goes to the bucket that is valid on the leave
 * day and lapses soonest; on a tie statutory goes first. Hours beyond every
 * bucket stay on the leave year's balance as an overdraft, which
 * nl-verlof-saldo-niet-negatief flags. What is left in a bucket after its
 * expiry date has lapsed, unless the balance carries a waiver.
 *
 * Recomputed from the requests every time, never accumulated, so running it
 * twice gives the same numbers.
 *
 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
 */
class LeaveAllocationCalculator {

	/**
	 * Years after the balance year that bovenwettelijk hours stay usable by
	 * default: the five-year limitation period (BW art. 7:642).
	 */
	public const DEFAULT_BOVENWETTELIJK_YEARS = 5;

	/**
	 * Allocate the uses over the balances and work out the lapse per balance.
	 *
	 * @param array<int, array<string, mixed>> $balances  Every balance of one employee and leave type.
	 * @param array<int, array{date: string, year: int, hours: float}> $uses The hours taken, per request per year.
	 * @param array<string, mixed>|null        $leaveType The administered LeaveType, or null for the defaults.
	 * @param string                           $today     The date the lapse is measured on, `Y-m-d`.
	 *
	 * @return array<string, array{usedStatutoryHours: float, usedBovenwettelijkHours: float, usedHours: float, expiredHours: float, bovenwettelijkExpiryDate: string}>
	 *   Keyed by balance id.
	 *
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
	 */
	public function allocate(array $balances, array $uses, ?array $leaveType, string $today): array {
		$run = new LeaveAllocationRun();
		$run->balances = $balances;
		$run->leaveType = $leaveType;
		foreach ($balances as $balance) {
			$this->openBalance(run: $run, balance: $balance);
		}

		usort($uses, fn (array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
		foreach ($uses as $use) {
			$this->place(run: $run, use: $use);
		}

		return $this->tally(run: $run, today: $today);
	}//end allocate()

	/**
	 * Open one balance in the run: its empty result row and its buckets. A
	 * balance without an id takes no part.
	 *
	 * @param LeaveAllocationRun   $run     The allocation.
	 * @param array<string, mixed> $balance The balance.
	 *
	 * @return void
	 */
	private function openBalance(LeaveAllocationRun $run, array $balance): void {
		$id = $this->idOf(balance: $balance);
		if ($id === '') {
			return;
		}

		$run->result[$id] = $this->emptyResultRow(run: $run, balance: $balance);
		foreach ($this->bucketsOf(run: $run, balance: $balance) as $bucket) {
			$run->buckets[] = $bucket;
		}
	}//end openBalance()

	/**
	 * The result row of a balance before any use: nothing used or expired,
	 * and the date its carried-over bovenwettelijk hours lapse.
	 *
	 * @param LeaveAllocationRun   $run     The allocation: its leave type.
	 * @param array<string, mixed> $balance The balance.
	 *
	 * @return array{usedStatutoryHours: float, usedBovenwettelijkHours: float, usedHours: float, expiredHours: float, bovenwettelijkExpiryDate: string}
	 */
	private function emptyResultRow(LeaveAllocationRun $run, array $balance): array {
		return [
			'usedStatutoryHours' => 0.0,
			'usedBovenwettelijkHours' => 0.0,
			'usedHours' => 0.0,
			'expiredHours' => 0.0,
			'bovenwettelijkExpiryDate' => $this->bovenwettelijkExpiry(balance: $balance, leaveType: $run->leaveType),
		];
	}//end emptyResultRow()

	/**
	 * The hours used from the buckets of each balance, and what lapsed by today.
	 *
	 * @param LeaveAllocationRun $run   The allocation: its consumed buckets and the per-balance result so far (overdrafts).
	 * @param string             $today The date the lapse is measured on.
	 *
	 * @return array<string, array{usedStatutoryHours: float, usedBovenwettelijkHours: float, usedHours: float, expiredHours: float, bovenwettelijkExpiryDate: string}>
	 */
	private function tally(LeaveAllocationRun $run, string $today): array {
		$result = $run->result;
		foreach ($run->buckets as $bucket) {
			$key = ($bucket['statutory'] === true ? 'usedStatutoryHours' : 'usedBovenwettelijkHours');
			$result[$bucket['balance']][$key] += $bucket['used'];
			if ($bucket['waived'] === false && $bucket['lapses'] < $today) {
				$result[$bucket['balance']]['expiredHours'] += max(0.0, $bucket['hours'] - $bucket['used']);
			}
		}

		return $this->rounded(result: $result);
	}//end tally()

	/**
	 * The per-balance result with every figure rounded to two decimals and
	 * the used hours as the sum of the rounded statutory and bovenwettelijk use.
	 *
	 * @param array<string, array<string, mixed>> $result The per-balance result.
	 *
	 * @return array<string, array{usedStatutoryHours: float, usedBovenwettelijkHours: float, usedHours: float, expiredHours: float, bovenwettelijkExpiryDate: string}>
	 */
	private function rounded(array $result): array {
		foreach ($result as $id => $row) {
			$row['usedStatutoryHours'] = round($row['usedStatutoryHours'], 2);
			$row['usedBovenwettelijkHours'] = round($row['usedBovenwettelijkHours'], 2);
			$row['usedHours'] = round($row['usedStatutoryHours'] + $row['usedBovenwettelijkHours'], 2);
			$row['expiredHours'] = round($row['expiredHours'], 2);
			$result[$id] = $row;
		}

		return $result;
	}//end rounded()

	/**
	 * Place one use in the buckets valid on its day, soonest lapse first.
	 *
	 * @param LeaveAllocationRun                           $run The allocation: its buckets, consumed in place, and the result, for an overdraft.
	 * @param array{date: string, year: int, hours: float} $use The use.
	 *
	 * @return void
	 */
	private function place(LeaveAllocationRun $run, array $use): void {
		$left = (float)$use['hours'];
		foreach ($this->eligible(buckets: $run->buckets, use: $use) as $index) {
			$take = min($left, $run->buckets[$index]['hours'] - $run->buckets[$index]['used']);
			$run->buckets[$index]['used'] += $take;
			$left -= $take;
			if ($left <= 0.0) {
				return;
			}
		}

		$this->overdraw(run: $run, use: (['hours' => $left] + $use));
	}//end place()

	/**
	 * Overdraft: on the balance of the leave year, as statutory use, so the
	 * negative-balance check sees it. With no balance that year it has
	 * nowhere to go, as before.
	 *
	 * @param LeaveAllocationRun                           $run The allocation: its balances and result.
	 * @param array{date: string, year: int, hours: float} $use The use, its hours the part no bucket held.
	 *
	 * @return void
	 */
	private function overdraw(LeaveAllocationRun $run, array $use): void {
		foreach ($run->balances as $balance) {
			$id = $this->idOf(balance: $balance);
			if ($id !== '' && (int)($balance['year'] ?? 0) === (int)$use['year']) {
				$run->result[$id]['usedStatutoryHours'] += $use['hours'];
				return;
			}
		}
	}//end overdraw()

	/**
	 * The indexes of the buckets a use may draw from, soonest lapse first and
	 * statutory first on a tie: its year is not after the leave year, it has
	 * not lapsed on the leave day and it has hours left.
	 *
	 * @param array<int, array<string, mixed>> $buckets The buckets.
	 * @param array{date: string, year: int, hours: float} $use The use.
	 *
	 * @return array<int, int>
	 */
	private function eligible(array $buckets, array $use): array {
		$order = [];
		foreach ($buckets as $index => $bucket) {
			if ($bucket['year'] <= (int)$use['year'] && $bucket['lapses'] >= (string)$use['date'] && $bucket['hours'] > $bucket['used']) {
				$order[$index] = $bucket['lapses'] . ($bucket['statutory'] === true ? '0' : '1') . sprintf('%04d', $index);
			}
		}

		asort($order);
		return array_keys($order);
	}//end eligible()

	/**
	 * The hours each approved request of this employee and type takes, per
	 * calendar year it touches, dated on its first day in that year.
	 *
	 * @param array<int, array<string, mixed>> $requests   Every LeaveRequest.
	 * @param array<int, array<string, mixed>> $balances   The employee's balances of this type.
	 * @param string                           $employeeId The employee.
	 * @param string                           $leaveType   The leave type.
	 * @param array<string, mixed>|null        $workingTime patterns, nonWorkingTimes and nonWorkingDates, to cost each day from the person's working time; null for the contract average.
	 *
	 * @return array{uses: array<int, array{date: string, year: int, hours: float}>, underivable: array<int, string>}
	 *
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-001
	 */
	public function usesFrom(array $requests, array $balances, string $employeeId, string $leaveType, ?array $workingTime = null): array {
		$contractHours = self::contractHoursByYear(balances: $balances);
		$uses = [];
		$underivable = [];
		foreach ($requests as $request) {
			if (self::isApprovedRequestOf(request: $request, balance: ['employeeId' => $employeeId, 'leaveType' => $leaveType]) === false) {
				continue;
			}

			foreach ($this->requestUses(request: $request, contractHours: $contractHours, workingTime: $workingTime) as $use) {
				if ($use === null) {
					$underivable[] = (string)($request['id'] ?? ($request['@self']['id'] ?? 'unknown'));
					continue;
				}

				$uses[] = $use;
			}
		}

		return ['uses' => $uses, 'underivable' => array_values(array_unique($underivable))];
	}//end usesFrom()

	/**
	 * The contract hours per week of each balance year that records them.
	 *
	 * @param array<int, array<string, mixed>> $balances The employee's balances of this type.
	 *
	 * @return array<int, float>
	 */
	private static function contractHoursByYear(array $balances): array {
		$contractHours = [];
		foreach ($balances as $balance) {
			if (($balance['contractHoursPerWeek'] ?? null) !== null) {
				$contractHours[(int)($balance['year'] ?? 0)] = (float)$balance['contractHoursPerWeek'];
			}
		}

		return $contractHours;
	}//end contractHoursByYear()

	/**
	 * Whether a request is an approved one of the balance's employee and type.
	 *
	 * @param array<string, mixed>                          $request The LeaveRequest.
	 * @param array{employeeId: string, leaveType: string} $balance The employee and leave type.
	 *
	 * @return bool
	 */
	private static function isApprovedRequestOf(array $request, array $balance): bool {
		return (string)($request['status'] ?? '') === 'approved'
			&& (string)($request['employeeId'] ?? '') === $balance['employeeId']
			&& (string)($request['leaveType'] ?? '') === $balance['leaveType'];
	}//end isApprovedRequestOf()

	/**
	 * The uses of one request, one per year it touches; null for a year whose hours cannot be derived.
	 *
	 * @param array<string, mixed> $request       The LeaveRequest.
	 * @param array<int, float>    $contractHours Contract hours per week by balance year.
	 * @param array<string, mixed>|null $workingTime The person's working time, or null.
	 *
	 * @return array<int, array{date: string, year: int, hours: float}|null>
	 */
	private function requestUses(array $request, array $contractHours, ?array $workingTime): array {
		$start = substr((string)($request['startDate'] ?? ''), 0, 10);
		$startYear = (int)substr($start, 0, 4);
		$endYear = max($startYear, (int)substr((string)($request['endDate'] ?? ''), 0, 4));
		$out = [];
		// The hours per year are LeaveHoursCalculator's, the rule the balance
		// projection has always used; referenced as a callable to keep this
		// class free of static access.
		$requestHours = [LeaveHoursCalculator::class, 'requestHours'];
		for ($year = $startYear; $startYear > 0 && $year <= $endYear; $year++) {
			$resolved = $requestHours($request, ($contractHours[$year] ?? null), $year, $workingTime);
			if ($resolved['derivable'] === false) {
				$out[] = null;
				continue;
			}

			if ($resolved['hours'] > 0) {
				$out[] = ['date' => max($start, $year . '-01-01'), 'year' => $year, 'hours' => (float)$resolved['hours']];
			}
		}

		return $out;
	}//end requestUses()

	/**
	 * The buckets of one balance.
	 *
	 * @param LeaveAllocationRun   $run     The allocation: its leave type.
	 * @param array<string, mixed> $balance The balance, with an id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function bucketsOf(LeaveAllocationRun $run, array $balance): array {
		$bucket = $this->bucketBase(balance: $balance);

		return array_merge([$bucket + self::statutoryHours(balance: $balance)], $this->bovenwettelijkBuckets(run: $run, balance: $balance));
	}//end bucketsOf()

	/**
	 * What every bucket of a balance shares: the balance, its year, nothing
	 * used yet and whether its expiry is waived.
	 *
	 * @param array<string, mixed> $balance The balance, with an id.
	 *
	 * @return array{balance: string, year: int, used: float, waived: bool}
	 */
	private function bucketBase(array $balance): array {
		return ['balance' => $this->idOf(balance: $balance), 'year' => (int)($balance['year'] ?? 0), 'used' => 0.0, 'waived' => (($balance['expiryWaived'] ?? false) === true)];
	}//end bucketBase()

	/**
	 * The bovenwettelijk buckets of a balance: the hours that lapse at the
	 * end of its year, and the hours the leave type carries over.
	 *
	 * @param LeaveAllocationRun   $run     The allocation: its leave type.
	 * @param array<string, mixed> $balance The balance, with an id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function bovenwettelijkBuckets(LeaveAllocationRun $run, array $balance): array {
		$bucket = $this->bucketBase(balance: $balance);
		$bovenwettelijk = (float)($balance['bovenwettelijkHours'] ?? 0);
		$carried = self::carriedHours(bovenwettelijk: $bovenwettelijk, leaveType: $run->leaveType);
		$out = [];
		if ($bovenwettelijk - $carried > 0.0) {
			$out[] = $bucket + ['statutory' => false, 'hours' => $bovenwettelijk - $carried, 'lapses' => $bucket['year'] . '-12-31'];
		}

		if ($carried > 0.0) {
			$out[] = $bucket + $this->carriedOver(run: $run, balance: $balance);
		}

		return $out;
	}//end bovenwettelijkBuckets()

	/**
	 * The bovenwettelijk hours of a balance the leave type carries over, and
	 * when they lapse.
	 *
	 * @param LeaveAllocationRun   $run     The allocation: its leave type.
	 * @param array<string, mixed> $balance The balance.
	 *
	 * @return array{statutory: bool, hours: float, lapses: string}
	 */
	private function carriedOver(LeaveAllocationRun $run, array $balance): array {
		return [
			'statutory' => false,
			'hours' => self::carriedHours(bovenwettelijk: (float)($balance['bovenwettelijkHours'] ?? 0), leaveType: $run->leaveType),
			'lapses' => $this->bovenwettelijkExpiry(balance: $balance, leaveType: $run->leaveType),
		];
	}//end carriedOver()

	/**
	 * The statutory hours of a balance and when they lapse: the stored
	 * expiry date, else 1 July of the next year.
	 *
	 * @param array<string, mixed> $balance The balance.
	 *
	 * @return array{statutory: bool, hours: float, lapses: string}
	 */
	private static function statutoryHours(array $balance): array {
		$expiry = trim((string)($balance['expiryDate'] ?? ''));

		return [
			'statutory' => true,
			'hours' => (float)($balance['entitledHours'] ?? 0),
			'lapses' => ($expiry !== '' ? substr($expiry, 0, 10) : ((int)($balance['year'] ?? 0) + 1) . '-07-01'),
		];
	}//end statutoryHours()

	/**
	 * The bovenwettelijk hours the leave type carries over: none, up to its
	 * cap, or all.
	 *
	 * @param float                     $bovenwettelijk The balance's bovenwettelijk hours.
	 * @param array<string, mixed>|null $leaveType      The leave type.
	 *
	 * @return float
	 */
	private static function carriedHours(float $bovenwettelijk, ?array $leaveType): float {
		return match ((string)($leaveType['carryOverRule'] ?? 'all')) {
			'none' => 0.0,
			'capped' => min($bovenwettelijk, max(0.0, (float)($leaveType['carryOverCapHours'] ?? 0))),
			default => $bovenwettelijk,
		};
	}//end carriedHours()

	/**
	 * When the carried bovenwettelijk hours of a balance lapse: the stored
	 * date, else 31 December of the year plus the leave type's term.
	 *
	 * @param array<string, mixed>      $balance   The balance.
	 * @param array<string, mixed>|null $leaveType The leave type.
	 *
	 * @return string `Y-m-d`.
	 *
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Unused-hours-SHALL-carry-over-by-the-leave-type's-rule-(REQ-LEX-002)
	 */
	public function bovenwettelijkExpiry(array $balance, ?array $leaveType): string {
		$stored = trim((string)($balance['bovenwettelijkExpiryDate'] ?? ''));
		if ($stored !== '') {
			return substr($stored, 0, 10);
		}

		$years = (int)($leaveType['bovenwettelijkExpiryYears'] ?? self::DEFAULT_BOVENWETTELIJK_YEARS);
		if ($years < 0) {
			$years = self::DEFAULT_BOVENWETTELIJK_YEARS;
		}

		return ((int)($balance['year'] ?? 0) + $years) . '-12-31';
	}//end bovenwettelijkExpiry()

	/**
	 * The id of a balance row.
	 *
	 * @param array<string, mixed> $balance The balance.
	 *
	 * @return string
	 */
	private function idOf(array $balance): string {
		return trim((string)($balance['id'] ?? ($balance['@self']['id'] ?? '')));
	}//end idOf()
}//end class
