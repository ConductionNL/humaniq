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
			if ($this->qualified(
				employeeId: $employeeId,
				requiredCompetences: $requiredCompetences,
				competences: ($sources['competences'] ?? []),
				from: $from,
				to: $to
			) === false
			) {
				continue;
			}

			$contracted = $this->workingHours->contractedHoursOver(
				employeeId: $employeeId,
				from: $from,
				to: $to,
				patterns: ($sources['workingPatterns'] ?? []),
				nonWorkingTimes: ($sources['nonWorkingTimes'] ?? []),
				nonWorkingDates: ($sources['nonWorkingDates'] ?? null)
			);

			$committed = $this->committedHours(
				employeeId: $employeeId,
				sources: $sources,
				from: $from,
				to: $to,
				contractedPerDay: $this->contractedPerDay(
					employeeId: $employeeId,
					sources: $sources,
					from: $from,
					to: $to
				)
			);

			$answers[] = [
				'employeeId' => $employeeId,
				'contractedHours' => $contracted['hours'],
				'committedHours' => round(num: $committed, precision: 2),
				'freeHours' => round(num: max(0.0, ($contracted['hours'] - $committed)), precision: 2),
				'patternOnly' => $contracted['patternOnly'],
				'hasContractedHours' => ($contracted['hours'] > 0.0),
			];
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
	 * The contracted hours of each day in the window, for turning a whole-day
	 * absence into hours.
	 *
	 * @param string $employeeId The employee.
	 * @param array<string, mixed> $sources The source rows.
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to Last day.
	 *
	 * @return array<string, float> Hours keyed by ISO date.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function contractedPerDay(
		string $employeeId,
		array $sources,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
	): array {
		$perDay = [];
		$cursor = $from->setTime(hour: 0, minute: 0);
		$last = $to->setTime(hour: 0, minute: 0);
		while ($cursor <= $last) {
			$perDay[$cursor->format('Y-m-d')] = $this->workingHours->contractedHoursOn(
				employeeId: $employeeId,
				date: $cursor,
				patterns: ($sources['workingPatterns'] ?? []),
				nonWorkingTimes: ($sources['nonWorkingTimes'] ?? []),
				nonWorkingDates: ($sources['nonWorkingDates'] ?? null)
			)['hours'];

			$cursor = $cursor->modify('+1 day');
		}

		return $perDay;
	}//end contractedPerDay()

	/**
	 * The hours already committed on one employee in the window.
	 *
	 * A whole-day entry (leave, sickness) costs that day's contracted hours; a
	 * timed entry (shift, booking, external busy time) costs its own length,
	 * capped at the day's contracted hours so an evening booking cannot make a
	 * person more than fully committed.
	 *
	 * @param string $employeeId The employee.
	 * @param array<string, mixed> $sources The source rows.
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to Last day.
	 * @param array<string, float> $contractedPerDay Contracted hours by ISO date.
	 *
	 * @return float The committed hours.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function committedHours(
		string $employeeId,
		array $sources,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		array $contractedPerDay,
	): float {
		$entries = $this->agenda->compose(
			sources: $sources,
			subjectType: 'employee',
			subjectId: $employeeId,
			from: $from->format('Y-m-d'),
			to: $to->format('Y-m-d')
		);

		$perDay = [];
		foreach ($entries as $entry) {
			if (in_array($entry['kind'], ['shift', 'leave', 'absent', 'booking', 'busy', 'interview'], true) === false) {
				continue;
			}

			$start = strtotime((string)$entry['start']);
			$end = strtotime((string)$entry['end']);
			if ($start === false || $end === false || $end <= $start) {
				continue;
			}

			$perDay = $this->spreadOverDays(
				perDay: $perDay,
				kind: (string)$entry['kind'],
				start: $start,
				end: $end,
				from: $from,
				to: $to,
				contractedPerDay: $contractedPerDay
			);
		}

		return array_sum($perDay);
	}//end committedHours()

	/**
	 * Add one agenda entry's hours to the per-day totals it touches.
	 *
	 * A day never counts more than the contracted hours, so two entries on one
	 * day cannot commit more time than the person has.
	 *
	 * @param array<string, float> $perDay Committed hours so far, by ISO date.
	 * @param string $kind The entry kind.
	 * @param int $start The entry's start timestamp.
	 * @param int $end The entry's end timestamp.
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to Last day.
	 * @param array<string, float> $contractedPerDay Contracted hours by ISO date.
	 *
	 * @return array<string, float> The per-day totals, with this entry added.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function spreadOverDays(
		array $perDay,
		string $kind,
		int $start,
		int $end,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		array $contractedPerDay
	): array {
		$cursor = $from->setTime(hour: 0, minute: 0);
		$last = $to->setTime(hour: 0, minute: 0);
		while ($cursor <= $last) {
			$day = $cursor->format('Y-m-d');
			$dayStart = strtotime($day . ' 00:00:00');
			$dayEnd = strtotime($day . ' 23:59:59');
			$cursor = $cursor->modify('+1 day');

			if ($dayStart === false || $dayEnd === false || $end <= $dayStart || $start >= $dayEnd) {
				continue;
			}

			$contracted = (float)($contractedPerDay[$day] ?? 0.0);
			if ($contracted <= 0.0) {
				// Nothing is committed on a day the person does not work.
				continue;
			}

			$overlapHours = $this->dayHours(
				kind: $kind,
				start: $start,
				end: $end,
				dayStart: $dayStart,
				dayEnd: $dayEnd,
				contracted: $contracted
			);

			$perDay[$day] = min($contracted, (($perDay[$day] ?? 0.0) + $overlapHours));
		}

		return $perDay;
	}//end spreadOverDays()

	/**
	 * What one entry commits on one day.
	 *
	 * A whole-day absence costs the contracted day, whatever the clock says: a
	 * 0.6 fte on leave loses 4.8 hours, not 24.
	 *
	 * @param string $kind The entry kind.
	 * @param int $start The entry's start timestamp.
	 * @param int $end The entry's end timestamp.
	 * @param int $dayStart Midnight at the start of the day.
	 * @param int $dayEnd The last second of the day.
	 * @param float $contracted Contracted hours on that day.
	 *
	 * @return float The committed hours.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function dayHours(
		string $kind,
		int $start,
		int $end,
		int $dayStart,
		int $dayEnd,
		float $contracted
	): float {
		if (in_array($kind, ['leave', 'absent'], true) === true) {
			return $contracted;
		}

		return ((min($end, $dayEnd) - max($start, $dayStart)) / 3600);
	}//end dayHours()

	/**
	 * Whether one employee holds every required competence on every day of the
	 * window.
	 *
	 * Every day, not any day: a qualification that expires on the Wednesday
	 * does not make somebody available for a Thursday shift.
	 *
	 * @param string $employeeId The employee.
	 * @param array<int, string> $requiredCompetences The codes.
	 * @param array<array<string, mixed>> $competences The EmployeeCompetence rows.
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to Last day.
	 *
	 * @return bool True when qualified throughout.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-002
	 */
	private function qualified(
		string $employeeId,
		array $requiredCompetences,
		array $competences,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
	): bool {
		if ($requiredCompetences === []) {
			return true;
		}

		foreach ($requiredCompetences as $code) {
			$cursor = $from->setTime(hour: 0, minute: 0);
			$last = $to->setTime(hour: 0, minute: 0);
			while ($cursor <= $last) {
				if ($this->competences->holds(
					competences: $competences,
					employeeId: $employeeId,
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
