<?php

/**
 * Contract of SeriesLatest
 *
 * The public surface of SeriesLatest that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of SeriesLatest.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SeriesLatestInterface {
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
	public function fromSeries(array $series): array;
}//end interface
