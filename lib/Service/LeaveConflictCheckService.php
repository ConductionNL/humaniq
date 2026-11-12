<?php

/**
 * Leave Conflict Check Service
 *
 * The leave cross-check of a roster (REQ-ROST-C05, row pln-leave-in-roster):
 * a person rostered on a day they are away. Approved leave is a `mandatory`
 * finding, which `RosterCompetenceGuard` refuses to publish; open sick leave is
 * an `advisory` finding, reported and not refused, because an open case has no
 * end date and would otherwise block every future roster the person is on.
 *
 * The AVG boundary of `leave-calendar-nc` holds: a finding says the person is
 * on leave or absent and nothing about why. No leave type, no reason, no
 * progression is copied, so no filter afterwards can forget one.
 *
 * Deliberately dependency-free (the CompetenceCheckService shape): the caller
 * supplies the already-fetched rows.
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Finds roster assignments that fall on a day of approved leave or sick leave.
 *
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
 */
class LeaveConflictCheckService {

	/**
	 * The finding kind, distinct from `working-time` and `competence`.
	 *
	 * @var string
	 */
	public const FINDING_KIND = 'leave';

	/**
	 * Findings for a set of assignments, at most one per assignment.
	 *
	 * @param array<array<string, mixed>> $assignments RosterAssignment rows.
	 * @param array<array<string, mixed>> $leaveRequests LeaveRequest rows, any employee and status.
	 * @param array<array<string, mixed>> $sickLeaveCases SickLeaveCase rows, any employee and status.
	 *
	 * @return array<int, array<string, mixed>> The findings, in assignment order.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	public function findings(array $assignments, array $leaveRequests, array $sickLeaveCases): array {
		$findings = [];
		foreach ($assignments as $assignment) {
			$day = RosteredDay::fromAssignment($assignment);
			if ($day === null) {
				continue;
			}

			// Approved leave first, so a refusal is never hidden behind an advisory.
			$source = ($this->leaveSource(day: $day, leaveRequests: $leaveRequests) ?? $this->sickSource(day: $day, sickLeaveCases: $sickLeaveCases));
			if ($source !== null) {
				$findings[] = $this->finding(day: $day, source: $source);
			}
		}

		return $findings;
	}//end findings()

	/**
	 * The approved leave covering the day, as a mandatory finding source.
	 *
	 * @param RosteredDay                 $day           The rostered day.
	 * @param array<array<string, mixed>> $leaveRequests LeaveRequest rows.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	private function leaveSource(RosteredDay $day, array $leaveRequests): ?array {
		$leaveId = $this->approvedLeaveOn(leaveRequests: $leaveRequests, day: $day);

		return ($leaveId === null ? null : ['absence' => 'leave', 'severity' => 'mandatory', 'type' => 'LeaveRequest', 'id' => $leaveId]);
	}//end leaveSource()

	/**
	 * The sick leave case covering the day, as an advisory finding source.
	 *
	 * @param RosteredDay                 $day            The rostered day.
	 * @param array<array<string, mixed>> $sickLeaveCases SickLeaveCase rows.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	private function sickSource(RosteredDay $day, array $sickLeaveCases): ?array {
		$caseId = $this->sickLeaveOn(sickLeaveCases: $sickLeaveCases, day: $day);

		return ($caseId === null ? null : ['absence' => 'absent', 'severity' => 'advisory', 'type' => 'SickLeaveCase', 'id' => $caseId]);
	}//end sickSource()

	/**
	 * One finding, carrying the kind of absence and none of its content.
	 *
	 * @param RosteredDay           $day    The rostered day.
	 * @param array<string, string> $source absence, severity, type and id of the absence.
	 *
	 * @return array<string, mixed> The finding.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	private function finding(RosteredDay $day, array $source): array {
		$employeeId = $day->employeeId;
		$date = $day->date;
		$statement = 'Medewerker ' . $employeeId . ' is op ' . $date . ' ingeroosterd maar is afwezig.';
		if ($source['absence'] === 'leave') {
			$statement = 'Medewerker ' . $employeeId . ' is op ' . $date . ' ingeroosterd maar heeft goedgekeurd verlof.';
		}

		return [
			'kind' => self::FINDING_KIND,
			'objectType' => 'RosterAssignment',
			'objectId' => $day->assignmentId,
			'employeeId' => $employeeId,
			'date' => $date,
			'absence' => $source['absence'],
			'sourceType' => $source['type'],
			'sourceId' => $source['id'],
			'severity' => $source['severity'],
			'statement' => $statement,
		];
	}//end finding()

	/**
	 * The id of the employee's approved leave covering a date, if any.
	 *
	 * @param array<array<string, mixed>> $leaveRequests LeaveRequest rows.
	 * @param RosteredDay $day The rostered day.
	 *
	 * @return string|null The leave request id, or null.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	private function approvedLeaveOn(array $leaveRequests, RosteredDay $day): ?string {
		foreach ($leaveRequests as $request) {
			if (trim((string)($request['employeeId'] ?? '')) === $day->employeeId
				&& trim((string)($request['status'] ?? '')) === 'approved'
				&& self::requestCovers(request: $request, date: $day->date) === true
			) {
				return trim((string)($request['id'] ?? ($request['@self']['id'] ?? '')));
			}
		}

		return null;
	}//end approvedLeaveOn()

	/**
	 * Whether a leave request's start and end dates include a date.
	 *
	 * @param array<string, mixed> $request The LeaveRequest.
	 * @param string               $date    The ISO date.
	 *
	 * @return bool
	 */
	private static function requestCovers(array $request, string $date): bool {
		$start = substr(trim((string)($request['startDate'] ?? '')), 0, 10);
		$end = substr(trim((string)($request['endDate'] ?? '')), 0, 10);

		return $start !== '' && $end !== '' && $start <= $date && $date <= $end;
	}//end requestCovers()

	/**
	 * The id of the employee's sick leave case covering a date, if any.
	 *
	 * An open case (`gemeld`) runs on; a recovered case (`hersteld`) ends on
	 * its recovery date, the rule the agenda composer uses.
	 *
	 * @param array<array<string, mixed>> $sickLeaveCases SickLeaveCase rows.
	 * @param RosteredDay $day The rostered day.
	 *
	 * @return string|null The case id, or null.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	private function sickLeaveOn(array $sickLeaveCases, RosteredDay $day): ?string {
		foreach ($sickLeaveCases as $case) {
			if (trim((string)($case['employeeId'] ?? '')) === $day->employeeId && self::caseCovers(case: $case, date: $day->date) === true) {
				return trim((string)($case['id'] ?? ($case['@self']['id'] ?? '')));
			}
		}

		return null;
	}//end sickLeaveOn()

	/**
	 * Whether a sick leave case runs on a date: started by then, and not
	 * recovered before it.
	 *
	 * @param array<string, mixed> $case The SickLeaveCase.
	 * @param string               $date The ISO date.
	 *
	 * @return bool
	 */
	private static function caseCovers(array $case, string $date): bool {
		$start = substr(trim((string)($case['firstSickDay'] ?? '')), 0, 10);
		if ($start === '' || $start > $date) {
			return false;
		}

		$recovered = substr(trim((string)($case['recoveredDate'] ?? '')), 0, 10);
		$closed = (trim((string)($case['status'] ?? '')) === 'hersteld');

		return ($closed === false || ($recovered !== '' && $recovered >= $date));
	}//end caseCovers()

}//end class
