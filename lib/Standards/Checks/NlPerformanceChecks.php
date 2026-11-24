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
 * @package  OCA\Humaniq\Standards\Checks
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
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): the rule is declared as an OpenRegister decision
 * table via the ProvidesTables capability, so the status enumeration and the
 * two presence gates are matched by the shared Dmn\DecisionTableEvaluator and
 * only the derivation (trimmed presence of rating and afspraken) stays here.
 * The closure predicate remains available as legacyChecks() -- the parity
 * oracle, NOT registered in the engine -- until staged retirement
 * (REQ-RULE-009).
 *
 * @spec openspec/specs/performance-reviews/spec.md#REQ-PRV-003
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Performance-review dossiervorming completeness check (rating + afspraken
 * on a vastgesteld beoordeling).
 */
final class NlPerformanceChecks implements CheckProvider, ProvidesTables {

	/**
	 * The review statuses on which the dossier must be complete: set
	 * (`vastgesteld`) or set and signed by the employee (`ondertekend`,
	 * REQ-PRV-007).
	 *
	 * @var string
	 */
	private const FINAL_STATUSES = 'in ("vastgesteld","ondertekend")';

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the one check this provider owns is table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * BW art. 7:669 lid 3 sub d -- a set review must carry a rating and
	 * concrete afspraken (ontslagdossier). FIRST hit policy: a final review
	 * with both present is satisfied, a final review missing either is
	 * violated, every other status passes (an unfinished review legitimately
	 * has neither yet).
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/performance-reviews/spec.md#REQ-PRV-003
	 */
	public static function tables(): array {
		return [
			'PerformanceReview' => [
				'nl-performance-dossiervorming' => [
					'derive' => self::dossierInputs(...),
					'table' => [
						'hitPolicy' => 'FIRST',
						'inputs' => [
							['name' => 'status', 'type' => 'string'],
							['name' => 'ratingRecorded', 'type' => 'boolean'],
							['name' => 'afsprakenRecorded', 'type' => 'boolean'],
						],
						'outputs' => [
							['name' => 'satisfied', 'type' => 'boolean'],
						],
						'rules' => [
							['id' => 'complete', 'inputEntries' => [self::FINAL_STATUSES, 'true', 'true'], 'outputEntries' => [true]],
							['id' => 'incomplete', 'inputEntries' => [self::FINAL_STATUSES, '-', '-'], 'outputEntries' => [false]],
							['id' => 'not-final', 'inputEntries' => ['-', '-', '-'], 'outputEntries' => [true]],
						],
					],
				],
			],
		];

	}//end tables()

	/**
	 * The table's inputs: the status and whether a non-blank rating and
	 * afspraken are recorded.
	 *
	 * @param array<string, mixed> $o The PerformanceReview.
	 *
	 * @return array{status: string, ratingRecorded: bool, afsprakenRecorded: bool}
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function dossierInputs(array $o): array {
		return [
			'status' => (string)($o['status'] ?? ''),
			'ratingRecorded' => (trim((string)($o['rating'] ?? '')) !== ''),
			'afsprakenRecorded' => (trim((string)($o['afspraken'] ?? '')) !== ''),
		];

	}//end dossierInputs()

	/**
	 * The pre-conversion closure predicate, kept as the PARITY ORACLE only:
	 * not registered in the engine (checks() is empty), consumed by the
	 * table/legacy parity test, retired with the other converted rules after
	 * the table form is proven on an OpenRegister-backed audit run
	 * (REQ-RULE-009, tasks section 6).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 */
	public static function legacyChecks(): array {
		return [
			'PerformanceReview' => [
				'nl-performance-dossiervorming' => static fn (array $o): bool => self::dossiervormingSatisfied($o),
			],
		];

	}//end legacyChecks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True unless `status` is `vastgesteld` (or `ondertekend`, set and signed
	 * by the employee, REQ-PRV-007) and either `rating` is null/empty
	 * or `afspraken` is null/empty. All other statuses pass vacuously -- an
	 * unfinished review legitimately has no rating or afspraken yet.
	 *
	 * @param array<string, mixed> $o The PerformanceReview.
	 *
	 * @return bool
	 */
	private static function dossiervormingSatisfied(array $o): bool {
		if (in_array((string)($o['status'] ?? ''), ['vastgesteld', 'ondertekend'], true) === false) {
			return true;
		}

		$rating = trim((string)($o['rating'] ?? ''));
		$afspraken = trim((string)($o['afspraken'] ?? ''));

		return $rating !== '' && $afspraken !== '';
	}//end dossiervormingSatisfied()

}//end class
