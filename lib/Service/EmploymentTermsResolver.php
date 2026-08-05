<?php

/**
 * Employment Terms Resolver
 *
 * Answers "what terms actually apply to THIS contract" for the terms that are
 * normally collective and occasionally individual: overwerktoeslag and
 * vakantiedagen.
 *
 * Terms are company-wide by default. A CAO is a collective agreement — it is
 * the norm, and an individual contract departing from it is the exception. So
 * resolution is **inherit first, override second**:
 *
 *   1. the contract's own value, when it carries one;
 *   2. otherwise the CAO the contract names, via {@see CaoRegistry};
 *   3. otherwise nothing — `null`, never a fabricated default.
 *
 * Every resolution reports WHERE it came from. A term that cannot say whether
 * it is the collective norm or a negotiated exception is unusable in a
 * conversation with an employee, a works council or an auditor, and the
 * provenance is exactly what someone asks for first.
 *
 * Two deliberate rules:
 *
 * - **An override wins IN FULL; it is never merged per category.** Taking
 *   `zondag` from the contract and `zaterdag` from the CAO would produce a set
 *   of terms that exists in neither document and that nobody agreed to.
 * - **An override MUST carry a reason.** Departing from a collective agreement
 *   is something a person decided and a person must be able to justify; an
 *   unexplained override is indistinguishable from a data-entry error, and it
 *   feeds a cost figure that reaches a statutory submission.
 *
 * Resolving to `null` is a first-class outcome, not a failure. The CAO corpus
 * marks unconfirmed figures `verified: false` / `placeholder: true` and
 * `CaoRegistry` deliberately resolves those to null, so a CAO whose overtime
 * article has not been transcribed yields no uplift rather than an invented
 * one. This service preserves that: no terms means no overtime addition, which
 * is a visible gap rather than a plausible wrong number.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use InvalidArgumentException;
use OCA\Hrmq\Standards\CaoRegistry;

/**
 * Resolves CAO-inherited employment terms with contract-level overrides.
 */
class EmploymentTermsResolver
{

    /**
     * The term came from the contract itself, departing from the CAO.
     *
     * @var string
     */
    public const SOURCE_CONTRACT = 'contract-override';

    /**
     * The term was inherited from the CAO the contract names.
     *
     * @var string
     */
    public const SOURCE_CAO = 'cao';

    /**
     * Overtime categories, in the order a UI should present them.
     *
     * @var string[]
     */
    public const OVERTIME_CATEGORIES = [
        'doordeweeks',
        'zaterdag',
        'zondag',
        'feestdag',
    ];


    /**
     * Resolve the overwerktoeslag percentages that apply to one contract.
     *
     * Percentages are the SURCHARGE, not the total: `50` means 150% of the
     * normal hourly wage is paid for that hour.
     *
     * @param array<string, mixed> $contract The EmploymentContract as an array.
     *
     * @return array{percentages: array<string, float>, source: string, basis: string, caoId: string|null}|null
     *         Null when neither the contract nor its CAO yields usable terms.
     *
     * @throws InvalidArgumentException When an override carries no reason.
     */
    public function resolveOvertimeToeslag(array $contract): ?array
    {
        $override = $this->normalisePercentages(raw: ($contract['overtimeToeslagPercentages'] ?? null));
        if ($override !== null) {
            $reason = trim((string) ($contract['overtimeTermsOverrideReason'] ?? ''));
            if ($reason === '') {
                throw new InvalidArgumentException(
                    'overtimeToeslagPercentages is set without overtimeTermsOverrideReason; '
                    .'a contract departing from its CAO must say why'
                );
            }

            return [
                'percentages' => $override,
                'source'      => self::SOURCE_CONTRACT,
                'basis'       => $reason,
                'caoId'       => $this->caoId(contract: $contract),
            ];
        }//end if

        $caoId = $this->caoId(contract: $contract);
        if ($caoId === null) {
            return null;
        }

        $percentages = CaoRegistry::overtimeToeslagPercentages($caoId);
        if ($percentages === null) {
            return null;
        }

        return [
            'percentages' => $percentages,
            'source'      => self::SOURCE_CAO,
            'basis'       => $caoId,
            'caoId'       => $caoId,
        ];

    }//end resolveOvertimeToeslag()


    /**
     * Resolve the full-time vakantiedagen entitlement for one contract.
     *
     * Statutory minimums are NOT applied here — `nl-verlof-wettelijk-minimum`
     * evaluates the resulting entitlement, so an override below the wettelijk
     * minimum surfaces as a violation rather than being silently corrected
     * into compliance by this resolver.
     *
     * @param array<string, mixed> $contract The EmploymentContract as an array.
     *
     * @return array{days: array<string, float>, source: string, basis: string, caoId: string|null}|null
     *         Null when neither the contract nor its CAO yields usable terms.
     *
     * @throws InvalidArgumentException When an override carries no reason.
     */
    public function resolveLeaveEntitlementDays(array $contract): ?array
    {
        $override = $this->normaliseLeaveDays(raw: ($contract['leaveEntitlementOverrideDays'] ?? null));
        if ($override !== null) {
            $reason = trim((string) ($contract['leaveTermsOverrideReason'] ?? ''));
            if ($reason === '') {
                throw new InvalidArgumentException(
                    'leaveEntitlementOverrideDays is set without leaveTermsOverrideReason; '
                    .'a contract departing from its CAO must say why'
                );
            }

            return [
                'days'   => $override,
                'source' => self::SOURCE_CONTRACT,
                'basis'  => $reason,
                'caoId'  => $this->caoId(contract: $contract),
            ];
        }//end if

        $caoId = $this->caoId(contract: $contract);
        if ($caoId === null) {
            return null;
        }

        $cao = CaoRegistry::get($caoId);
        $leaf = ($cao['leaveEntitlement'] ?? null);
        // Re-uses CaoRegistry's own usability lever indirectly: minLeaveHours
        // returns null for an unverified/placeholder leaf, so asking it with a
        // nominal week tells us whether the leaf may be read at all without
        // duplicating the verified/placeholder logic here.
        if (CaoRegistry::minLeaveHours($caoId, 40.0) === null || is_array($leaf) === false) {
            return null;
        }

        $value = (array) ($leaf['value'] ?? []);
        $days  = [
            'vakantiedagenWettelijk'      => (float) ($value['vakantiedagenWettelijk'] ?? 0),
            'vakantiedagenBovenwettelijk' => (float) ($value['vakantiedagenBovenwettelijk'] ?? 0),
        ];

        return [
            'days'   => $days,
            'source' => self::SOURCE_CAO,
            'basis'  => $caoId,
            'caoId'  => $caoId,
        ];

    }//end resolveLeaveEntitlementDays()


    /**
     * The CAO id the contract names, if any.
     *
     * @param array<string, mixed> $contract The EmploymentContract as an array.
     *
     * @return string|null Null when the contract names no CAO.
     */
    private function caoId(array $contract): ?string
    {
        $caoId = trim((string) ($contract['cao'] ?? ''));

        return ($caoId === '' ? null : $caoId);

    }//end caoId()


    /**
     * Coerce a raw override map into numeric surcharge percentages.
     *
     * @param mixed $raw Raw override value.
     *
     * @return array<string, float>|null Null when the override is absent or carries no numeric entry.
     */
    private function normalisePercentages(mixed $raw): ?array
    {
        if (is_array($raw) === false || $raw === []) {
            return null;
        }

        $out = [];
        foreach ($raw as $category => $percentage) {
            if (is_numeric($percentage) === false) {
                continue;
            }

            if ((float) $percentage < 0.0) {
                throw new InvalidArgumentException(
                    'an overwerktoeslag percentage must not be negative, got: '.$percentage
                    .' for "'.$category.'"'
                );
            }

            $out[(string) $category] = (float) $percentage;
        }

        return ($out === [] ? null : $out);

    }//end normalisePercentages()


    /**
     * Coerce a raw leave override into numeric day counts.
     *
     * @param mixed $raw Raw override value.
     *
     * @return array<string, float>|null Null when the override is absent or carries no numeric entry.
     */
    private function normaliseLeaveDays(mixed $raw): ?array
    {
        if (is_array($raw) === false || $raw === []) {
            return null;
        }

        $out = [];
        foreach (['vakantiedagenWettelijk', 'vakantiedagenBovenwettelijk'] as $key) {
            $value = ($raw[$key] ?? null);
            if (is_numeric($value) === false) {
                continue;
            }

            if ((float) $value < 0.0) {
                throw new InvalidArgumentException('a leave entitlement must not be negative, got: '.$value);
            }

            $out[$key] = (float) $value;
        }

        return ($out === [] ? null : $out);

    }//end normaliseLeaveDays()
}//end class
