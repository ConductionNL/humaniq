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
		$buckets = [];
		$result = [];
		foreach ($balances as $balance) {
			$id = $this->idOf(balance: $balance);
			if ($id === '') {
				continue;
			}

			$result[$id] = [
				'usedStatutoryHours' => 0.0,
				'usedBovenwettelijkHours' => 0.0,
				'usedHours' => 0.0,
				'expiredHours' => 0.0,
				'bovenwettelijkExpiryDate' => $this->bovenwettelijkExpiry(balance: $balance, leaveType: $leaveType),
			];
			foreach ($this->bucketsOf(balance: $balance, id: $id, leaveType: $leaveType) as $bucket) {
				$buckets[] = $bucket;
			}
		}

		usort($uses, fn (array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
		foreach ($uses as $use) {
			$this->place(buckets: $buckets, result: $result, balances: $balances, use: $use);
		}

		return $this->tally(buckets: $buckets, result: $result, today: $today);
	}//end allocate()

	/**
	 * The hours used from the buckets of each balance, and what lapsed by today.
	 *
	 * @param array<int, array<string, mixed>>    $buckets The consumed buckets.
	 * @param array<string, array<string, mixed>> $result  The per-balance result so far (overdrafts).
	 * @param string                              $today   The date the lapse is measured on.
	 *
	 * @return array<string, array{usedStatutoryHours: float, usedBovenwettelijkHours: float, usedHours: float, expiredHours: float, bovenwettelijkExpiryDate: string}>
	 */
	private function tally(array $buckets, array $result, string $today): array {
		foreach ($buckets as $bucket) {
			$key = ($bucket['statutory'] === true ? 'usedStatutoryHours' : 'usedBovenwettelijkHours');
			$result[$bucket['balance']][$key] += $bucket['used'];
			if ($bucket['waived'] === false && $bucket['lapses'] < $today) {
				$result[$bucket['balance']]['expiredHours'] += max(0.0, $bucket['hours'] - $bucket['used']);
			}
		}

		foreach ($result as $id => $row) {
			$row['usedStatutoryHours'] = round($row['usedStatutoryHours'], 2);
			$row['usedBovenwettelijkHours'] = round($row['usedBovenwettelijkHours'], 2);
			$row['usedHours'] = round($row['usedStatutoryHours'] + $row['usedBovenwettelijkHours'], 2);
			$row['expiredHours'] = round($row['expiredHours'], 2);
			$result[$id] = $row;
		}

		return $result;
	}//end tally()

	/**
	 * Place one use in the buckets valid on its day, soonest lapse first.
	 *
	 * @param array<int, array<string, mixed>>   $buckets  The buckets, consumed in place.
	 * @param array<string, array<string, mixed>> $result  The per-balance result, for an overdraft.
	 * @param array<int, array<string, mixed>>   $balances The balances.
	 * @param array{date: string, year: int, hours: float} $use The use.
	 *
	 * @return void
	 */
	private function place(array &$buckets, array &$result, array $balances, array $use): void {
		$left = (float)$use['hours'];
		foreach ($this->eligible(buckets: $buckets, use: $use) as $index) {
			$take = min($left, $buckets[$index]['hours'] - $buckets[$index]['used']);
			$buckets[$index]['used'] += $take;
			$left -= $take;
			if ($left <= 0.0) {
				return;
			}
		}

		// Overdraft: on the balance of the leave year, as statutory use, so the
		// negative-balance check sees it. With no balance that year it has
		// nowhere to go, as before.
		foreach ($balances as $balance) {
			$id = $this->idOf(balance: $balance);
			if ($id !== '' && (int)($balance['year'] ?? 0) === (int)$use['year']) {
				$result[$id]['usedStatutoryHours'] += $left;
				return;
			}
		}
	}//end place()

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
		$contractHours = [];
		foreach ($balances as $balance) {
			if (($balance['contractHoursPerWeek'] ?? null) !== null) {
				$contractHours[(int)($balance['year'] ?? 0)] = (float)$balance['contractHoursPerWeek'];
			}
		}

		$uses = [];
		$underivable = [];
		foreach ($requests as $request) {
			if ((string)($request['status'] ?? '') !== 'approved'
				|| (string)($request['employeeId'] ?? '') !== $employeeId
				|| (string)($request['leaveType'] ?? '') !== $leaveType
			) {
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
	 * @param array<string, mixed>      $balance   The balance.
	 * @param string                    $id        Its id.
	 * @param array<string, mixed>|null $leaveType The leave type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function bucketsOf(array $balance, string $id, ?array $leaveType): array {
		$year = (int)($balance['year'] ?? 0);
		$waived = (($balance['expiryWaived'] ?? false) === true);
		$expiry = trim((string)($balance['expiryDate'] ?? ''));
		$bucket = ['balance' => $id, 'year' => $year, 'used' => 0.0, 'waived' => $waived];

		$out = [
			$bucket + [
				'statutory' => true,
				'hours' => (float)($balance['entitledHours'] ?? 0),
				'lapses' => ($expiry !== '' ? substr($expiry, 0, 10) : ($year + 1) . '-07-01'),
			],
		];

		$bovenwettelijk = (float)($balance['bovenwettelijkHours'] ?? 0);
		$rule = (string)($leaveType['carryOverRule'] ?? 'all');
		$carried = $bovenwettelijk;
		if ($rule === 'none') {
			$carried = 0.0;
		} else if ($rule === 'capped') {
			$carried = min($bovenwettelijk, max(0.0, (float)($leaveType['carryOverCapHours'] ?? 0)));
		}

		if ($bovenwettelijk - $carried > 0.0) {
			$out[] = $bucket + ['statutory' => false, 'hours' => $bovenwettelijk - $carried, 'lapses' => $year . '-12-31'];
		}

		if ($carried > 0.0) {
			$out[] = $bucket + [
				'statutory' => false,
				'hours' => $carried,
				'lapses' => $this->bovenwettelijkExpiry(balance: $balance, leaveType: $leaveType),
			];
		}

		return $out;
	}//end bucketsOf()

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
