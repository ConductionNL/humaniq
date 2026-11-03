<?php

/**
 * Sick Pay Result
 *
 * The pure-value output of `SickPayCalculator::compute()` (design.md D2): the
 * doorbetaald loon, the wachtdag deduction, the resulting payable gross fed
 * to `PayrollCalculator`, and the figures `PayrollRunService` stamps onto the
 * Payslip plus the ones the independent `nl-loondoorbetaling-floor` check
 * recomputation reads back (design.md D5). All money in integer cents.
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
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * Immutable output of one loondoorbetaling-bij-ziekte calculation, integer
 * cents throughout.
 */
final class SickPayResult {

	/**
	 * `L` — the floored doorbetaald loon (before the wachtdag deduction).
	 *
	 * @var integer
	 */
	public readonly int $doorbetaaldLoonCents;

	/**
	 * `wd` — the wachtdag deduction (`0` when no wachtdag applies).
	 *
	 * @var integer
	 */
	public readonly int $wachtdagDeductionCents;

	/**
	 * `L - wd` — fed to `PayrollCalculator` as `grossMonthlySalaryCents`.
	 *
	 * @var integer
	 */
	public readonly int $payableGrossCents;

	/**
	 * `M` — the WML monthly floor (part-time scaled), for stamping + the independent floor recomputation.
	 *
	 * @var integer
	 */
	public readonly int $minimumWageFloorCents;

	/**
	 * Whether the statutory/WML floor raised `doorbetaaldLoon` above the pre-floor `L0`.
	 *
	 * @var boolean
	 */
	public readonly bool $floorApplied;

	/**
	 * `p` — the applied loondoorbetaling percentage.
	 *
	 * @var float
	 */
	public readonly float $appliedPercentage;

	/**
	 * Whether the year-1 WML floor was in scope for this computation.
	 *
	 * @var boolean
	 */
	public readonly bool $yearOne;

	/**
	 * `W` — the reference wage this computation was run against, echoed for stamping.
	 *
	 * @var integer
	 */
	public readonly int $referenceWageCents;

	/**
	 * The fields stay flat and in this order: readers and the serialised
	 * Payslip fields address them directly.
	 *
	 * @param SickPayAmounts $amounts The doorbetaald loon, wachtdag deduction, payable gross and WML floor.
	 * @param SickPayBasis $basis Whether the floor applied, the percentage, the year-one scope and the reference wage.
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
	 */
	public function __construct(SickPayAmounts $amounts, SickPayBasis $basis) {
		$this->doorbetaaldLoonCents = $amounts->doorbetaaldLoonCents;
		$this->wachtdagDeductionCents = $amounts->wachtdagDeductionCents;
		$this->payableGrossCents = $amounts->payableGrossCents;
		$this->minimumWageFloorCents = $amounts->minimumWageFloorCents;
		$this->floorApplied = $basis->floorApplied;
		$this->appliedPercentage = $basis->appliedPercentage;
		$this->yearOne = $basis->yearOne;
		$this->referenceWageCents = $basis->referenceWageCents;

	}//end __construct()

}//end class
