<?php

/**
 * Table/legacy parity for the converted GL-post idempotency rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the PayrollGLPost
 * rule of NlGlPostChecks is a declared decision table, and its pre-conversion predicate stays as the parity oracle
 * (`NlGlPostChecks::legacyChecks()`). This suite drives both paths over a
 * pinned matrix (status x active posts for the run x journal entry x
 * posted-at x lines snapshot) and asserts identical verdicts on every
 * fixture.
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

use OCA\Humaniq\Standards\Checks\NlGlPostChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the GL-post idempotency rule.
 */
class NlGlPostTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$statuses = ['pending' => 'pending', 'posted' => 'posted', 'failed' => 'failed', 'blank' => '', 'absent' => null];
		$counts = ['none' => null, 'one' => 1, 'two' => 2, 'string-two' => '2'];
		$journals = ['je-1' => 'je-1', 'blank' => ' ', 'absent' => null];
		$moments = ['set' => '2026-05-25T10:00:00Z', 'absent' => null];
		$lines = [
			'balanced' => [['side' => 'debit', 'amount' => '100.10'], ['side' => 'credit', 'amount' => 100.1]],
			'unbalanced' => [['side' => 'debit', 'amount' => 100], ['side' => 'credit', 'amount' => 99.99]],
			'empty' => [],
			'malformed' => [['side' => 'left', 'amount' => 10], ['side' => 'credit', 'amount' => 10]],
			'zero' => [['side' => 'debit', 'amount' => 0], ['side' => 'credit', 'amount' => 0]],
			'not-a-list' => 'x',
		];

		$posts = [];
		foreach ($statuses as $sLabel => $status) {
			foreach ($counts as $cLabel => $count) {
				foreach ($journals as $jLabel => $journal) {
					foreach ($moments as $mLabel => $moment) {
						foreach ($lines as $lLabel => $snapshot) {
							$posts[sprintf('%s active=%s journal=%s postedAt=%s lines=%s', $sLabel, $cLabel, $jLabel, $mLabel, $lLabel)] = [
								['status' => $status, 'payrollRunId' => 'run-1', 'journalEntryId' => $journal, 'postedAt' => $moment, 'lines' => $snapshot],
								['glpost' => ['activeCountByRun' => ($count === null ? [] : ['run-1' => $count])]],
							];
						}
					}
				}
			}
		}

		$posts['no glpost index'] = [['status' => 'pending', 'payrollRunId' => 'run-1'], []];

		return ['PayrollGLPost' => $posts];

	}//end fixtures()

	/**
	 * Evaluate one table-declared check the way the engine's wrapper does.
	 *
	 * @param array{derive: callable, table: array<string, mixed>} $spec    The declared check.
	 * @param array<string, mixed>                                 $object  The fixture.
	 * @param array<string, mixed>                                 $context The evaluation context.
	 *
	 * @return bool
	 */
	private function tableVerdict(array $spec, array $object, array $context): bool {
		$inputs = ($spec['derive'])($object, $context);
		if ($inputs === null) {
			return true;
		}

		return TableCheckEvaluator::satisfied($spec['table'], $inputs);

	}//end tableVerdict()

	/**
	 * Both rules answer identically through the table path and the legacy oracle.
	 *
	 * @return void
	 */
	public function testTableAndLegacyVerdictsAgreeOnEveryFixture(): void {
		$tables = NlGlPostChecks::tables();
		$legacy = NlGlPostChecks::legacyChecks();
		$fixtures = $this->fixtures();

		$this->assertSame(array_keys($legacy), array_keys($tables), 'every legacy object type has a table form and vice versa');

		foreach ($tables as $objectType => $specs) {
			$this->assertSame(array_keys($legacy[$objectType]), array_keys($specs));
			foreach ($fixtures[$objectType] as $label => [$object, $context]) {
				foreach ($specs as $ruleId => $spec) {
					$this->assertSame(
						(bool)($legacy[$objectType][$ruleId])($object, $context),
						$this->tableVerdict($spec, $object, $context),
						sprintf('%s/%s: table and legacy verdicts diverge on fixture "%s"', $objectType, $ruleId, $label)
					);
				}
			}
		}

	}//end testTableAndLegacyVerdictsAgreeOnEveryFixture()

	/**
	 * The matrix exercises both verdicts for every rule.
	 *
	 * @return void
	 */
	public function testTheMatrixExercisesBothVerdicts(): void {
		$fixtures = $this->fixtures();
		foreach (NlGlPostChecks::tables() as $objectType => $specs) {
			foreach ($specs as $ruleId => $spec) {
				$seen = [];
				foreach ($fixtures[$objectType] as [$object, $context]) {
					$seen[$this->tableVerdict($spec, $object, $context) === true ? 'pass' : 'fail'] = true;
				}

				$this->assertSame(
					['fail' => true, 'pass' => true],
					['fail' => ($seen['fail'] ?? false), 'pass' => ($seen['pass'] ?? false)],
					sprintf('%s/%s: the fixture matrix must exercise both verdicts', $objectType, $ruleId)
				);
			}
		}

	}//end testTheMatrixExercisesBothVerdicts()

	/**
	 * Only the table form is registered.
	 *
	 * @return void
	 */
	public function testTheRulesAreTableDeclaredOnly(): void {
		$this->assertSame([], NlGlPostChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
