<?php

/**
 * Unit Trend Sources
 *
 * What one org unit's trend series is computed from: the month buckets, the
 * administration's rows for the metric, and the callbacks that load a schema
 * and compute a series.
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
 * @spec openspec/specs/department-figures/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Closure;

/**
 * The inputs of {@see UnitTrends::series()}.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
final class UnitTrendSources {

	/**
	 * @param array<int, string> $periodKeys `YYYY-MM` buckets.
	 * @param array<string, array<int, array<string, mixed>>> $rows The metric's rows for the administration.
	 * @param Closure(string): array<int, array<string, mixed>> $load Loads a schema's rows for the administration.
	 * @param Closure(array<int, string>, array<string, array<int, array<string, mixed>>>, array<string, float>): array<int, array<string, mixed>> $seriesFor Computes the metric's series for some buckets, rows and shares.
	 */
	public function __construct(
		public readonly array $periodKeys,
		public readonly array $rows,
		public readonly Closure $load,
		public readonly Closure $seriesFor,
	) {

	}//end __construct()
}//end class
