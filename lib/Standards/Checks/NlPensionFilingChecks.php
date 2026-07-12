<?php

/**
 * NL Pension Filing (UPA) Check Provider
 *
 * Executable checks for the Dutch sector-pension filing rules of the payroll
 * corpus (lib/Standards/rules/payroll.json, framework nl-pensioenaangifte),
 * mapped onto the PensionFiling / PayrollRun object types (pension-filing-upa-mvp):
 * a delivery must reference an approved-or-later PayrollRun
 * (nl-upa-payrollrun-approved), every period with an approved run must have
 * at least one delivery (nl-upa-monthly-completeness), and an unsent delivery
 * must not be overdue or within 14 days of its deadline
 * (nl-upa-deadline-alert).
 *
 * The reference-integrity and completeness predicates are cross-object: they
 * read the `context['related']` index RuleAuditService::audit() populates in
 * its pre-pass (PayrollRun `{id, period, status}` by id + approved-run period
 * set; PensionFiling period set) rather than loading siblings themselves.
 * This provider does NOT implement SeedsObjects: a self-contained sample
 * cannot carry a resolvable `payrollRunId`, and a dangling reference would
 * immediately violate the fail-closed nl-upa-payrollrun-approved rule — the
 * PayrollRun + PensionFiling seed data instead lives in
 * lib/Settings/register.d/hr-seed.json (ADR-001).
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
 * @spec openspec/changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Dutch sector-pension (UPA) filing executable checks.
 */
final class NlPensionFilingChecks implements CheckProvider
{

    /**
     * PayrollRun statuses that count as "approved or later" for both the
     * reference-integrity and monthly-completeness predicates.
     *
     * @var string[]
     */
    private const APPROVED_OR_LATER = ['approved', 'posted', 'paid'];

    /**
     * Number of days before (and including) the deadline that an unsent
     * filing starts alerting (nl-upa-deadline-alert).
     *
     * @var int
     */
    private const ALERT_WINDOW_DAYS = 14;


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'PensionFiling' => [
                // UPA-specificatie (SIVI) / fund delivery terms — the delivery reports
                // approved salary data, so its PayrollRun must be approved or later.
                'nl-upa-payrollrun-approved' => static fn(array $o, array $c): bool => self::payrollRunApproved($o, $c),
                // Fund aanleverschema / uitvoeringsreglement — an unsent delivery does not
                // sit within 14 days of, or past, its deadline.
                'nl-upa-deadline-alert'      => static fn(array $o, array $c): bool => self::deadlineNotAlerting($o),
            ],
            // Additive on PayrollRun: NlPayrollChecks already registers
            // xc-payroll-gl-reconciliation / xc-withholding-liability-clearing here;
            // RuleEngine merges providers per object type, never overwrites.
            'PayrollRun'    => [
                // APG aanleververplichting (UPA, monthly delivery) — every period with an
                // approved-or-later NL run must have at least one PensionFiling.
                'nl-upa-monthly-completeness' => static fn(array $o, array $c): bool => self::monthlyCompletenessSatisfied($o, $c),
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
     * True when the filing's `payrollRunId` resolves in the context's
     * PayrollRun index to a run whose status is approved/posted/paid.
     * Fail-closed: an empty or dangling reference, or a run in any other
     * status, is a violation.
     *
     * @param array<string, mixed> $o The PensionFiling.
     * @param array<string, mixed> $c Evaluation context (carries `related`).
     *
     * @return bool
     */
    private static function payrollRunApproved(array $o, array $c): bool
    {
        $payrollRunId = trim((string) ($o['payrollRunId'] ?? ''));
        if ($payrollRunId === '') {
            return false;
        }

        $byId = self::relatedPayrollRunsById($c);
        $run  = ($byId[$payrollRunId] ?? null);
        if (is_array($run) === false) {
            return false;
        }

        return in_array((string) ($run['status'] ?? ''), self::APPROVED_OR_LATER, true);

    }//end payrollRunApproved()


    /**
     * True when this NL PayrollRun is not (yet) approved-or-later, or its
     * period has at least one PensionFiling in the context's period set
     * (MVP fund-blind check — the full per-configured-fund obligation is
     * recorded in the rule statement, not enforced field-by-field here).
     *
     * @param array<string, mixed> $o The PayrollRun.
     * @param array<string, mixed> $c Evaluation context (carries `related`).
     *
     * @return bool
     */
    private static function monthlyCompletenessSatisfied(array $o, array $c): bool
    {
        if (strtoupper((string) ($o['jurisdiction'] ?? '')) !== 'NL') {
            return true;
        }

        if (in_array((string) ($o['status'] ?? ''), self::APPROVED_OR_LATER, true) === false) {
            return true;
        }

        $period = (string) ($o['period'] ?? '');
        if ($period === '') {
            return true;
        }

        $filedPeriods = (array) ($c['related']['PensionFiling']['filedPeriods'] ?? []);
        return in_array($period, $filedPeriods, true);

    }//end monthlyCompletenessSatisfied()


    /**
     * True when the filing is already sent, or its deadline is more than 14
     * days away — false (a violation) once it is within the alert window or
     * already overdue, evaluated against the current date (the audit run
     * date).
     *
     * @param array<string, mixed> $o The filing.
     *
     * @return bool
     */
    private static function deadlineNotAlerting(array $o): bool
    {
        if ((string) ($o['status'] ?? 'concept') === 'verzonden') {
            return true;
        }

        $deadline = strtotime((string) ($o['deadline'] ?? ''));
        if ($deadline === false) {
            return true;
        }

        $today         = (new \DateTimeImmutable('today'))->getTimestamp();
        $daysRemaining = (int) floor((($deadline - $today)) / 86400);

        return $daysRemaining > self::ALERT_WINDOW_DAYS;

    }//end deadlineNotAlerting()


    /**
     * The `related.PayrollRun.byId` index from the context, or an empty
     * array when the pre-pass has not populated it.
     *
     * @param array<string, mixed> $c Evaluation context.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function relatedPayrollRunsById(array $c): array
    {
        $byId = ($c['related']['PayrollRun']['byId'] ?? []);
        return is_array($byId) === true ? $byId : [];

    }//end relatedPayrollRunsById()


}//end class
