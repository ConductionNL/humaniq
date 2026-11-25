<?php

/**
 * Table/legacy parity for the converted UPA rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the PensionFiling
 * run-approval and PayrollRun monthly-completeness rules of
 * NlPensionFilingChecks are declared decision tables, and their pre-conversion predicates stay as the
 * parity oracle (`NlPensionFilingChecks::legacyChecks()`). This suite drives
 * both paths over pinned matrices (run reference x referenced run status;
 * jurisdiction x status x period x filed periods) and asserts identical
 * verdicts on every fixture.
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlPensionFilingChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the UPA run-approval and completeness rules.
 */
class NlPensionFilingTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$references = ['run-1' => 'run-1', 'padded' => ' run-1 ', 'blank' => '', 'absent' => null, 'other' => 'run-2'];
		$runs = [
			'approved' => ['run-1' => ['status' => 'approved']],
			'posted' => ['run-1' => ['status' => 'posted']],
			'paid' => ['run-1' => ['status' => 'paid']],
			'draft' => ['run-1' => ['status' => 'draft']],
			'no status' => ['run-1' => []],
			'not an array' => ['run-1' => 'approved'],
			'none' => [],
		];

		$filings = [];
		foreach ($references as $rLabel => $reference) {
			foreach ($runs as $runLabel => $byId) {
				$filings[sprintf('ref=%s run=%s', $rLabel, $runLabel)] = [
					['payrollRunId' => $reference],
					['related' => ['PayrollRun' => ['byId' => $byId]]],
				];
			}
		}

		$filings['no run index'] = [['payrollRunId' => 'run-1'], []];

		$jurisdictions = ['NL' => 'NL', 'nl' => 'nl', 'BE' => 'BE', 'absent' => null];
		$statuses = ['approved', 'posted', 'paid', 'draft', ''];
		$periods = ['2026-05' => '2026-05', 'blank' => ''];
		$filed = ['filed' => ['2026-05'], 'other' => ['2026-04'], 'none' => []];

		$payrollRuns = [];
		foreach ($jurisdictions as $jLabel => $jurisdiction) {
			foreach ($statuses as $status) {
				foreach ($periods as $pLabel => $period) {
					foreach ($filed as $fLabel => $filedPeriods) {
						$payrollRuns[sprintf('%s %s period=%s filed=%s', $jLabel, $status, $pLabel, $fLabel)] = [
							['jurisdiction' => $jurisdiction, 'status' => $status, 'period' => $period],
							['related' => ['PensionFiling' => ['filedPeriods' => $filedPeriods]]],
						];
					}
				}
			}
		}

		$payrollRuns['no filing index'] = [['jurisdiction' => 'NL', 'status' => 'approved', 'period' => '2026-05'], []];

		return ['PensionFiling' => $filings, 'PayrollRun' => $payrollRuns];

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
		$tables = NlPensionFilingChecks::tables();
		$legacy = NlPensionFilingChecks::legacyChecks();
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
		foreach (NlPensionFilingChecks::tables() as $objectType => $specs) {
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
	 * Only the deadline alert stays a closure, and no rule is registered both
	 * ways.
	 *
	 * @return void
	 */
	public function testOnlyTheDeadlineAlertStaysAClosure(): void {
		$closures = NlPensionFilingChecks::checks();
		$this->assertSame(['PensionFiling' => ['nl-upa-deadline-alert']], array_map('array_keys', $closures));

		foreach (NlPensionFilingChecks::tables() as $objectType => $specs) {
			$this->assertSame([], array_intersect(array_keys($specs), array_keys($closures[$objectType] ?? [])), $objectType);
		}

	}//end testOnlyTheDeadlineAlertStaysAClosure()

}//end class
