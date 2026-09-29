<?php

/**
 * Humaniq PersonnelBudgetTotals
 *
 * The surcharges and roll-ups of the personnel budget
 * (reporting-personnel-budget-and-scenarios D2): holiday allowance at the
 * BW 7:634 minimum, employer charges at the ratio of the last twelve finalised
 * payroll runs (or the scenario's own percentage), and totals per unit,
 * reference job and cost centre.
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
 * Surcharges and roll-ups of the personnel budget.
 *
 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
 */
class PersonnelBudgetTotals {

	/**
	 * Runs whose totals count as finalised.
	 */
	private const FINAL_RUN_STATES = ['approved', 'posted', 'paid'];

	/**
	 * Constructor.
	 *
	 * @param float $holidayAllowancePercentage The holiday allowance (BW 7:634 minimum, 8%).
	 */
	public function __construct(
		private readonly float $holidayAllowancePercentage = 8.0,
	) {

	}//end __construct()

	/**
	 * The holiday allowance percentage.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
	 */
	public function holidayAllowancePercentage(): float {
		return $this->holidayAllowancePercentage;
	}//end holidayAllowancePercentage()

	/**
	 * Add holiday allowance, employer charges and the total to a line.
	 *
	 * @param array<string, mixed> $line The line.
	 * @param float $chargesRatio Employer charges over gross.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
	 */
	public function withSurcharges(array $line, float $chargesRatio): array {
		$base = ((float)$line['occupiedCost'] + (float)$line['vacantCost']);
		$holiday = ($base * $this->holidayAllowancePercentage / 100.0);
		$charges = (($base + $holiday) * $chargesRatio);
		$line['occupiedCost'] = round((float)$line['occupiedCost'], 2);
		$line['vacantCost'] = round((float)$line['vacantCost'], 2);
		$line['holidayAllowance'] = round($holiday, 2);
		$line['employerCharges'] = round($charges, 2);
		$line['total'] = round(($base + $holiday + $charges), 2);

		return $line;
	}//end withSurcharges()

	/**
	 * Employer charges over gross from the last twelve finalised runs, else the scenario's percentage.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 * @param array<int, array<string, mixed>> $runs The payroll runs.
	 *
	 * @return array{ratio: float, basis: string}
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
	 */
	public function chargesBasis(array $scenario, array $runs): array {
		$administrationId = (string)($scenario['administrationId'] ?? '');
		$final = array_values(
			array_filter(
				$runs,
				static fn (array $run): bool => in_array(($run['status'] ?? ''), self::FINAL_RUN_STATES, true)
					&& ($administrationId === '' || (string)($run['administrationId'] ?? $administrationId) === $administrationId)
			)
		);
		usort($final, static fn (array $left, array $right): int => strcmp((string)($right['period'] ?? ''), (string)($left['period'] ?? '')));
		$final = array_slice($final, 0, 12);
		$gross = array_sum(array_map(static fn (array $run): float => (float)($run['totalGross'] ?? 0), $final));
		if ($gross > 0.0) {
			return ['ratio' => (array_sum(array_map(static fn (array $run): float => (float)($run['totalEmployerCharges'] ?? 0), $final)) / $gross), 'basis' => 'payroll-history'];
		}

		if (is_numeric($scenario['employerChargesPercentage'] ?? null) === true) {
			return ['ratio' => ((float)$scenario['employerChargesPercentage'] / 100.0), 'basis' => 'scenario'];
		}

		return ['ratio' => 0.0, 'basis' => 'none'];
	}//end chargesBasis()

	/**
	 * Sum lines per key, with the unit's budgeted FTE per month.
	 *
	 * @param array<int, array<string, mixed>> $lines The lines.
	 * @param string $key orgUnitId, normfunctieId or costCenter.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
	 */
	public function rollUp(array $lines, string $key): array {
		$out = [];
		foreach ($lines as $line) {
			$group = ($line[$key] !== '') ? (string)$line[$key] : 'none';
			$out[$group] ??= ['name' => (($key === 'orgUnitId') ? $line['unitName'] : $group), 'occupiedCost' => 0.0, 'vacantCost' => 0.0, 'total' => 0.0, 'unpriced' => false, 'fteByMonth' => array_fill(0, 12, 0.0)];
			$out[$group]['occupiedCost'] = round(($out[$group]['occupiedCost'] + $line['occupiedCost']), 2);
			$out[$group]['vacantCost'] = round(($out[$group]['vacantCost'] + $line['vacantCost']), 2);
			$out[$group]['total'] = round(($out[$group]['total'] + $line['total']), 2);
			$out[$group]['unpriced'] = ($out[$group]['unpriced'] || $line['unpriced']);
			foreach ($line['fteByMonth'] as $index => $month) {
				$out[$group]['fteByMonth'][$index] = round(($out[$group]['fteByMonth'][$index] + $month['budgeted']), 4);
			}
		}

		return $out;
	}//end rollUp()

	/**
	 * Merge the per-unit roll-ups of the baseline and two scenarios, with the differences.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $byUnit baseline, a and b, each keyed by unit id.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-002
	 */
	public function compareUnits(array $byUnit): array {
		$ids = [];
		foreach ($byUnit as $rows) {
			foreach (array_keys($rows) as $unitId) {
				$ids[strval($unitId)] = true;
			}
		}

		$units = [];
		foreach (array_keys($ids) as $unitId) {
			$unitId = strval($unitId);
			$unit = ['orgUnitId' => $unitId, 'name' => ''];
			foreach ($byUnit as $which => $rows) {
				$row = ($rows[$unitId] ?? ['name' => '', 'total' => 0.0, 'fteByMonth' => array_fill(0, 12, 0.0)]);
				$unit['name'] = ($unit['name'] !== '' ? $unit['name'] : strval($row['name'] ?? ''));
				$unit[$which] = ['cost' => floatval($row['total'] ?? 0), 'fteByMonth' => ($row['fteByMonth'] ?? [])];
			}

			$unit['diffA'] = ['cost' => round(($unit['a']['cost'] - $unit['baseline']['cost']), 2)];
			$unit['diffB'] = ['cost' => round(($unit['b']['cost'] - $unit['baseline']['cost']), 2)];
			$units[$unitId] = $unit;
		}

		return $units;
	}//end compareUnits()

}//end class
