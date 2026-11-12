<?php

/**
 * Agenda Composer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What is planned for a person, a team or a room, composed on read from the
 * objects that already say it.
 *
 * WHY NOTHING IS STORED
 * ---------------------
 * Six sources produce agenda entries: rostered assignments, approved leave,
 * open sick leave, interviews, resource bookings and cached external busy time.
 * Storing a seventh copy would mean keeping six things in step, and would make
 * the AVG boundary something to re-enforce per copy rather than once. Composed
 * on read, a leave request cancelled at 16:00 is off the agenda at 16:00
 * (design D4).
 *
 * THE AVG BOUNDARY, INHERITED RATHER THAN RESTATED
 * ------------------------------------------------
 * An entry sourced from a `SickLeaveCase` says the person is absent and says
 * nothing about why: no reason, no diagnosis, no progression. An entry sourced
 * from an external calendar says busy and carries no title, no location and no
 * attendees. That is the line `leave-calendar-nc` already draws for the
 * Nextcloud calendar, drawn here in the same shape, and the way it is enforced
 * is that the composer never copies those fields in the first place. A filter
 * applied afterwards is a filter somebody can forget.
 *
 * Deliberately dependency-free: the caller supplies the already-fetched rows.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Composes one subject's agenda from the six sources that already hold it.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
 */
class AgendaComposer {

	/**
	 * The kinds an entry can carry, one per source.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = ['shift', 'leave', 'absent', 'interview', 'booking', 'busy', 'secondment'];

	/**
	 * Constructor.
	 *
	 * @param AgendaBookingEntries $bookings Builds the booking and busy-time entries.
	 * @param AgendaSecondmentEntries $secondments Builds the secondment entries.
	 */
	public function __construct(
		private readonly AgendaBookingEntries $bookings = new AgendaBookingEntries(),
		private readonly AgendaSecondmentEntries $secondments = new AgendaSecondmentEntries(),
	) {

	}//end __construct()


	/**
	 * Compose the agenda for one subject over a period.
	 *
	 * @param array<string, mixed> $sources Rows per source: assignments, shifts, leaveRequests, sickLeaveCases, interviews, bookings, subscriptions.
	 * @param array{subjectType: string, subjectId: string, from: string, to: string, memberEmployeeIds?: array<int, mixed>} $query The subject kind (`employee`, `orgUnit` or `resource`), its id, the first and last day (inclusive, ISO dates), and the employees in the org unit when the subject is one.
	 *
	 * @return array<int, array<string, mixed>> The entries, in start order.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function compose(array $sources, array $query): array {
		$window = new AgendaWindow(
			subject: new AgendaSubject(type: $query['subjectType'], id: $query['subjectId'], memberEmployeeIds: ($query['memberEmployeeIds'] ?? [])),
			from: $query['from'],
			to: $query['to']
		);

		$entries = array_merge(
			$this->shiftEntries(sources: $sources, window: $window),
			$this->leaveEntries(sources: $sources, window: $window),
			$this->absenceEntries(sources: $sources, window: $window),
			$this->interviewEntries(sources: $sources, window: $window),
			$this->secondments->entries(sources: $sources, window: $window),
			$this->bookings->bookingEntries(sources: $sources, window: $window),
			$this->bookings->busyEntries(sources: $sources, window: $window)
		);

		usort(
			$entries,
			static function (array $a, array $b): int {
				return ([$a['start'], $a['kind']] <=> [$b['start'], $b['kind']]);
			}
		);

		return $entries;
	}//end compose()

	/**
	 * Rostered shifts as agenda entries.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param AgendaWindow $window The subject's employees and the first and last day.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function shiftEntries(array $sources, AgendaWindow $window): array {
		$shiftsById = [];
		foreach (($sources['shifts'] ?? []) as $shift) {
			$shiftsById[trim((string)($shift['id'] ?? ''))] = $shift;
		}

		$entries = [];
		foreach (($sources['assignments'] ?? []) as $assignment) {
			$date = substr(trim((string)($assignment['date'] ?? '')), 0, 10);
			if (self::employeeIn(row: $assignment, window: $window) !== null && $date !== '' && $date >= $window->from && $date <= $window->to) {
				$entries[] = $this->shiftEntry(assignment: $assignment, shift: ($shiftsById[trim((string)($assignment['shiftId'] ?? ''))] ?? []));
			}
		}

		return $entries;
	}//end shiftEntries()

	/**
	 * One rostered shift, at its planned times or over the whole day.
	 *
	 * @param array<string, mixed> $assignment The RosterAssignment.
	 * @param array<string, mixed> $shift      Its Shift, or [].
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function shiftEntry(array $assignment, array $shift): array {
		$date = substr(trim((string)($assignment['date'] ?? '')), 0, 10);

		return [
			'kind' => 'shift',
			'subjectType' => 'employee',
			'subjectId' => trim((string)($assignment['employeeId'] ?? '')),
			'start' => ($this->stringOrNull(($assignment['plannedStart'] ?? null)) ?? ($date . 'T00:00:00')),
			'end' => ($this->stringOrNull(($assignment['plannedEnd'] ?? null)) ?? ($date . 'T23:59:59')),
			'label' => trim((string)($shift['name'] ?? 'Dienst')),
			'sourceType' => 'RosterAssignment',
			'sourceId' => trim((string)($assignment['id'] ?? '')),
		];
	}//end shiftEntry()

	/**
	 * The row's employee when the window stands for them; null otherwise.
	 *
	 * @param array<string, mixed> $row    A row with an employeeId.
	 * @param AgendaWindow         $window The subject's employees.
	 *
	 * @return string|null
	 */
	private static function employeeIn(array $row, AgendaWindow $window): ?string {
		$employeeId = trim((string)($row['employeeId'] ?? ''));

		return (in_array($employeeId, $window->employees, true) === true ? $employeeId : null);
	}//end employeeIn()

	/**
	 * A whole-day entry from its first to its last day.
	 *
	 * @param array{kind: string, subjectId: string, label: string, sourceType: string, sourceId: string} $source What the entry is.
	 * @param array{0: string, 1: string}                                                                  $days   The first and last day, Y-m-d.
	 *
	 * @return array<string, mixed>
	 */
	private static function wholeDaysEntry(array $source, array $days): array {
		return [
			'kind' => $source['kind'],
			'subjectType' => 'employee',
			'subjectId' => $source['subjectId'],
			'start' => ($days[0] . 'T00:00:00'),
			'end' => ($days[1] . 'T23:59:59'),
			'label' => $source['label'],
			'sourceType' => $source['sourceType'],
			'sourceId' => $source['sourceId'],
		];
	}//end wholeDaysEntry()

	/**
	 * Approved leave as agenda entries.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param AgendaWindow $window The subject's employees and the first and last day.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function leaveEntries(array $sources, AgendaWindow $window): array {
		$entries = [];
		foreach (($sources['leaveRequests'] ?? []) as $request) {
			$employeeId = self::employeeIn(row: $request, window: $window);
			if ($employeeId === null || trim((string)($request['status'] ?? '')) !== 'approved') {
				continue;
			}

			$start = substr(trim((string)($request['startDate'] ?? '')), 0, 10);
			$end = substr(trim((string)($request['endDate'] ?? '')), 0, 10);
			if ($start !== '' && $end !== '' && $start <= $window->to && $end >= $window->from) {
				$entries[] = self::wholeDaysEntry(source: ['kind' => 'leave', 'subjectId' => $employeeId, 'label' => 'Verlof', 'sourceType' => 'LeaveRequest', 'sourceId' => trim((string)($request['id'] ?? ''))], days: [$start, $end]);
			}
		}

		return $entries;
	}//end leaveEntries()

	/**
	 * Open sick leave as agenda entries.
	 *
	 * The entry says absent and the person's name. It carries no reason, no
	 * diagnosis and no progression, because none of those is copied here.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param AgendaWindow $window The subject's employees and the first and last day.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function absenceEntries(array $sources, AgendaWindow $window): array {
		$entries = [];
		foreach (($sources['sickLeaveCases'] ?? []) as $case) {
			$employeeId = self::employeeIn(row: $case, window: $window);
			$days = self::absenceDays(case: $case, window: $window);
			if ($employeeId !== null && $days !== null) {
				$entries[] = self::wholeDaysEntry(source: ['kind' => 'absent', 'subjectId' => $employeeId, 'label' => 'Afwezig', 'sourceType' => 'SickLeaveCase', 'sourceId' => trim((string)($case['id'] ?? ''))], days: $days);
			}
		}

		return $entries;
	}//end absenceEntries()

	/**
	 * The days a sick leave case covers inside the window: from the first sick
	 * day to the recovery of a closed case, else to the window's end; null
	 * when it does not touch the window.
	 *
	 * @param array<string, mixed> $case   The SickLeaveCase.
	 * @param AgendaWindow         $window The first and last day.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function absenceDays(array $case, AgendaWindow $window): ?array {
		$start = substr(trim((string)($case['firstSickDay'] ?? '')), 0, 10);
		if ($start === '') {
			return null;
		}

		$recovered = substr(trim((string)($case['recoveredDate'] ?? '')), 0, 10);
		$closed = (trim((string)($case['status'] ?? '')) === 'hersteld');
		$end = (($closed === true && $recovered !== '') ? $recovered : $window->to);

		return ($start > $window->to || $end < $window->from) ? null : [$start, $end];
	}//end absenceDays()

	/**
	 * Interviews as agenda entries.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param AgendaWindow $window The subject's employees and the first and last day.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function interviewEntries(array $sources, AgendaWindow $window): array {
		$entries = [];
		foreach (($sources['interviews'] ?? []) as $interview) {
			$entry = $this->interviewEntry(interview: $interview, window: $window);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end interviewEntries()

	/**
	 * One interview inside the window, on the first of its participants the
	 * window stands for; null otherwise.
	 *
	 * @param array<string, mixed> $interview The interview row.
	 * @param AgendaWindow         $window    The subject's employees and the first and last day.
	 *
	 * @return array<string, mixed>|null
	 */
	private function interviewEntry(array $interview, AgendaWindow $window): ?array {
		$start = $this->stringOrNull(($interview['scheduledStart'] ?? ($interview['start'] ?? null)));
		$end = $this->stringOrNull(($interview['scheduledEnd'] ?? ($interview['end'] ?? null)));
		if ($start === null || substr($start, 0, 10) < $window->from || substr($start, 0, 10) > $window->to) {
			return null;
		}

		$held = array_values(array_intersect($this->interviewParticipants($interview), $window->employees));
		if ($held === []) {
			return null;
		}

		return [
			'kind' => 'interview',
			'subjectType' => 'employee',
			'subjectId' => $held[0],
			'start' => $start,
			'end' => ($end ?? $start),
			'label' => 'Gesprek',
			'sourceType' => 'Interview',
			'sourceId' => trim((string)($interview['id'] ?? '')),
		];
	}//end interviewEntry()

	/**
	 * The employees an interview holds.
	 *
	 * @param array<string, mixed> $interview The interview row.
	 *
	 * @return array<int, string> The employee ids.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function interviewParticipants(array $interview): array {
		$candidates = [];
		foreach (['employeeId', 'interviewerId', 'participantId'] as $key) {
			$value = $this->stringOrNull(($interview[$key] ?? null));
			if ($value !== null) {
				$candidates[] = $value;
			}
		}

		$list = ($interview['interviewerIds'] ?? []);
		if (is_array($list) === true) {
			foreach ($list as $value) {
				$value = $this->stringOrNull($value);
				if ($value !== null) {
					$candidates[] = $value;
				}
			}
		}

		return array_values(array_unique($candidates));
	}//end interviewParticipants()

	/**
	 * Narrow a raw value to a non-empty string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null Null when absent, not a string, or blank.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringOrNull()
}//end class
