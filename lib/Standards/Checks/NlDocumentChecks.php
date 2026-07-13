<?php

/**
 * NL Contract-Document Check Provider
 *
 * Executable check for the written-contract document-evidence rule
 * (`nl-contract-schriftelijk`, lib/Standards/rules/labour.json,
 * hrmq-docudesk-documents), mapped onto the `EmploymentContract` object type.
 *
 * The predicate is cross-object: it reads the `context['documents']
 * ['generatedArbeidsovereenkomstByContract']` index `RuleAuditService::audit()`
 * populates in its pre-pass (a contractId => true map, present only for
 * contracts with an active `generated` arbeidsovereenkomst `GeneratedDocument`)
 * rather than loading sibling rows itself. The violation is on the CONTRACT
 * (a missing document), not on the document schema -- a permanent contract
 * with `writtenContract: true` and no entry in the index is non-compliant;
 * every other contract (temporary/agency/minijob, or not written) is
 * vacuously compliant.
 *
 * This provider does NOT implement SeedsObjects: the seeded
 * `contract-jansen-permanent` + its `generated` arbeidsovereenkomst live
 * declaratively in `lib/Settings/register.d/hr-documents.json` (ADR-001), the
 * same pattern NlGlPostChecks/NlPensionFilingChecks document for cross-object
 * predicates whose sample would otherwise need a resolvable sibling reference.
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
 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-009
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Written-permanent-contract document-evidence executable check.
 */
final class NlDocumentChecks implements CheckProvider
{


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'EmploymentContract' => [
                'nl-contract-schriftelijk' => static fn(array $o, array $context): bool => self::isCompliant($o, $context),
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
     * The `nl-contract-schriftelijk` predicate (spec.md REQ-HDD-009): a
     * permanent, written contract must have an active generated
     * arbeidsovereenkomst GeneratedDocument on file.
     *
     * @param array<string, mixed> $o       The EmploymentContract object.
     * @param array<string, mixed> $context Evaluation context; reads `documents.generatedArbeidsovereenkomstByContract`.
     *
     * @return bool
     */
    private static function isCompliant(array $o, array $context): bool
    {
        $permanent = ((string) ($o['type'] ?? '') === 'permanent');
        $written   = (($o['writtenContract'] ?? false) === true);
        if ($permanent === false || $written === false) {
            // Non-permanent or unwritten contracts carry no document-evidence
            // obligation under this rule -- vacuously compliant.
            return true;
        }

        $contractId = (string) ($o['id'] ?? $o['@self']['id'] ?? '');
        if ($contractId === '') {
            // No identity to key the document index on (e.g. an unpersisted
            // sample) -- never fabricate a violation without a resolvable id.
            return true;
        }

        return (bool) ($context['documents']['generatedArbeidsovereenkomstByContract'][$contractId] ?? false);

    }//end isCompliant()


}//end class
