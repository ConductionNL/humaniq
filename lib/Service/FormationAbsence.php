<?php

/**
 * Formation Absence
 *
 * people-formation-positions REQ-FRM-003: the fraction of a day an occupant
 * is away for the net FTE. Away is an approved leave request of a type in
 * the net-FTE list covering the day (fraction 1), or a sickness case that has
 * run longer than the long-term threshold on that day (its absence
 * percentage on that day). The larger of the two counts, never their sum.
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
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Who is away, and how much, on a day.
 */
class FormationAbsence {

	/**
	 * Approved net-FTE leave per employee: list of [start, end].
	 *
	 * @var array<string, list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>>
	 */
	private array $leave = [];

	/**
	 * Sickness per employee: first day, last sick day (null while open) and steps.
	 *
	 * @var array<string, list<array{first: DateTimeImmutable, last: DateTimeImmutable|null, steps: list<array{from: DateTimeImmutable, percentage: float}>}>>
	 */
	private array $sick = [];

	/**
	 * @param AbsenceProgression         $progression       Date parsing and progression steps.
	 * @param list<array<string, mixed>> $leaveRequests     LeaveRequest records.
	 * @param list<array<string, mixed>> $sickCases         SickLeaveCase records.
	 * @param array<int, string>         $netLeaveTypes     Leave type codes that count as away.
	 * @param int                        $longTermSickWeeks Weeks after which sickness counts.
	 */
	public function __construct(
		private readonly AbsenceProgression $progression,
		array $leaveRequests,
		array $sickCases,
		array $netLeaveTypes,
		private readonly int $longTermSickWeeks,
	) {
		foreach ($leaveRequests as $request) {
			$this->addLeave(request: $request, netLeaveTypes: $netLeaveTypes);
		}

		foreach ($sickCases as $case) {
			$this->addSickness(case: $case);
		}

	}//end __construct()

	/**
	 * The fraction of `$day` the employee is away, 0 to 1.
	 *
	 * @param string            $employeeId The employee.
	 * @param DateTimeImmutable $day        The day.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
	 */
	public function fraction(string $employeeId, DateTimeImmutable $day): float {
		foreach (($this->leave[$employeeId] ?? []) as [$start, $end]) {
			if ($start <= $day && $end >= $day) {
				return 1.0;
			}
		}

		$fraction = 0.0;
		foreach (($this->sick[$employeeId] ?? []) as $case) {
			$fraction = max($fraction, $this->sickFraction(case: $case, day: $day));
		}

		return min(1.0, $fraction);
	}//end fraction()

	/**
	 * Record an approved leave request of a net-FTE type.
	 *
	 * @param array<string, mixed> $request       The LeaveRequest.
	 * @param array<int, string>   $netLeaveTypes Leave type codes that count.
	 *
	 * @return void
	 */
	private function addLeave(array $request, array $netLeaveTypes): void {
		if (($request['status'] ?? '') !== 'approved'
			|| in_array((string)($request['leaveType'] ?? ''), $netLeaveTypes, true) === false
		) {
			return;
		}

		$start = $this->progression->date(value: ($request['startDate'] ?? null));
		$end = ($this->progression->date(value: ($request['endDate'] ?? null)) ?? $start);
		$employeeId = (string)($request['employeeId'] ?? '');
		if ($start === null || $end === null || $employeeId === '') {
			return;
		}

		$this->leave[$employeeId][] = [$start, $end];
	}//end addLeave()

	/**
	 * Record a sickness case.
	 *
	 * @param array<string, mixed> $case The SickLeaveCase.
	 *
	 * @return void
	 */
	private function addSickness(array $case): void {
		$first = $this->progression->date(value: ($case['firstSickDay'] ?? null));
		$employeeId = (string)($case['employeeId'] ?? '');
		if ($first === null || $employeeId === '') {
			return;
		}

		$recovered = $this->progression->date(value: ($case['recoveredDate'] ?? null));
		$this->sick[$employeeId][] = [
			'first' => $first,
			'last' => ($recovered === null) ? null : $recovered->modify('-1 day'),
			'steps' => $this->progression->steps(case: $case, firstSickDay: $first),
		];
	}//end addSickness()

	/**
	 * The fraction away on a day for one case: zero before the case has run
	 * longer than the threshold, and after recovery.
	 *
	 * @param array{first: DateTimeImmutable, last: DateTimeImmutable|null, steps: list<array{from: DateTimeImmutable, percentage: float}>} $case The case.
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return float
	 */
	private function sickFraction(array $case, DateTimeImmutable $day): float {
		$longTermFrom = $case['first']->modify('+' . $this->longTermSickWeeks . ' weeks');
		if ($day < $longTermFrom || ($case['last'] !== null && $day > $case['last'])) {
			return 0.0;
		}

		$percentage = 0.0;
		foreach ($case['steps'] as $step) {
			if ($step['from'] <= $day) {
				$percentage = $step['percentage'];
			}
		}

		return max(0.0, ($percentage / 100.0));
	}//end sickFraction()

}//end class
