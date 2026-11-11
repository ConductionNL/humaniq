<?php

/**
 * Humaniq UnitWageCostSplit
 *
 * The wage cost of each compared unit over a comparison's months, with a
 * person placed in several of them in a month split between them so no
 * cost counts twice.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Splits the wage cost over the compared units, month by month.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
final class UnitWageCostSplit {

	/**
	 * Constructor.
	 *
	 * @param UnitMembership $membership Unit trees and placements.
	 * @param DepartmentFigures $figures The wage cost of a month.
	 */
	public function __construct(
		private readonly UnitMembership $membership,
		private readonly DepartmentFigures $figures,
	) {
	}//end __construct()

	/**
	 * Fill each compared unit's wage cost over the comparison's months.
	 *
	 * @param DepartmentComparison $run The comparison, with its unit rows.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function fill(DepartmentComparison $run): void {
		$run->costs = array_fill(0, count($run->rows), null);
		foreach ($run->window['months'] as $month) {
			$this->addMonthCosts(run: $run, month: $month);
		}
	}//end fill()

	/**
	 * Add one month's wage cost to each compared unit, with a person placed in
	 * several of them that month split between them so no cost counts twice.
	 *
	 * @param DepartmentComparison $run The comparison being built.
	 * @param string $month The month, `YYYY-MM`.
	 *
	 * @return void
	 */
	private function addMonthCosts(DepartmentComparison $run, string $month): void {
		[$shares, $placed] = $this->sharesOn(run: $run, month: $month);
		foreach ($shares as $index => $unitShares) {
			$weights = [];
			foreach ($unitShares as $employeeId => $share) {
				$weights[$employeeId] = ($share / max(1.0, $placed[$employeeId]));
			}

			$cost = $this->figures->wageCost($run->data['PayrollRun'], $run->data['Payslip'], $weights, $month);
			if ($cost !== null) {
				$run->costs[$index] = round((($run->costs[$index] ?? 0.0) + $cost), 2);
			}
		}
	}//end addMonthCosts()

	/**
	 * Each compared unit's placement shares in a month, and each person's total share over all of them.
	 *
	 * @param DepartmentComparison $run The comparison being built.
	 * @param string $month The month, `YYYY-MM`.
	 *
	 * @return array{0: array<int, array<string, float>>, 1: array<string, float>}
	 */
	private function sharesOn(DepartmentComparison $run, string $month): array {
		$shares = [];
		$placed = [];
		$start = new DateTimeImmutable($month . '-01');
		$end = $start->modify('last day of this month');
		foreach ($run->rows as $index => $row) {
			$subtree = $this->membership->subtree((string)$row['id'], $run->data['OrgUnit']);
			$shares[$index] = $this->membership->sharesInWindow($subtree, $run->data['OrgAssignment'], $start, $end);
			foreach ($shares[$index] as $employeeId => $share) {
				$placed[$employeeId] = (($placed[$employeeId] ?? 0.0) + $share);
			}
		}

		return [$shares, $placed];
	}//end sharesOn()
}//end class
