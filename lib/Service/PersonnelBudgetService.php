<?php

/**
 * Humaniq PersonnelBudgetService
 *
 * Next year's personnel cost of the formation, per place and rolled up per
 * unit, reference job and cost centre, for a scenario of FTE changes that
 * never touches the live formation (reporting-personnel-budget-and-scenarios
 * D2, D3). A gross cost model, month by month:
 *
 * - occupied FTE at the occupant's own salary (an approved, not yet applied
 *   raise from its effective date), times the share of the contract on the place;
 * - vacant FTE at the monthly minimum of the reference job's scale, or flagged
 *   unpriced when that scale is not sourced;
 * - both raised by the scenario's CAO raise from 1 January;
 * - holiday allowance on top, and employer charges at the ratio of the
 *   administration's last twelve finalised runs, or the scenario's percentage.
 *
 * A contract counts in a month when it runs on the first of that month; a
 * mutation counts from the month its effective date falls on the first of.
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
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Computes a personnel budget and compares scenarios.
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */
class PersonnelBudgetService {

	/**
	 * The CAO whose scale key space the HR21 reference jobs use.
	 */
	public const REFERENCE_JOB_CAO = 'cao-gemeenten';

	/**
	 * Constructor.
	 *
	 * @param AbsenceProgression $progression The shared date parser.
	 * @param CaoScaleLookup $scales Scale minimums.
	 * @param PersonnelBudgetTotals $totals Surcharges and roll-ups.
	 * @param float $fullTimeHoursWeek The full-time week an FTE is measured against.
	 */
	public function __construct(
		private readonly AbsenceProgression $progression,
		private readonly CaoScaleLookup $scales,
		private readonly PersonnelBudgetTotals $totals = new PersonnelBudgetTotals(),
		private readonly float $fullTimeHoursWeek = AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK,
	) {

	}//end __construct()

	/**
	 * The budget of one scenario for one year.
	 *
	 * @param array<string, mixed> $scenario The FormationScenario.
	 * @param array<string, mixed> $rows places, contracts, employees, normfuncties, orgUnits, compAdjustments, mutations, runs.
	 * @param int $year The budget year.
	 *
	 * @return array<string, mixed> basis, lines, byUnit, byFunction, byCostCenter, total.
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
	 */
	public function budget(array $scenario, array $rows, int $year): array {
		$raise = (1.0 + ((float)($scenario['caoRaisePercentage'] ?? 0) / 100.0));
		$charges = $this->totals->chargesBasis(scenario: $scenario, runs: (array)($rows['runs'] ?? []));
		$scenarioId = (string)($scenario['id'] ?? '');
		$mutations = array_values(
			array_filter((array)($rows['mutations'] ?? []), static fn (array $mutation): bool => $scenarioId !== '' && (string)($mutation['scenarioId'] ?? '') === $scenarioId)
		);

		$lines = [];
		foreach ($this->placesOf(rows: $rows, mutations: $mutations) as $place) {
			$line = $this->placeLine(place: $place, mutations: $mutations, rows: $rows, year: $year, raise: $raise);
			$lines[] = $this->totals->withSurcharges(line: $line, chargesRatio: $charges['ratio']);
		}

		return [
			'scenarioId' => $scenarioId,
			'year' => $year,
			'basis' => [
				'caoRaisePercentage' => (float)($scenario['caoRaisePercentage'] ?? 0),
				'holidayAllowancePercentage' => $this->totals->holidayAllowancePercentage(),
				'employerChargesPercentage' => round(($charges['ratio'] * 100.0), 4),
				'employerChargesBasis' => $charges['basis'],
				'fullTimeHoursPerWeek' => $this->fullTimeHoursWeek,
				'monthRule' => 'a contract or change counts in a month when it applies on the first of that month',
			],
			'lines' => $lines,
			'byUnit' => $this->totals->rollUp(lines: $lines, key: 'orgUnitId'),
			'byFunction' => $this->totals->rollUp(lines: $lines, key: 'normfunctieId'),
			'byCostCenter' => $this->totals->rollUp(lines: $lines, key: 'costCenter'),
			'total' => round(array_sum(array_column($lines, 'total')), 2),
		];
	}//end budget()

	/**
	 * Two scenarios and the baseline (the first scenario without its changes), per unit.
	 *
	 * @param array<string, mixed> $a The first scenario.
	 * @param array<string, mixed> $b The second scenario.
	 * @param array<string, mixed> $rows The rows, as budget() takes them.
	 * @param int $year The budget year.
	 *
	 * @return array<string, mixed> year and units, each with baseline, a, b and the differences.
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-002
	 */
	public function compare(array $a, array $b, array $rows, int $year): array {
		$budgets = [
			'baseline' => $this->budget(scenario: array_merge($a, ['id' => '']), rows: $rows, year: $year)['byUnit'],
			'a' => $this->budget(scenario: $a, rows: $rows, year: $year)['byUnit'],
			'b' => $this->budget(scenario: $b, rows: $rows, year: $year)['byUnit'],
		];

		$units = [];
		foreach (array_unique(array_merge(array_keys($budgets['baseline']), array_keys($budgets['a']), array_keys($budgets['b']))) as $unitId) {
			$unit = ['orgUnitId' => (string)$unitId, 'name' => ''];
			foreach ($budgets as $which => $byUnit) {
				$row = ($byUnit[$unitId] ?? ['name' => '', 'total' => 0.0, 'fteByMonth' => array_fill(0, 12, 0.0)]);
				$unit['name'] = ($unit['name'] !== '' ? $unit['name'] : (string)$row['name']);
				$unit[$which] = ['cost' => (float)$row['total'], 'fteByMonth' => $row['fteByMonth']];
			}

			$unit['diffA'] = ['cost' => round(($unit['a']['cost'] - $unit['baseline']['cost']), 2)];
			$unit['diffB'] = ['cost' => round(($unit['b']['cost'] - $unit['baseline']['cost']), 2)];
			$units[(string)$unitId] = $unit;
		}

		return ['year' => $year, 'a' => (string)($a['id'] ?? ''), 'b' => (string)($b['id'] ?? ''), 'units' => $units];
	}//end compare()

	/**
	 * The live places plus the new places the scenario's mutations propose.
	 *
	 * @param array<string, mixed> $rows The rows.
	 * @param array<int, array<string, mixed>> $mutations The scenario's mutations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function placesOf(array $rows, array $mutations): array {
		$places = array_values((array)($rows['places'] ?? []));
		foreach ($mutations as $mutation) {
			if (trim((string)($mutation['formatieplaatsId'] ?? '')) !== '') {
				continue;
			}

			$places[] = [
				'id' => 'new:' . (string)($mutation['id'] ?? count($places)),
				'orgUnitId' => ($mutation['orgUnitId'] ?? null),
				'normfunctieId' => ($mutation['normfunctieId'] ?? null),
				'title' => (string)($mutation['title'] ?? ''),
				'budgetedFte' => 0.0,
				'proposed' => true,
			];
		}

		return $places;
	}//end placesOf()

	/**
	 * One place's line: FTE and base cost per month, before surcharges.
	 *
	 * @param array<string, mixed> $place The place.
	 * @param array<int, array<string, mixed>> $mutations The scenario's mutations.
	 * @param array<string, mixed> $rows The rows.
	 * @param int $year The year.
	 * @param float $raise The CAO raise factor.
	 *
	 * @return array<string, mixed>
	 */
	private function placeLine(array $place, array $mutations, array $rows, int $year, float $raise): array {
		$placeId = (string)($place['id'] ?? '');
		$unit = $this->find(rows: (array)($rows['orgUnits'] ?? []), id: (string)($place['orgUnitId'] ?? ''));
		$minimum = $this->scaleMinimum(place: $place, normfuncties: (array)($rows['normfuncties'] ?? []));
		$line = [
			'placeId' => $placeId, 'title' => (string)($place['title'] ?? ''), 'proposed' => (($place['proposed'] ?? false) === true),
			'orgUnitId' => (string)($place['orgUnitId'] ?? ''), 'unitName' => (string)($unit['name'] ?? ''),
			'costCenter' => (string)($unit['costCenter'] ?? ''), 'normfunctieId' => (string)($place['normfunctieId'] ?? ''),
			'scaleMinimum' => $minimum, 'occupiedCost' => 0.0, 'vacantCost' => 0.0, 'unpriced' => false, 'fteByMonth' => [],
		];
		for ($month = 1; $month <= 12; $month++) {
			$day = sprintf('%04d-%02d-01', $year, $month);
			$budgeted = $this->budgetedOn(place: $place, mutations: $mutations, day: $day);
			$occupied = $this->occupiedOn(placeId: $placeId, rows: $rows, day: $day, raise: $raise);
			$vacant = max(0.0, ($budgeted - $occupied['fte']));
			$line['occupiedCost'] += $occupied['cost'];
			$line['vacantCost'] += ($minimum === null) ? 0.0 : ($vacant * $minimum * $raise);
			$line['unpriced'] = ($line['unpriced'] || ($minimum === null && $vacant > 0.0));
			$line['fteByMonth'][] = ['month' => substr($day, 0, 7), 'budgeted' => round($budgeted, 4), 'occupied' => round($occupied['fte'], 4), 'vacant' => round($vacant, 4)];
		}

		return $line;
	}//end placeLine()

	/**
	 * The place's budgeted FTE on a day, with the scenario's changes.
	 *
	 * @param array<string, mixed> $place The place.
	 * @param array<int, array<string, mixed>> $mutations The scenario's mutations.
	 * @param string $day The first of a month.
	 *
	 * @return float
	 */
	private function budgetedOn(array $place, array $mutations, string $day): float {
		$fte = $this->runsOn(row: $place, from: 'validFrom', until: 'validUntil', day: $day) ? (float)($place['budgetedFte'] ?? 0) : 0.0;
		$placeId = (string)($place['id'] ?? '');
		foreach ($mutations as $mutation) {
			$target = trim((string)($mutation['formatieplaatsId'] ?? ''));
			$targetId = ($target === '') ? 'new:' . (string)($mutation['id'] ?? '') : $target;
			$from = (string)($this->progression->date(value: ($mutation['effectiveDate'] ?? null))?->format('Y-m-d') ?? '9999-12-31');
			if ($targetId === $placeId && $from <= $day) {
				$fte += (float)($mutation['fteDelta'] ?? 0);
			}
		}

		return max(0.0, $fte);
	}//end budgetedOn()

	/**
	 * The occupied FTE and its monthly cost on a day.
	 *
	 * @param string $placeId The place.
	 * @param array<string, mixed> $rows The rows.
	 * @param string $day The first of a month.
	 * @param float $raise The CAO raise factor.
	 *
	 * @return array{fte: float, cost: float}
	 */
	private function occupiedOn(string $placeId, array $rows, string $day, float $raise): array {
		$contracts = (array)($rows['contracts'] ?? []);
		$out = ['fte' => 0.0, 'cost' => 0.0];
		foreach ($contracts as $contract) {
			if ((string)($contract['formatieplaatsId'] ?? '') !== $placeId || $this->runsOn(row: $contract, from: 'startDate', until: 'endDate', day: $day) === false) {
				continue;
			}

			$employeeId = (string)($contract['employeeId'] ?? '');
			$hours = (float)($contract['hoursPerWeek'] ?? 0);
			$allHours = $this->hoursOn(employeeId: $employeeId, contracts: $contracts, day: $day);
			$share = ($allHours > 0.0) ? ($hours / $allHours) : 0.0;
			$out['fte'] += ($this->fullTimeHoursWeek > 0.0) ? ($hours / $this->fullTimeHoursWeek) : 0.0;
			$out['cost'] += ($this->salaryOn(employeeId: $employeeId, rows: $rows, day: $day) * $raise * $share);
		}

		return $out;
	}//end occupiedOn()

	/**
	 * The employee's contracted hours on a day, across all their contracts.
	 *
	 * @param string $employeeId The employee.
	 * @param array<int, array<string, mixed>> $contracts The contracts.
	 * @param string $day The day.
	 *
	 * @return float
	 */
	private function hoursOn(string $employeeId, array $contracts, string $day): float {
		$hours = 0.0;
		foreach ($contracts as $contract) {
			if ((string)($contract['employeeId'] ?? '') === $employeeId && $this->runsOn(row: $contract, from: 'startDate', until: 'endDate', day: $day) === true) {
				$hours += (float)($contract['hoursPerWeek'] ?? 0);
			}
		}

		return $hours;
	}//end hoursOn()

	/**
	 * The monthly salary on a day: the latest approved, not yet applied raise from its date, else the current salary.
	 *
	 * @param string $employeeId The employee.
	 * @param array<string, mixed> $rows The rows.
	 * @param string $day The day.
	 *
	 * @return float
	 */
	private function salaryOn(string $employeeId, array $rows, string $day): float {
		$salary = (float)($this->find(rows: (array)($rows['employees'] ?? []), id: $employeeId)['grossMonthlySalary'] ?? 0);
		$latest = '';
		foreach ((array)($rows['compAdjustments'] ?? []) as $adjustment) {
			$from = (string)($this->progression->date(value: ($adjustment['effectiveDate'] ?? null))?->format('Y-m-d') ?? '');
			$applies = ((string)($adjustment['employeeId'] ?? '') === $employeeId && ($adjustment['status'] ?? '') === 'approved' && is_numeric($adjustment['proposedSalary'] ?? null));
			if ($applies === true && $from !== '' && $from <= $day && $from >= $latest) {
				$latest = $from;
				$salary = ((float)$adjustment['proposedSalary'] / 100.0);
			}
		}

		return $salary;
	}//end salaryOn()

	/**
	 * The monthly scale minimum of the place's reference job, or null when not sourced.
	 *
	 * @param array<string, mixed> $place The place.
	 * @param array<int, array<string, mixed>> $normfuncties The reference jobs.
	 *
	 * @return float|null
	 */
	private function scaleMinimum(array $place, array $normfuncties): ?float {
		$schaal = trim((string)($this->find(rows: $normfuncties, id: (string)($place['normfunctieId'] ?? ''))['caoSchaal'] ?? ''));
		if ($schaal === '') {
			return null;
		}

		$cents = $this->scales->minimumCents(self::REFERENCE_JOB_CAO, $schaal);

		return ($cents === null) ? null : ($cents / 100.0);
	}//end scaleMinimum()

	/**
	 * Whether a row with a from and until date applies on a day.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string $from The start field.
	 * @param string $until The end field.
	 * @param string $day The day (Y-m-d).
	 *
	 * @return bool
	 */
	private function runsOn(array $row, string $from, string $until, string $day): bool {
		$start = $this->progression->date(value: ($row[$from] ?? null))?->format('Y-m-d');
		$end = $this->progression->date(value: ($row[$until] ?? null))?->format('Y-m-d');

		return ($start === null || $start <= $day) && ($end === null || $end >= $day);
	}//end runsOn()

	/**
	 * A row by id.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>
	 */
	private function find(array $rows, string $id): array {
		foreach ($rows as $row) {
			if ($id !== '' && (string)($row['id'] ?? '') === $id) {
				return $row;
			}
		}

		return [];
	}//end find()

}//end class
