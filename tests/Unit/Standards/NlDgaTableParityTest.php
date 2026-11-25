<?php

/**
 * Table/legacy parity for the converted gebruikelijkloon rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the Employee rule
 * of NlDgaChecks is a declared decision table, and its pre-conversion
 * predicate stays as the parity oracle (`NlDgaChecks::legacyChecks()`). This
 * suite drives both paths over a pinned matrix (DGA flag x justification x
 * monthly salary from far below to far above the loaded norm) and asserts
 * identical verdicts on every fixture.
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

use OCA\Humaniq\Standards\Checks\NlDgaChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the gebruikelijkloon rule.
 */
class NlDgaTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$flags = ['dga' => true, 'not dga' => false, 'truthy' => 1, 'absent' => null];
		$justifications = ['none' => null, 'blank' => ' ', 'given' => 'Vergelijkbare functie lager betaald'];
		$salaries = ['tiny' => 1000, 'modest' => 4000.0, 'high' => 9000, 'huge' => 50000, 'string' => '4000', 'text' => 'n.v.t.', 'absent' => null];

		$employees = [];
		foreach ($flags as $fLabel => $flag) {
			foreach ($justifications as $jLabel => $justification) {
				foreach ($salaries as $sLabel => $salary) {
					$employees[sprintf('%s justification=%s salary=%s', $fLabel, $jLabel, $sLabel)] = [
						['isDga' => $flag, 'gebruikelijkloonJustification' => $justification, 'grossMonthlySalary' => $salary],
						[],
					];
				}
			}
		}

		return ['Employee' => $employees];

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
		$tables = NlDgaChecks::tables();
		$legacy = NlDgaChecks::legacyChecks();
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
		foreach (NlDgaChecks::tables() as $objectType => $specs) {
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
		$this->assertSame([], NlDgaChecks::checks());

	}//end testTheRulesAreTableDeclaredOnly()

}//end class
