<?php

/**
 * Agenda Composer
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
	public const KINDS = ['shift', 'leave', 'absent', 'interview', 'booking', 'busy'];

	/**
	 * Constructor.
	 *
	 * @param AgendaBookingEntries $bookings Builds the booking and busy-time entries.
	 */
	public function __construct(
		private readonly AgendaBookingEntries $bookings = new AgendaBookingEntries(),
	) {

	}//end __construct()


	/**
	 * Compose the agenda for one subject over a period.
	 *
	 * @param array<string, mixed> $sources Rows per source: assignments, shifts, leaveRequests, sickLeaveCases, interviews, bookings, subscriptions.
	 * @param string $subjectType `employee`, `orgUnit` or `resource`.
	 * @param string $subjectId The subject's id.
	 * @param string $from First day, inclusive (ISO date).
	 * @param string $to Last day, inclusive (ISO date).
	 * @param array<int, string> $memberEmployeeIds The employees in the org unit, when the subject is one.
	 *
	 * @return array<int, array<string, mixed>> The entries, in start order.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function compose(
		array $sources,
		string $subjectType,
		string $subjectId,
		string $from,
		string $to,
		array $memberEmployeeIds = [],
	): array {
		$employees = $this->subjectEmployees(
			subjectType: $subjectType,
			subjectId: $subjectId,
			memberEmployeeIds: $memberEmployeeIds
		);

		$entries = array_merge(
			$this->shiftEntries(sources: $sources, employees: $employees, from: $from, to: $to),
			$this->leaveEntries(sources: $sources, employees: $employees, from: $from, to: $to),
			$this->absenceEntries(sources: $sources, employees: $employees, from: $from, to: $to),
			$this->interviewEntries(sources: $sources, employees: $employees, from: $from, to: $to),
			$this->bookings->bookingEntries(
				sources: $sources,
				employees: $employees,
				subjectType: $subjectType,
				subjectId: $subjectId,
				from: $from,
				to: $to
			),
			$this->bookings->busyEntries(sources: $sources, employees: $employees, from: $from, to: $to)
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
	 * The employees an agenda subject stands for.
	 *
	 * @param string $subjectType `employee`, `orgUnit` or `resource`.
	 * @param string $subjectId The subject's id.
	 * @param array<int, string> $memberEmployeeIds The org unit's members.
	 *
	 * @return array<int, string> The employee ids, empty for a resource.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function subjectEmployees(string $subjectType, string $subjectId, array $memberEmployeeIds): array {
		if ($subjectType === 'employee') {
			return [$subjectId];
		}

		if ($subjectType === 'orgUnit') {
			return array_values(array_unique(array_map('strval', $memberEmployeeIds)));
		}

		return [];
	}//end subjectEmployees()

	/**
	 * Rostered shifts as agenda entries.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param array<int, string> $employees The subject's employees.
	 * @param string $from First day (ISO date).
	 * @param string $to Last day (ISO date).
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function shiftEntries(array $sources, array $employees, string $from, string $to): array {
		$shiftsById = [];
		foreach (($sources['shifts'] ?? []) as $shift) {
			$shiftsById[trim((string)($shift['id'] ?? ''))] = $shift;
		}

		$entries = [];
		foreach (($sources['assignments'] ?? []) as $assignment) {
			$employeeId = trim((string)($assignment['employeeId'] ?? ''));
			$date = substr(trim((string)($assignment['date'] ?? '')), 0, 10);
			if (in_array($employeeId, $employees, true) === false || $date === '') {
				continue;
			}

			if ($date < $from || $date > $to) {
				continue;
			}

			$shift = ($shiftsById[trim((string)($assignment['shiftId'] ?? ''))] ?? []);
			$entries[] = [
				'kind' => 'shift',
				'subjectType' => 'employee',
				'subjectId' => $employeeId,
				'start' => ($this->stringOrNull(($assignment['plannedStart'] ?? null)) ?? ($date . 'T00:00:00')),
				'end' => ($this->stringOrNull(($assignment['plannedEnd'] ?? null)) ?? ($date . 'T23:59:59')),
				'label' => trim((string)($shift['name'] ?? 'Dienst')),
				'sourceType' => 'RosterAssignment',
				'sourceId' => trim((string)($assignment['id'] ?? '')),
			];
		}

		return $entries;
	}//end shiftEntries()

	/**
	 * Approved leave as agenda entries.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param array<int, string> $employees The subject's employees.
	 * @param string $from First day (ISO date).
	 * @param string $to Last day (ISO date).
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function leaveEntries(array $sources, array $employees, string $from, string $to): array {
		$entries = [];
		foreach (($sources['leaveRequests'] ?? []) as $request) {
			$employeeId = trim((string)($request['employeeId'] ?? ''));
			if (in_array($employeeId, $employees, true) === false) {
				continue;
			}

			if (trim((string)($request['status'] ?? '')) !== 'approved') {
				continue;
			}

			$start = substr(trim((string)($request['startDate'] ?? '')), 0, 10);
			$end = substr(trim((string)($request['endDate'] ?? '')), 0, 10);
			if ($start === '' || $end === '' || $start > $to || $end < $from) {
				continue;
			}

			$entries[] = [
				'kind' => 'leave',
				'subjectType' => 'employee',
				'subjectId' => $employeeId,
				'start' => ($start . 'T00:00:00'),
				'end' => ($end . 'T23:59:59'),
				'label' => 'Verlof',
				'sourceType' => 'LeaveRequest',
				'sourceId' => trim((string)($request['id'] ?? '')),
			];
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
	 * @param array<int, string> $employees The subject's employees.
	 * @param string $from First day (ISO date).
	 * @param string $to Last day (ISO date).
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function absenceEntries(array $sources, array $employees, string $from, string $to): array {
		$entries = [];
		foreach (($sources['sickLeaveCases'] ?? []) as $case) {
			$employeeId = trim((string)($case['employeeId'] ?? ''));
			if (in_array($employeeId, $employees, true) === false) {
				continue;
			}

			$start = substr(trim((string)($case['firstSickDay'] ?? '')), 0, 10);
			if ($start === '') {
				continue;
			}

			$recovered = substr(trim((string)($case['recoveredDate'] ?? '')), 0, 10);
			$closed = (trim((string)($case['status'] ?? '')) === 'hersteld');
			$end = (($closed === true && $recovered !== '') ? $recovered : $to);
			if ($start > $to || $end < $from) {
				continue;
			}

			$entries[] = [
				'kind' => 'absent',
				'subjectType' => 'employee',
				'subjectId' => $employeeId,
				'start' => ($start . 'T00:00:00'),
				'end' => ($end . 'T23:59:59'),
				'label' => 'Afwezig',
				'sourceType' => 'SickLeaveCase',
				'sourceId' => trim((string)($case['id'] ?? '')),
			];
		}

		return $entries;
	}//end absenceEntries()

	/**
	 * Interviews as agenda entries.
	 *
	 * @param array<string, mixed> $sources The source rows.
	 * @param array<int, string> $employees The subject's employees.
	 * @param string $from First day (ISO date).
	 * @param string $to Last day (ISO date).
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	private function interviewEntries(array $sources, array $employees, string $from, string $to): array {
		$entries = [];
		foreach (($sources['interviews'] ?? []) as $interview) {
			$start = $this->stringOrNull(($interview['scheduledStart'] ?? ($interview['start'] ?? null)));
			$end = $this->stringOrNull(($interview['scheduledEnd'] ?? ($interview['end'] ?? null)));
			if ($start === null) {
				continue;
			}

			$day = substr($start, 0, 10);
			if ($day < $from || $day > $to) {
				continue;
			}

			$participants = $this->interviewParticipants($interview);
			$held = array_values(array_intersect($participants, $employees));
			if ($held === []) {
				continue;
			}

			$entries[] = [
				'kind' => 'interview',
				'subjectType' => 'employee',
				'subjectId' => $held[0],
				'start' => $start,
				'end' => ($end ?? $start),
				'label' => 'Gesprek',
				'sourceType' => 'Interview',
				'sourceId' => trim((string)($interview['id'] ?? '')),
			];
		}

		return $entries;
	}//end interviewEntries()

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
