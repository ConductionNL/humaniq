<?php

/**
 * NL Reiskosten (Mileage) Check Provider
 *
 * Executable check for the Dutch onbelaste kilometervergoeding rule of the
 * payroll corpus (lib/Standards/rules/payroll.json), mapped onto the Expense
 * object type: a mileage claim (category travel, travelType business or
 * commute, a positive distanceKm) reimbursed at more than the corpus's
 * rateEurPerKm per kilometer is flagged (nl-reiskosten-onbelast-tarief). The
 * rate is read from RuleCatalogue::all() at evaluation time, never hardcoded
 * here (mileage-rules design.md D1) -- an annual re-issue (or a same-year
 * correction, e.g. the Belastingdienst's real EUR 0,23 to EUR 0,25 mid-2026
 * change) is a one-number JSON edit, no code change. Every other combination
 * -- wrong category, missing/invalid travelType, absent or non-positive
 * distanceKm, non-numeric amount, or an unreadable catalogue parameter -- is
 * vacuously satisfied, never a false violation, mirroring the
 * nl-cao-minimumloon-schaal precedent (NlCaoChecks).
 *
 * This is an audit-time compliance signal only (occ hrmq:rules:audit /
 * RuleAuditService); it does not guard any Expense lifecycle transition
 * (mileage-rules REQ-MILE-004) and does not compute or gross up the
 * loonheffing on the bovenmatige (excess) vergoeding -- named as a follow-up
 * in design.md.
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
 * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Standards\RuleCatalogue;

/**
 * Dutch onbelaste-kilometervergoeding executable check for Expense.
 */
final class NlReiskostenChecks implements CheckProvider
{

    /**
     * The corpus rule id this provider enforces.
     *
     * @var string
     */
    private const RULE_ID = 'nl-reiskosten-onbelast-tarief';


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'Expense' => [
                // Wet LB 1964 art. 31a lid 2 -- a mileage claim reimbursed at more
                // than the onbelaste kilometervergoeding per km is a violation;
                // vacuous outside the mileage scope or when the rate is unreadable.
                self::RULE_ID => static fn(array $o): bool => self::onbelastTariefSatisfied($o),
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
     * True when the Expense is outside the mileage scope of this rule, or --
     * when in scope -- its per-km reimbursement is at or under the corpus's
     * onbelast rate.
     *
     * @param array<string, mixed> $o The Expense.
     *
     * @return bool
     */
    private static function onbelastTariefSatisfied(array $o): bool
    {
        if ((string) ($o['category'] ?? '') !== 'travel') {
            return true;
        }

        if (in_array(($o['travelType'] ?? null), ['business', 'commute'], true) === false) {
            return true;
        }

        $distanceKm = ($o['distanceKm'] ?? null);
        if (is_numeric($distanceKm) === false || (float) $distanceKm <= 0.0) {
            return true;
        }

        $amount = ($o['amount'] ?? null);
        if (is_numeric($amount) === false) {
            return true;
        }

        $rateEurPerKm = self::rateEurPerKm();
        if ($rateEurPerKm === null) {
            return true;
        }

        $perKm = ((float) $amount / (float) $distanceKm);

        return self::perKmWithinRate($perKm, $rateEurPerKm);

    }//end onbelastTariefSatisfied()


    /**
     * The nl-reiskosten-onbelast-tarief rule's parameters.rateEurPerKm, read
     * fresh from the catalogue, or null when the rule or its parameters
     * cannot be read (vacuous-scope discipline, never a hardcoded literal).
     *
     * @return float|null
     */
    private static function rateEurPerKm(): ?float
    {
        foreach (RuleCatalogue::all() as $rule) {
            if ((string) ($rule['id'] ?? '') !== self::RULE_ID) {
                continue;
            }

            $parameters = ($rule['parameters'] ?? null);
            if (is_array($parameters) === false || is_numeric($parameters['rateEurPerKm'] ?? null) === false) {
                return null;
            }

            return (float) $parameters['rateEurPerKm'];
        }

        return null;

    }//end rateEurPerKm()


    /**
     * True when the per-km reimbursement is at or under the onbelast rate, at
     * cent-per-km precision (avoids float-equality issues at the boundary,
     * mirroring NlPayrollChecks::centsEqual/ratesEqual).
     *
     * @param float $perKm The claim's amount divided by its distanceKm.
     * @param float $rate  The catalogue's rateEurPerKm.
     *
     * @return bool
     */
    private static function perKmWithinRate(float $perKm, float $rate): bool
    {
        return (int) round($perKm * 100) <= (int) round($rate * 100);

    }//end perKmWithinRate()


}//end class
