<?php

/**
 * Pattern Day Equivalents
 *
 * Contracted working hours from the working pattern, in calendar-day
 * equivalents, for one absence-rate run.
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
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Answers how many day-equivalents an employee is contracted for over a
 * range, so the absent and the available side of a rate use one basis.
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
 */
final class PatternDayEquivalents {

	/**
	 * @param WorkingHoursService $workingHours The one definition of a working day.
	 * @param WorkingTimeSources $sources The patterns, non-working times and calendar dates.
	 * @param AbsencePeriod $period The period and its full-time week.
	 */
	public function __construct(
		private readonly WorkingHoursService $workingHours,
		private readonly WorkingTimeSources $sources,
		public readonly AbsencePeriod $period,
	) {

	}//end __construct()

	/**
	 * Contracted day-equivalents of one employee over a range.
	 *
	 * @param string $employeeId The employee.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable}|null $range First and last day, inclusive; null for the whole period.
	 *
	 * @return float Day-equivalents.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function over(string $employeeId, ?array $range = null): float {
		$range = ($range ?? [$this->period->start, $this->period->end]);
		$resolved = $this->workingHours->contractedHoursOver(
			employeeId: $employeeId,
			from: $range[0],
			to: $range[1],
			patterns: $this->sources->workingPatterns,
			nonWorkingTimes: $this->sources->nonWorkingTimes,
			nonWorkingDates: $this->sources->nonWorkingDates
		);

		return $this->workingHours->dayEquivalents(
			hours: $resolved['hours'],
			fullTimeHoursWeek: $this->period->fullTimeHoursWeek
		);
	}//end over()

	/**
	 * Contracted day-equivalents of several employees over the whole period.
	 *
	 * @param list<string> $employeeIds The employees.
	 *
	 * @return float Day-equivalents.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function total(array $employeeIds): float {
		$total = 0.0;
		foreach ($employeeIds as $employeeId) {
			$total += $this->over(employeeId: $employeeId);
		}

		return $total;
	}//end total()

	/**
	 * Absent day-equivalents of one employee over absence segments: the
	 * contracted hours each segment covers, at its percentage.
	 *
	 * @param string $employeeId The employee.
	 * @param list<array{from: DateTimeImmutable, to: DateTimeImmutable, percentage: float}> $segments The segments.
	 *
	 * @return float Day-equivalents.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-003
	 */
	public function absent(string $employeeId, array $segments): float {
		$absent = 0.0;
		foreach ($segments as $segment) {
			$hours = $this->over(employeeId: $employeeId, range: [$segment['from'], $segment['to']]);
			$absent += ($hours * ($segment['percentage'] / 100.0));
		}

		return $absent;
	}//end absent()

}//end class
