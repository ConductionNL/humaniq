<?php

/**
 * Calculation Input
 *
 * The pure-value input to `PayrollCalculator::calculate()` (design.md D1):
 * the employee's payroll-relevant fields, the contract's Awf tariff, the wage
 * period, and the employer-level settings the tables file cannot know
 * (Aof classification, Whk percentage). Zero Nextcloud dependencies.
 *
 * `$verzekeringsplichtig` (dga-payroll-mode design.md D1) is additive and
 * defaults to `true`, so every pre-existing named-argument call site is
 * unaffected: `false` (a DGA — director-major-shareholder, not
 * verzekeringsplichtig for the werknemersverzekeringen, Wfsv art. 6 lid 1
 * sub d) gates `PayrollCalculator::calculate()` step 9 to zero
 * Awf/Aof/Wko/Whk while every other component stays computed exactly as
 * for `true`.
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll;

/**
 * Immutable input to one gross-to-net calculation.
 */
final class CalculationInput
{


    /**
     * @param int         $grossMonthlySalaryCents      The fixed monthly gross salary, in integer cents (`tvl`).
     * @param string      $taxTableColor                `wit` or `groen`.
     * @param bool        $loonheffingskortingToegepast Whether the employee elected the loonheffingskorting.
     * @param string|null $dateOfBirth                  ISO-8601 date of birth, or null when unknown (treated as below-AOW).
     * @param string      $period                        Wage period, `YYYY-MM`.
     * @param string      $awfTariff                     `low` or `high` (from the covering EmploymentContract).
     * @param string      $aofTariff                     `laag` or `hoog` (employer-level config).
     * @param float       $whkPercentage                 The employer's Whk percentage (percentage scale, e.g. `1.52`).
     * @param bool        $verzekeringsplichtig          Whether the employee is verzekeringsplichtig for the werknemersverzekeringen (dga-payroll-mode). `false` for a DGA — zeroes Awf/Aof/Wko/Whk; every other component is unaffected.
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
     */
    public function __construct(
        public readonly int $grossMonthlySalaryCents,
        public readonly string $taxTableColor,
        public readonly bool $loonheffingskortingToegepast,
        public readonly ?string $dateOfBirth,
        public readonly string $period,
        public readonly string $awfTariff,
        public readonly string $aofTariff,
        public readonly float $whkPercentage,
        public readonly bool $verzekeringsplichtig=true,
    ) {

    }//end __construct()


}//end class
