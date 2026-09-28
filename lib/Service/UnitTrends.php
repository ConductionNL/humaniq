<?php

/**
 * Unit Trends
 *
 * department-figures: the trend metrics for one org unit and its children,
 * and the absence frequency. Per period it takes the people placed in the
 * unit, restricts the rows to them and computes the metric over those
 * (design.md D1). A period with fewer people than the small-unit threshold
 * answers null with the reason `unit-too-small` rather than a figure that
 * points at one person (design.md risks). The wage cost of a unit comes
 * from its people's payslips with the run's employer charge share, because
 * the run totals cannot be split per person.
 *
 * Split out of {@see AnalyticsService}, which keeps the administration-wide
 * series, for the reason that class's own docblock gives for
 * ObligationsService: one class doing both passed phpmd's complexity and
 * coupling ceilings.
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

/**
 * Trend series for one org unit's people.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
class UnitTrends {

	/**
	 * @param UnitMembership    $membership Unit trees, placements and row restriction.
	 * @param DepartmentFigures $figures    Frequency and wage cost.
	 * @param TrendPeriods      $periods    Month bounds.
	 */
	public function __construct(
		private readonly UnitMembership $membership = new UnitMembership(),
		private readonly DepartmentFigures $figures = new DepartmentFigures(),
		private readonly TrendPeriods $periods = new TrendPeriods(),
	) {
	}//end __construct()

	/**
	 * A metric for one unit, period by period.
	 *
	 * @param array{id: string, minimumMembers: int, metric: string} $unit       The unit, its threshold and the metric.
	 * @param array<int, string>                                   $periodKeys `YYYY-MM` buckets.
	 * @param array<string, array<int, array<string, mixed>>>      $rows       The metric's rows for the administration.
	 * @param callable(string): array<int, array<string, mixed>>   $load       Loads a schema's rows for the administration.
	 * @param callable(array<int, string>, array<string, array<int, array<string, mixed>>>, array<string, float>): array<int, array<string, mixed>> $seriesFor The metric over restricted rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function series(array $unit, array $periodKeys, array $rows, callable $load, callable $seriesFor): array {
		$subtree = $this->membership->subtree($unit['id'], $load('OrgUnit'));
		$assignments = $load('OrgAssignment');
		$payslips = ($unit['metric'] === 'payroll-cost') ? $load('Payslip') : [];

		$series = [];
		foreach ($periodKeys as $period) {
			[$start, $end] = $this->periods->bounds($period);
			$shares = $this->membership->sharesInWindow($subtree, $assignments, $start, $end);
			if (count($shares) < $unit['minimumMembers']) {
				$series[] = $this->withheld($unit['metric'], $period);
				continue;
			}

			if ($unit['metric'] === 'payroll-cost') {
				$series[] = ['date' => $period, 'value' => $this->figures->wageCost($rows['PayrollRun'], $payslips, $shares, $period)];
				continue;
			}

			$series[] = $seriesFor([$period], $this->membership->restrict($rows, $shares), $shares)[0];
		}

		return $series;
	}//end series()

	/**
	 * Absence-frequency series: sick reports per employee per year in each
	 * bucket. The people are the unit's when given, else everyone with a
	 * contract in the bucket.
	 *
	 * @param array<int, string>               $periodKeys `YYYY-MM` buckets.
	 * @param array<int, array<string, mixed>> $cases      SickLeaveCase rows.
	 * @param array<int, array<string, mixed>> $contracts  EmploymentContract rows.
	 * @param array<string, float>|null        $shares     A unit's people, or null.
	 *
	 * @return array<int, array{date: string, value: float|null}>
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function frequencySeries(array $periodKeys, array $cases, array $contracts, ?array $shares): array {
		$series = [];
		foreach ($periodKeys as $period) {
			[$start, $end] = $this->periods->bounds($period);
			$people = ($shares ?? $this->membership->contractShares($contracts, $start, $end));
			$series[] = ['date' => $period, 'value' => $this->figures->frequency($cases, $people, $start, $end)];
		}

		return $series;
	}//end frequencySeries()

	/**
	 * A withheld bucket: every figure null, with the reason.
	 *
	 * @param string $metric The metric.
	 * @param string $period `YYYY-MM`.
	 *
	 * @return array<string, mixed>
	 */
	private function withheld(string $metric, string $period): array {
		$empty = match ($metric) {
			'approval-lead-time' => ['median' => null, 'p90' => null],
			'headcount' => ['headcount' => null, 'starters' => null, 'leavers' => null],
			default => ['value' => null],
		};

		return (['date' => $period] + $empty + ['suppressed' => 'unit-too-small']);
	}//end withheld()

}//end class
