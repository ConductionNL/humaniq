<?php

/**
 * Analytics Calculators
 *
 * The calculators the trend series are computed with: the absence rate, the
 * percentile, the per-unit series, the month windows and the employee
 * timeline.
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

/**
 * The calculators behind {@see AnalyticsService}.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
final class AnalyticsCalculators {

	/**
	 * Places employees on a timeline and counts them per period.
	 *
	 * @var EmployeeTimeline
	 */
	public readonly EmployeeTimeline $timeline;

	/**
	 * @param AbsenceRateService $absenceRate The FTE-weighted verzuimpercentage calculator.
	 * @param Percentile $percentile The median/p90 calculator.
	 * @param UnitTrends $unitTrends A metric for one org unit's people, and the absence frequency.
	 * @param TrendPeriods $periods The trailing month windows and date parsing.
	 */
	public function __construct(
		public readonly AbsenceRateService $absenceRate,
		public readonly Percentile $percentile,
		public readonly UnitTrends $unitTrends = new UnitTrends(),
		public readonly TrendPeriods $periods = new TrendPeriods(),
	) {
		$this->timeline = new EmployeeTimeline();

	}//end __construct()
}//end class
