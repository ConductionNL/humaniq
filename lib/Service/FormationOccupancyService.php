<?php

/**
 * Formation Occupancy Service
 *
 * people-formation-positions: budgeted, filled, vacant and net FTE per
 * formation place and per unit, computed from the records on read and never
 * stored (design.md D3). Pure: the caller loads the records, this class only
 * counts, so every figure is testable without OpenRegister.
 *
 * Filled FTE is the average, over the working days (Monday to Friday) of the
 * window, of the FTE of the contracts naming the place and active on the day.
 * Net FTE subtracts, per occupant and day, their FTE times the fraction they
 * are away: 1 on a day covered by an approved leave request whose type is in
 * the net-FTE list, or the absence percentage of a sickness case that has run
 * longer than the long-term threshold on that day.
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
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Computes formation occupancy from records handed in.
 */
class FormationOccupancyService {

	/**
	 * @param AbsenceProgression $progression Date parsing and sickness progression steps.
	 */
	public function __construct(
		private readonly AbsenceProgression $progression,
	) {

	}//end __construct()

	/**
	 * Occupancy per place and for the unit, averaged over the working days
	 * from `$from` to `$to` inclusive.
	 *
	 * @param list<array<string, mixed>> $places             The unit's Formatieplaats records.
	 * @param list<array<string, mixed>> $contracts          EmploymentContract records.
	 * @param list<array<string, mixed>> $leaveRequests      LeaveRequest records.
	 * @param list<array<string, mixed>> $sickCases          SickLeaveCase records.
	 * @param DateTimeImmutable          $from               First day.
	 * @param DateTimeImmutable          $to                 Last day.
	 * @param array<int, string>         $netLeaveTypes      Leave type codes that count as away.
	 * @param int                        $longTermSickWeeks  Weeks after which sickness counts.
	 * @param float                      $fullTimeHoursWeek  Hours of one FTE.
	 *
	 * @return array{places: list<array<string, mixed>>, total: array<string, mixed>}
	 *
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Every record set is an input of the pure count.
	 */
	public function occupancy(
		array $places,
		array $contracts,
		array $leaveRequests,
		array $sickCases,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		array $netLeaveTypes,
		int $longTermSickWeeks,
		float $fullTimeHoursWeek,
	): array {
		$days = $this->workingDays(from: $from, to: $to);
		$away = new FormationAbsence(
			progression: $this->progression,
			leaveRequests: $leaveRequests,
			sickCases: $sickCases,
			netLeaveTypes: $netLeaveTypes,
			longTermSickWeeks: $longTermSickWeeks
		);

		$rows = [];
		$total = ['budgetedFte' => 0.0, 'filledFte' => 0.0, 'vacantFte' => 0.0, 'netFte' => 0.0];
		foreach ($places as $place) {
			$row = $this->placeRow(
				place: $place,
				contracts: $contracts,
				days: $days,
				away: $away,
				fullTimeHoursWeek: $fullTimeHoursWeek
			);
			$rows[] = $row;
			foreach (array_keys($total) as $key) {
				$total[$key] += $row[$key];
			}
		}

		foreach (array_keys($total) as $key) {
			$total[$key] = round($total[$key], 2);
		}

		$total['overfilled'] = ($total['filledFte'] > $total['budgetedFte']);

		return ['places' => $rows, 'total' => $total];
	}//end occupancy()

	/**
	 * One place's figures.
	 *
	 * @param array<string, mixed>       $place             The Formatieplaats.
	 * @param list<array<string, mixed>> $contracts         EmploymentContract records.
	 * @param list<DateTimeImmutable>    $days              The working days of the window.
	 * @param FormationAbsence           $away              Who is away on which day.
	 * @param float                      $fullTimeHoursWeek Hours of one FTE.
	 *
	 * @return array<string, mixed>
	 */
	private function placeRow(
		array $place,
		array $contracts,
		array $days,
		FormationAbsence $away,
		float $fullTimeHoursWeek,
	): array {
		$placeId = (string)($place['id'] ?? ($place['uuid'] ?? ''));
		$budgeted = max(0.0, (float)($place['budgetedFte'] ?? 0));
		$occupants = [];
		foreach ($contracts as $contract) {
			if ($placeId !== '' && (string)($contract['formatieplaatsId'] ?? '') === $placeId) {
				$occupants[] = $contract;
			}
		}

		$filled = 0.0;
		$net = 0.0;
		foreach ($days as $day) {
			foreach ($occupants as $contract) {
				if ($this->activeOn(contract: $contract, day: $day) === false) {
					continue;
				}

				$fte = $this->fte(contract: $contract, fullTimeHoursWeek: $fullTimeHoursWeek);
				$filled += $fte;
				$net += ($fte * (1.0 - $away->fraction(employeeId: (string)($contract['employeeId'] ?? ''), day: $day)));
			}
		}

		$count = max(1, count($days));
		$filled = round(($filled / $count), 2);
		$net = round(($net / $count), 2);

		return [
			'id' => $placeId,
			'title' => (string)($place['title'] ?? ''),
			'budgetedFte' => round($budgeted, 2),
			'filledFte' => $filled,
			'vacantFte' => round(max(0.0, ($budgeted - $filled)), 2),
			'overfilled' => ($filled > $budgeted),
			'netFte' => $net,
		];
	}//end placeRow()

	/**
	 * Whether a contract runs on a day.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param DateTimeImmutable    $day      The day.
	 *
	 * @return bool
	 */
	private function activeOn(array $contract, DateTimeImmutable $day): bool {
		$start = $this->progression->date(value: ($contract['startDate'] ?? null));
		$end = $this->progression->date(value: ($contract['endDate'] ?? null));

		return ($start === null || $start <= $day) && ($end === null || $end >= $day);
	}//end activeOn()

	/**
	 * A contract's FTE from its weekly hours.
	 *
	 * @param array<string, mixed> $contract          The contract.
	 * @param float                $fullTimeHoursWeek Hours of one FTE.
	 *
	 * @return float
	 */
	private function fte(array $contract, float $fullTimeHoursWeek): float {
		$hours = ($contract['hoursPerWeek'] ?? null);
		if (is_numeric($hours) === false || $fullTimeHoursWeek <= 0.0) {
			return 0.0;
		}

		return max(0.0, ((float)$hours / $fullTimeHoursWeek));
	}//end fte()

	/**
	 * The Monday to Friday days of a window; the window's first day when it
	 * holds none, so a single weekend date still answers.
	 *
	 * @param DateTimeImmutable $from First day.
	 * @param DateTimeImmutable $to   Last day.
	 *
	 * @return list<DateTimeImmutable>
	 */
	private function workingDays(DateTimeImmutable $from, DateTimeImmutable $to): array {
		$days = [];
		for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
			if ((int)$day->format('N') <= 5) {
				$days[] = $day;
			}
		}

		if ($days === []) {
			$days[] = $from->setTime(0, 0);
		}

		return $days;
	}//end workingDays()

}//end class
