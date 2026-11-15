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
 *
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
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
	 * of the period.
	 *
	 * @param list<array<string, mixed>> $places    The unit's Formatieplaats records.
	 * @param list<array<string, mixed>> $contracts EmploymentContract records.
	 * @param FormationAbsenceSources    $absences  Leave and sickness records and the rules that make them count.
	 * @param AbsencePeriod              $period    First and last day, inclusive, and the hours of one FTE.
	 *
	 * @return array{places: list<array<string, mixed>>, total: array<string, mixed>}
	 *
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
	 */
	public function occupancy(
		array $places,
		array $contracts,
		FormationAbsenceSources $absences,
		AbsencePeriod $period,
	): array {
		$window = new FormationWindow(
			days: $this->workingDays(period: $period),
			away: new FormationAbsence(progression: $this->progression, sources: $absences),
			fullTimeHoursWeek: $period->fullTimeHoursWeek
		);

		$rows = [];
		foreach ($places as $place) {
			$rows[] = $this->placeRow(
				place: $place,
				occupied: $this->occupiedFte(occupants: $this->occupantsOf(place: $place, contracts: $contracts), window: $window)
			);
		}

		return ['places' => $rows, 'total' => $this->total(rows: $rows)];
	}//end occupancy()

	/**
	 * The unit's figures: the sum of the place rows, and whether it is overfilled.
	 *
	 * @param list<array<string, mixed>> $rows The place rows.
	 *
	 * @return array<string, mixed>
	 */
	private function total(array $rows): array {
		$total = ['budgetedFte' => 0.0, 'filledFte' => 0.0, 'vacantFte' => 0.0, 'netFte' => 0.0];
		foreach ($rows as $row) {
			foreach (array_keys($total) as $key) {
				$total[$key] += $row[$key];
			}
		}

		foreach (array_keys($total) as $key) {
			$total[$key] = round($total[$key], 2);
		}

		$total['overfilled'] = ($total['filledFte'] > $total['budgetedFte']);

		return $total;
	}//end total()

	/**
	 * The id a place is matched on: its id, else its uuid, else empty.
	 *
	 * @param array<string, mixed> $place The Formatieplaats.
	 *
	 * @return string
	 */
	private function placeId(array $place): string {
		return (string)($place['id'] ?? ($place['uuid'] ?? ''));
	}//end placeId()

	/**
	 * The contracts on a place. A place without an id has none.
	 *
	 * @param array<string, mixed>       $place     The Formatieplaats.
	 * @param list<array<string, mixed>> $contracts EmploymentContract records.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function occupantsOf(array $place, array $contracts): array {
		$placeId = $this->placeId(place: $place);
		$occupants = [];
		foreach ($contracts as $contract) {
			if ($placeId !== '' && (string)($contract['formatieplaatsId'] ?? '') === $placeId) {
				$occupants[] = $contract;
			}
		}

		return $occupants;
	}//end occupantsOf()

	/**
	 * The filled and the net FTE of a place's occupants, averaged over the
	 * window's days and rounded.
	 *
	 * @param list<array<string, mixed>> $occupants The place's contracts.
	 * @param FormationWindow            $window    The days, who is away, and the hours of one FTE.
	 *
	 * @return array{filled: float, net: float}
	 */
	private function occupiedFte(array $occupants, FormationWindow $window): array {
		$filled = 0.0;
		$net = 0.0;
		foreach ($window->days as $day) {
			foreach ($occupants as $contract) {
				if ($this->activeOn(contract: $contract, day: $day) === false) {
					continue;
				}

				$fte = $this->fte(contract: $contract, fullTimeHoursWeek: $window->fullTimeHoursWeek);
				$filled += $fte;
				$net += ($fte * (1.0 - $window->away->fraction(employeeId: (string)($contract['employeeId'] ?? ''), day: $day)));
			}
		}

		$count = max(1, count($window->days));

		return ['filled' => round(($filled / $count), 2), 'net' => round(($net / $count), 2)];
	}//end occupiedFte()

	/**
	 * One place's figures.
	 *
	 * @param array<string, mixed>              $place    The Formatieplaats.
	 * @param array{filled: float, net: float}  $occupied The place's filled and net FTE.
	 *
	 * @return array<string, mixed>
	 */
	private function placeRow(array $place, array $occupied): array {
		$budgeted = max(0.0, (float)($place['budgetedFte'] ?? 0));

		return [
			'id' => $this->placeId(place: $place),
			'title' => (string)($place['title'] ?? ''),
			'budgetedFte' => round($budgeted, 2),
			'filledFte' => $occupied['filled'],
			'vacantFte' => round(max(0.0, ($budgeted - $occupied['filled'])), 2),
			'overfilled' => ($occupied['filled'] > $budgeted),
			'netFte' => $occupied['net'],
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
	 * @param AbsencePeriod $period First and last day.
	 *
	 * @return list<DateTimeImmutable>
	 */
	private function workingDays(AbsencePeriod $period): array {
		$days = [];
		for ($day = $period->start->setTime(0, 0); $day <= $period->end; $day = $day->modify('+1 day')) {
			if ((int)$day->format('N') <= 5) {
				$days[] = $day;
			}
		}

		if ($days === []) {
			$days[] = $period->start->setTime(0, 0);
		}

		return $days;
	}//end workingDays()

}//end class
