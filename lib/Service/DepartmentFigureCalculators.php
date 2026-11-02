<?php

/**
 * Department Figure Calculators
 *
 * The calculators the department figures are computed with: frequency and
 * wage cost per unit, the FTE-weighted absence rate, and the month windows.
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

/**
 * The calculators behind {@see DepartmentFiguresService}.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
final class DepartmentFigureCalculators {

	/**
	 * @param DepartmentFigures $figures Frequency and wage cost.
	 * @param AbsenceRateService $absenceRate The FTE-weighted absence rate.
	 * @param TrendPeriods $periods The trailing month windows.
	 */
	public function __construct(
		public readonly DepartmentFigures $figures,
		public readonly AbsenceRateService $absenceRate,
		public readonly TrendPeriods $periods = new TrendPeriods(),
	) {

	}//end __construct()
}//end class
