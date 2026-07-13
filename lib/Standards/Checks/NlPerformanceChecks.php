<?php

/**
 * NL Performance Dossiervorming Check Provider
 *
 * Executable check for the single performance-review dossiervorming rule of
 * the labour corpus (lib/Standards/rules/labour.json, framework bw7-10):
 * `nl-performance-dossiervorming` (BW art. 7:669 lid 3 sub d, redelijke grond
 * disfunctioneren via de Wet werk en zekerheid) -- a `vastgesteld`
 * PerformanceReview must carry a non-null `rating` and non-empty `afspraken`,
 * because without a documented beoordeling and concrete afspraken there is no
 * ontslagdossier for an underperformance dismissal.
 *
 * The predicate is single-object (no cross-referencing context needed) and
 * evaluates only on `status: vastgesteld` -- a concept/ingediend/besproken
 * review legitimately lacks a rating and afspraken and passes vacuously
 * (performance-reviews-mvp design D8).
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
 * @spec openspec/changes/performance-reviews-mvp/specs/performance-reviews/spec.md#REQ-PRV-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Performance-review dossiervorming completeness check (rating + afspraken
 * on a vastgesteld beoordeling).
 */
final class NlPerformanceChecks implements CheckProvider
{


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'PerformanceReview' => [
                // BW art. 7:669 lid 3 sub d -- a vastgesteld beoordeling must
                // carry a rating and concrete afspraken (ontslagdossier).
                'nl-performance-dossiervorming' => static fn(array $o): bool => self::dossiervormingSatisfied($o),
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
     * True unless `status` is `vastgesteld` and either `rating` is null/empty
     * or `afspraken` is null/empty. All other statuses pass vacuously -- an
     * unfinished review legitimately has no rating or afspraken yet.
     *
     * @param array<string, mixed> $o The PerformanceReview.
     *
     * @return bool
     */
    private static function dossiervormingSatisfied(array $o): bool
    {
        if ((string) ($o['status'] ?? '') !== 'vastgesteld') {
            return true;
        }

        $rating    = trim((string) ($o['rating'] ?? ''));
        $afspraken = trim((string) ($o['afspraken'] ?? ''));

        return $rating !== '' && $afspraken !== '';

    }//end dossiervormingSatisfied()


}//end class
