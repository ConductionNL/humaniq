<?php

/**
 * NL Payroll Engine Check Provider
 *
 * Executable checks for the two payroll-core engine-contract rules the
 * `payroll-core-schema` chain head added to the corpus
 * (lib/Standards/rules/payroll.json) — unenforced until this provider
 * registered their predicates (payroll-core-engine design.md D7):
 *
 * - `nl-engine-table-version` (PayrollRun): vacuous when `engineVersion` is
 *   null (hand-entered runs stay out of scope); else requires `calculatedAt`
 *   present AND the referenced versioned table file
 *   `lib/Standards/tables/{engineVersion}.json` to exist. The available table
 *   ids are globbed ONCE (memoised in `TaxTables::availableIds()`) — no
 *   per-object IO.
 * - `nl-engine-output-consistency` (Payslip): vacuous when `payrollRunId` is
 *   null or the referenced run — resolved via the `payroll.runsById` audit
 *   context `RuleAuditService::audit()` enriches (the glpost context
 *   precedent) — carries no `engineVersion`; else asserts the cents-exact net
 *   equation `nettoPay = grossPay - loonheffing - pensionContribution
 *   (null->0) - (zvw if zvwMode = inhouding)` (NlPayrollChecks::centsEqual
 *   semantics).
 *
 * This provider does NOT implement SeedsObjects: the pre-existing seeded
 * run/payslip stay hand-entered (null engineVersion/payrollRunId) and vacuous
 * under both predicates — the golden fixtures are this change's canonical
 * data (design.md Seed Data).
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Payroll\TaxTables;

/**
 * Payroll-engine traceability + output-consistency executable checks.
 */
final class NlEngineChecks implements CheckProvider
{


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
     */
    public static function checks(): array
    {
        return [
            'PayrollRun' => [
                'nl-engine-table-version'       => static fn(array $o): bool => self::hasValidTableVersion($o),
            ],
            'Payslip'    => [
                'nl-engine-output-consistency'  => static fn(array $o, array $context): bool => self::isOutputConsistent($o, $context),
            ],
        ];

    }//end checks()


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, mixed>>
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
     */
    public static function seedSpec(): array
    {
        return [];

    }//end seedSpec()


    /**
     * The `nl-engine-table-version` predicate (spec.md REQ-PCE-007): a run
     * that carries `engineVersion` must carry `calculatedAt` and reference an
     * existing versioned table file; hand-entered runs (null engineVersion)
     * are vacuously compliant.
     *
     * @param array<string, mixed> $o The PayrollRun object.
     *
     * @return bool
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
     */
    private static function hasValidTableVersion(array $o): bool
    {
        $engineVersion = trim((string) ($o['engineVersion'] ?? ''));
        if ($engineVersion === '') {
            // Hand-entered run — out of scope (vacuous pass).
            return true;
        }

        if (trim((string) ($o['calculatedAt'] ?? '')) === '') {
            return false;
        }

        return in_array($engineVersion, TaxTables::availableIds(), true);

    }//end hasValidTableVersion()


    /**
     * The `nl-engine-output-consistency` predicate (spec.md REQ-PCE-007):
     * on an engine-produced payslip (its run carries `engineVersion`) the net
     * wage reconciles cents-exact to `grossPay - loonheffing -
     * pensionContribution(null->0) - (zvw if zvwMode = inhouding)`; employer-
     * borne charges never reduce net. Vacuous when `payrollRunId` is null or
     * the referenced run is unresolvable/hand-entered.
     *
     * @param array<string, mixed> $o       The Payslip object.
     * @param array<string, mixed> $context Evaluation context; reads `payroll.runsById`.
     *
     * @return bool
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
     */
    private static function isOutputConsistent(array $o, array $context): bool
    {
        $runId = trim((string) ($o['payrollRunId'] ?? ''));
        if ($runId === '') {
            // Hand-entered payslip — out of scope (vacuous pass).
            return true;
        }

        $run = ($context['payroll']['runsById'][$runId] ?? null);
        if (is_array($run) === false || trim((string) ($run['engineVersion'] ?? '')) === '') {
            // Unresolvable or hand-entered run — out of scope (vacuous pass).
            return true;
        }

        if (self::numeric($o, 'grossPay') === false
            || self::numeric($o, 'loonheffing') === false
            || self::numeric($o, 'nettoPay') === false
        ) {
            return false;
        }

        $pension = is_numeric($o['pensionContribution'] ?? null) === true ? (float) $o['pensionContribution'] : 0.0;
        $zvw     = 0.0;
        if ((string) ($o['zvwMode'] ?? '') === 'inhouding' && is_numeric($o['zvw'] ?? null) === true) {
            $zvw = (float) $o['zvw'];
        }

        $expectedNet = (((float) $o['grossPay']) - ((float) $o['loonheffing']) - $pension - $zvw);

        return self::centsEqual((float) $o['nettoPay'], $expectedNet);

    }//end isOutputConsistent()


    /**
     * True when an object field holds a present, numeric value.
     *
     * @param array<string, mixed> $o   Object.
     * @param string               $key Field.
     *
     * @return bool
     */
    private static function numeric(array $o, string $key): bool
    {
        return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;

    }//end numeric()


    /**
     * Compare two amounts at cent precision (avoids float-equality issues).
     *
     * @param float $a Left amount.
     * @param float $b Right amount.
     *
     * @return bool
     */
    private static function centsEqual(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);

    }//end centsEqual()


}//end class
