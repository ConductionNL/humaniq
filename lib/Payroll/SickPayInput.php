<?php

/**
 * Sick Pay Input
 *
 * The pure-value input to `SickPayCalculator::compute()` (design.md D1/D2):
 * the reference wage, the samengesteld/aangepast loon composition, the case's
 * applied percentage, the year-1/year-2 switch, the wachtdag flags, and the
 * part-time inputs the WML floor is scaled by. Zero Nextcloud dependencies —
 * the `CalculationInput` idiom.
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
 * Immutable input to one loondoorbetaling-bij-ziekte calculation.
 */
final class SickPayInput
{


    /**
     * @param int   $referenceWageCents           `W` — the reference wage (the employee's full `grossMonthlySalary`), in integer cents.
     * @param int   $aangepastLoonCents            `A` — the wage still earned from partial work (samengesteld/aangepast loon), in integer cents. `0` when fully sick.
     * @param float $loondoorbetalingPercentage    `p` — the case's `loondoorbetalingPercentage` (statutory minimum 70, CAOs may set 90/100).
     * @param bool  $yearOne                       Whether the run period falls within the first 52 weeks of `firstSickDay`.
     * @param bool  $wachtdag                      Whether the case configures a wachtdag (waiting day).
     * @param bool  $firstSickDayInPeriod          Whether the case's `firstSickDay` falls within the run period (the wachtdag is deducted only once, at case start).
     * @param float $contractHoursPerWeek          The employee's contracted hours per week, for the WML floor's part-time factor.
     * @param float $fulltimeHoursPerWeek          The full-time hours-per-week basis the WML floor's part-time factor is scaled against.
     *
     * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
     */
    public function __construct(
        public readonly int $referenceWageCents,
        public readonly int $aangepastLoonCents,
        public readonly float $loondoorbetalingPercentage,
        public readonly bool $yearOne,
        public readonly bool $wachtdag,
        public readonly bool $firstSickDayInPeriod,
        public readonly float $contractHoursPerWeek,
        public readonly float $fulltimeHoursPerWeek,
    ) {

    }//end __construct()


}//end class
