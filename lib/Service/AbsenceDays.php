<?php

/**
 * Humaniq AbsenceDays
 *
 * The hours one leave request or one sick report takes on each day, in hours
 * from the employee's working pattern (time-non-project-activities D2):
 *
 * - Approved leave: a request with `hours` spreads them over all of its
 *   working days in proportion to each day's contracted hours; a request
 *   without hours takes each working day's contracted hours.
 * - Sickness: each day of the case costs the day's contracted hours times the
 *   absence percentage in force that day (`absenceProgression`). A recovered
 *   case ends on its `recoveredDate`, the window {@see AbsenceRateService}
 *   uses; an open case runs on.
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
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateInterval;
use DateTimeImmutable;

/**
 * Spreads leave and sickness over days.
 *
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */
class AbsenceDays {

	/**
	 * Constructor.
	 *
	 * @param WorkingHoursService $workingHours The contracted hours per day.
	 * @param AbsenceProgression  $progression  The sickness absence steps and date parsing.
	 */
	public function __construct(
		private readonly WorkingHoursService $workingHours,
		private readonly AbsenceProgression $progression,
	) {

	}//end __construct()

	/**
	 * Contracted hours by ISO date over a range.
	 *
	 * @param EmployeeWorkingTime                               $time  The employee and the working-time records.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range First and last day, inclusive.
	 *
	 * @return array<string, float>
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function contracted(EmployeeWorkingTime $time, array $range): array {
		$hours = [];
		foreach ($this->days(from: $range[0], to: $range[1]) as $day) {
			$hours[$day->format('Y-m-d')] = (float)$this->workingHours->hoursOn(time: $time, date: $day)['hours'];
		}

		return $hours;
	}//end contracted()

	/**
	 * The hours of one approved leave request on each day of the range.
	 *
	 * @param EmployeeWorkingTime                               $time    The employee and the working-time records.
	 * @param array<string, mixed>                              $request The LeaveRequest.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range   First and last day, inclusive.
	 *
	 * @return array<string, float> Hours by ISO date, only working days inside the range.
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function leave(EmployeeWorkingTime $time, array $request, array $range): array {
		$window = $this->leaveWindow(request: $request, range: $range);
		if ($window === null) {
			return [];
		}

		// The whole request, also outside the range: its hours are spread over
		// all of its working days, not only the ones this period sees.
		$contracted = $this->contracted(time: $time, range: $window);
		$share = $this->leaveShare(request: $request, contracted: $contracted);

		$hours = [];
		foreach ($contracted as $date => $dayHours) {
			if ($dayHours > 0.0 && $this->inRange(date: (string)$date, range: $range) === true) {
				$hours[(string)$date] = ($dayHours * $share);
			}
		}

		return $hours;
	}//end leave()

	/**
	 * The sick hours of one case on each day of the range.
	 *
	 * @param array<string, mixed>                              $sickCase The SickLeaveCase.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range    First and last day, inclusive.
	 * @param array<string, float>                              $contract Contracted hours by ISO date over the range.
	 *
	 * @return array<string, float> Hours by ISO date.
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function sickness(array $sickCase, array $range, array $contract): array {
		$first = $this->progression->date(value: ($sickCase['firstSickDay'] ?? null));
		if ($first === null) {
			return [];
		}

		$last = $range[1];
		$recovered = $this->progression->date(value: ($sickCase['recoveredDate'] ?? null));
		if ($recovered !== null && ($sickCase['status'] ?? null) === 'hersteld') {
			$last = min($last, $recovered);
		}

		$steps = $this->progression->steps(case: $sickCase, firstSickDay: $first);
		$hours = [];
		foreach ($this->days(from: max($first, $range[0]), to: $last) as $day) {
			$date = $day->format('Y-m-d');
			$hours[$date] = (($contract[$date] ?? 0.0) * $this->percentageOn(steps: $steps, day: $day) / 100);
		}

		return $hours;
	}//end sickness()

	/**
	 * Every day from one date to another, inclusive, at midnight.
	 *
	 * @param DateTimeImmutable $from The first day.
	 * @param DateTimeImmutable $to   The last day.
	 *
	 * @return list<DateTimeImmutable>
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function days(DateTimeImmutable $from, DateTimeImmutable $to): array {
		$days = [];
		$cursor = $from->setTime(0, 0);
		$last = $to->setTime(0, 0);
		$step = new DateInterval('P1D');
		while ($cursor <= $last) {
			$days[] = $cursor;
			$cursor = $cursor->add($step);
		}

		return $days;
	}//end days()

	/**
	 * The first and last day of an approved request that touches the range.
	 *
	 * @param array<string, mixed>                              $request The LeaveRequest.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range   First and last day, inclusive.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null Null for a request that is not approved, has no dates or misses the range.
	 */
	private function leaveWindow(array $request, array $range): ?array {
		if (($request['status'] ?? null) !== 'approved') {
			return null;
		}

		$start = $this->progression->date(value: ($request['startDate'] ?? null));
		$end = ($this->progression->date(value: ($request['endDate'] ?? null)) ?? $start);
		if ($start === null || $end === null || $end < $start || $end < $range[0] || $start > $range[1]) {
			return null;
		}

		return [$start, $end];
	}//end leaveWindow()

	/**
	 * The part of each contracted day the request takes: all of it without
	 * requested hours, else the requested hours over the request's contracted hours.
	 *
	 * @param array<string, mixed> $request    The LeaveRequest.
	 * @param array<string, float> $contracted Contracted hours by ISO date over the whole request.
	 *
	 * @return float
	 */
	private function leaveShare(array $request, array $contracted): float {
		$requested = ($request['hours'] ?? null);
		if (is_numeric($requested) === false || (float)$requested <= 0.0) {
			return 1.0;
		}

		$sum = array_sum($contracted);

		return ($sum > 0.0) ? ((float)$requested / $sum) : 0.0;
	}//end leaveShare()

	/**
	 * The absence percentage in force on a day.
	 *
	 * @param list<array{from: DateTimeImmutable, percentage: float}> $steps Ordered ascending by `from`.
	 * @param DateTimeImmutable                                       $day   The day.
	 *
	 * @return float
	 */
	private function percentageOn(array $steps, DateTimeImmutable $day): float {
		$percentage = 0.0;
		foreach ($steps as $step) {
			if ($step['from'] <= $day) {
				$percentage = (float)$step['percentage'];
			}
		}

		return $percentage;
	}//end percentageOn()

	/**
	 * Whether an ISO date lies inside a range.
	 *
	 * @param string                                            $date  The ISO date.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range First and last day, inclusive.
	 *
	 * @return bool
	 */
	private function inRange(string $date, array $range): bool {
		return $date >= $range[0]->format('Y-m-d') && $date <= $range[1]->format('Y-m-d');
	}//end inRange()

}//end class
