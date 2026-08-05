<?php

/**
 * NL Payroll GL-Post Check Provider
 *
 * Executable check for the payroll-to-GL posting idempotency rule
 * (`nl-glpost-idempotent-per-run`, lib/Standards/rules/payroll.json,
 * payroll-glpost-shillinq), mapped onto the `PayrollGLPost` object type.
 *
 * The predicate is cross-object: it reads the `context['glpost']
 * ['activeCountByRun']` index `RuleAuditService::audit()` populates in its
 * pre-pass (a payrollRunId => active-record-count map) rather than loading
 * sibling rows itself. An active (`pending`/`posted`) record violates when
 * its run's active count exceeds 1; a `posted` record additionally violates
 * unless it carries a `journalEntryId` + `postedAt` and its `lines` snapshot
 * balances cents-equal. `failed`/`skipped-no-shillinq` attempts are always
 * compliant — they are superseded-by-retry outcomes, never a violation
 * (design.md D6).
 *
 * This provider does NOT implement SeedsObjects: the seed PayrollGLPost lives
 * declaratively in `lib/Settings/register.d/hr-glpost.json` (ADR-001), the
 * same pattern NlPensionFilingChecks documents for cross-object predicates
 * whose sample would otherwise need a resolvable sibling reference.
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
 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Payroll-to-GL posting idempotency executable check.
 */
final class NlGlPostChecks implements CheckProvider
{


    /**
     * PayrollGLPost statuses that count toward the at-most-one-per-run
     * invariant.
     *
     * @var string[]
     */
    private const ACTIVE_STATUSES = ['pending', 'posted'];


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'PayrollGLPost' => [
                'nl-glpost-idempotent-per-run' => static fn(array $o, array $context): bool => self::isCompliant($o, $context),
            ],
        ];

    }//end checks()


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, mixed>>
     */
    public static function seedSpec(): array
    {
        return [];

    }//end seedSpec()


    /**
     * The `nl-glpost-idempotent-per-run` predicate (spec.md REQ-PGP-007).
     *
     * @param array<string, mixed> $o       The PayrollGLPost object.
     * @param array<string, mixed> $context Evaluation context; reads `glpost.activeCountByRun`.
     *
     * @return bool
     */
    private static function isCompliant(array $o, array $context): bool
    {
        $status = (string) ($o['status'] ?? '');
        if (in_array($status, self::ACTIVE_STATUSES, true) === false) {
            // failed / skipped-no-shillinq attempts are superseded, never a violation.
            return true;
        }

        $runId       = (string) ($o['payrollRunId'] ?? '');
        $activeCount = (int) ($context['glpost']['activeCountByRun'][$runId] ?? 0);
        if ($activeCount > 1) {
            return false;
        }

        if ($status !== 'posted') {
            return true;
        }

        if (self::present($o, 'journalEntryId') === false || self::present($o, 'postedAt') === false) {
            return false;
        }

        return self::linesBalance($o['lines'] ?? []);

    }//end isCompliant()


    /**
     * True when a posted record's `lines` snapshot is non-empty, well-shaped,
     * and its debit total equals its credit total to the cent.
     *
     * @param mixed $lines The `lines` field value.
     *
     * @return bool
     */
    private static function linesBalance(mixed $lines): bool
    {
        if (is_array($lines) === false || $lines === []) {
            return false;
        }

        $debitCents  = 0;
        $creditCents = 0;
        foreach ($lines as $line) {
            if (is_array($line) === false
                || isset($line['side'], $line['amount']) === false
                || is_numeric($line['amount']) === false
            ) {
                return false;
            }

            if ($line['side'] !== 'debit' && $line['side'] !== 'credit') {
                return false;
            }

            $cents = (int) round(((float) $line['amount']) * 100);
            if ($line['side'] === 'debit') {
                $debitCents += $cents;
            }

            if ($line['side'] === 'credit') {
                $creditCents += $cents;
            }
        }

        return $debitCents === $creditCents && $debitCents > 0;

    }//end linesBalance()


    /**
     * True when an object field holds a non-empty value.
     *
     * @param array<string, mixed> $o   Object.
     * @param string               $key Field.
     *
     * @return bool
     */
    private static function present(array $o, string $key): bool
    {
        return isset($o[$key]) === true && trim((string) $o[$key]) !== '';

    }//end present()


}//end class
