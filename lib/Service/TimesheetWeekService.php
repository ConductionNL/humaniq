<?php

/**
 * Humaniq TimesheetWeekService
 *
 * The week view of a timesheet: the booked entries plus read-only lines for
 * the employee's approved leave and sickness in the timesheet's period, in
 * hours taken from the working pattern, and the totals per kind beside the
 * contract hours. Absence is composed from its own records, never booked a
 * second time as a time entry, so the two can never drift apart (design D2).
 *
 * How leave and sickness spread over days is {@see AbsenceDays}. A day never
 * carries more absence than it has contracted hours: sickness on a day of
 * leave takes only what the leave left.
 *
 * The working calendar (public holidays) is not read here, so the contract
 * hours are those of the working pattern and the answer says `patternOnly`.
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
 * Composes a timesheet's week view and its totals per kind.
 *
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */
class TimesheetWeekService {

	/**
	 * The activity code absence lines for leave carry.
	 *
	 * @var string
	 */
	public const ACTIVITY_LEAVE = 'verlof';

	/**
	 * The activity code absence lines for sickness carry.
	 *
	 * @var string
	 */
	public const ACTIVITY_SICKNESS = 'ziekte';

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $store       The register reads.
	 * @param TimeActivityCatalog  $activities  The activity kinds.
	 * @param AbsenceDays          $absenceDays Contracted, leave and sick hours per day.
	 */
	public function __construct(
		private readonly RecordStoreInterface $store,
		private readonly TimeActivityCatalog $activities,
		private readonly AbsenceDays $absenceDays,
	) {

	}//end __construct()

	/**
	 * The week view of one timesheet, or null when the timesheet is gone.
	 *
	 * @param string $timesheetId The timesheet uuid.
	 *
	 * @return array<string, mixed>|null The view, as {@see compose()} returns it.
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function weekFor(string $timesheetId): ?array {
		$timesheetId = trim($timesheetId);
		if ($timesheetId === '') {
			return null;
		}

		$timesheet = $this->store->findObjectData($timesheetId, 'Timesheet');
		if ($timesheet === null) {
			return null;
		}

		return $this->compose(
			timesheet: $timesheet,
			entries: $this->store->findFiltered('TimeEntry', ['timesheetId' => $timesheetId]),
			absence: $this->absenceRecordsOf(employeeId: trim((string)($timesheet['employeeId'] ?? '')))
		);
	}//end weekFor()

	/**
	 * The absence hours of one timesheet over the given entries.
	 *
	 * @param array<string, mixed>             $timesheet The Timesheet payload.
	 * @param array<int, array<string, mixed>> $entries   Its TimeEntry payloads.
	 *
	 * @return float The absence hours.
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function absenceHoursFor(array $timesheet, array $entries): float {
		$view = $this->compose(
			timesheet: $timesheet,
			entries: $entries,
			absence: $this->absenceRecordsOf(employeeId: trim((string)($timesheet['employeeId'] ?? '')))
		);

		return (float)$view['totals']['absenceHours'];
	}//end absenceHoursFor()

	/**
	 * Compose the view from already-read records.
	 *
	 * @param array<string, mixed>             $timesheet The Timesheet payload.
	 * @param array<int, array<string, mixed>> $entries   Its TimeEntry payloads.
	 * @param array<string, mixed>             $absence   The employee's records: `leave` (LeaveRequest), `sickness` (SickLeaveCase), `sources` (WorkingTimeSources) and `kinds` (kind by activity code).
	 *
	 * @return array{period: string, from: string|null, to: string|null, patternOnly: bool, lines: list<array<string, mixed>>, weeks: list<array<string, mixed>>, totals: array<string, float>}
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public function compose(array $timesheet, array $entries, array $absence): array {
		$period = trim((string)($timesheet['period'] ?? ''));
		$range = self::periodRange(period: $period);
		$entryKind = new EntryKind(kinds: ($absence['kinds'] ?? []));

		$lines = [];
		foreach ($entries as $entry) {
			$lines[] = $this->entryLine(entry: $entry, entryKind: $entryKind);
		}

		$contract = [];
		if ($range !== null) {
			$time = new EmployeeWorkingTime(
				employeeId: trim((string)($timesheet['employeeId'] ?? '')),
				sources: ($absence['sources'] ?? new WorkingTimeSources())
			);
			$contract = $this->absenceDays->contracted(time: $time, range: $range);
			$lines = array_merge($lines, $this->absenceLines(time: $time, range: $range, contract: $contract, absence: $absence));
		}

		usort($lines, static fn (array $a, array $b): int => [$a['date'], $a['source']] <=> [$b['date'], $b['source']]);

		return [
			'period' => $period,
			'from' => ($range === null ? null : $range[0]->format('Y-m-d')),
			'to' => ($range === null ? null : $range[1]->format('Y-m-d')),
			'patternOnly' => true,
			'lines' => $lines,
			'weeks' => $this->weeksOf(lines: $lines, contract: $contract),
			'totals' => $this->totalsOf(lines: $lines, contract: $contract),
		];
	}//end compose()

	/**
	 * The first and last day a period covers, or null when it is not a period.
	 *
	 * `YYYY-MM` is a month, `YYYY-Www` an ISO week and `YYYY-Www-D` one day of
	 * an ISO week (the grains the Timesheet period accepts).
	 *
	 * @param string $period The period.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
	 *
	 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
	 */
	public static function periodRange(string $period): ?array {
		$parts = [];
		if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $parts) === 1) {
			$first = (new DateTimeImmutable())->setDate((int)$parts[1], (int)$parts[2], 1)->setTime(0, 0);

			return [$first, $first->modify('last day of this month')];
		}

		if (preg_match('/^(\d{4})-W(\d{2})(?:-([1-7]))?$/', $period, $parts) === 1) {
			$week = (int)$parts[2];
			if ($week < 1 || $week > 53) {
				return null;
			}

			$monday = (new DateTimeImmutable())->setISODate((int)$parts[1], $week, 1)->setTime(0, 0);
			if (isset($parts[3]) === true) {
				$day = $monday->add(new DateInterval('P' . ((int)$parts[3] - 1) . 'D'));

				return [$day, $day];
			}

			return [$monday, $monday->add(new DateInterval('P6D'))];
		}

		return null;
	}//end periodRange()

	/**
	 * The employee's leave, sickness and working-time records, and the
	 * activity kinds.
	 *
	 * @param string $employeeId The employee uuid.
	 *
	 * @return array<string, mixed> `leave`, `sickness`, `sources` and `kinds`.
	 */
	private function absenceRecordsOf(string $employeeId): array {
		if ($employeeId === '') {
			return ['leave' => [], 'sickness' => [], 'sources' => new WorkingTimeSources(), 'kinds' => $this->activities->kindsByCode()];
		}

		return [
			'leave' => $this->store->findFiltered('LeaveRequest', ['employeeId' => $employeeId, 'status' => 'approved']),
			'sickness' => $this->store->findFiltered('SickLeaveCase', ['employeeId' => $employeeId]),
			'sources' => new WorkingTimeSources(
				workingPatterns: $this->store->findFiltered('WorkingPattern', ['employeeId' => $employeeId]),
				nonWorkingTimes: $this->store->findFiltered('NonWorkingTime', ['employeeId' => $employeeId]),
				nonWorkingDates: null
			),
			'kinds' => $this->activities->kindsByCode(),
		];
	}//end absenceRecordsOf()

	/**
	 * One booked entry as a line.
	 *
	 * @param array<string, mixed> $entry     The TimeEntry payload.
	 * @param EntryKind            $entryKind Classifies the entry.
	 *
	 * @return array<string, mixed>
	 */
	private function entryLine(array $entry, EntryKind $entryKind): array {
		$date = trim((string)($entry['date'] ?? ''));
		if ($date === '') {
			$started = strtotime((string)($entry['startedAt'] ?? ''));
			$date = ($started === false ? '' : gmdate('Y-m-d', $started));
		}

		return [
			'date' => $date,
			'kind' => $entryKind->kindOf(entry: $entry),
			'activityCode' => trim((string)($entry['activityCode'] ?? '')),
			'projectId' => trim((string)($entry['projectId'] ?? '')),
			'description' => (string)($entry['description'] ?? ''),
			'hours' => round((float)($entry['hours'] ?? 0), 2),
			'source' => 'entry',
			'readOnly' => false,
		];
	}//end entryLine()

	/**
	 * The read-only leave and sickness lines inside the range.
	 *
	 * @param EmployeeWorkingTime                               $time     The employee and the working-time records.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range    First and last day, inclusive.
	 * @param array<string, float>                              $contract Contracted hours by ISO date over the range.
	 * @param array<string, mixed>                              $absence  The employee's records.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function absenceLines(EmployeeWorkingTime $time, array $range, array $contract, array $absence): array {
		$taken = [];
		$lines = [];
		foreach (($absence['leave'] ?? []) as $request) {
			foreach ($this->absenceDays->leave(time: $time, request: $request, range: $range) as $date => $hours) {
				$hours = min($hours, (($contract[$date] ?? 0.0) - ($taken[$date] ?? 0.0)));
				if ($hours <= 0.0) {
					continue;
				}

				$taken[$date] = (($taken[$date] ?? 0.0) + $hours);
				$lines[] = $this->absenceLine(date: $date, hours: $hours, activityCode: self::ACTIVITY_LEAVE, source: 'leave');
			}
		}

		foreach (($absence['sickness'] ?? []) as $case) {
			foreach ($this->absenceDays->sickness(sickCase: $case, range: $range, contract: $contract) as $date => $hours) {
				$hours = min($hours, (($contract[$date] ?? 0.0) - ($taken[$date] ?? 0.0)));
				if ($hours <= 0.0) {
					continue;
				}

				$taken[$date] = (($taken[$date] ?? 0.0) + $hours);
				$lines[] = $this->absenceLine(date: $date, hours: $hours, activityCode: self::ACTIVITY_SICKNESS, source: 'sickness');
			}
		}

		return $lines;
	}//end absenceLines()

	/**
	 * One read-only absence line.
	 *
	 * @param string $date         The ISO date.
	 * @param float  $hours        The absence hours.
	 * @param string $activityCode The absence activity.
	 * @param string $source       `leave` or `sickness`.
	 *
	 * @return array<string, mixed>
	 */
	private function absenceLine(string $date, float $hours, string $activityCode, string $source): array {
		return [
			'date' => $date,
			'kind' => TimeActivityCatalog::KIND_ABSENCE,
			'activityCode' => $activityCode,
			'projectId' => '',
			'description' => '',
			'hours' => round($hours, 2),
			'source' => $source,
			'readOnly' => true,
		];
	}//end absenceLine()

	/**
	 * Hours per kind and contract hours over all lines.
	 *
	 * @param list<array<string, mixed>> $lines    The lines.
	 * @param array<string, float>       $contract Contracted hours by ISO date.
	 *
	 * @return array{projectHours: float, nonProjectHours: float, absenceHours: float, bookedHours: float, contractHours: float}
	 */
	private function totalsOf(array $lines, array $contract): array {
		$byKind = [TimeActivityCatalog::KIND_PROJECT => 0.0, TimeActivityCatalog::KIND_NON_PROJECT => 0.0, TimeActivityCatalog::KIND_ABSENCE => 0.0];
		foreach ($lines as $line) {
			$byKind[$line['kind']] = (($byKind[$line['kind']] ?? 0.0) + (float)$line['hours']);
		}

		return [
			'projectHours' => round($byKind[TimeActivityCatalog::KIND_PROJECT], 2),
			'nonProjectHours' => round($byKind[TimeActivityCatalog::KIND_NON_PROJECT], 2),
			'absenceHours' => round($byKind[TimeActivityCatalog::KIND_ABSENCE], 2),
			'bookedHours' => round(array_sum($byKind), 2),
			'contractHours' => round(array_sum($contract), 2),
		];
	}//end totalsOf()

	/**
	 * The totals per ISO week.
	 *
	 * @param list<array<string, mixed>> $lines    The lines.
	 * @param array<string, float>       $contract Contracted hours by ISO date.
	 *
	 * @return list<array<string, mixed>> One row per week, in date order.
	 */
	private function weeksOf(array $lines, array $contract): array {
		$linesByWeek = [];
		$contractByWeek = [];
		foreach ($lines as $line) {
			$week = $this->isoWeekOf(date: (string)$line['date']);
			if ($week !== null) {
				$linesByWeek[$week][] = $line;
			}
		}

		foreach ($contract as $date => $hours) {
			$week = $this->isoWeekOf(date: (string)$date);
			if ($week !== null) {
				$contractByWeek[$week][$date] = $hours;
			}
		}

		$weeks = array_unique(array_merge(array_keys($linesByWeek), array_keys($contractByWeek)));
		sort($weeks);

		$rows = [];
		foreach ($weeks as $week) {
			$rows[] = ['week' => $week] + $this->totalsOf(lines: ($linesByWeek[$week] ?? []), contract: ($contractByWeek[$week] ?? []));
		}

		return $rows;
	}//end weeksOf()

	/**
	 * The ISO week of a date, as `YYYY-Www`, or null for a date that is not one.
	 *
	 * @param string $date The ISO date.
	 *
	 * @return string|null
	 */
	private function isoWeekOf(string $date): ?string {
		$parts = [];
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			return null;
		}

		return (new DateTimeImmutable($date))->format('o-\WW');
	}//end isoWeekOf()

}//end class
