<?php

/**
 * NL HR-Signals Check Provider
 *
 * Executable checks for the three hr-signals corpus rules
 * (lib/Standards/rules/labour.json, framework `hr-signals` /`bw7-10`): two
 * keyed on `EmploymentContract` -- a temporary contract nearing its `endDate`
 * with no successor is signalled for HR follow-up
 * (nl-signaal-contract-verloopt, advisory), and a fixed-term contract of six
 * months or longer whose written aanzegging deadline has passed without a
 * recorded `aanzegdOn` violates the statutory aanzegplicht
 * (nl-aanzegtermijn-bewaking, BW 7:668 lid 1, mandatory) -- and a third keyed
 * on `BhvCertificering` (bhv-organisatie): a certification nearing its
 * `certificaatGeldigTot` is signalled for HR follow-up
 * (nl-bhv-certificaat-verloopt, advisory). All three are literal siblings in
 * the same `hr-signals` framework/provider -- no second alerting mechanism
 * (bhv-organisatie design.md D1).
 *
 * The successor predicate is cross-object: it reads
 * `context['signals']['contractsByEmployeeId']`, a FULL-LIST index
 * `RuleAuditService::buildSignalsContext()` populates in its pre-pass
 * (design.md D4) -- unlike the existing
 * `context['related']['EmploymentContract']['byEmployeeId']` index (last-wins,
 * consumed by NlOnboardingChecks), which cannot see sibling contracts. The
 * aanzegtermijn and BHV-certificate predicates are both object-local -- no
 * context needed.
 *
 * This provider does NOT implement SeedsObjects: the seeded
 * `contract-devries-tijdelijk` (deliberately violating both EmploymentContract
 * rules at the seed anchor) and the seeded BHV-certificate violation
 * (bhv-organisatie) live declaratively in lib/Settings/register.d/hr-seed.json
 * (ADR-001), the same pattern NlOnboardingChecks/NlDocumentChecks document for
 * predicates whose sample needs a resolvable cross-reference or an
 * intentional-violation demonstration rather than a compliant sample.
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
 * @spec openspec/changes/hr-signals/specs/hr-signals/spec.md
 * @spec openspec/specs/bhv-organisatie/spec.md#REQ-BHV-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use DateTimeImmutable;

/**
 * Contract-expiry signal + statutory aanzegtermijn executable checks.
 */
final class NlSignalChecks implements CheckProvider
{

    /**
     * `nl-signaal-contract-verloopt` window, days (parameters.windowDays in
     * labour.json -- kept in sync here since the predicate is code, not data).
     *
     * @var int
     */
    private const WINDOW_DAYS = 60;

    /**
     * `nl-aanzegtermijn-bewaking` minimum fixed-term duration, months
     * (parameters.minContractMonths in labour.json).
     *
     * @var int
     */
    private const MIN_CONTRACT_MONTHS = 6;

    /**
     * `nl-aanzegtermijn-bewaking` required notice period, months
     * (parameters.noticeMonths in labour.json).
     *
     * @var int
     */
    private const NOTICE_MONTHS = 1;

    /**
     * `nl-bhv-certificaat-verloopt` window, days (parameters.windowDays in
     * labour.json -- kept in sync here since the predicate is code, not
     * data; also the window the "Aflopende BHV-certificaten" dashboard
     * widget filter must stay in sync with, src/manifest.json).
     *
     * @var int
     */
    private const BHV_WINDOW_DAYS = 90;


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'EmploymentContract' => [
                // HR contract-lifecycle control -- expiring temporary contract
                // with no successor is signalled for HR follow-up.
                'nl-signaal-contract-verloopt' => static fn(array $o, array $context): bool => self::contractVerloeptSatisfied($o, $context),
                // BW art. 7:668 lid 1 -- written aanzegging at least one month
                // before the end date of a fixed-term contract of >= 6 months.
                'nl-aanzegtermijn-bewaking'    => static fn(array $o): bool => self::aanzegtermijnSatisfied($o),
            ],
            'BhvCertificering'   => [
                // bhv-organisatie -- expiring BHV-related certification is
                // signalled for HR follow-up (herhalingscursus /
                // herbeoordeling van de aanwijzing). Same hr-signals
                // framework, same object-local shape as the two
                // EmploymentContract predicates above -- no second alerting
                // mechanism (design.md D1).
                'nl-bhv-certificaat-verloopt' => static fn(array $o): bool => self::bhvCertificaatVerloeptSatisfied($o),
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
     * `nl-signaal-contract-verloopt`: true unless the contract is temporary,
     * its `endDate` is a parseable date, and today is within
     * `[today, today + WINDOW_DAYS]` of it AND no successor contract resolves
     * for the same employee. Permanent/agency/minijob contracts, open-ended
     * contracts, contracts further out than the window, and already-expired
     * contracts all pass vacuously (design.md D2).
     *
     * @param array<string, mixed> $o       The EmploymentContract.
     * @param array<string, mixed> $context Evaluation context; reads `signals.contractsByEmployeeId`.
     *
     * @return bool
     */
    private static function contractVerloeptSatisfied(array $o, array $context): bool
    {
        if ((string) ($o['type'] ?? '') !== 'temporary') {
            return true;
        }

        $endDate = strtotime((string) ($o['endDate'] ?? ''));
        if ($endDate === false) {
            return true;
        }

        $today       = (new DateTimeImmutable('today'))->getTimestamp();
        $windowEnd   = strtotime('+'.self::WINDOW_DAYS.' days', $today);
        $withinWindow = $endDate >= $today && $endDate <= $windowEnd;
        if ($withinWindow === false) {
            return true;
        }

        return self::hasSuccessor($o, $context);

    }//end contractVerloeptSatisfied()


    /**
     * True when another contract for the same employeeId (a different object
     * id) starts after this one AND either has no endDate or an endDate after
     * this one's -- the shape-based successor heuristic (design.md D2/Risks).
     *
     * @param array<string, mixed> $o       The EmploymentContract being evaluated.
     * @param array<string, mixed> $context Evaluation context; reads `signals.contractsByEmployeeId`.
     *
     * @return bool
     */
    private static function hasSuccessor(array $o, array $context): bool
    {
        $employeeId = (string) ($o['employeeId'] ?? '');
        $ownId      = (string) ($o['id'] ?? $o['@self']['id'] ?? '');
        $ownStart   = strtotime((string) ($o['startDate'] ?? ''));
        if ($ownStart === false) {
            return false;
        }

        $siblings = self::contractsByEmployeeId($context)[$employeeId] ?? [];

        foreach ($siblings as $sibling) {
            $siblingId = (string) ($sibling['id'] ?? '');
            if ($ownId !== '' && $siblingId !== '' && $siblingId === $ownId) {
                // Never count itself as its own successor.
                continue;
            }

            $siblingStart = strtotime((string) ($sibling['startDate'] ?? ''));
            if ($siblingStart === false || $siblingStart <= $ownStart) {
                continue;
            }

            $siblingEndRaw = trim((string) ($sibling['endDate'] ?? ''));
            if ($siblingEndRaw === '') {
                // Open-ended successor.
                return true;
            }

            $siblingEnd = strtotime($siblingEndRaw);
            $ownEnd     = strtotime((string) ($o['endDate'] ?? ''));
            if ($siblingEnd !== false && $ownEnd !== false && $siblingEnd > $ownEnd) {
                return true;
            }
        }

        return false;

    }//end hasSuccessor()


    /**
     * The `signals.contractsByEmployeeId` index from the context, or an empty
     * array when the pre-pass has not populated it.
     *
     * @param array<string, mixed> $context Evaluation context.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function contractsByEmployeeId(array $context): array
    {
        $byEmployeeId = ($context['signals']['contractsByEmployeeId'] ?? []);
        return is_array($byEmployeeId) === true ? $byEmployeeId : [];

    }//end contractsByEmployeeId()


    /**
     * `nl-aanzegtermijn-bewaking`: true unless the contract is temporary, its
     * `startDate`/`endDate` are parseable dates, the fixed term runs at least
     * `MIN_CONTRACT_MONTHS`, the contract is still live (`endDate >= today`),
     * the aanzeg deadline (`endDate` minus `NOTICE_MONTHS`) has passed, AND
     * `aanzegdOn` is empty or later than that deadline. Short fixed terms,
     * non-temporary contracts, contracts whose deadline has not yet passed,
     * and already-expired contracts all pass vacuously (design.md D2).
     *
     * @param array<string, mixed> $o The EmploymentContract.
     *
     * @return bool
     */
    private static function aanzegtermijnSatisfied(array $o): bool
    {
        if ((string) ($o['type'] ?? '') !== 'temporary') {
            return true;
        }

        $startDate = strtotime((string) ($o['startDate'] ?? ''));
        $endDate   = strtotime((string) ($o['endDate'] ?? ''));
        if ($startDate === false || $endDate === false) {
            return true;
        }

        $minTermEnd = strtotime('+'.self::MIN_CONTRACT_MONTHS.' months', $startDate);
        if ($endDate < $minTermEnd) {
            // Fixed term shorter than the statutory threshold -- out of scope.
            return true;
        }

        $today = (new DateTimeImmutable('today'))->getTimestamp();
        if ($endDate < $today) {
            // Already expired -- historical breaches are not re-flagged
            // (monitoring-window scope, design.md D2).
            return true;
        }

        $deadline = strtotime('-'.self::NOTICE_MONTHS.' months', $endDate);
        if ($today <= $deadline) {
            // Deadline has not passed yet.
            return true;
        }

        $aanzegdOn = trim((string) ($o['aanzegdOn'] ?? ''));
        if ($aanzegdOn === '') {
            return false;
        }

        $aanzegdOnDate = strtotime($aanzegdOn);
        if ($aanzegdOnDate === false) {
            return false;
        }

        return $aanzegdOnDate <= $deadline;

    }//end aanzegtermijnSatisfied()


    /**
     * `nl-bhv-certificaat-verloopt`: true unless `certificaatGeldigTot` is a
     * parseable date AND today is within `[today, today + BHV_WINDOW_DAYS]`
     * of it. A certificate further out, or already expired, passes vacuously
     * -- the same "expiring date, no successor-equivalent needed" shape as
     * `nl-signaal-contract-verloopt`, minus the successor lookup (a BHV
     * certificate has no "successor contract" concept; a renewal is simply a
     * new record, bhv-organisatie design.md D4). Object-local: no context
     * needed.
     *
     * @param array<string, mixed> $o The BhvCertificering.
     *
     * @return bool
     */
    private static function bhvCertificaatVerloeptSatisfied(array $o): bool
    {
        $geldigTot = strtotime((string) ($o['certificaatGeldigTot'] ?? ''));
        if ($geldigTot === false) {
            return true;
        }

        $today     = (new DateTimeImmutable('today'))->getTimestamp();
        $windowEnd = strtotime('+'.self::BHV_WINDOW_DAYS.' days', $today);

        return ($geldigTot >= $today && $geldigTot <= $windowEnd) === false;

    }//end bhvCertificaatVerloeptSatisfied()


}//end class
