<?php

/**
 * SeriesLatest
 *
 * The "most recent bucket of a trend series" half of the Dashboard's KPI
 * row, split out of {@see AnalyticsService} for the same reason
 * {@see Percentile} was: one job per class keeps each one under the fleet's
 * phpmd complexity threshold (AnalyticsService sits at the 50 ceiling, and
 * folding this in took it to 53), and a stateless, side-effect-free helper
 * is reachable from a unit test without a Nextcloud bootstrap or an
 * ObjectService double.
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Reduces a bucketed trend series to the two scalars a KPI tile needs.
 * Instantiated (never called statically) so `AnalyticsService` can hold it
 * as an injected collaborator — the `Percentile` DI shape, and the fleet's
 * phpmd `StaticAccess` rule's own preferred fix.
 *
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
 */
final class SeriesLatest {

	/**
	 * The two most recent buckets of a series, as scalars, so the Dashboard's
	 * KPI tiles can read a headline number from the SAME guarded endpoint the
	 * trend charts already call — no second endpoint, and no second place
	 * where the tenant scoping could be got wrong.
	 *
	 * A null bucket stays null and is NOT skipped over in favour of an older
	 * one that does have a number. Every series in {@see AnalyticsService}
	 * emits null for "no measurement ran in this period" precisely so that it
	 * cannot be mistaken for a good reading; silently reaching back to the
	 * last populated month would reinstate exactly that confusion, and would
	 * do it invisibly, since the tile has nowhere to say which month it is
	 * showing.
	 *
	 * `approval-lead-time` carries its headline under `median` rather than
	 * `value` (it also emits a `p90`), so the key is taken from the series
	 * itself rather than assumed.
	 *
	 * @param array<int, array<string, mixed>> $series The bucketed series, oldest first.
	 *
	 * @return array{date: string|null, value: float|null, previous: float|null}
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	public function fromSeries(array $series): array {
		$count = count($series);
		if ($count === 0) {
			return ['date' => null, 'value' => null, 'previous' => null];
		}

		$last = $series[($count - 1)];
		$key = array_key_exists('median', $last) === true ? 'median' : 'value';

		$previous = null;
		if ($count >= 2) {
			$previous = ($series[($count - 2)][$key] ?? null);
		}

		return [
			'date' => ($last['date'] ?? null),
			'value' => ($last[$key] ?? null),
			'previous' => $previous,
		];
	}//end fromSeries()

}//end class
