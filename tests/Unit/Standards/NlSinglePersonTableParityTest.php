<?php

/**
 * Table/legacy parity for the converted single-person employee-count rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the Administration rule of NlSinglePersonChecks is a declared decision
 * table, and its pre-conversion predicate stays as the parity oracle
 * (`NlSinglePersonChecks::legacyChecks()`). This suite drives both paths
 * over a pinned matrix (mode x administration id x employee corpus) and
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

use OCA\Humaniq\Standards\Checks\NlSinglePersonChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the single-person employee-count rule.
 */
class NlSinglePersonTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$modes = ['single' => 'dga_single_person', 'standard' => 'standard', 'absent' => null];
		$administrations = ['A-1' => 'A-1', 'padded' => ' A-1 ', 'blank' => '', 'whitespace' => '  '];
		$dga = ['administrationId' => 'A-1', 'isDga' => true, 'endDate' => null];
		$employees = [
			'none' => [],
			'one dga' => [$dga],
			'one non-dga' => [array_merge($dga, ['isDga' => false])],
			'one truthy dga' => [array_merge($dga, ['isDga' => 1])],
			'one ended dga' => [array_merge($dga, ['endDate' => '2026-01-31'])],
			'dga ended blank' => [array_merge($dga, ['endDate' => ' '])],
			'two dga' => [$dga, $dga],
			'dga elsewhere' => [array_merge($dga, ['administrationId' => 'A-2'])],
			'dga + ended colleague' => [$dga, array_merge($dga, ['isDga' => false, 'endDate' => '2025-12-31'])],
			'dga + active colleague' => [$dga, array_merge($dga, ['isDga' => false])],
			'padded employee admin' => [array_merge($dga, ['administrationId' => ' A-1'])],
		];

		$administrationsUnderTest = [];
		foreach ($modes as $mLabel => $mode) {
			foreach ($administrations as $aLabel => $administrationId) {
				foreach ($employees as $eLabel => $corpus) {
					$byId = [];
					foreach ($corpus as $i => $employee) {
						$byId['e' . $i] = $employee;
					}

					$administrationsUnderTest[sprintf('mode=%s adm=%s employees=%s', $mLabel, $aLabel, $eLabel)] = [
						['mode' => $mode, 'administrationId' => $administrationId],
						['payroll' => ['employeesById' => $byId]],
					];
				}
			}
		}

		$administrationsUnderTest['no payroll index'] = [['mode' => 'dga_single_person', 'administrationId' => 'A-1'], []];

		return ['Administration' => $administrationsUnderTest];

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
		$tables = NlSinglePersonChecks::tables();
		$legacy = NlSinglePersonChecks::legacyChecks();
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
		foreach (NlSinglePersonChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlSinglePersonChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
