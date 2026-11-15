<?php

/**
 * Humaniq LeaveHoursCalculator.
 *
 * The arithmetic behind `LeaveBalance.usedHours`, with no object store in
 * sight: how many hours one LeaveRequest consumes, and what the approved
 * requests for an employee/year/leaveType sum to. Split out of
 * {@see LeaveBalanceProjectionService} so the decision surface is pure,
 * exhaustively unit testable, and separately reasoned about from the reads and
 * writes that carry it.
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
 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Pure leave-hours arithmetic.
 */
final class LeaveHoursCalculator {

	/**
	 * The status a request must carry to count against a balance.
	 *
	 * @var string
	 */
	public const COUNTED_STATUS = 'approved';

	/**
	 * A literal calendar date, the only shape the date fields may take.
	 *
	 * @var string
	 */
	private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

	/**
	 * Seconds in a day, the step used when walking a date range.
	 *
	 * @var int
	 */
	private const ONE_DAY = 86400;

	/**
	 * Count the Monday to Friday days in an inclusive date range.
	 *
	 * This is the plain weekday count behind the contract average. A request
	 * costed with the person's working time goes through {@see requestHours()}
	 * with `$workingTime`, which also skips the days openregister's working
	 * calendar marks non-working (leave-hours-from-the-working-pattern).
	 *
	 * @param string $start First day of the range, `YYYY-MM-DD`.
	 * @param string $end Last day of the range, inclusive, `YYYY-MM-DD`.
	 * @param int|null $limitToYear When given, only days falling in this calendar year are counted.
	 *
	 * @return int The number of working days, 0 when the range is empty or unparseable.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	public static function workingDaysBetween(string $start, string $end, ?int $limitToYear = null): int {
		$range = self::parseRange($start, $end);
		if ($range === null) {
			return 0;
		}

		$days = 0;
		for ($ts = $range[0]; $ts <= $range[1]; $ts += self::ONE_DAY) {
			if (self::isCountableDay($ts, $limitToYear) === true) {
				$days++;
			}
		}

		return $days;

	}//end workingDaysBetween()

	/**
	 * Turn a start/end pair into timestamps, rejecting anything unusable.
	 *
	 * Both dates must be a literal `YYYY-MM-DD`. Without that check an empty
	 * string reaches strtotime() as ' 00:00:00 UTC', which parses as TODAY
	 * rather than failing, so a request with no dates silently counted a
	 * phantom working day whenever this ran on a weekday.
	 *
	 * @param string $start First day of the range.
	 * @param string $end Last day of the range, inclusive.
	 *
	 * @return array{0: int, 1: int}|null The timestamps, or null when the range is unusable.
	 */
	private static function parseRange(string $start, string $end): ?array {
		if (preg_match(self::DATE_PATTERN, $start) !== 1
			|| preg_match(self::DATE_PATTERN, $end) !== 1
		) {
			return null;
		}

		$startTs = strtotime($start . ' 00:00:00 UTC');
		$endTs = strtotime($end . ' 00:00:00 UTC');
		if ($startTs === false || $endTs === false || $endTs < $startTs) {
			return null;
		}

		return [$startTs, $endTs];

	}//end parseRange()

	/**
	 * Whether one day counts: a weekday, and inside the year when one is given.
	 *
	 * @param int $timestamp The day, as a UTC midnight timestamp.
	 * @param int|null $limitToYear The calendar year to restrict to, or null for no restriction.
	 *
	 * @return bool True when the day counts towards the total.
	 */
	private static function isCountableDay(int $timestamp, ?int $limitToYear): bool {
		if ($limitToYear !== null && (int)gmdate('Y', $timestamp) !== $limitToYear) {
			return false;
		}

		// gmdate('N') is 1 (Monday) through 7 (Sunday).
		return ((int)gmdate('N', $timestamp) <= 5);

	}//end isCountableDay()

	/**
	 * The hours one LeaveRequest consumes from a balance, and how that was
	 * worked out.
	 *
	 * An explicit `hours` above zero wins (basis `explicit`) and is attributed
	 * wholly to the calendar year of `startDate`, because a single total cannot
	 * be split across a year boundary without inventing a distribution. A
	 * derived value counts only the days falling inside `$year`, so a request
	 * spanning New Year splits across two balances.
	 *
	 * With `$workingTime` each day costs what the person was contracted to work
	 * that day ({@see LeaveDayCosts}, {@see WorkingHoursService::contractedHoursOn()}): basis
	 * `pattern`, or `pattern-only` when the calendar could not be read. Without
	 * a pattern in force, a weekday costs the contract hours divided by five,
	 * except a day the calendar marks non-working (basis `contract-average`).
	 * Without `$workingTime` it is the contract average over weekdays, as the
	 * balance projection computed it before.
	 *
	 * @param array<string, mixed>      $request              The LeaveRequest row.
	 * @param float|null                $contractHoursPerWeek The balance's contract hours snapshot.
	 * @param int                       $year                 The calendar year of the balance being recomputed.
	 * @param array<string, mixed>|null $workingTime          patterns, nonWorkingTimes, nonWorkingDates (null or absent: calendar unread).
	 *
	 * @return array{hours: float, derivable: bool, basis: string, days: list<array{date: string, hours: float, reason: string}>}
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-001
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-002
	 */
	public static function requestHours(array $request, ?float $contractHoursPerWeek, int $year, ?array $workingTime = null): array {
		if ((float)($request['hours'] ?? 0) > 0) {
			return self::explicitHours(request: $request, year: $year);
		}

		if ($workingTime !== null) {
			return (new LeaveDayCosts())->cost(request: $request, contractHoursPerWeek: $contractHoursPerWeek, year: $year, workingTime: $workingTime);
		}

		return self::contractAverageHours(request: $request, balance: ['hoursPerWeek' => $contractHoursPerWeek, 'year' => $year]);

	}//end requestHours()

	/**
	 * An explicit hours value, wholly in the calendar year of the start date.
	 *
	 * @param array<string, mixed> $request The LeaveRequest row, with hours above zero.
	 * @param int                  $year    The calendar year of the balance.
	 *
	 * @return array{hours: float, derivable: bool, basis: string, days: list<array{date: string, hours: float, reason: string}>}
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	private static function explicitHours(array $request, int $year): array {
		$startYear = (int)substr((string)($request['startDate'] ?? ''), 0, 4);

		return [
			'hours' => ($startYear === $year ? (float)$request['hours'] : 0.0),
			'derivable' => true,
			'basis' => 'explicit',
			'days' => [],
		];
	}//end explicitHours()

	/**
	 * The contract hours divided by five for every weekday of the request
	 * inside the year; not derivable without contract hours.
	 *
	 * @param array<string, mixed>                     $request The LeaveRequest row.
	 * @param array{hoursPerWeek: float|null, year: int} $balance The balance's contract hours snapshot and year.
	 *
	 * @return array{hours: float, derivable: bool, basis: string, days: list<array{date: string, hours: float, reason: string}>}
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-002
	 */
	private static function contractAverageHours(array $request, array $balance): array {
		$hoursPerWeek = $balance['hoursPerWeek'];
		if ($hoursPerWeek === null || $hoursPerWeek <= 0) {
			return [
				'hours' => 0.0,
				'derivable' => false,
				'basis' => 'contract-average',
				'days' => [],
			];
		}

		$workingDays = self::workingDaysBetween(
			(string)($request['startDate'] ?? ''),
			(string)($request['endDate'] ?? ''),
			$balance['year']
		);

		return [
			'hours' => ($workingDays * ($hoursPerWeek / 5)),
			'derivable' => true,
			'basis' => 'contract-average',
			'days' => [],
		];
	}//end contractAverageHours()


	/**
	 * Sum the approved requests belonging to one balance.
	 *
	 * @param array<int, array<string, mixed>> $requests Every LeaveRequest in scope.
	 * @param LeaveUsedHoursScope              $balance  The balance: its employee, year, leave type and contract hours snapshot.
	 *
	 * @return array{usedHours: float, underivable: array<int, string>} The total, and the ids that could not be derived.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 */
	public static function usedHoursFor(array $requests, LeaveUsedHoursScope $balance): array {
		$tally = ['usedHours' => 0.0, 'underivable' => []];
		foreach (self::countedRequests(requests: $requests, balance: $balance) as $request) {
			$resolved = self::requestHours($request, $balance->contractHoursPerWeek, $balance->year);
			if ($resolved['derivable'] === false) {
				$tally['underivable'][] = (string)($request['id'] ?? ($request['@self']['id'] ?? 'unknown'));
				continue;
			}

			$tally['usedHours'] += $resolved['hours'];
		}

		return self::rounded(tally: $tally);

	}//end usedHoursFor()

	/**
	 * The requests that count against the balance, in their order.
	 *
	 * @param array<int, array<string, mixed>> $requests Every LeaveRequest in scope.
	 * @param LeaveUsedHoursScope              $balance  The balance.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function countedRequests(array $requests, LeaveUsedHoursScope $balance): array {
		return array_values(array_filter($requests, static fn (array $request): bool => self::belongsToBalance($request, $balance) === true));
	}//end countedRequests()

	/**
	 * The used hours rounded to two decimals, with the underivable ids.
	 *
	 * @param array{usedHours: float, underivable: array<int, string>} $tally The total and the ids that could not be derived.
	 *
	 * @return array{usedHours: float, underivable: array<int, string>}
	 */
	private static function rounded(array $tally): array {
		return [
			'usedHours' => round($tally['usedHours'], 2),
			'underivable' => $tally['underivable'],
		];
	}//end rounded()

	/**
	 * Whether one request counts against one balance at all.
	 *
	 * @param array<string, mixed> $request The LeaveRequest row.
	 * @param LeaveUsedHoursScope  $balance The balance.
	 *
	 * @return bool True when the request is approved and matches the balance.
	 */
	private static function belongsToBalance(array $request, LeaveUsedHoursScope $balance): bool {
		return ((string)($request['status'] ?? '') === self::COUNTED_STATUS
			&& (string)($request['employeeId'] ?? '') === $balance->employeeId
			&& (string)($request['leaveType'] ?? '') === $balance->leaveType
			&& self::touchesYear($request, $balance->year) === true);

	}//end belongsToBalance()

	/**
	 * Whether a request's date range overlaps a calendar year at all.
	 *
	 * @param array<string, mixed> $request The LeaveRequest row.
	 * @param int $year The calendar year.
	 *
	 * @return bool True when any day of the request falls in the year.
	 */
	private static function touchesYear(array $request, int $year): bool {
		$startYear = (int)substr((string)($request['startDate'] ?? ''), 0, 4);
		$endYear = (int)substr((string)($request['endDate'] ?? ''), 0, 4);
		if ($startYear === 0) {
			return false;
		}

		if ($endYear === 0) {
			$endYear = $startYear;
		}

		return ($year >= $startYear && $year <= $endYear);

	}//end touchesYear()

}//end class
