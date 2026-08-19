<?php

/**
 * Percentile
 *
 * The pure percentile-over-an-array half of the approval-lead-time
 * calculation, split out of {@see AnalyticsService} for the same reason
 * {@see AbsenceProgression} was split out of {@see AbsenceRateService}: one
 * job per class keeps each one under the fleet's phpmd complexity
 * threshold, and a stateless, side-effect-free helper is reachable from a
 * unit test without a Nextcloud bootstrap or an ObjectService double.
 *
 * WHY A PERCENTILE, NOT A MEAN
 * -----------------------------
 * REQ-DSI-007 (orchestrator-revised 2026-08-19): OpenRegister's `dataSource`
 * aggregation has no `MEDIAN` metric, but the approval-lead-time widget does
 * not use that path — its durations are already an array in PHP by the time
 * this class sees them, so a percentile is a sort and an index. Linear
 * interpolation between the two nearest ranks (the same method R's default
 * `quantile(type = 7)` and NumPy's default use) is applied rather than a
 * nearest-rank method, because nearest-rank rounds p90 down to the bulk of
 * the population on a small, heavily-skewed sample (nine records at 2 days
 * plus one at 200: nearest-rank's p90 lands on the 9th of 10 sorted values —
 * still 2 — which would make the widget's whole reason for existing invisible
 * on the exact shape of data it exists to surface).
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

/**
 * Percentile-over-a-sorted-array calculator. Instantiated (never called
 * statically) so `AnalyticsService` can hold it as an injected collaborator
 * — the `AbsenceRateService` / `AbsenceProgression` DI shape, and the
 * fleet's phpmd `StaticAccess` rule's own preferred fix (instance calls,
 * not `Foo::bar()`).
 *
 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-007
 */
final class Percentile {

	/**
	 * The `$rank`th percentile of `$sortedValues` via linear interpolation
	 * between the two nearest ranks. Returns `null` for an empty population
	 * — the `AbsenceRateService` precedent: a metric with nothing to
	 * measure reports no figure, never `0`.
	 *
	 * @param array<int, float> $sortedValues Values, ALREADY sorted ascending — this method does not sort.
	 * @param float $rank Percentile rank, 0-100.
	 *
	 * @return float|null The percentile value, rounded to 2 decimals, or null when `$sortedValues` is empty.
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-007
	 */
	public function value(array $sortedValues, float $rank): ?float {
		$count = count($sortedValues);
		if ($count === 0) {
			return null;
		}

		if ($count === 1) {
			return round($sortedValues[0], 2);
		}

		$index = (($rank / 100.0) * ($count - 1));
		$lower = (int)floor($index);
		$upper = (int)ceil($index);
		if ($lower === $upper) {
			return round($sortedValues[$lower], 2);
		}

		$fraction = ($index - $lower);
		$interpolated = ($sortedValues[$lower] + ($fraction * ($sortedValues[$upper] - $sortedValues[$lower])));
		return round($interpolated, 2);
	}//end value()

}//end class
