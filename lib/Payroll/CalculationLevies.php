<?php

/**
 * Calculation Levies
 *
 * What one calculation levies besides the wage itself: the wage tax election
 * and the employee insurance tariffs.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * Part of a {@see CalculationInput}.
 *
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */
final class CalculationLevies {

	/**
	 * @param WageTaxElection $wageTax Table colour, loonheffingskorting and 30%-ruling rate.
	 * @param InsuranceTariffs $insurance Awf and Aof tariffs, Whk percentage and whether the employee is insured.
	 * @param int $rvuThresholdCents The early-retirement final-levy threshold for the period, in cents; 0 when the wage is no early-retirement benefit (payroll-special-regimes D4).
	 * @param float $rvuFinalLevyRate The early-retirement final-levy rate on the percentage scale (0-100), read from the regime's tables leaf.
	 *
	 * @spec openspec/changes/payroll-special-regimes
	 */
	public function __construct(
		public readonly WageTaxElection $wageTax,
		public readonly InsuranceTariffs $insurance,
		public readonly int $rvuThresholdCents = 0,
		public readonly float $rvuFinalLevyRate = 0.0,
	) {

	}//end __construct()
}//end class
