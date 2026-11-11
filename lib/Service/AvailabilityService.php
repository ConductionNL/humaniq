<?php

/**
 * Availability Service
 *
 * Who is free in a window, for how many hours, and optionally only those
 * qualified for the work.
 *
 * WHY HOURS AND NOT A YES OR NO
 * -----------------------------
 * "Available on Thursday" hides the difference between a person with one free
 * afternoon and a person with a free week, and the planner who cannot see that
 * difference books the wrong one. So the answer is free hours per employee
 * (REQ-AGD-002).
 *
 * WHY IT DIVIDES BY THE PERSON'S OWN HOURS
 * ----------------------------------------
 * Free time is contracted time minus what is already on the person. Contracted
 * time comes from `working-hours-per-person`, never from an instance default:
 * a 0.6 fte measured against a full-time week is reported as having half a week
 * free when they have none, which is the exact mistake that change exists to
 * stop.
 *
 * WHAT IS SUBTRACTED
 * ------------------
 * Rostered assignments, approved leave, open sick leave, resource bookings that
 * hold the person, and cached busy time from a subscribed external calendar.
 * The busy time counts, and its title does not travel with it.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Answers free hours per employee over a window.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
 */
class AvailabilityService {

	/**
	 * Constructor.
	 *
	 * @param WorkingHoursService $workingHours The contracted-hours denominator.
	 * @param AgendaComposer $agenda The composer that says what already holds the person.
	 * @param CompetenceCheckService $competences The qualification filter.
	 */
	public function __construct(
		private readonly WorkingHoursService $workingHours = new WorkingHoursService(),
		private readonly AgendaComposer $agenda = new AgendaComposer(),
		private readonly CompetenceCheckService $competences = new CompetenceCheckService(),
	) {

	}//end __construct()

	/**
	 * Free hours per employee over a window.
	 *
	 * @param array<int, string> $employeeIds The employees to answer for.
	 * @param DateTimeImmutable $from First day, inclusive.
	 * @param DateTimeImmutable $to Last day, inclusive.
	 * @param array<string, mixed> $sources Rows per source, as {@see AgendaComposer::compose()} takes them, plus `workingPatterns`, `nonWorkingTimes`, `competences` and `nonWorkingDates`.
	 * @param array<int, string> $requiredCompetences Codes every answered employee must hold on every day of the window.
	 *
	 * @return array<int, array<string, mixed>> One row per employee, most free first.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	public function availability(
		array $employeeIds,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		array $sources,
		array $requiredCompetences = [],
	): array {
		$answers = [];
		foreach (array_values(array_unique(array_map('strval', $employeeIds))) as $employeeId) {
			$window = new EmployeeWindow(employeeId: $employeeId, from: $from, to: $to, sources: $sources);
			if ($this->qualified(window: $window, requiredCompetences: $requiredCompetences) === false) {
				continue;
			}

			$answers[] = $this->freeHoursOf(window: $window);
		}

		usort(
			$answers,
			static function (array $a, array $b): int {
				return ([$b['freeHours'], $a['employeeId']] <=> [$a['freeHours'], $b['employeeId']]);
			}
		);

		return $answers;
	}//end availability()

	/**
	 * One employee's contracted, committed and free hours over the window.
	 *
	 * @param EmployeeWindow $window The employee, the days and the source rows.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function freeHoursOf(EmployeeWindow $window): array {
		$contracted = $this->workingHours->hoursOver(time: self::workingTimeOf(window: $window), range: [$window->from, $window->to]);

		$committed = $this->committedHours(window: $window, contractedPerDay: $this->contractedPerDay(window: $window));

		return [
			'employeeId' => $window->employeeId,
			'contractedHours' => $contracted['hours'],
			'committedHours' => round(num: $committed, precision: 2),
			'freeHours' => round(num: max(0.0, ($contracted['hours'] - $committed)), precision: 2),
			'patternOnly' => $contracted['patternOnly'],
			'hasContractedHours' => ($contracted['hours'] > 0.0),
		];
	}//end freeHoursOf()

	/**
	 * The contracted hours of each day in the window, for turning a whole-day
	 * absence into hours.
	 *
	 * @param EmployeeWindow $window The employee, the days and the source rows.
	 *
	 * @return array<string, float> Hours keyed by ISO date, one entry per day of the window, in order.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function contractedPerDay(EmployeeWindow $window): array {
		$perDay = [];
		$cursor = $window->from->setTime(hour: 0, minute: 0);
		$last = $window->to->setTime(hour: 0, minute: 0);
		while ($cursor <= $last) {
			$perDay[$cursor->format('Y-m-d')] = $this->workingHours->hoursOn(time: self::workingTimeOf(window: $window), date: $cursor)['hours'];

			$cursor = $cursor->modify('+1 day');
		}

		return $perDay;
	}//end contractedPerDay()

	/**
	 * The employee of a window and its working patterns, non-working times
	 * and calendar dates.
	 *
	 * @param EmployeeWindow $window The employee, the days and the source rows.
	 *
	 * @return EmployeeWorkingTime
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private static function workingTimeOf(EmployeeWindow $window): EmployeeWorkingTime {
		return new EmployeeWorkingTime(
			employeeId: $window->employeeId,
			sources: new WorkingTimeSources(
				workingPatterns: ($window->sources['workingPatterns'] ?? []),
				nonWorkingTimes: ($window->sources['nonWorkingTimes'] ?? []),
				nonWorkingDates: ($window->sources['nonWorkingDates'] ?? null)
			)
		);
	}//end workingTimeOf()

	/**
	 * The hours already committed on one employee in the window.
	 *
	 * A whole-day entry (leave, sickness) costs that day's contracted hours; a
	 * timed entry (shift, booking, external busy time) costs its own length,
	 * capped at the day's contracted hours so an evening booking cannot make a
	 * person more than fully committed. A day never counts more than the
	 * contracted hours, so two entries on one day cannot commit more time than
	 * the person has.
	 *
	 * @param EmployeeWindow $window The employee, the days and the source rows.
	 * @param array<string, float> $contractedPerDay Contracted hours by ISO date.
	 *
	 * @return float The committed hours.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function committedHours(EmployeeWindow $window, array $contractedPerDay): float {
		$perDay = [];
		foreach ($this->spansOf(window: $window) as $span) {
			foreach ($this->spreadOverDays(span: $span, contractedPerDay: $contractedPerDay) as $day => $hours) {
				$perDay[$day] = min((float)$contractedPerDay[$day], (($perDay[$day] ?? 0.0) + $hours));
			}
		}

		return array_sum($perDay);
	}//end committedHours()

	/**
	 * The committing agenda entries of one employee in the window, as spans.
	 *
	 * @param EmployeeWindow $window The employee, the days and the source rows.
	 *
	 * @return list<CommitmentSpan>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function spansOf(EmployeeWindow $window): array {
		$entries = $this->agenda->compose(
			sources: $window->sources,
			query: [
				'subjectType' => 'employee',
				'subjectId' => $window->employeeId,
				'from' => $window->from->format('Y-m-d'),
				'to' => $window->to->format('Y-m-d'),
			]
		);

		$spans = [];
		foreach ($entries as $entry) {
			if (in_array($entry['kind'], ['shift', 'leave', 'absent', 'booking', 'busy', 'interview', 'secondment'], true) === false) {
				continue;
			}

			$start = strtotime((string)$entry['start']);
			$end = strtotime((string)$entry['end']);
			if ($start === false || $end === false || $end <= $start) {
				continue;
			}

			$spans[] = new CommitmentSpan(start: $start, end: $end, share: $this->dayShare(entry: $entry, window: $window));
		}

		return $spans;
	}//end spansOf()

	/**
	 * One agenda entry's hours on each working day it touches.
	 *
	 * @param CommitmentSpan $span The entry.
	 * @param array<string, float> $contractedPerDay Contracted hours by ISO date, one entry per day of the window.
	 *
	 * @return array<string, float> The entry's hours by ISO date, only on the days it touches that the person works.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function spreadOverDays(CommitmentSpan $span, array $contractedPerDay): array {
		$hours = [];
		foreach ($contractedPerDay as $day => $contracted) {
			$day = (string)$day;
			$dayStart = strtotime($day . ' 00:00:00');
			$dayEnd = strtotime($day . ' 23:59:59');
			if ($dayStart === false || $dayEnd === false || $span->end <= $dayStart || $span->start >= $dayEnd) {
				continue;
			}

			if ((float)$contracted <= 0.0) {
				// Nothing is committed on a day the person does not work.
				continue;
			}

			$hours[$day] = $this->dayHours(span: $span, day: ['start' => $dayStart, 'end' => $dayEnd, 'contracted' => (float)$contracted]);
		}

		return $hours;
	}//end spreadOverDays()

	/**
	 * What one entry commits on one day.
	 *
	 * A whole-day entry costs its share of the contracted day, whatever the
	 * clock says: a 0.6 fte on leave loses 4.8 hours, not 24, and a 16-hour
	 * secondment in a 36-hour week costs 16/36 of each working day.
	 *
	 * @param CommitmentSpan $span The entry.
	 * @param array{start: int, end: int, contracted: float} $day Midnight, the last second and the contracted hours of the day.
	 *
	 * @return float The committed hours.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function dayHours(CommitmentSpan $span, array $day): float {
		if ($span->share !== null) {
			return ($day['contracted'] * $span->share);
		}

		return ((min($span->end, $day['end']) - max($span->start, $day['start'])) / 3600);
	}//end dayHours()

	/**
	 * The share of each working day an entry commits: all of it for leave and
	 * sickness, hours per week over the contracted week for a secondment, and
	 * null for a timed entry.
	 *
	 * @param array<string, mixed> $entry The agenda entry.
	 * @param EmployeeWindow $window The employee, the first day of the window and the source rows.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-002
	 */
	private function dayShare(array $entry, EmployeeWindow $window): ?float {
		if (in_array($entry['kind'], ['leave', 'absent'], true) === true) {
			return 1.0;
		}

		if ($entry['kind'] !== 'secondment') {
			return null;
		}

		// The contracted week of the pattern alone: no non-working times, no calendar.
		$patternsOnly = new EmployeeWorkingTime(
			employeeId: $window->employeeId,
			sources: new WorkingTimeSources(workingPatterns: ($window->sources['workingPatterns'] ?? []), nonWorkingTimes: [], nonWorkingDates: [])
		);
		$week = 0.0;
		for ($day = 0; $day < 7; $day++) {
			$week += $this->workingHours->hoursOn(time: $patternsOnly, date: $window->from->modify('+' . $day . ' days'))['hours'];
		}

		if ($week <= 0.0) {
			return 0.0;
		}

		return min(1.0, ((float)($entry['hoursPerWeek'] ?? 0) / $week));
	}//end dayShare()

	/**
	 * Whether one employee holds every required competence on every day of the
	 * window.
	 *
	 * Every day, not any day: a qualification that expires on the Wednesday
	 * does not make somebody available for a Thursday shift.
	 *
	 * @param EmployeeWindow $window The employee, the days and the EmployeeCompetence rows.
	 * @param array<int, string> $requiredCompetences The codes.
	 *
	 * @return bool True when qualified throughout.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function qualified(EmployeeWindow $window, array $requiredCompetences): bool {
		if ($requiredCompetences === []) {
			return true;
		}

		foreach ($requiredCompetences as $code) {
			$cursor = $window->from->setTime(hour: 0, minute: 0);
			$last = $window->to->setTime(hour: 0, minute: 0);
			while ($cursor <= $last) {
				if ($this->competences->holds(
					competences: ($window->sources['competences'] ?? []),
					employeeId: $window->employeeId,
					code: (string)$code,
					date: $cursor->format('Y-m-d')
				) === false
				) {
					return false;
				}

				$cursor = $cursor->modify('+1 day');
			}
		}

		return true;
	}//end qualified()
}//end class
