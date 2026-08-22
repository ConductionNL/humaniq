<?php

/**
 * Calculation Result
 *
 * The pure-value output of `PayrollCalculator::calculate()` (design.md D2):
 * the full component breakdown of one gross-to-net calculation, all money in
 * integer cents. Carries every figure `PayrollRunService` stamps onto a
 * Payslip plus the intermediate Awf/Aof/Wko/Whk lines the balancing-invariant
 * test cross-checks (design.md D8) even though only their sum
 * (`werknemersverzekeringenCents`) is a Payslip field.
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * Immutable output of one gross-to-net calculation, integer cents throughout.
 */
final class CalculationResult {

	/**
	 * @param int $grossPayCents The gross wage for the period (`tvl`, echoed).
	 * @param int $loonheffingCents Combined loonheffing withheld (`x`).
	 * @param int $arbeidskortingCents Applied arbeidskorting tijdvakbedrag.
	 * @param int $volksverzekeringenCents Informative volksverzekeringen split.
	 * @param int $zvwCents Zvw contribution for the period.
	 * @param string $zvwMode `werkgeversheffing` (MVP; `inhouding` never emitted).
	 * @param float $zvwRate Applied Zvw rate percentage.
	 * @param float $appliedTaxRate Effective loonheffing rate percentage.
	 * @param int $nettoPayCents Net wage paid to the employee.
	 * @param int $vakantiegeldReservedCents Vakantiebijslag reserved this period.
	 * @param float $vakantiegeldRate Applied vakantiebijslag rate percentage.
	 * @param int $awfCents Awf employer charge (informative line).
	 * @param int $aofCents Aof employer charge (informative line).
	 * @param int $wkoCents Wko opslag employer charge (informative line).
	 * @param int $whkCents Whk employer charge (informative line).
	 * @param int $werknemersverzekeringenCents `awf+aof+wko+whk` (the Payslip field).
	 * @param int $employerChargesCents `werknemersverzekeringen + zvw`.
	 * @param bool $aboveLmax Whether the tabelloon exceeded the tables' `Lmax` ceiling (documented edge, design.md D2 step 3).
	 *
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
	 */
	public function __construct(
		public readonly int $grossPayCents,
		public readonly int $loonheffingCents,
		public readonly int $arbeidskortingCents,
		public readonly int $volksverzekeringenCents,
		public readonly int $zvwCents,
		public readonly string $zvwMode,
		public readonly float $zvwRate,
		public readonly float $appliedTaxRate,
		public readonly int $nettoPayCents,
		public readonly int $vakantiegeldReservedCents,
		public readonly float $vakantiegeldRate,
		public readonly int $awfCents,
		public readonly int $aofCents,
		public readonly int $wkoCents,
		public readonly int $whkCents,
		public readonly int $werknemersverzekeringenCents,
		public readonly int $employerChargesCents,
		public readonly bool $aboveLmax,
	) {

	}//end __construct()

}//end class
