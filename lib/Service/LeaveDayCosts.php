<?php

/**
 * Humaniq LeaveDayCosts.
 *
 * What each day of a leave request costs, from the person's working time: the
 * working pattern in force minus their non-working times, zero on a day
 * openregister's working calendar marks non-working
 * ({@see WorkingHoursService::contractedHoursOn()}). A day without a pattern
 * costs the contract hours divided by five on a weekday that the calendar does
 * not mark. The answer states its basis: `pattern`, `pattern-only` (calendar
 * unread) or `contract-average` (leave-hours-from-the-working-pattern D1, D2).
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
 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-001
 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateInterval;
use DateTimeImmutable;

/**
 * Per-day leave cost.
 *
 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-001
 */
final class LeaveDayCosts {

	/**
	 * The cost of a request day by day inside one balance year.
	 *
	 * @param array<string, mixed> $request              The LeaveRequest row.
	 * @param float|null           $contractHoursPerWeek The contract hours snapshot, for days without a pattern.
	 * @param int                  $year                 The balance year.
	 * @param array<string, mixed> $workingTime          patterns, nonWorkingTimes, nonWorkingDates (null or absent: calendar unread).
	 *
	 * @return array{hours: float, derivable: bool, basis: string, days: list<array{date: string, hours: float, reason: string}>}
	 *
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-001
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-002
	 */
	public function cost(array $request, ?float $contractHoursPerWeek, int $year, array $workingTime): array {
		$calendar = (is_array($workingTime['nonWorkingDates'] ?? null) === true ? array_values($workingTime['nonWorkingDates']) : null);
		$average = null;
		if ($contractHoursPerWeek !== null && $contractHoursPerWeek > 0) {
			$average = ($contractHoursPerWeek / 5);
		}

		$hoursService = new WorkingHoursService();
		$total = 0.0;
		$days = [];
		$usedPattern = false;
		foreach ($this->datesIn(request: $request, year: $year) as $date) {
			$answer = $hoursService->contractedHoursOn(
				employeeId: (string)($request['employeeId'] ?? ''),
				date: $date,
				patterns: (array)($workingTime['patterns'] ?? []),
				nonWorkingTimes: (array)($workingTime['nonWorkingTimes'] ?? []),
				nonWorkingDates: $calendar
			);
			$day = $this->dayCost(date: $date, answer: $answer, average: $average, calendar: $calendar);
			if ($day === null) {
				return ['hours' => 0.0, 'derivable' => false, 'basis' => 'contract-average', 'days' => []];
			}

			$usedPattern = ($usedPattern || $answer['patternFound']);
			$total += $day['hours'];
			$days[] = $day;
		}

		return ['hours' => round($total, 2), 'derivable' => true, 'basis' => $this->basis(usedPattern: $usedPattern, calendar: $calendar), 'days' => $days];
	}//end cost()

	/**
	 * The days of a request that fall in the year; none when the dates are unusable.
	 *
	 * @param array<string, mixed> $request The LeaveRequest row.
	 * @param int                  $year    The balance year.
	 *
	 * @return list<DateTimeImmutable>
	 */
	private function datesIn(array $request, int $year): array {
		$start = (string)($request['startDate'] ?? '');
		$end = (string)($request['endDate'] ?? '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
			return [];
		}

		$dates = [];
		$last = new DateTimeImmutable($end);
		for ($cursor = new DateTimeImmutable($start); $cursor <= $last; $cursor = $cursor->add(new DateInterval('P1D'))) {
			if ((int)$cursor->format('Y') === $year) {
				$dates[] = $cursor;
			}
		}

		return $dates;
	}//end datesIn()

	/**
	 * One day's cost and why, or null when it cannot be derived (no pattern
	 * in force and no contract hours).
	 *
	 * @param DateTimeImmutable                                                                $date     The day.
	 * @param array{hours: float, patternOnly: bool, patternFound: bool, calendarApplied: bool} $answer   WorkingHoursService's answer for the day.
	 * @param float|null                                                                       $average  Contract hours divided by five, or null.
	 * @param list<string>|null                                                                $calendar The calendar's non-working dates, or null when unread.
	 *
	 * @return array{date: string, hours: float, reason: string}|null
	 */
	private function dayCost(DateTimeImmutable $date, array $answer, ?float $average, ?array $calendar): ?array {
		$day = $date->format('Y-m-d');
		$weekend = ((int)$date->format('N') > 5);

		if ($answer['patternFound'] === true) {
			$hours = (float)$answer['hours'];
			$reason = ($hours > 0.0 ? 'pattern' : $this->freeReason(feestdag: $answer['calendarApplied'], weekend: $weekend));
			return ['date' => $day, 'hours' => $hours, 'reason' => $reason];
		}

		if ($average === null) {
			return null;
		}

		$feestdag = ($calendar !== null && in_array($day, $calendar, true) === true);
		if ($weekend === true || $feestdag === true) {
			return ['date' => $day, 'hours' => 0.0, 'reason' => $this->freeReason(feestdag: $feestdag, weekend: $weekend)];
		}

		return ['date' => $day, 'hours' => $average, 'reason' => 'contract-average'];
	}//end dayCost()

	/**
	 * The basis of a cost.
	 *
	 * @param bool              $usedPattern Whether any day came from a pattern.
	 * @param list<string>|null $calendar    The calendar dates, or null when unread.
	 *
	 * @return string pattern, pattern-only or contract-average.
	 */
	private function basis(bool $usedPattern, ?array $calendar): string {
		if ($usedPattern === false) {
			return 'contract-average';
		}

		return ($calendar === null ? 'pattern-only' : 'pattern');
	}//end basis()

	/**
	 * Why a day costs nothing.
	 *
	 * @param bool $feestdag Whether the calendar marks it non-working.
	 * @param bool $weekend  Whether it is a Saturday or Sunday.
	 *
	 * @return string feestdag, weekend or vrije-dag.
	 */
	private function freeReason(bool $feestdag, bool $weekend): string {
		if ($feestdag === true) {
			return 'feestdag';
		}

		return ($weekend === true ? 'weekend' : 'vrije-dag');
	}//end freeReason()

}//end class
