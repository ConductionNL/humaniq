<?php

/**
 * NL DGA Gebruikelijkloon Check Provider
 *
 * Executable check for the dga-payroll-mode corpus rule
 * (lib/Standards/rules/payroll.json) -- unenforced until this provider
 * registered its predicate (design.md D5):
 *
 * - `nl-gebruikelijkloon-norm` (Employee): vacuous for a non-DGA employee;
 *   vacuous when a non-empty `gebruikelijkloonJustification` is on file (MVP:
 *   presence-only exemption, content validation is a named follow-up);
 *   vacuous when `grossMonthlySalary` is absent/non-numeric; else satisfied
 *   only when the annualised salary (`grossMonthlySalary x 12`) is at least
 *   the loaded tables' `gebruikelijkloon().jaarnormCents` (Wet LB 1964
 *   art. 12a, EUR 58.000/jaar in 2026).
 *
 * This provider does NOT implement SeedsObjects: dga-payroll-mode ships no
 * seed DGA Employee (design.md D5) -- `seedSpec()` returns [] since the
 * existing seeded Employee (`isDga` absent -> `false` default) stays
 * vacuously compliant with no seed backfill needed.
 *
 * @category Standards
 * @package  OCA\Hrmq\Standards\Checks
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
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Payroll\TaxTables;

/**
 * The gebruikelijkloon-norm self-check: a DGA's annualised salary vs the
 * sourced 2026 floor.
 */
final class NlDgaChecks implements CheckProvider
{


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     *
     * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
     */
    public static function checks(): array
    {
        return [
            'Employee' => [
                'nl-gebruikelijkloon-norm' => static fn(array $o): bool => self::meetsGebruikelijkloonNorm($o),
            ],
        ];

    }//end checks()


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, mixed>>
     *
     * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
     */
    public static function seedSpec(): array
    {
        return [];

    }//end seedSpec()


    /**
     * The `nl-gebruikelijkloon-norm` predicate (spec.md REQ-DGA-004): vacuous
     * for a non-DGA employee, vacuous when justified, vacuous when there is
     * no salary to evaluate yet -- else the annualised
     * `grossMonthlySalary x 12` must reach the loaded tables'
     * gebruikelijkloon norm.
     *
     * single-person-modes (REQ-SPM-006/D5): exposed `public` so the
     * self-service `PayrollController::dgaStatus()` endpoint can REUSE this
     * exact predicate for its `met` verdict -- the ONE new caller. The norm
     * comparison is never reimplemented; only its invocation surface is new.
     * The predicate itself is unchanged.
     *
     * @param array<string, mixed> $o The Employee object.
     *
     * @return bool
     *
     * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-004
     * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-006
     */
    public static function meetsGebruikelijkloonNorm(array $o): bool
    {
        if (($o['isDga'] ?? false) !== true) {
            // Vacuous: not a DGA, the norm does not apply.
            return true;
        }

        if (trim((string) ($o['gebruikelijkloonJustification'] ?? '')) !== '') {
            // MVP: presence-only exemption (Non-Goal: content validation).
            return true;
        }

        $grossMonthly = ($o['grossMonthlySalary'] ?? null);
        if (is_numeric($grossMonthly) === false) {
            // No salary to evaluate yet -- the hourly/no-salary path is out
            // of scope; never a false violation.
            return true;
        }

        $annualCents = (int) round(((float) $grossMonthly) * 12 * 100);

        $ids = TaxTables::availableIds();
        if ($ids === []) {
            // Defensive: no table loaded, never hit outside a broken install.
            return true;
        }

        $norm = TaxTables::load(max($ids))->gebruikelijkloon()['jaarnormCents'];

        return $annualCents >= $norm;

    }//end meetsGebruikelijkloonNorm()


}//end class
