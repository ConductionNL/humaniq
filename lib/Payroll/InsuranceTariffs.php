<?php

/**
 * Insurance Tariffs
 *
 * The employee insurance side of one calculation: the Awf and Aof tariffs, the
 * Whk percentage, and whether the employee is insured at all.
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
final class InsuranceTariffs {

	/**
	 * @param string $awfTariff `low` or `high` (from the covering EmploymentContract).
	 * @param string $aofTariff `laag` or `hoog` (employer-level config).
	 * @param float $whkPercentage The employer's Whk percentage (percentage scale, e.g. `1.52`).
	 * @param bool $verzekeringsplichtig Whether the employee is verzekeringsplichtig for the werknemersverzekeringen (dga-payroll-mode). `false` for a DGA — zeroes Awf/Aof/Wko/Whk; every other component is unaffected.
	 * @param string $zvwMode `werkgeversheffing` (the employer's levy, default) or `inhouding` (the contribution withheld from net pay, payroll-special-regimes D3).
	 *
	 * @spec openspec/changes/payroll-special-regimes
	 */
	public function __construct(
		public readonly string $awfTariff,
		public readonly string $aofTariff,
		public readonly float $whkPercentage,
		public readonly bool $verzekeringsplichtig = true,
		public readonly string $zvwMode = 'werkgeversheffing',
	) {

	}//end __construct()
}//end class
