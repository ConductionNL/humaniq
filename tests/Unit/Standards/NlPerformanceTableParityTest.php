<?php

/**
 * Table/legacy parity for the converted performance-review rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the
 * `nl-performance-dossiervorming` rule is now a declared decision table, and
 * its pre-conversion predicate stays as the parity oracle
 * (`NlPerformanceChecks::legacyChecks()`). This suite drives both paths over a
 * pinned fixture matrix (every status, missing, blank and whitespace-only
 * rating/afspraken) and asserts identical verdicts on every fixture.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlPerformanceChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Parity between the table-declared performance check and its legacy predicate.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */
class NlPerformanceTableParityTest extends TestCase {

	/**
	 * The pinned PerformanceReview fixture matrix: every status crossed with
	 * present, missing, blank and whitespace-only rating and afspraken.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fixtures(): array {
		$statuses = ['concept', 'ingediend', 'besproken', 'vastgesteld', 'ondertekend', '', 'Vastgesteld', null];
		$values = [
			'present' => 'goed',
			'missing' => null,
			'blank' => '',
			'whitespace' => '   ',
		];

		$fixtures = [];
		foreach ($statuses as $status) {
			foreach ($values as $ratingLabel => $rating) {
				foreach ($values as $afsprakenLabel => $afspraken) {
					$label = sprintf('status=%s rating=%s afspraken=%s', var_export($status, true), $ratingLabel, $afsprakenLabel);
					$fixtures[$label] = [
						'employeeId' => 'employee-visser',
						'cycleId' => 'review-cycle-2026',
						'status' => $status,
						'rating' => $rating,
						'afspraken' => ($afspraken === 'goed' ? 'Volgt in Q3 de cursus Advanced Vue.' : $afspraken),
					];
				}
			}
		}

		$fixtures['status absent'] = ['employeeId' => 'employee-visser', 'rating' => null, 'afspraken' => null];

		return $fixtures;

	}//end fixtures()

	/**
	 * Evaluate one table-declared check the way the engine's wrapper does.
	 *
	 * @param array{derive: callable, table: array<string, mixed>} $spec The declared check.
	 * @param array<string, mixed> $review The fixture.
	 *
	 * @return bool
	 */
	private function tableVerdict(array $spec, array $review): bool {
		$inputs = ($spec['derive'])($review, ['jurisdiction' => 'NL']);
		if ($inputs === null) {
			return true;
		}

		return TableCheckEvaluator::satisfied($spec['table'], $inputs);

	}//end tableVerdict()

	/**
	 * The table path and the legacy oracle agree on every fixture.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlPerformanceChecks::tables()['PerformanceReview'];
		$legacy = NlPerformanceChecks::legacyChecks()['PerformanceReview'];

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy rule has a table form and vice versa');

		foreach ($this->fixtures() as $label => $review) {
			foreach ($tables as $ruleId => $spec) {
				$this->assertSame(
					(bool)($legacy[$ruleId])($review),
					$this->tableVerdict($spec, $review),
					sprintf('%s: table and legacy verdicts diverge on fixture "%s"', $ruleId, $label)
				);
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * The matrix exercises both verdicts, so agreement is not vacuous.
	 *
	 * @return void
	 */
	public function testTheMatrixExercisesBothVerdicts(): void {
		$spec = NlPerformanceChecks::tables()['PerformanceReview']['nl-performance-dossiervorming'];

		$seen = [];
		foreach ($this->fixtures() as $review) {
			$seen[$this->tableVerdict($spec, $review) === true ? 'pass' : 'fail'] = true;
		}

		$this->assertSame(['fail' => true, 'pass' => true], ['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)]);

	}//end testTheMatrixExercisesBothVerdicts()

}//end class
