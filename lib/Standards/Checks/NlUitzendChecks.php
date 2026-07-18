<?php

/**
 * NL Uitzend (agency-worker) Check Provider
 *
 * Executable checks for the two agency-side rules the `uitzend-flexpool` change
 * added to the labour corpus (lib/Standards/rules/labour.json, framework
 * hr-uitzend), both mapped onto the EmploymentContract object type and both
 * strictly guarded on `type === 'agency'` so a permanent/temporary/minijob
 * contract is never evaluated (uitzend-flexpool D1/D3):
 *
 * - `nl-uitzendbeding-alleen-fase-a` (EmploymentContract): on an agency
 *   contract, when `uitzendbedingVanToepassing` is true the `uitzendFase` must
 *   equal `A` -- the uitzendbeding (BW art. 7:691 lid 2) is only legally sound
 *   during fase A. Vacuous for non-agency contracts and when the beding does
 *   not apply. Asserts the fase/beding relationship only, never a week-count
 *   (uitzend-flexpool D2).
 * - `nl-inlenersbeloning-onderbouwing-vereist` (EmploymentContract): on an
 *   agency contract with a populated `hourlyWage`, `inlenersbeloningReferentie`
 *   must be non-empty (WAADI art. 8, the inlenersbeloning duty). Presence-only
 *   (the WID-check boolean-gate shape) -- the referenced figure's correctness
 *   is never validated. Vacuous for non-agency contracts and when no wage is
 *   set (uitzend-flexpool D3).
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
 * @spec openspec/changes/uitzend-flexpool/specs/uitzend-flexpool/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Agency-worker (uitzendkracht) executable checks -- both agency-scoped.
 */
final class NlUitzendChecks implements CheckProvider
{

    /**
     * The EmploymentContract.type value both checks are exclusively scoped to.
     *
     * @var string
     */
    private const AGENCY_TYPE = 'agency';


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     *
     * @spec openspec/changes/uitzend-flexpool/specs/uitzend-flexpool/spec.md
     */
    public static function checks(): array
    {
        return [
            'EmploymentContract' => [
                // BW art. 7:691 lid 2 -- the uitzendbeding only holds in fase A.
                'nl-uitzendbeding-alleen-fase-a'          => static fn(array $contract): bool => self::uitzendbedingAlleenFaseASatisfied($contract),
                // WAADI art. 8 -- an agency wage needs an inlenersbeloning reference.
                'nl-inlenersbeloning-onderbouwing-vereist' => static fn(array $contract): bool => self::inlenersbeloningOnderbouwingSatisfied($contract),
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
     * The `nl-uitzendbeding-alleen-fase-a` predicate (spec.md REQ-UITZ-002):
     * vacuous pass for any non-agency contract (the guard is `type === 'agency'`
     * only) and when `uitzendbedingVanToepassing` is not strictly true.
     * Otherwise the beding applies, so `uitzendFase` must equal `A`.
     *
     * @param array<string, mixed> $contract The EmploymentContract.
     *
     * @return bool
     *
     * @spec openspec/changes/uitzend-flexpool/specs/uitzend-flexpool/spec.md
     */
    private static function uitzendbedingAlleenFaseASatisfied(array $contract): bool
    {
        if ((string) ($contract['type'] ?? '') !== self::AGENCY_TYPE) {
            // Not an agency contract -- out of scope, never evaluated.
            return true;
        }

        if (($contract['uitzendbedingVanToepassing'] ?? null) !== true) {
            // The beding does not apply -- any (or no) fase is fine.
            return true;
        }

        return (string) ($contract['uitzendFase'] ?? '') === 'A';

    }//end uitzendbedingAlleenFaseASatisfied()


    /**
     * The `nl-inlenersbeloning-onderbouwing-vereist` predicate (spec.md
     * REQ-UITZ-003): vacuous pass for any non-agency contract (the guard is
     * `type === 'agency'` only) and when `hourlyWage` is absent/non-numeric
     * (nothing to substantiate). Otherwise `inlenersbeloningReferentie` must be
     * a non-empty string -- presence only, the figure is never validated.
     *
     * @param array<string, mixed> $contract The EmploymentContract.
     *
     * @return bool
     *
     * @spec openspec/changes/uitzend-flexpool/specs/uitzend-flexpool/spec.md
     */
    private static function inlenersbeloningOnderbouwingSatisfied(array $contract): bool
    {
        if ((string) ($contract['type'] ?? '') !== self::AGENCY_TYPE) {
            // Not an agency contract -- out of scope, never evaluated.
            return true;
        }

        if (is_numeric($contract['hourlyWage'] ?? null) === false) {
            // No wage set -- nothing decidable to substantiate.
            return true;
        }

        return trim((string) ($contract['inlenersbeloningReferentie'] ?? '')) !== '';

    }//end inlenersbeloningOnderbouwingSatisfied()


}//end class
