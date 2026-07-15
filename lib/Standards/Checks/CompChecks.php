<?php

/**
 * Comp Check Provider
 *
 * Executable check for the compensation-review-cycles corpus rule
 * (lib/Standards/rules/labour.json, comp-cycles design.md D7):
 * `comp-adjustment-within-band` — a proposed/approved/effective
 * CompAdjustment's `proposedSalary` must sit within its target SalaryBand's
 * `[minSalary, maxSalary]` range. Vacuous when the adjustment targets no band
 * (`targetBandId` null, the `payScalesVerified` advisory-until-confirmed
 * precedent) or is still `draft` (nothing proposed yet). The band is resolved
 * via the `comp.salaryBandsById` audit context (the `cao.employeesById` /
 * `payroll.runsById` enrichment precedent, RuleAuditService::audit()) rather
 * than per-object IO, so the predicate stays a pure
 * `fn(array $object, array $context): bool`.
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
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Within-band executable check for compensation-review adjustments.
 */
final class CompChecks implements CheckProvider
{

    /**
     * CompAdjustment statuses the within-band rule applies to — a still-draft
     * proposal has nothing decided yet (design.md D7).
     *
     * @var string[]
     */
    private const APPLICABLE_STATUSES = ['proposed', 'approved', 'effective'];


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     *
     * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
     */
    public static function checks(): array
    {
        return [
            'CompAdjustment' => [
                'comp-adjustment-within-band' => static fn(array $o, array $context): bool => self::withinBandSatisfied($o, $context),
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
     * The `comp-adjustment-within-band` predicate (spec.md REQ-COMP-007):
     * vacuous when the adjustment is still `draft`, when `targetBandId` is
     * absent, or when the referenced SalaryBand cannot be resolved (nothing
     * decidable from this object alone — the `NlCaoChecks` "unresolvable
     * sibling -> vacuous" precedent). Otherwise requires
     * `minSalary <= proposedSalary <= maxSalary`.
     *
     * @param array<string, mixed> $o       The CompAdjustment.
     * @param array<string, mixed> $context Evaluation context; reads `comp.salaryBandsById`.
     *
     * @return bool
     *
     * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
     */
    private static function withinBandSatisfied(array $o, array $context): bool
    {
        if (in_array((string) ($o['status'] ?? ''), self::APPLICABLE_STATUSES, true) === false) {
            // Still draft -- nothing proposed yet, out of scope.
            return true;
        }

        $bandId = trim((string) ($o['targetBandId'] ?? ''));
        if ($bandId === '') {
            // No band targeted -- vacuous (the payScalesVerified precedent).
            return true;
        }

        $band = ($context['comp']['salaryBandsById'][$bandId] ?? null);
        if (is_array($band) === false) {
            // Unresolvable band -- nothing decidable from this object alone.
            return true;
        }

        $proposedSalary = ($o['proposedSalary'] ?? null);
        $minSalary      = ($band['minSalary'] ?? null);
        $maxSalary      = ($band['maxSalary'] ?? null);
        if (is_numeric($proposedSalary) === false || is_numeric($minSalary) === false || is_numeric($maxSalary) === false) {
            return true;
        }

        return ((float) $proposedSalary) >= ((float) $minSalary) && ((float) $proposedSalary) <= ((float) $maxSalary);

    }//end withinBandSatisfied()


}//end class
