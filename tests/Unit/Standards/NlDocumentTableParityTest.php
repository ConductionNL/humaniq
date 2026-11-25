<?php

/**
 * Table/legacy parity for the converted document-evidence rules (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the EmploymentContract and Payslip rules of NlDocumentChecks are declared
 * decision tables, and their pre-conversion predicates stay as the parity
 * oracle (`NlDocumentChecks::legacyChecks()`). This suite drives both paths
 * over a pinned matrix (contract type x written flag x id by `id`, `@self.id`
 * or none x document indexed true/false/absent; payslip id x indexed) and
 * asserts identical verdicts on every fixture.
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

use OCA\Humaniq\Standards\Checks\NlDocumentChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the document-evidence rules.
 */
class NlDocumentTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$types = ['permanent' => 'permanent', 'fixed-term' => 'fixed-term', 'absent' => null];
		$written = ['written' => true, 'unwritten' => false, 'truthy' => 1, 'absent' => null];
		$ids = ['id' => ['id' => 'c-1'], 'self' => ['@self' => ['id' => 'c-1']], 'none' => []];
		$indexes = ['documented' => ['c-1' => true], 'undocumented' => ['c-1' => false], 'absent' => []];

		$contracts = [];
		foreach ($types as $typeLabel => $type) {
			foreach ($written as $writtenLabel => $flag) {
				foreach ($ids as $idLabel => $identity) {
					foreach ($indexes as $indexLabel => $index) {
						$contracts[sprintf('type=%s %s id=%s doc=%s', $typeLabel, $writtenLabel, $idLabel, $indexLabel)] = [
							array_merge($identity, ['type' => $type, 'writtenContract' => $flag]),
							['documents' => ['generatedArbeidsovereenkomstByContract' => $index]],
						];
					}
				}
			}
		}

		$payslips = [];
		foreach ($ids as $idLabel => $identity) {
			foreach ($indexes as $indexLabel => $index) {
				$payslips[sprintf('id=%s doc=%s', $idLabel, $indexLabel)] = [
					$identity,
					['documents' => ['generatedLoonstrookByPayslip' => $index]],
				];
			}
		}

		$payslips['no documents index'] = [['id' => 'c-1'], []];

		return ['EmploymentContract' => $contracts, 'Payslip' => $payslips];

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
		$tables = NlDocumentChecks::tables();
		$legacy = NlDocumentChecks::legacyChecks();
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
		foreach (NlDocumentChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlDocumentChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
