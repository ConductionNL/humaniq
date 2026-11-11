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
	 * @param EmployeeWorkingTime $time The employee and the patterns, non-working times and calendar dates to read (any employee; the calendar dates are null when that calendar could not be read).
	 * @param DateTimeImmutable   $date The date asked about.
	 *
	 * @return array{hours: float, patternOnly: bool, patternFound: bool, calendarApplied: bool}
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function hoursOn(EmployeeWorkingTime $time, DateTimeImmutable $date): array {
		$day = new WorkingDay(time: $time, date: $date);
		$pattern = $this->patternInForce(employeeId: $time->employeeId, date: $date, patterns: $time->sources->workingPatterns);

		if ($pattern === null) {
			return [
				'hours' => 0.0,
				'patternOnly' => $day->isPatternOnly(),
				'patternFound' => false,
				'calendarApplied' => false,
			];
		}

		return self::dayAnswer(day: $day, hours: $this->hoursAfterNonWorkingTimes(day: $day, pattern: $pattern));
	}//end hoursOn()

	/**
	 * Contracted hours for one employee over a closed date range.
	 *
	 * @param EmployeeWorkingTime                               $time  The employee and the records to read.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range First and last day, inclusive.
	 *
	 * @return array{hours: float, patternOnly: bool, days: int, workedDays: int}
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function hoursOver(EmployeeWorkingTime $time, array $range): array {
		$total = 0.0;
		$days = 0;
		$workedDays = 0;
		foreach (self::daysIn(range: $range) as $date) {
			$hours = $this->hoursOn(time: $time, date: $date)['hours'];

			$total += $hours;
			++$days;
			if ($hours > 0.0) {
				++$workedDays;
			}
		}

		return [
			'hours' => round(num: $total, precision: 4),
			'patternOnly' => ($time->sources->nonWorkingDates === null),
			'days' => $days,
			'workedDays' => $workedDays,
		];
	}//end hoursOver()

	/**
	 * Every day of a range, each at midnight.
	 *
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range First and last day, inclusive.
	 *
	 * @return list<DateTimeImmutable>
	 */
	private static function daysIn(array $range): array {
		$days = [];
		$cursor = $range[0]->setTime(hour: 0, minute: 0);
		$last = $range[1]->setTime(hour: 0, minute: 0);
		$step = new DateInterval('P1D');
		while ($cursor <= $last) {
			$days[] = $cursor;
			$cursor = $cursor->add($step);
		}

		return $days;
	}//end daysIn()

	/**
	 * The pattern's hours for the weekday, less every non-working time that
	 * covers the day: a time without hours takes the whole day.
	 *
	 * @param WorkingDay           $day     The employee's day.
	 * @param array<string, mixed> $pattern The pattern in force.
	 *
	 * @return float
	 */
	private function hoursAfterNonWorkingTimes(WorkingDay $day, array $pattern): float {
		$hours = $this->floatOrZero(value: ($pattern[(self::WEEKDAY_PROPERTIES[$day->weekday()] ?? '')] ?? null));
		foreach ($day->time->sources->nonWorkingTimes as $entry) {
			if ($this->nonWorkingTimeApplies(entry: $entry, day: $day) === true) {
				$hours = self::lessNonWorking(entry: $entry, hours: $hours);
			}
		}

		return $hours;
	}//end hoursAfterNonWorkingTimes()

	/**
	 * The hours left after one non-working time.
	 *
	 * @param array<string, mixed> $entry The NonWorkingTime.
	 * @param float                $hours The hours before it.
	 *
	 * @return float
	 */
	private static function lessNonWorking(array $entry, float $hours): float {
		$hoursNotWorked = ($entry['hoursNotWorked'] ?? null);
		if (is_numeric($hoursNotWorked) === false) {
			// A whole non-working day, which is the common case: nobody
			// records how many hours a standing free Wednesday is.
			return 0.0;
		}

		return max(0.0, ($hours - (float)$hoursNotWorked));
	}//end lessNonWorking()

	/**
	 * The answer for a day with a pattern: the working calendar's
	 * non-working dates zero it.
	 *
	 * @param WorkingDay $day   The employee's day.
	 * @param float      $hours The pattern hours left after non-working times.
	 *
	 * @return array{hours: float, patternOnly: bool, patternFound: bool, calendarApplied: bool}
	 */
	private static function dayAnswer(WorkingDay $day, float $hours): array {
		$calendarApplied = $day->isCalendarNonWorking();

		return [
			'hours' => round(num: ($calendarApplied === true ? 0.0 : $hours), precision: 4),
			'patternOnly' => $day->isPatternOnly(),
			'patternFound' => true,
			'calendarApplied' => $calendarApplied,
		];
	}//end dayAnswer()

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
		foreach ($this->windowsByEmployee(patterns: $patterns) as $employeeId => $windows) {
			self::assertWindowsDoNotOverlap(employeeId: $employeeId, windows: $windows);
		}
	}//end assertPatternsDoNotOverlap()

	/**
	 * Each employee's pattern windows (`from` date, `until` or 9999-12-31),
	 * in pattern order. A pattern without an employee or a start date cannot
	 * overlap anything: the schema refuses it before this service sees it.
	 *
	 * @param array<array<string, mixed>> $patterns WorkingPattern objects (plain arrays).
	 *
	 * @return array<int|string, array<int, array{from: string, until: string}>>
	 */
	private function windowsByEmployee(array $patterns): array {
		$byEmployee = [];
		foreach ($patterns as $pattern) {
			$employeeId = $this->stringOrNull(value: ($pattern['employeeId'] ?? null));
			$validFrom = $this->stringOrNull(value: ($pattern['validFrom'] ?? null));
			if ($employeeId === null || $validFrom === null) {
				continue;
			}

			$byEmployee[$employeeId][] = [
				'from' => substr($validFrom, 0, 10),
				'until' => ($this->stringOrNull(value: ($pattern['validUntil'] ?? null)) ?? '9999-12-31'),
			];
		}

		return $byEmployee;
	}//end windowsByEmployee()

	/**
	 * Refuse the first pair of one employee's windows that share a day.
	 *
	 * @param int|string $employeeId The employee (an int when the id is numeric, as an array key).
	 * @param array<int, array{from: string, until: string}> $windows The employee's windows.
	 *
	 * @return void
	 *
	 * @throws OverlappingWorkingPatternException When two windows overlap.
	 */
	private static function assertWindowsDoNotOverlap(int|string $employeeId, array $windows): void {
		$count = count($windows);
		for ($i = 0; $i < $count; $i++) {
			$b = self::firstOverlapWith(windows: $windows, i: $i);
			if ($b !== null) {
				throw new OverlappingWorkingPatternException(
					'Two working patterns for employee ' . $employeeId
					. ' cover the same days (' . $windows[$i]['from'] . ' and ' . $b['from']
					. '). End the running pattern before writing a new one.'
				);
			}
		}
	}//end assertWindowsDoNotOverlap()

	/**
	 * The first window after window `$i` that shares a day with it, or null.
	 *
	 * @param array<int, array{from: string, until: string}> $windows One employee's windows.
	 * @param int $i The window to compare the later ones with.
	 *
	 * @return array{from: string, until: string}|null
	 */
	private static function firstOverlapWith(array $windows, int $i): ?array {
		$a = $windows[$i];
		$count = count($windows);
		for ($j = ($i + 1); $j < $count; $j++) {
			$b = $windows[$j];
			if ($a['from'] <= substr($b['until'], 0, 10) && $b['from'] <= substr($a['until'], 0, 10)) {
				return $b;
			}
		}

		return null;
	}//end firstOverlapWith()

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
	 * @param WorkingDay           $day   The employee's day.
	 *
	 * @return bool True when it applies.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-002
	 */
	private function nonWorkingTimeApplies(array $entry, WorkingDay $day): bool {
		if ($this->stringOrNull(value: ($entry['employeeId'] ?? null)) !== $day->time->employeeId) {
			return false;
		}

		if ($this->windowCovers(entry: $entry, isoDate: $day->isoDate()) === false) {
			return false;
		}

		$recurring = $this->stringOrNull(value: ($entry['recurringWeekday'] ?? null));
		if ($recurring !== null) {
			return (strtolower($recurring) === $day->weekday());
		}

		// A one-off entry needs a window: an entry with neither a weekday nor a
		// date would otherwise apply to every day for ever.
		return ($this->stringOrNull(value: ($entry['startDate'] ?? null)) !== null || $this->stringOrNull(value: ($entry['endDate'] ?? null)) !== null);
	}//end nonWorkingTimeApplies()

	/**
	 * Whether the date falls inside the entry's start and end dates, each
	 * open when absent.
	 *
	 * @param array<string, mixed> $entry   The NonWorkingTime.
	 * @param string               $isoDate The date, `Y-m-d`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-002
	 */
	private function windowCovers(array $entry, string $isoDate): bool {
		$start = $this->stringOrNull(value: ($entry['startDate'] ?? null));
		if ($start !== null && substr($start, 0, 10) > $isoDate) {
			return false;
		}

		$end = $this->stringOrNull(value: ($entry['endDate'] ?? null));

		return ($end === null || substr($end, 0, 10) >= $isoDate);
	}//end windowCovers()

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
