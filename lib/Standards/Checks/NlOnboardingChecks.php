<?php

/**
 * NL Onboarding Check Provider
 *
 * Executable checks for the Dutch onboarding rules of the labour/payroll corpus
 * (lib/Standards/rules/labour.json, frameworks nl-wid / bw7-10 / nl-loonheffingen),
 * mapped onto the Onboarding object type (onboarding-wizard-mvp): the WID identity
 * check must be done on or before the first workday (nl-onboarding-wid-check), a
 * proeftijd must respect the BW 7:652 contract-type caps and never be silently
 * outlived (nl-onboarding-proeftijd-bewaking), and the employee's
 * loonheffingenverklaring must be on file before the case is ready for (or past)
 * the first workday (nl-onboarding-loonheffingenverklaring).
 *
 * The proeftijd-cap and loonheffingenverklaring predicates are cross-object: they
 * read the `context['related']['Employee']` and
 * `context['related']['EmploymentContract']` indexes RuleAuditService::audit()
 * populates in its pre-pass (design.md D3), rather than loading siblings
 * themselves. This provider does NOT implement SeedsObjects: a self-contained
 * sample cannot carry a resolvable `employeeId` cross-reference (the pension
 * precedent) — the Onboarding seed data instead lives in
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
 * @spec openspec/changes/onboarding-wizard-mvp/specs/onboarding-wizard/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use DateTimeImmutable;

/**
 * Dutch onboarding (WID / proeftijd / loonheffingenverklaring) executable checks.
 */
final class NlOnboardingChecks implements CheckProvider
{

    /**
     * Onboarding statuses at/past "ready for the first workday" — the MVP proxy
     * for "the first payroll run may already have happened" (design.md D3, no
     * per-employee PayrollRun membership exists in the data model yet).
     *
     * @var string[]
     */
    private const READY_OR_LATER = ['gereed_eerste_werkdag', 'proeftijd_lopend', 'afgerond'];


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'Onboarding' => [
                // Wet op de identificatieplicht art. 15 jo. Wet LB 1964 art. 28 lid 1
                // onder f — the WID check must precede the first workday.
                'nl-onboarding-wid-check'              => static fn(array $o): bool => self::widCheckSatisfied($o),
                // BW art. 7:652 — proeftijd contract-type caps, plus overdue-unclosed.
                'nl-onboarding-proeftijd-bewaking'      => static fn(array $o, array $c): bool => self::proeftijdSatisfied($o, $c),
                // Wet LB 1964 art. 29 jo. 28 — loonheffingenverklaring on file before
                // the first payroll run (proxied by status, D3).
                'nl-onboarding-loonheffingenverklaring' => static fn(array $o, array $c): bool => self::loonheffingenverklaringSatisfied($o, $c),
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
     * True when the case is geannuleerd (cancelled cases never WID-flag), or
     * widCheckDone is true, or neither timing trigger has fired yet: startDate is
     * not in the past AND status has not reached gereed_eerste_werkdag or later.
     *
     * @param array<string, mixed> $o The Onboarding case.
     *
     * @return bool
     */
    private static function widCheckSatisfied(array $o): bool
    {
        if ((string) ($o['status'] ?? 'aangenomen') === 'geannuleerd') {
            return true;
        }

        if (($o['widCheckDone'] ?? false) === true) {
            return true;
        }

        $startDate = strtotime((string) ($o['startDate'] ?? ''));
        $startDatePassed = $startDate !== false && $startDate < (new DateTimeImmutable('today'))->getTimestamp();

        $readyOrLater = in_array((string) ($o['status'] ?? ''), self::READY_OR_LATER, true);

        return $startDatePassed === false && $readyOrLater === false;

    }//end widCheckSatisfied()


    /**
     * True unless (a) a contract resolves for the case's employeeId and
     * proeftijdEndDate exceeds startDate plus the BW 7:652 cap for that
     * contract's type/duration, or (b) the case is proeftijd_lopend with
     * proeftijdEndDate already past (a running proeftijd must be explicitly
     * closed via afronden/annuleren). When no contract resolves, branch (a) is
     * skipped — deliberately not fail-closed, contracts are optional MVP data
     * (design.md D3).
     *
     * @param array<string, mixed> $o The Onboarding case.
     * @param array<string, mixed> $c Evaluation context (carries `related`).
     *
     * @return bool
     */
    private static function proeftijdSatisfied(array $o, array $c): bool
    {
        if (self::proeftijdWithinContractCap($o, $c) === false) {
            return false;
        }

        return self::proeftijdNotOverdueUnclosed($o);

    }//end proeftijdSatisfied()


    /**
     * Branch (a): true when no contract resolves for employeeId, or
     * proeftijdEndDate is absent, or proeftijdEndDate does not exceed startDate
     * plus the resolved contract's BW 7:652 cap.
     *
     * @param array<string, mixed> $o The Onboarding case.
     * @param array<string, mixed> $c Evaluation context (carries `related`).
     *
     * @return bool
     */
    private static function proeftijdWithinContractCap(array $o, array $c): bool
    {
        $proeftijdEndDate = trim((string) ($o['proeftijdEndDate'] ?? ''));
        if ($proeftijdEndDate === '') {
            return true;
        }

        $employeeId = (string) ($o['employeeId'] ?? '');
        $contract   = self::relatedContractByEmployeeId($c)[$employeeId] ?? null;
        if (is_array($contract) === false) {
            return true;
        }

        $startDate = strtotime((string) ($o['startDate'] ?? ''));
        $endDate   = strtotime($proeftijdEndDate);
        if ($startDate === false || $endDate === false) {
            return true;
        }

        $capMonths = self::proeftijdCapMonths($contract);
        $capTimestamp = strtotime('+'.$capMonths.' months', $startDate);

        return $endDate <= $capTimestamp;

    }//end proeftijdWithinContractCap()


    /**
     * The BW 7:652 proeftijd cap in months for a resolved contract: 2 months for
     * a permanent contract or a fixed-term contract of 2 years or more; 1 month
     * for a fixed-term contract shorter than 2 years.
     *
     * @param array<string, mixed> $contract The resolved EmploymentContract fields.
     *
     * @return int
     */
    private static function proeftijdCapMonths(array $contract): int
    {
        if ((string) ($contract['type'] ?? '') === 'permanent') {
            return 2;
        }

        $contractStart = strtotime((string) ($contract['startDate'] ?? ''));
        $contractEnd   = strtotime((string) ($contract['endDate'] ?? ''));
        if ($contractStart === false || $contractEnd === false) {
            // Duration not decidable — treat as the shorter (fixed-term) cap.
            return 1;
        }

        $twoYearsOut = strtotime('+2 years', $contractStart);

        return $contractEnd < $twoYearsOut ? 1 : 2;

    }//end proeftijdCapMonths()


    /**
     * Branch (b): true unless the case is proeftijd_lopend and proeftijdEndDate
     * is before the audit-run date.
     *
     * @param array<string, mixed> $o The Onboarding case.
     *
     * @return bool
     */
    private static function proeftijdNotOverdueUnclosed(array $o): bool
    {
        if ((string) ($o['status'] ?? '') !== 'proeftijd_lopend') {
            return true;
        }

        $proeftijdEndDate = strtotime((string) ($o['proeftijdEndDate'] ?? ''));
        if ($proeftijdEndDate === false) {
            return true;
        }

        return $proeftijdEndDate >= (new DateTimeImmutable('today'))->getTimestamp();

    }//end proeftijdNotOverdueUnclosed()


    /**
     * True unless the case's status is at/past gereed_eerste_werkdag and its
     * resolved Employee lacks loonheffingenVerklaringOnFile = true. Fail-closed
     * on a dangling employeeId at those statuses (per REQ-OBW-004).
     *
     * @param array<string, mixed> $o The Onboarding case.
     * @param array<string, mixed> $c Evaluation context (carries `related`).
     *
     * @return bool
     */
    private static function loonheffingenverklaringSatisfied(array $o, array $c): bool
    {
        if (in_array((string) ($o['status'] ?? ''), self::READY_OR_LATER, true) === false) {
            return true;
        }

        $employeeId = (string) ($o['employeeId'] ?? '');
        $employee   = self::relatedEmployeesById($c)[$employeeId] ?? null;
        if (is_array($employee) === false) {
            return false;
        }

        return ($employee['loonheffingenVerklaringOnFile'] ?? false) === true;

    }//end loonheffingenverklaringSatisfied()


    /**
     * The `related.Employee.byId` index from the context, or an empty array
     * when the pre-pass has not populated it.
     *
     * @param array<string, mixed> $c Evaluation context.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function relatedEmployeesById(array $c): array
    {
        $byId = ($c['related']['Employee']['byId'] ?? []);
        return is_array($byId) === true ? $byId : [];

    }//end relatedEmployeesById()


    /**
     * The `related.EmploymentContract.byEmployeeId` index from the context, or
     * an empty array when the pre-pass has not populated it.
     *
     * @param array<string, mixed> $c Evaluation context.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function relatedContractByEmployeeId(array $c): array
    {
        $byEmployeeId = ($c['related']['EmploymentContract']['byEmployeeId'] ?? []);
        return is_array($byEmployeeId) === true ? $byEmployeeId : [];

    }//end relatedContractByEmployeeId()


}//end class
