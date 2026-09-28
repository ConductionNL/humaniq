<?php

/**
 * Employee History Service
 *
 * people-employment-history: one employee's dated events composed on read
 * from the records that carry them, never stored (design.md D1), and the
 * contracts active on a date with their summed hours and FTE (D3). Pure: the
 * caller loads and RBAC-filters the rows, this class only maps and orders
 * them, so every rule is testable without OpenRegister.
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
 * @spec openspec/specs/employee-history/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Maps an employee's rows onto one chronological history.
 */
class EmployeeHistoryService {

	/**
	 * The schemas a history reads, with the detail page each event links to.
	 *
	 * @var array<string, string>
	 */
	public const SOURCES = [
		'EmploymentContract' => 'EmploymentContractDetail',
		'OrgAssignment' => 'OrgAssignmentDetail',
		'CompAdjustment' => 'CompAdjustmentDetail',
		'LeaveRequest' => 'LeaveRequestDetail',
		'SickLeaveCase' => 'SickLeaveCaseDetail',
		'PerformanceReview' => 'PerformanceReviewDetail',
	];

	/**
	 * @param AbsenceProgression $progression Date parsing, midnight-normalised.
	 */
	public function __construct(
		private readonly AbsenceProgression $progression,
	) {

	}//end __construct()

	/**
	 * The events in the rows handed in, newest first. Only rows of
	 * `$employeeId` count; a rejected or unsubmitted leave request, a pay
	 * change not yet applied and a review not yet final are left out.
	 *
	 * @param string                                         $employeeId   The employee.
	 * @param array<string, list<array<string, mixed>>>       $rowsBySchema Rows per source schema.
	 *
	 * @return list<array{id: string, schema: string, kind: string, start: string, end: string|null, detail: string, route: string}>
	 *
	 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-001
	 */
	public function historyFor(string $employeeId, array $rowsBySchema): array {
		$events = [];
		foreach (self::SOURCES as $schema => $route) {
			foreach (($rowsBySchema[$schema] ?? []) as $row) {
				if ((string)($row['employeeId'] ?? '') !== $employeeId) {
					continue;
				}

				foreach ($this->eventsOf(schema: $schema, row: $row) as $event) {
					$event['schema'] = $schema;
					$event['route'] = $route;
					$event['id'] = (string)($row['id'] ?? ($row['uuid'] ?? ''));
					$events[] = $event;
				}
			}
		}

		usort($events, static fn (array $a, array $b): int => [$b['start'], $b['kind']] <=> [$a['start'], $a['kind']]);

		return $events;
	}//end historyFor()

	/**
	 * The contracts active on `$date`, with summed hours and FTE.
	 *
	 * @param string                     $employeeId        The employee.
	 * @param list<array<string, mixed>> $contracts         EmploymentContract rows.
	 * @param DateTimeImmutable          $date              The day.
	 * @param float                      $fullTimeHoursWeek Hours of one FTE.
	 *
	 * @return array{date: string, contracts: list<array<string, mixed>>, totalHoursPerWeek: float, totalFte: float, concurrent: bool}
	 *
	 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-003
	 */
	public function activeEmploymentsOn(string $employeeId, array $contracts, DateTimeImmutable $date, float $fullTimeHoursWeek): array {
		$day = $date->setTime(0, 0);
		$active = [];
		$hours = 0.0;
		foreach ($contracts as $contract) {
			if ((string)($contract['employeeId'] ?? '') !== $employeeId || $this->runsOn(row: $contract, day: $day) === false) {
				continue;
			}

			$perWeek = is_numeric($contract['hoursPerWeek'] ?? null) ? (float)$contract['hoursPerWeek'] : 0.0;
			$hours += $perWeek;
			$active[] = [
				'id' => (string)($contract['id'] ?? ($contract['uuid'] ?? '')),
				'type' => $contract['type'] ?? null,
				'cao' => $contract['cao'] ?? null,
				'caoSchaal' => $contract['caoSchaal'] ?? null,
				'hoursPerWeek' => $perWeek,
				'startDate' => $contract['startDate'] ?? null,
				'endDate' => $contract['endDate'] ?? null,
				'fte' => ($fullTimeHoursWeek > 0.0) ? round(($perWeek / $fullTimeHoursWeek), 4) : 0.0,
			];
		}

		return [
			'date' => $day->format('Y-m-d'),
			'contracts' => $active,
			'totalHoursPerWeek' => $hours,
			'totalFte' => ($fullTimeHoursWeek > 0.0) ? round(($hours / $fullTimeHoursWeek), 4) : 0.0,
			'concurrent' => count($active) > 1,
		];
	}//end activeEmploymentsOn()

	/**
	 * The events one row contributes.
	 *
	 * @param string               $schema The source schema.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return list<array{kind: string, start: string, end: string|null, detail: string}>
	 */
	private function eventsOf(string $schema, array $row): array {
		return match ($schema) {
			'EmploymentContract' => $this->span(row: $row, startKind: 'contract-start', endKind: 'contract-end', detail: (string)($row['type'] ?? '')),
			'OrgAssignment' => $this->span(row: $row, startKind: 'placement-start', endKind: 'placement-end', detail: (string)($row['role'] ?? '')),
			'CompAdjustment' => $this->payChange(row: $row),
			'LeaveRequest' => $this->period(row: $row, kind: 'leave', start: 'startDate', end: 'endDate', include: ($row['status'] ?? '') === 'approved', detail: (string)($row['leaveType'] ?? '')),
			'SickLeaveCase' => $this->period(row: $row, kind: 'sickness', start: 'firstSickDay', end: 'recoveredDate', include: true, detail: ''),
			'PerformanceReview' => $this->period(row: $row, kind: 'review', start: 'besprokenOp', end: '', include: ($row['status'] ?? '') === 'vastgesteld', detail: (string)($row['rating'] ?? '')),
			default => [],
		};
	}//end eventsOf()

	/**
	 * A start event, and an end event once the end date has a value.
	 *
	 * @param array<string, mixed> $row       The row.
	 * @param string               $startKind Kind of the start event.
	 * @param string               $endKind   Kind of the end event.
	 * @param string               $detail    Short detail.
	 *
	 * @return list<array{kind: string, start: string, end: string|null, detail: string}>
	 */
	private function span(array $row, string $startKind, string $endKind, string $detail): array {
		$events = [];
		$start = $this->day(value: ($row['startDate'] ?? null));
		if ($start !== null) {
			$events[] = ['kind' => $startKind, 'start' => $start, 'end' => null, 'detail' => $detail];
		}

		$end = $this->day(value: ($row['endDate'] ?? null));
		if ($end !== null) {
			$events[] = ['kind' => $endKind, 'start' => $end, 'end' => null, 'detail' => $detail];
		}

		return $events;
	}//end span()

	/**
	 * An applied pay change, dated on its effective date.
	 *
	 * @param array<string, mixed> $row The CompAdjustment.
	 *
	 * @return list<array{kind: string, start: string, end: string|null, detail: string}>
	 */
	private function payChange(array $row): array {
		if (trim((string)($row['appliedAt'] ?? '')) === '') {
			return [];
		}

		$start = ($this->day(value: ($row['effectiveDate'] ?? null)) ?? $this->day(value: $row['appliedAt']));
		if ($start === null) {
			return [];
		}

		$detail = '';
		if (is_numeric($row['currentSalary'] ?? null) === true && is_numeric($row['proposedSalary'] ?? null) === true) {
			$detail = $row['currentSalary'] . ' > ' . $row['proposedSalary'];
		}

		return [['kind' => 'pay-change', 'start' => $start, 'end' => null, 'detail' => $detail]];
	}//end payChange()

	/**
	 * One event over a period, when `$include` holds and it has a start.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param string               $kind    Event kind.
	 * @param string               $start   Start field.
	 * @param string               $end     End field, or ''.
	 * @param bool                 $include Whether the row counts.
	 * @param string               $detail  Short detail.
	 *
	 * @return list<array{kind: string, start: string, end: string|null, detail: string}>
	 */
	private function period(array $row, string $kind, string $start, string $end, bool $include, string $detail): array {
		$from = $this->day(value: ($row[$start] ?? null));
		if ($include === false || $from === null) {
			return [];
		}

		$until = ($end === '') ? null : $this->day(value: ($row[$end] ?? null));

		return [['kind' => $kind, 'start' => $from, 'end' => $until, 'detail' => $detail]];
	}//end period()

	/**
	 * Whether a row with startDate/endDate runs on a day.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param DateTimeImmutable    $day The day.
	 *
	 * @return bool
	 */
	private function runsOn(array $row, DateTimeImmutable $day): bool {
		$start = $this->progression->date(value: ($row['startDate'] ?? null));
		$end = $this->progression->date(value: ($row['endDate'] ?? null));

		return ($start === null || $start <= $day) && ($end === null || $end >= $day);
	}//end runsOn()

	/**
	 * A stored date as YYYY-MM-DD, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function day(mixed $value): ?string {
		return $this->progression->date(value: $value)?->format('Y-m-d');
	}//end day()

}//end class
