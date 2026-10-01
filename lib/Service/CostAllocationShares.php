<?php

/**
 * Cost Allocation Shares
 *
 * The pure arithmetic of payroll-cost-allocation: the shares of a fixed
 * split, the shares of the hours booked, and a cent amount split by weights
 * with the remainder on the largest share (design D2, D3).
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Shares and cent splits of a cost allocation.
 *
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */
class CostAllocationShares {

	/**
	 * The shares of a fixed split.
	 *
	 * @param array<string, mixed> $allocation The allocation.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function fixedShares(array $allocation): array {
		$shares = [];
		foreach ((array)($allocation['splits'] ?? []) as $split) {
			$costCenter = trim((string)($split['costCenter'] ?? ''));
			if ($costCenter === '' || is_numeric($split['percentage'] ?? null) === false) {
				continue;
			}

			$shares[] = ['costCenter' => $costCenter, 'projectId' => $this->codeOrNull($split['projectId'] ?? null), 'percentage' => (float)$split['percentage'], 'allocationSource' => 'fixed'];
		}

		return $shares;
	}//end fixedShares()

	/**
	 * The shares of the hours booked, per cost centre and project, in the
	 * order they first appear.
	 *
	 * @param list<array<string, mixed>> $entries            The approved entries.
	 * @param string|null                $fallbackCostCenter The placement's cost centre for entries without one.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function hoursShares(array $entries, ?string $fallbackCostCenter): array {
		$hours = [];
		$keys = [];
		foreach ($entries as $entry) {
			$value = (is_numeric($entry['hours'] ?? null) === true ? (float)$entry['hours'] : 0.0);
			if ($value <= 0.0) {
				continue;
			}

			$costCenter = ($this->codeOrNull($entry['costCenter'] ?? null) ?? $fallbackCostCenter);
			$projectId = $this->codeOrNull($entry['projectId'] ?? null);
			$key = ($costCenter ?? '') . '|' . ($projectId ?? '');
			$keys[$key] = [$costCenter, $projectId];
			$hours[$key] = (($hours[$key] ?? 0.0) + $value);
		}

		$total = array_sum($hours);
		if ($total <= 0.0) {
			return [];
		}

		$shares = [];
		foreach ($hours as $key => $value) {
			$shares[] = ['costCenter' => $keys[$key][0], 'projectId' => $keys[$key][1], 'percentage' => round(($value / $total * 100), 2), 'allocationSource' => 'hours'];
		}

		return $shares;
	}//end hoursShares()

	/**
	 * Split an amount in cents by weights; the remainder goes to the
	 * largest weight.
	 *
	 * @param int         $total   The amount, in cents.
	 * @param list<float> $weights The weights.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function splitCents(int $total, array $weights): array {
		$sum = array_sum($weights);
		if ($sum <= 0.0) {
			$weights = array_fill(0, count($weights), 1.0);
			$sum = (float)count($weights);
		}

		$parts = array_map(static fn (float $weight): int => (int)round($total * $weight / $sum), $weights);
		$largest = array_keys($weights, max($weights), true)[0];
		$parts[$largest] += ($total - array_sum($parts));

		return $parts;
	}//end splitCents()

	/**
	 * A trimmed code, or null when empty.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public function codeOrNull(mixed $value): ?string {
		$code = trim((string)($value ?? ''));
		return ($code === '' ? null : $code);
	}//end codeOrNull()

}//end class
