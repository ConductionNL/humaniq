<?php

/**
 * Working Hours Service
 *
 * The one answer to "how many hours does this person work on this date".
 *
 * WHY THIS EXISTS
 * ---------------
 * humaniq could already say what was planned (`rostering`) and what was
 * clocked (`time-attendance`), and neither says how many hours the person is
 * contracted for. Every capacity figure, every availability answer and every
 * absence percentage divides by that number, and each of them used to derive
 * it for itself from an fte fraction. Four derivations of the same figure is
 * how an absence percentage and a capacity percentage come to disagree about
 * the same person in the same week, so this service is the single resolution
 * point the change's design D6 asks for.
 *
 * THE ORDER OF RESOLUTION
 * -----------------------
 * 1. The `WorkingPattern` in force on that date supplies the contracted hours
 *    for that weekday. A pattern is dated, and a contract change writes a new
 *    one rather than editing the running one, so a figure over last quarter
 *    still divides by last quarter's contract (design D2).
 * 2. The employee's `NonWorkingTime` entries subtract from it: a whole day for
 *    a standing free Wednesday, part of one for a reduced re-integration
 *    afternoon. Neither is leave and neither is sickness (design D3).
 * 3. openregister's working calendar zeroes the day when it is a feestdag.
 *    humaniq reads that calendar and never owns it (decision D19).
 *
 * WHAT IT REFUSES TO DO
 * ---------------------
 * - **It does not guess a calendar it could not read.** When the caller passes
 *   no set of non-working dates, the answer is marked `patternOnly` and the
 *   caller is expected to say so. Returning eight hours for second Whitsun
 *   because openregister was unreachable is a wrong number that looks right.
 * - **It does not pick between two patterns.** Overlapping patterns for one
 *   employee are refused by {@see assertPatternsDoNotOverlap()} rather than
 *   resolved by ordering.
 * - **It holds no calendar of its own.** No feestdag, no freeze period and no
 *   week numbering lives here or in humaniq's register.
 *
 * Deliberately dependency-free: the caller supplies the already-fetched
 * patterns, non-working times and calendar dates, which is what makes every
 * branch below reachable from a unit test without a Nextcloud bootstrap. The
 * same shape as {@see AbsenceRateService}.
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
 * @spec openspec/specs/working-hours-per-person/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateInterval;
use DateTimeImmutable;

/**
 * Pure calculator answering the contracted hours of one employee on one date,
 * and over a range.
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
 */
class WorkingHoursService {

	/**
	 * The `WorkingPattern` property holding each weekday's contracted hours.
	 *
	 * Keyed by the lowercase English weekday name, which is also the
	 * `NonWorkingTime.recurringWeekday` vocabulary, so one map serves both.
	 *
	 * @var array<string, string>
	 */
	public const WEEKDAY_PROPERTIES = [
		'monday' => 'hoursMonday',
		'tuesday' => 'hoursTuesday',
		'wednesday' => 'hoursWednesday',
		'thursday' => 'hoursThursday',
		'friday' => 'hoursFriday',
		'saturday' => 'hoursSaturday',
		'sunday' => 'hoursSunday',
	];

	/**
	 * How many days a week of contracted hours is spread over when a figure is
	 * expressed in calendar-day equivalents.
	 *
	 * @var float
	 */
	public const DAYS_PER_WEEK = 7.0;

	/**
	 * Contracted hours for one employee on one date.
	 *
	 * @param string $employeeId The employee uuid.
	 * @param DateTimeImmutable $date The date asked about.
	 * @param array<array<string, mixed>> $patterns WorkingPattern objects (plain arrays), any employee.
	 * @param array<array<string, mixed>> $nonWorkingTimes NonWorkingTime objects (plain arrays), any employee.
	 * @param array<int, string>|null $nonWorkingDates ISO dates openregister's working calendar marks non-working, or null when that calendar could not be read.
	 *
	 * @return array{hours: float, patternOnly: bool, patternFound: bool, calendarApplied: bool}
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function contractedHoursOn(
		string $employeeId,
		DateTimeImmutable $date,
		array $patterns,
		array $nonWorkingTimes = [],
		?array $nonWorkingDates = null,
	): array {
		$patternOnly = ($nonWorkingDates === null);
		$pattern = $this->patternInForce(employeeId: $employeeId, date: $date, patterns: $patterns);

		if ($pattern === null) {
			return [
				'hours' => 0.0,
				'patternOnly' => $patternOnly,
				'patternFound' => false,
				'calendarApplied' => false,
			];
		}

		$weekday = strtolower($date->format('l'));
		$hours = $this->floatOrZero(value: ($pattern[(self::WEEKDAY_PROPERTIES[$weekday] ?? '')] ?? null));

		foreach ($nonWorkingTimes as $entry) {
			if ($this->nonWorkingTimeApplies(entry: $entry, employeeId: $employeeId, date: $date, weekday: $weekday) === false) {
				continue;
			}

			$hoursNotWorked = ($entry['hoursNotWorked'] ?? null);
			if (is_numeric($hoursNotWorked) === false) {
				// A whole non-working day, which is the common case: nobody
				// records how many hours a standing free Wednesday is.
				$hours = 0.0;
				continue;
			}

			$hours = max(0.0, ($hours - (float)$hoursNotWorked));
		}

		$calendarApplied = false;
		if ($nonWorkingDates !== null && in_array($date->format('Y-m-d'), $nonWorkingDates, true) === true) {
			$hours = 0.0;
			$calendarApplied = true;
		}

		return [
			'hours' => round(num: $hours, precision: 4),
			'patternOnly' => $patternOnly,
			'patternFound' => true,
			'calendarApplied' => $calendarApplied,
		];
	}//end contractedHoursOn()

	/**
	 * Contracted hours for one employee over a closed date range.
	 *
	 * @param string $employeeId The employee uuid.
	 * @param DateTimeImmutable $from First day, inclusive.
	 * @param DateTimeImmutable $to Last day, inclusive.
	 * @param array<array<string, mixed>> $patterns WorkingPattern objects (plain arrays).
	 * @param array<array<string, mixed>> $nonWorkingTimes NonWorkingTime objects (plain arrays).
	 * @param array<int, string>|null $nonWorkingDates ISO dates the working calendar marks non-working, or null when unreadable.
	 *
	 * @return array{hours: float, patternOnly: bool, days: int, workedDays: int}
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function contractedHoursOver(
		string $employeeId,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		array $patterns,
		array $nonWorkingTimes = [],
		?array $nonWorkingDates = null,
	): array {
		$total = 0.0;
		$days = 0;
		$workedDays = 0;

		$cursor = $from->setTime(hour: 0, minute: 0);
		$last = $to->setTime(hour: 0, minute: 0);
		$step = new DateInterval('P1D');

		while ($cursor <= $last) {
			$answer = $this->contractedHoursOn(
				employeeId: $employeeId,
				date: $cursor,
				patterns: $patterns,
				nonWorkingTimes: $nonWorkingTimes,
				nonWorkingDates: $nonWorkingDates
			);

			$total += $answer['hours'];
			++$days;
			if ($answer['hours'] > 0.0) {
				++$workedDays;
			}

			$cursor = $cursor->add($step);
		}

		return [
			'hours' => round(num: $total, precision: 4),
			'patternOnly' => ($nonWorkingDates === null),
			'days' => $days,
			'workedDays' => $workedDays,
		];
	}//end contractedHoursOver()

	/**
	 * The pattern governing one employee on one date, or null when none does.
	 *
	 * @param string $employeeId The employee uuid.
	 * @param DateTimeImmutable $date The date asked about.
	 * @param array<array<string, mixed>> $patterns WorkingPattern objects (plain arrays).
	 *
	 * @return array<string, mixed>|null The pattern in force, or null.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	public function patternInForce(string $employeeId, DateTimeImmutable $date, array $patterns): ?array {
		$day = $date->format('Y-m-d');
		foreach ($patterns as $pattern) {
			if ($this->stringOrNull(value: ($pattern['employeeId'] ?? null)) !== $employeeId) {
				continue;
			}

			$validFrom = $this->stringOrNull(value: ($pattern['validFrom'] ?? null));
			if ($validFrom === null || substr($validFrom, 0, 10) > $day) {
				continue;
			}

			$validUntil = $this->stringOrNull(value: ($pattern['validUntil'] ?? null));
			if ($validUntil !== null && substr($validUntil, 0, 10) < $day) {
				continue;
			}

			return $pattern;
		}

		return null;
	}//end patternInForce()

	/**
	 * Refuse a set of patterns in which two cover the same employee on the same
	 * day.
	 *
	 * Call this before writing a pattern, with the candidate included in the
	 * set. The check is over the whole set rather than pairwise against the
	 * candidate, so an overlap already present is reported too.
	 *
	 * @param array<array<string, mixed>> $patterns WorkingPattern objects (plain arrays), one employee or many.
	 *
	 * @return void
	 *
	 * @throws OverlappingWorkingPatternException When two patterns for one employee overlap.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	public function assertPatternsDoNotOverlap(array $patterns): void {
		$byEmployee = [];
		foreach ($patterns as $pattern) {
			$employeeId = $this->stringOrNull(value: ($pattern['employeeId'] ?? null));
			$validFrom = $this->stringOrNull(value: ($pattern['validFrom'] ?? null));
			if ($employeeId === null || $validFrom === null) {
				// A pattern without an employee or a start date cannot overlap
				// anything: the schema refuses it before this service sees it.
				continue;
			}

			$byEmployee[$employeeId][] = [
				'from' => substr($validFrom, 0, 10),
				'until' => ($this->stringOrNull(value: ($pattern['validUntil'] ?? null)) ?? '9999-12-31'),
			];
		}

		foreach ($byEmployee as $employeeId => $windows) {
			$count = count($windows);
			for ($i = 0; $i < $count; $i++) {
				for ($j = ($i + 1); $j < $count; $j++) {
					$a = $windows[$i];
					$b = $windows[$j];
					if ($a['from'] <= substr($b['until'], 0, 10) && $b['from'] <= substr($a['until'], 0, 10)) {
						throw new OverlappingWorkingPatternException(
							'Two working patterns for employee ' . $employeeId
							. ' cover the same days (' . $a['from'] . ' and ' . $b['from']
							. '). End the running pattern before writing a new one.'
						);
					}
				}
			}
		}
	}//end assertPatternsDoNotOverlap()

	/**
	 * Contracted hours expressed in calendar-day equivalents, the basis
	 * {@see AbsenceRateService} measures availability on.
	 *
	 * @param float $hours Contracted hours.
	 * @param float $fullTimeHoursWeek Hours per week a 1.0 FTE works.
	 *
	 * @return float Day-equivalents, or 0.0 when the full-time week is unusable.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function dayEquivalents(float $hours, float $fullTimeHoursWeek): float {
		if ($fullTimeHoursWeek <= 0.0) {
			return 0.0;
		}

		return ($hours / ($fullTimeHoursWeek / self::DAYS_PER_WEEK));
	}//end dayEquivalents()

	/**
	 * Whether one non-working time covers one employee's date.
	 *
	 * @param array<string, mixed> $entry The NonWorkingTime.
	 * @param string $employeeId The employee uuid.
	 * @param DateTimeImmutable $date The date asked about.
	 * @param string $weekday The lowercase English weekday name of that date.
	 *
	 * @return bool True when it applies.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-002
	 */
	private function nonWorkingTimeApplies(
		array $entry,
		string $employeeId,
		DateTimeImmutable $date,
		string $weekday,
	): bool {
		if ($this->stringOrNull(value: ($entry['employeeId'] ?? null)) !== $employeeId) {
			return false;
		}

		$day = $date->format('Y-m-d');
		$start = $this->stringOrNull(value: ($entry['startDate'] ?? null));
		$end = $this->stringOrNull(value: ($entry['endDate'] ?? null));
		if ($start !== null && substr($start, 0, 10) > $day) {
			return false;
		}

		if ($end !== null && substr($end, 0, 10) < $day) {
			return false;
		}

		$recurring = $this->stringOrNull(value: ($entry['recurringWeekday'] ?? null));
		if ($recurring !== null) {
			return (strtolower($recurring) === $weekday);
		}

		// A one-off entry needs a window: an entry with neither a weekday nor a
		// date would otherwise apply to every day for ever.
		return ($start !== null || $end !== null);
	}//end nonWorkingTimeApplies()

	/**
	 * Narrow a raw value to a float, defaulting to zero.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return float The value, or 0.0 when it is not numeric.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	private function floatOrZero(mixed $value): float {
		if (is_numeric($value) === false) {
			return 0.0;
		}

		return max(0.0, (float)$value);
	}//end floatOrZero()

	/**
	 * Narrow a raw value to a non-empty string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null Null when absent, not a string, or blank.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
