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
	}//end allocate()

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
		$order = [];
		foreach ($buckets as $index => $bucket) {
			if ($bucket['year'] <= (int)$use['year'] && $bucket['lapses'] >= (string)$use['date'] && $bucket['hours'] > $bucket['used']) {
				$order[$index] = $bucket['lapses'] . ($bucket['statutory'] === true ? '0' : '1') . sprintf('%04d', $index);
			}
		}

		asort($order);
		foreach (array_keys($order) as $index) {
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
