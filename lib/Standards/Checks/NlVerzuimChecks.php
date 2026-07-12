<?php

/**
 * NL Verzuim (Wet verbetering poortwachter) Check Provider
 *
 * Executable checks for the Dutch sickness/re-integration rules of the labour
 * corpus (lib/Standards/rules/labour.json, framework nl-poortwachter /
 * bw7-10), mapped onto the SickLeaveCase object type (leave-verzuim-mvp):
 * each non-null WVP milestone Due date must equal firstSickDay plus its
 * rule-parameterised week offset, with no null Due allowed on an open case
 * (nl-wvp-milestone-derivation); an open case with a milestone overdue or
 * approaching and not yet Done is flagged (nl-wvp-milestone-overdue); and an
 * open case's loondoorbetalingPercentage must be at least 70
 * (nl-loondoorbetaling-minimum). All three predicates are single-object and
 * side-effect free; the seed SickLeaveCase objects live in
 * lib/Settings/register.d/hr-seed.json (ADR-001), not via SeedsObjects — the
 * seeds are deliberately chosen to exercise recovery, an overdue milestone,
 * and an approaching milestone.
 *
 * Deviation note (design.md / REQ-VWP-004 said "overdue mandatory / within-14-
 * days advisory"): RuleCatalogue's `severity` enum is `mandatory | conditional
 * | recommended` (rules/SCHEMA.md) — there is no `advisory` severity, and
 * RuleEngine::violationFor() always takes the Violation's severity from the
 * catalogue rule, not from the predicate call site, so a single rule id cannot
 * emit two different severities for the same object. This mirrors the exact
 * precedent already shipped for nl-loonaangifte-deadline-alert /
 * nl-upa-deadline-alert: "past OR within the alert window" is one boolean
 * condition producing one Violation at the rule's declared severity
 * (mandatory here). nl-wvp-milestone-overdue therefore reports a single
 * mandatory violation whenever ANY open milestone is overdue or within the
 * 14-day window and not Done — both the "overdue" and "approaching" cases in
 * the design's seed narrative surface via this one check.
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
 * @spec openspec/changes/leave-verzuim-mvp/specs/verzuim-wvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Standards\RuleCatalogue;

/**
 * Dutch sickness / Wet verbetering poortwachter executable checks.
 */
final class NlVerzuimChecks implements CheckProvider
{

    /**
     * Number of days before (and including) a milestone Due date that an
     * undone milestone on an open case starts alerting
     * (nl-wvp-milestone-overdue).
     *
     * @var int
     */
    private const ALERT_WINDOW_DAYS = 14;

    /**
     * The four WVP milestone Due/Done field pairs, in derivation order, each
     * mapped to its `parameters.milestoneWeeks` key on the
     * nl-wvp-milestone-derivation corpus rule.
     *
     * @var array<int, array{due: string, done: string, weeksKey: string}>
     */
    private const MILESTONES = [
        ['due' => 'probleemanalyseDue', 'done' => 'probleemanalyseDone', 'weeksKey' => 'probleemanalyse'],
        ['due' => 'planVanAanpakDue', 'done' => 'planVanAanpakDone', 'weeksKey' => 'planVanAanpak'],
        ['due' => 'uwv42WeekMeldingDue', 'done' => 'uwv42WeekMeldingDone', 'weeksKey' => 'uwv42WeekMelding'],
        ['due' => 'eerstejaarsevaluatieDue', 'done' => 'eerstejaarsevaluatieDone', 'weeksKey' => 'eerstejaarsevaluatie'],
    ];


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'SickLeaveCase' => [
                // Regeling procesgang art. 2/4; ZW art. 38 — each Due equals
                // firstSickDay + its rule-parameterised week offset; no null Due
                // on an open case.
                'nl-wvp-milestone-derivation' => static fn(array $o): bool => self::derivationSatisfied($o),
                // Regeling procesgang art. 2/4; ZW art. 38 — an open case with an
                // overdue or approaching, not-yet-Done milestone is flagged.
                'nl-wvp-milestone-overdue' => static fn(array $o): bool => self::overdueSatisfied($o),
                // BW art. 7:629 lid 1 — at least 70% loondoorbetaling on open cases.
                'nl-loondoorbetaling-minimum' => static fn(array $o): bool => self::loondoorbetalingSatisfied($o),
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
     * True when every non-null Due date equals firstSickDay plus its rule-
     * parameterised week offset, and (on an open/gemeld case) no Due date is
     * null. On a hersteld case, null Due dates are allowed (the clock never
     * mattered). Passes vacuously when firstSickDay is unparseable or the
     * corpus rule/parameters are missing (not decidable).
     *
     * @param array<string, mixed> $o The SickLeaveCase.
     *
     * @return bool
     */
    private static function derivationSatisfied(array $o): bool
    {
        $milestoneWeeks = self::milestoneWeeks();
        if ($milestoneWeeks === null) {
            return true;
        }

        $firstSickDay = strtotime((string) ($o['firstSickDay'] ?? ''));
        if ($firstSickDay === false) {
            return true;
        }

        $isOpen = (string) ($o['status'] ?? 'gemeld') === 'gemeld';

        foreach (self::MILESTONES as $milestone) {
            $weeks = ($milestoneWeeks[$milestone['weeksKey']] ?? null);
            if (is_numeric($weeks) === false) {
                continue;
            }

            $due = trim((string) ($o[$milestone['due']] ?? ''));
            if ($due === '') {
                if ($isOpen === true) {
                    return false;
                }

                continue;
            }

            $expected = date('Y-m-d', strtotime('+'.((int) $weeks * 7).' days', $firstSickDay));
            if ($due !== $expected) {
                return false;
            }
        }//end foreach

        return true;

    }//end derivationSatisfied()


    /**
     * True on a hersteld (closed) case, or when every WVP milestone on an
     * open case is either Done or more than 14 days from its Due date.
     * Mirrors the nl-loonaangifte-deadline-alert / nl-upa-deadline-alert
     * pattern: "past OR within the alert window" collapses to one boolean
     * condition, evaluated against the audit run date.
     *
     * @param array<string, mixed> $o The SickLeaveCase.
     *
     * @return bool
     */
    private static function overdueSatisfied(array $o): bool
    {
        if ((string) ($o['status'] ?? 'gemeld') !== 'gemeld') {
            return true;
        }

        $today = (new \DateTimeImmutable('today'))->getTimestamp();

        foreach (self::MILESTONES as $milestone) {
            $done = trim((string) ($o[$milestone['done']] ?? ''));
            if ($done !== '') {
                continue;
            }

            $due = strtotime((string) ($o[$milestone['due']] ?? ''));
            if ($due === false) {
                continue;
            }

            $daysRemaining = (int) floor(($due - $today) / 86400);
            if ($daysRemaining <= self::ALERT_WINDOW_DAYS) {
                return false;
            }
        }

        return true;

    }//end overdueSatisfied()


    /**
     * True on a hersteld (closed) case, or when an open case's
     * loondoorbetalingPercentage is present and at least 70.
     *
     * @param array<string, mixed> $o The SickLeaveCase.
     *
     * @return bool
     */
    private static function loondoorbetalingSatisfied(array $o): bool
    {
        if ((string) ($o['status'] ?? 'gemeld') !== 'gemeld') {
            return true;
        }

        $percentage = ($o['loondoorbetalingPercentage'] ?? null);
        if ($percentage === null || $percentage === '') {
            return false;
        }

        return (float) $percentage >= 70.0;

    }//end loondoorbetalingSatisfied()


    /**
     * The `parameters.milestoneWeeks` map of the nl-wvp-milestone-derivation
     * corpus rule, or null when the rule/parameters are missing from the
     * catalogue.
     *
     * @return array<string, mixed>|null
     */
    private static function milestoneWeeks(): ?array
    {
        foreach (RuleCatalogue::all() as $rule) {
            if ((string) ($rule['id'] ?? '') !== 'nl-wvp-milestone-derivation') {
                continue;
            }

            $parameters = ($rule['parameters'] ?? null);
            if (is_array($parameters) === false) {
                return null;
            }

            $weeks = ($parameters['milestoneWeeks'] ?? null);
            return is_array($weeks) === true ? $weeks : null;
        }

        return null;

    }//end milestoneWeeks()


}//end class
