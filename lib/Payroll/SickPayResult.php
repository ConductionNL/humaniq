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
 * @package  OCA\Hrmq\Payroll
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

namespace OCA\Hrmq\Payroll;

/**
 * Immutable output of one loondoorbetaling-bij-ziekte calculation, integer
 * cents throughout.
 */
final class SickPayResult
{


    /**
     * @param int   $doorbetaaldLoonCents   `L` — the floored doorbetaald loon (before the wachtdag deduction).
     * @param int   $wachtdagDeductionCents `wd` — the wachtdag deduction (`0` when no wachtdag applies).
     * @param int   $payableGrossCents      `L - wd` — fed to `PayrollCalculator` as `grossMonthlySalaryCents`.
     * @param int   $minimumWageFloorCents  `M` — the WML monthly floor (part-time scaled), for stamping + the independent floor recomputation.
     * @param bool  $floorApplied           Whether the statutory/WML floor raised `doorbetaaldLoon` above the pre-floor `L0`.
     * @param float $appliedPercentage      `p` — the applied loondoorbetaling percentage.
     * @param bool  $yearOne                Whether the year-1 WML floor was in scope for this computation.
     * @param int   $referenceWageCents     `W` — the reference wage this computation was run against, echoed for stamping.
     *
     * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
     */
    public function __construct(
        public readonly int $doorbetaaldLoonCents,
        public readonly int $wachtdagDeductionCents,
        public readonly int $payableGrossCents,
        public readonly int $minimumWageFloorCents,
        public readonly bool $floorApplied,
        public readonly float $appliedPercentage,
        public readonly bool $yearOne,
        public readonly int $referenceWageCents,
    ) {

    }//end __construct()


}//end class
