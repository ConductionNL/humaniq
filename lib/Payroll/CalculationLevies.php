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
	 */
	public function __construct(
		public readonly WageTaxElection $wageTax,
		public readonly InsuranceTariffs $insurance,
	) {

	}//end __construct()
}//end class
