<?php

/**
 * NL HR21 (functiehuis) Check Provider
 *
 * Executable check for the functiehuis-hr21 corpus rule
 * (lib/Standards/rules/payroll.json): `nl-hr21-schaal-consistentie` — a
 * contract's own `caoSchaal` must agree with the Cao Gemeenten schaal its
 * assigned `Normfunctie` (HR21 standard job function) maps to, but ONLY when
 * that mapping is itself confirmed (`caoSchaalVerified: true`). Vacuous when
 * `normfunctieId` is null, unresolvable, or the resolved Normfunctie's mapping
 * is unverified/placeholder — the `NlCaoChecks::minimumloonSchaalSatisfied()`
 * placeholder-is-advisory precedent (design.md D4), applied to a
 * classification mapping instead of a pay-scale rate. The Normfunctie is
 * resolved via the `hr21.normfunctiesById` audit context
 * (RuleAuditService::buildHr21Context(), the `comp.salaryBandsById` /
 * `cao.employeesById` enrichment precedent) rather than per-object IO, so the
 * predicate stays a pure `fn(array $object, array $context): bool`.
 *
 * Also implements `SeedsObjects`: a small illustrative subset of `Normfunctie`
 * reference rows (functiehuis-hr21 proposal.md "Honesty about verification" —
 * NOT a claimed-complete ~150-function library; HR21's exact catalog was not
 * independently verified from a primary VNG source in this pass). Every
 * seeded row carries `caoSchaalVerified: false` except one, which is flipped
 * to `true` purely so the consistency check has a resolvable, non-vacuous
 * mapping to demonstrate the clean-pass and violation scenarios against
 * (tasks.md #11) — its `caoSchaalSource` says so explicitly, so this single
 * exception is never mistaken for an actual VNG/HR21 confirmation.
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
 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-001
 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
 * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * HR21 normfunctie-to-schaal consistency check, plus the illustrative
 * Normfunctie reference-row seed.
 */
final class NlHr21Checks implements CheckProvider, SeedsObjects
{


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     *
     * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
     */
    public static function checks(): array
    {
        return [
            'EmploymentContract' => [
                'nl-hr21-schaal-consistentie' => static fn(array $contract, array $context): bool => self::schaalConsistentieSatisfied($contract, $context),
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
     * {@inheritDoc}
     *
     * A small illustrative `Normfunctie` subset spanning three HR21
     * hoofdprocessen (design.md Seed Data) — NOT a claimed-complete library.
     * Every row is `caoSchaalVerified: false` except `HR21-002`, which is
     * flipped to `true` (with its `caoSchaalSource` saying so) purely to give
     * the seeded `EmploymentContract` proof cases (REQ-HR21-005) a resolvable,
     * non-vacuous mapping to check against.
     *
     * @return array<string, array<int, array<string, mixed>>>
     *
     * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-001
     */
    public static function seedObjects(): array
    {
        return [
            'Normfunctie' => [
                [
                    'functiecode'       => 'HR21-001',
                    'naam'              => 'Medewerker Beheer',
                    'functiegroep'      => 'Beheer',
                    'caoSchaal'         => '6',
                    'caoSchaalVerified' => false,
                    'caoSchaalSource'   => 'HR21/VNG functieboek — mapping not yet independently confirmed against a primary source.',
                ],
                [
                    'functiecode'       => 'HR21-002',
                    'naam'              => 'Senior Beleidsmedewerker',
                    'functiegroep'      => 'Beleid',
                    'caoSchaal'         => '10',
                    'caoSchaalVerified' => true,
                    'caoSchaalSource'   => 'Illustrative proof-case only — flipped to verified:true so the nl-hr21-schaal-consistentie check has one resolvable mapping to demonstrate against; NOT an actual VNG/HR21 confirmation.',
                ],
                [
                    'functiecode'       => 'HR21-003',
                    'naam'              => 'Beleidsmedewerker',
                    'functiegroep'      => 'Beleid',
                    'caoSchaal'         => '9',
                    'caoSchaalVerified' => false,
                    'caoSchaalSource'   => 'HR21/VNG functieboek — mapping not yet independently confirmed against a primary source.',
                ],
                [
                    'functiecode'       => 'HR21-004',
                    'naam'              => 'Teammanager',
                    'functiegroep'      => 'Management',
                    'caoSchaal'         => '11',
                    'caoSchaalVerified' => false,
                    'caoSchaalSource'   => 'HR21/VNG functieboek — mapping not yet independently confirmed against a primary source.',
                ],
                [
                    'functiecode'       => 'HR21-005',
                    'naam'              => 'Afdelingsmanager',
                    'functiegroep'      => 'Management',
                    'caoSchaal'         => '13',
                    'caoSchaalVerified' => false,
                    'caoSchaalSource'   => 'HR21/VNG functieboek — mapping not yet independently confirmed against a primary source.',
                ],
            ],
        ];

    }//end seedObjects()


    /**
     * The `nl-hr21-schaal-consistentie` predicate (spec.md REQ-HR21-003):
     * vacuous when `normfunctieId` is absent, does not resolve to a
     * `Normfunctie` (via the `hr21.normfunctiesById` audit context), or the
     * resolved Normfunctie's `caoSchaalVerified` is not `true`. Otherwise
     * requires the contract's own `caoSchaal` to equal the resolved
     * Normfunctie's `caoSchaal`.
     *
     * @param array<string, mixed> $contract The EmploymentContract.
     * @param array<string, mixed> $context  Evaluation context; reads `hr21.normfunctiesById`.
     *
     * @return bool
     *
     * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
     */
    private static function schaalConsistentieSatisfied(array $contract, array $context): bool
    {
        $normfunctieId = trim((string) ($contract['normfunctieId'] ?? ''));
        if ($normfunctieId === '') {
            // No normfunctie assigned -- out of scope.
            return true;
        }

        $normfunctie = ($context['hr21']['normfunctiesById'][$normfunctieId] ?? null);
        if (is_array($normfunctie) === false) {
            // Unresolvable normfunctie -- nothing decidable from this object alone.
            return true;
        }

        if (($normfunctie['caoSchaalVerified'] ?? false) !== true) {
            // Unverified/placeholder mapping -- advisory, never a false mandatory violation.
            return true;
        }

        $mappedSchaal = trim((string) ($normfunctie['caoSchaal'] ?? ''));
        if ($mappedSchaal === '') {
            return true;
        }

        $contractSchaal = trim((string) ($contract['caoSchaal'] ?? ''));
        return $contractSchaal === $mappedSchaal;

    }//end schaalConsistentieSatisfied()


}//end class
