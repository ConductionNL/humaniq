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
		$mutations = self::scenarioMutations(scenarioId: $scenarioId, rows: $rows);
		$lines = $this->budgetLines(budgetRun: ['mutations' => $mutations, 'rows' => $rows, 'year' => $year, 'raise' => $raise], chargesRatio: $charges['ratio']);

		return [
			'scenarioId' => $scenarioId,
			'year' => $year,
			'basis' => $this->basisOf(scenario: $scenario, charges: $charges),
			'lines' => $lines,
			'byUnit' => $this->totals->rollUp(lines: $lines, key: 'orgUnitId'),
			'byFunction' => $this->totals->rollUp(lines: $lines, key: 'normfunctieId'),
			'byCostCenter' => $this->totals->rollUp(lines: $lines, key: 'costCenter'),
			'total' => round(array_sum(array_column($lines, 'total')), 2),
		];
	}//end budget()

	/**
	 * The scenario's own mutations; none for the baseline (no scenario id).
	 *
	 * @param string               $scenarioId The scenario.
	 * @param array<string, mixed> $rows       The rows, as budget() takes them.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function scenarioMutations(string $scenarioId, array $rows): array {
		return array_values(
			array_filter((array)($rows['mutations'] ?? []), static fn (array $mutation): bool => $scenarioId !== '' && (string)($mutation['scenarioId'] ?? '') === $scenarioId)
		);
	}//end scenarioMutations()

	/**
	 * One line per place, with the employer charges and surcharges added.
	 *
	 * @param array<string, mixed> $budgetRun    The mutations, rows, year and raise factor.
	 * @param float                $chargesRatio The employer charges ratio.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function budgetLines(array $budgetRun, float $chargesRatio): array {
		$lines = [];
		foreach ($this->placesOf(rows: $budgetRun['rows'], mutations: $budgetRun['mutations']) as $place) {
			$line = $this->placeLine(place: $place, budgetRun: $budgetRun);
			$lines[] = $this->totals->withSurcharges(line: $line, chargesRatio: $chargesRatio);
		}

		return $lines;
	}//end budgetLines()

	/**
	 * What the budget rests on: the raise, holiday allowance, employer
	 * charges and where they come from, the full-time week and the month rule.
	 *
	 * @param array<string, mixed>                $scenario The FormationScenario.
	 * @param array{ratio: float, basis: mixed}   $charges  The employer charges basis.
	 *
	 * @return array<string, mixed>
	 */
	private function basisOf(array $scenario, array $charges): array {
		return [
			'caoRaisePercentage' => (float)($scenario['caoRaisePercentage'] ?? 0),
			'holidayAllowancePercentage' => $this->totals->holidayAllowancePercentage(),
			'employerChargesPercentage' => round(($charges['ratio'] * 100.0), 4),
			'employerChargesBasis' => $charges['basis'],
			'fullTimeHoursPerWeek' => $this->fullTimeHoursWeek,
			'monthRule' => 'a contract or change counts in a month when it applies on the first of that month',
		];
	}//end basisOf()

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

		$units = $this->totals->compareUnits(byUnit: $budgets);

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
	 * @param array<string, mixed> $place     The place.
	 * @param array<string, mixed> $budgetRun The scenario's `mutations`, the `rows`, the `year` and the CAO `raise` factor.
	 *
	 * @return array<string, mixed>
	 */
	private function placeLine(array $place, array $budgetRun): array {
		$month = new PersonnelBudgetMonth();
		$month->rows = $budgetRun['rows'];
		$month->mutations = $budgetRun['mutations'];
		$month->raise = $budgetRun['raise'];
		$month->place = $place;
		$line = $this->emptyLine(month: $month);
		for ($number = 1; $number <= 12; $number++) {
			$month->day = sprintf('%04d-%02d-01', $budgetRun['year'], $number);
			$line = $this->withMonth(line: $line, month: $month);
		}

		return $line;
	}//end placeLine()

	/**
	 * The place's line before any month: who and where, and its scale
	 * minimum, which is set on the month.
	 *
	 * @param PersonnelBudgetMonth $month The place being budgeted; its scale minimum is set.
	 *
	 * @return array<string, mixed>
	 */
	private function emptyLine(PersonnelBudgetMonth $month): array {
		$place = $month->place;
		$unit = $this->find(rows: (array)($month->rows['orgUnits'] ?? []), id: (string)($place['orgUnitId'] ?? ''));
		$month->minimum = $this->scaleMinimum(place: $place, normfuncties: (array)($month->rows['normfuncties'] ?? []));
		return [
			'placeId' => (string)($place['id'] ?? ''), 'title' => (string)($place['title'] ?? ''), 'proposed' => (($place['proposed'] ?? false) === true),
			'orgUnitId' => (string)($place['orgUnitId'] ?? ''), 'unitName' => (string)($unit['name'] ?? ''),
			'costCenter' => (string)($unit['costCenter'] ?? ''), 'normfunctieId' => (string)($place['normfunctieId'] ?? ''),
			'scaleMinimum' => $month->minimum, 'occupiedCost' => 0.0, 'vacantCost' => 0.0, 'unpriced' => false, 'fteByMonth' => [],
		];
	}//end emptyLine()

	/**
	 * The line with one month added: its budgeted, occupied and vacant FTE,
	 * the occupied cost and the vacant cost at the scale minimum.
	 *
	 * @param array<string, mixed> $line  The line so far.
	 * @param PersonnelBudgetMonth $month The place and the month.
	 *
	 * @return array<string, mixed>
	 */
	private function withMonth(array $line, PersonnelBudgetMonth $month): array {
		$minimum = $month->minimum;
		$budgeted = $this->budgetedOn(month: $month);
		$occupied = $this->occupiedOn(month: $month);
		$vacant = max(0.0, ($budgeted - $occupied['fte']));
		$line['occupiedCost'] += $occupied['cost'];
		$line['vacantCost'] += ($minimum === null) ? 0.0 : ($vacant * $minimum * $month->raise);
		$line['unpriced'] = ($line['unpriced'] || ($minimum === null && $vacant > 0.0));
		$line['fteByMonth'][] = ['month' => substr($month->day, 0, 7), 'budgeted' => round($budgeted, 4), 'occupied' => round($occupied['fte'], 4), 'vacant' => round($vacant, 4)];

		return $line;
	}//end withMonth()

	/**
	 * The place's budgeted FTE on the month's first day, with the scenario's changes.
	 *
	 * @param PersonnelBudgetMonth $month The place, the scenario's mutations and the day.
	 *
	 * @return float
	 */
	private function budgetedOn(PersonnelBudgetMonth $month): float {
		$place = $month->place;
		$fte = $this->runsOn(span: [($place['validFrom'] ?? null), ($place['validUntil'] ?? null)], day: $month->day) ? (float)($place['budgetedFte'] ?? 0) : 0.0;
		foreach ($month->mutations as $mutation) {
			if ($this->changesPlaceBy(mutation: $mutation, month: $month) === true) {
				$fte += (float)($mutation['fteDelta'] ?? 0);
			}
		}

		return max(0.0, $fte);
	}//end budgetedOn()

	/**
	 * Whether a mutation changes the month's place by its first day: it
	 * targets the place (a new place is `new:` and the mutation id) and is
	 * effective on or before the day; without a date it never is.
	 *
	 * @param array<string, mixed> $mutation The mutation.
	 * @param PersonnelBudgetMonth $month    The place and the day.
	 *
	 * @return bool
	 */
	private function changesPlaceBy(array $mutation, PersonnelBudgetMonth $month): bool {
		$target = trim((string)($mutation['formatieplaatsId'] ?? ''));
		$targetId = ($target === '') ? 'new:' . (string)($mutation['id'] ?? '') : $target;
		$from = (string)($this->progression->date(value: ($mutation['effectiveDate'] ?? null))?->format('Y-m-d') ?? '9999-12-31');

		return ($targetId === (string)($month->place['id'] ?? '') && $from <= $month->day);
	}//end changesPlaceBy()

	/**
	 * The occupied FTE of the place and its monthly cost on the month's first day.
	 *
	 * @param PersonnelBudgetMonth $month The place, the rows, the day and the raise factor.
	 *
	 * @return array{fte: float, cost: float}
	 */
	private function occupiedOn(PersonnelBudgetMonth $month): array {
		$placeId = (string)($month->place['id'] ?? '');
		$out = ['fte' => 0.0, 'cost' => 0.0];
		foreach ((array)($month->rows['contracts'] ?? []) as $contract) {
			if ((string)($contract['formatieplaatsId'] ?? '') !== $placeId || $this->contractRunsOn(contract: $contract, day: $month->day) === false) {
				continue;
			}

			$part = $this->contractPart(contract: $contract, month: $month);
			$out['fte'] += $part['fte'];
			$out['cost'] += $part['cost'];
		}

		return $out;
	}//end occupiedOn()

	/**
	 * The FTE one contract fills and the share of the employee's salary it costs.
	 *
	 * @param array<string, mixed> $contract The contract, running on the day.
	 * @param PersonnelBudgetMonth $month    The rows, the day and the raise factor.
	 *
	 * @return array{fte: float, cost: float}
	 */
	private function contractPart(array $contract, PersonnelBudgetMonth $month): array {
		$employeeId = (string)($contract['employeeId'] ?? '');
		$hours = (float)($contract['hoursPerWeek'] ?? 0);
		$allHours = $this->hoursOn(employeeId: $employeeId, month: $month);
		$share = ($allHours > 0.0) ? ($hours / $allHours) : 0.0;

		return [
			'fte' => ($this->fullTimeHoursWeek > 0.0) ? ($hours / $this->fullTimeHoursWeek) : 0.0,
			'cost' => ($this->salaryOn(employeeId: $employeeId, month: $month) * $month->raise * $share),
		];
	}//end contractPart()

	/**
	 * The employee's contracted hours on the day, across all their contracts.
	 *
	 * @param string               $employeeId The employee.
	 * @param PersonnelBudgetMonth $month      The contracts and the day.
	 *
	 * @return float
	 */
	private function hoursOn(string $employeeId, PersonnelBudgetMonth $month): float {
		$hours = 0.0;
		foreach ((array)($month->rows['contracts'] ?? []) as $contract) {
			if ((string)($contract['employeeId'] ?? '') === $employeeId && $this->contractRunsOn(contract: $contract, day: $month->day) === true) {
				$hours += (float)($contract['hoursPerWeek'] ?? 0);
			}
		}

		return $hours;
	}//end hoursOn()

	/**
	 * The monthly salary on the day: the latest approved, not yet applied raise from its date, else the current salary.
	 *
	 * @param string               $employeeId The employee.
	 * @param PersonnelBudgetMonth $month      The rows and the day.
	 *
	 * @return float
	 */
	private function salaryOn(string $employeeId, PersonnelBudgetMonth $month): float {
		$salary = (float)($this->find(rows: (array)($month->rows['employees'] ?? []), id: $employeeId)['grossMonthlySalary'] ?? 0);
		$latest = '';
		foreach ((array)($month->rows['compAdjustments'] ?? []) as $adjustment) {
			$from = $this->approvedRaiseFrom(adjustment: $adjustment, employeeId: $employeeId);
			if ($from !== '' && $from <= $month->day && $from >= $latest) {
				$latest = $from;
				$salary = ((float)$adjustment['proposedSalary'] / 100.0);
			}
		}

		return $salary;
	}//end salaryOn()

	/**
	 * The date an approved raise for the employee with a proposed salary
	 * takes effect, or '' when the adjustment is not one or has no date.
	 *
	 * @param array<string, mixed> $adjustment The CompAdjustment.
	 * @param string               $employeeId The employee.
	 *
	 * @return string
	 */
	private function approvedRaiseFrom(array $adjustment, string $employeeId): string {
		$from = (string)($this->progression->date(value: ($adjustment['effectiveDate'] ?? null))?->format('Y-m-d') ?? '');
		$applies = ((string)($adjustment['employeeId'] ?? '') === $employeeId && ($adjustment['status'] ?? '') === 'approved' && is_numeric($adjustment['proposedSalary'] ?? null));

		return ($applies === true ? $from : '');
	}//end approvedRaiseFrom()

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
	 * Whether a contract applies on a day.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param string               $day      The day (Y-m-d).
	 *
	 * @return bool
	 */
	private function contractRunsOn(array $contract, string $day): bool {
		return $this->runsOn(span: [($contract['startDate'] ?? null), ($contract['endDate'] ?? null)], day: $day);
	}//end contractRunsOn()

	/**
	 * Whether a span of a from and an until date applies on a day; a missing
	 * date does not limit it.
	 *
	 * @param array{0: mixed, 1: mixed} $span The raw from and until values.
	 * @param string                    $day  The day (Y-m-d).
	 *
	 * @return bool
	 */
	private function runsOn(array $span, string $day): bool {
		$start = $this->progression->date(value: $span[0])?->format('Y-m-d');
		$end = $this->progression->date(value: $span[1])?->format('Y-m-d');

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
