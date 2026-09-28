<?php

/**
 * Department Figures Service
 *
 * department-figures: the absence rate, the absence frequency, the wage cost
 * and the open vacancies of org units, side by side for HR and accountants
 * (REQ-DPF-001) and for the units a manager leads (REQ-DPF-002). A unit's
 * figures cover the people placed in it or its children in the window, as
 * unit totals only. A manager does not see the figures of a unit with fewer
 * people than the small-unit threshold; the service withholds them and says
 * why.
 *
 * When units are compared, a person placed in two of the compared units in
 * the same month has their wage cost split between them, and the cost of
 * people placed in none is reported as its own line, so the units and that
 * line always add up to the administration's total.
 *
 * Every method trusts the administration it is given: the controller has
 * authorised the caller for it.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Unit figures for the comparison, the manager's units and one unit.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
class DepartmentFiguresService {

	/**
	 * The schemas a unit row reads.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMAS = [
		'OrgUnit',
		'OrgAssignment',
		'SickLeaveCase',
		'EmploymentContract',
		'PayrollRun',
		'Payslip',
		'Formatieplaats',
		'Vacancy',
	];

	/**
	 * @param AnalyticsService   $analytics   Loads the administration's rows and resolves period windows.
	 * @param UnitMembership     $membership  Unit trees and placements.
	 * @param DepartmentFigures  $figures     Frequency and wage cost.
	 * @param AbsenceRateService $absenceRate The FTE-weighted absence rate.
	 * @param SettingsService    $settings    The small-unit threshold.
	 * @param TrendPeriods       $periods     The trailing month windows.
	 */
	public function __construct(
		private readonly AnalyticsService $analytics,
		private readonly UnitMembership $membership,
		private readonly DepartmentFigures $figures,
		private readonly AbsenceRateService $absenceRate,
		private readonly SettingsService $settings,
		private readonly TrendPeriods $periods = new TrendPeriods(),
	) {
	}//end __construct()

	/**
	 * The small-unit threshold a manager is held to.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function minimumMembers(): int {
		return $this->settings->getDepartmentFiguresMinimumMembers();
	}//end minimumMembers()

	/**
	 * Whether the user manages this unit, directly or through a unit above it.
	 *
	 * @param string $userId           The Nextcloud user id.
	 * @param string $administrationId The caller's active administration.
	 * @param string $unitId           The unit asked for.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function managesUnit(string $userId, string $administrationId, string $unitId): bool {
		$units = $this->analytics->rowsFor('OrgUnit', $administrationId);
		$employees = $this->analytics->rowsFor('Employee', $administrationId);
		foreach ($this->membership->managedUnitIds($userId, $units, $employees) as $managed) {
			if (in_array($unitId, $this->membership->subtree($managed, $units), true) === true) {
				return true;
			}
		}

		return false;
	}//end managesUnit()

	/**
	 * The units under `$parentUnitId` (or the top units) side by side, with
	 * the wage cost of people placed in none of them and the total.
	 *
	 * @param string      $administrationId The authorised administration.
	 * @param string      $period           `quarter`, `half-year`, `year` or one month `YYYY-MM`.
	 * @param string|null $parentUnitId     Compare this unit's children, or the top units when null.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException When the period is not recognised.
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function compare(string $administrationId, string $period, ?string $parentUnitId): array {
		$window = $this->window($period);
		$data = $this->load($administrationId);

		$parent = trim((string)$parentUnitId);
		$rows = [];
		foreach ($data['OrgUnit'] as $unit) {
			if (trim((string)($unit['parentUnitId'] ?? '')) === $parent) {
				$rows[] = $this->unitRow($data, $this->membership->rowId($unit), $window, 0);
			}
		}

		$total = $this->sumMonths($window['months'], fn (string $month): ?float => $this->figures->wageCost($data['PayrollRun'], $data['Payslip'], null, $month));
		$costs = $this->comparedWageCosts($data, $rows, $window['months']);
		foreach (array_keys($rows) as $index) {
			$rows[$index]['wageCost'] = $costs[$index];
		}

		$placed = array_sum(array_map(fn (?float $cost): float => ($cost ?? 0.0), $costs));

		return [
			'period' => $period,
			'from' => $window['from']->format('Y-m-d'),
			'to' => $window['to']->format('Y-m-d'),
			'units' => $rows,
			'notPlaced' => ['wageCost' => ($total === null) ? null : round(($total - $placed), 2)],
			'total' => ['wageCost' => $total],
		];
	}//end compare()

	/**
	 * The figures of every unit the user manages, under the small-unit rule.
	 *
	 * @param string $userId           The Nextcloud user id.
	 * @param string $administrationId The caller's active administration.
	 * @param string $period           The window.
	 *
	 * @return array{period: string, units: list<array<string, mixed>>}
	 *
	 * @throws \InvalidArgumentException When the period is not recognised.
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function managedUnits(string $userId, string $administrationId, string $period): array {
		$window = $this->window($period);
		$data = $this->load($administrationId);
		$employees = $this->analytics->rowsFor('Employee', $administrationId);

		$rows = [];
		foreach ($this->membership->managedUnitIds($userId, $data['OrgUnit'], $employees) as $unitId) {
			$rows[] = $this->unitRow($data, $unitId, $window, $this->minimumMembers());
		}

		return ['period' => $period, 'units' => $rows];
	}//end managedUnits()

	/**
	 * One unit's figures, or null when the unit is not in the administration.
	 *
	 * @param string $administrationId The authorised administration.
	 * @param string $unitId           The unit.
	 * @param string $period           The window.
	 * @param int    $minimumMembers   The small-unit threshold (0 for none).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \InvalidArgumentException When the period is not recognised.
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function unitFigures(string $administrationId, string $unitId, string $period, int $minimumMembers): ?array {
		$data = $this->load($administrationId);
		foreach ($data['OrgUnit'] as $unit) {
			if ($this->membership->rowId($unit) === $unitId) {
				return $this->unitRow($data, $unitId, $this->window($period), $minimumMembers);
			}
		}

		return null;
	}//end unitFigures()

	/**
	 * One unit's row over the window.
	 *
	 * @param array<string, list<array<string, mixed>>>                                              $data    Rows per schema.
	 * @param string                                                                                 $unitId  The unit.
	 * @param array{months: list<string>, from: DateTimeImmutable, to: DateTimeImmutable}            $window  The window.
	 * @param int                                                                                    $minimum The small-unit threshold.
	 *
	 * @return array<string, mixed>
	 */
	private function unitRow(array $data, string $unitId, array $window, int $minimum): array {
		$subtree = $this->membership->subtree($unitId, $data['OrgUnit']);
		$shares = $this->membership->sharesInWindow($subtree, $data['OrgAssignment'], $window['from'], $window['to']);
		$row = [
			'id' => $unitId,
			'name' => $this->unitName($data['OrgUnit'], $unitId),
			'members' => count($shares),
			'openVacancies' => $this->openVacancies($data, $subtree),
		];

		if (count($shares) < $minimum) {
			return ($row + ['absenceRate' => null, 'absenceFrequency' => null, 'wageCost' => null, 'suppressed' => 'unit-too-small']);
		}

		$own = $this->membership->restrict(['SickLeaveCase' => $data['SickLeaveCase'], 'EmploymentContract' => $data['EmploymentContract']], $shares);
		$rate = $this->absenceRate->absenceRate($own['SickLeaveCase'], $own['EmploymentContract'], $window['from'], $window['to']);

		return ($row + [
			'absenceRate' => $rate['percentage'],
			'absenceFrequency' => $this->figures->frequency($data['SickLeaveCase'], $shares, $window['from'], $window['to']),
			'wageCost' => $this->sumMonths(
				$window['months'],
				fn (string $month): ?float => $this->figures->wageCost(
					$data['PayrollRun'],
					$data['Payslip'],
					$this->monthShares($subtree, $data['OrgAssignment'], $month),
					$month
				)
			),
		]);
	}//end unitRow()

	/**
	 * The wage cost of each compared unit, with a person placed in several of
	 * them in a month split between them so no cost counts twice.
	 *
	 * @param array<string, list<array<string, mixed>>> $data   Rows per schema.
	 * @param list<array<string, mixed>>                $rows   The compared unit rows.
	 * @param list<string>                              $months The window's months.
	 *
	 * @return list<float|null> Per row, in the same order.
	 */
	private function comparedWageCosts(array $data, array $rows, array $months): array {
		$costs = array_fill(0, count($rows), null);
		foreach ($months as $month) {
			$shares = [];
			$placed = [];
			foreach ($rows as $index => $row) {
				$subtree = $this->membership->subtree((string)$row['id'], $data['OrgUnit']);
				$shares[$index] = $this->monthShares($subtree, $data['OrgAssignment'], $month);
				foreach ($shares[$index] as $employeeId => $share) {
					$placed[$employeeId] = (($placed[$employeeId] ?? 0.0) + $share);
				}
			}

			foreach ($shares as $index => $unitShares) {
				$weights = [];
				foreach ($unitShares as $employeeId => $share) {
					$weights[$employeeId] = ($share / max(1.0, $placed[$employeeId]));
				}

				$cost = $this->figures->wageCost($data['PayrollRun'], $data['Payslip'], $weights, $month);
				if ($cost !== null) {
					$costs[$index] = round((($costs[$index] ?? 0.0) + $cost), 2);
				}
			}
		}//end foreach

		return $costs;
	}//end comparedWageCosts()

	/**
	 * Shares of the people placed in a subtree during one month.
	 *
	 * @param list<string>               $subtree     The units.
	 * @param list<array<string, mixed>> $assignments OrgAssignment rows.
	 * @param string                     $month       `YYYY-MM`.
	 *
	 * @return array<string, float>
	 */
	private function monthShares(array $subtree, array $assignments, string $month): array {
		$start = new DateTimeImmutable($month . '-01');

		return $this->membership->sharesInWindow($subtree, $assignments, $start, $start->modify('last day of this month'));
	}//end monthShares()

	/**
	 * Sum a per-month figure; null when no month had one.
	 *
	 * @param list<string>                   $months The months.
	 * @param callable(string): (float|null) $figure The figure of one month.
	 *
	 * @return float|null
	 */
	private function sumMonths(array $months, callable $figure): ?float {
		$sum = null;
		foreach ($months as $month) {
			$value = $figure($month);
			if ($value !== null) {
				$sum = (($sum ?? 0.0) + $value);
			}
		}

		return ($sum === null) ? null : round($sum, 2);
	}//end sumMonths()

	/**
	 * The published vacancies on the formation places of a subtree.
	 *
	 * @param array<string, list<array<string, mixed>>> $data    Rows per schema.
	 * @param list<string>                              $subtree The units.
	 *
	 * @return int
	 */
	private function openVacancies(array $data, array $subtree): int {
		$places = [];
		foreach ($data['Formatieplaats'] as $place) {
			if (in_array(trim((string)($place['orgUnitId'] ?? '')), $subtree, true) === true) {
				$places[] = $this->membership->rowId($place);
			}
		}

		$open = 0;
		foreach ($data['Vacancy'] as $vacancy) {
			if (($vacancy['status'] ?? '') === 'gepubliceerd'
				&& in_array(trim((string)($vacancy['formatieplaatsId'] ?? '')), $places, true) === true
			) {
				$open++;
			}
		}

		return $open;
	}//end openVacancies()

	/**
	 * A unit's name.
	 *
	 * @param list<array<string, mixed>> $units  OrgUnit rows.
	 * @param string                     $unitId The unit.
	 *
	 * @return string
	 */
	private function unitName(array $units, string $unitId): string {
		foreach ($units as $unit) {
			if ($this->membership->rowId($unit) === $unitId) {
				return (string)($unit['name'] ?? '');
			}
		}

		return '';
	}//end unitName()

	/**
	 * The months, first day and last day of a window.
	 *
	 * @param string $period `quarter`, `half-year`, `year` or `YYYY-MM`.
	 *
	 * @return array{months: list<string>, from: DateTimeImmutable, to: DateTimeImmutable}
	 *
	 * @throws \InvalidArgumentException When the period is not recognised.
	 */
	private function window(string $period): array {
		$months = (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1) ? [$period] : array_values($this->periods->keys($period));
		$from = new DateTimeImmutable($months[0] . '-01');
		$last = new DateTimeImmutable($months[(count($months) - 1)] . '-01');

		return ['months' => $months, 'from' => $from, 'to' => $last->modify('last day of this month')];
	}//end window()

	/**
	 * The rows a unit row reads, for the administration.
	 *
	 * @param string $administrationId The authorised administration.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function load(string $administrationId): array {
		$data = [];
		foreach (self::SCHEMAS as $schema) {
			$data[$schema] = array_values($this->analytics->rowsFor($schema, $administrationId));
		}

		return $data;
	}//end load()

}//end class
