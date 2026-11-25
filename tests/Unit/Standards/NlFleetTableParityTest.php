<?php

/**
 * Table/legacy parity for the converted vehicle fiscal-fields rule (REQ-RULE-009).
 *
 * Next conversion wave of rules-onto-or-decision-tables: the Asset rule of
 * NlFleetChecks is a declared decision table, and its pre-conversion
 * predicate stays as the parity oracle (`NlFleetChecks::legacyChecks()`).
 * This suite drives both paths over a pinned matrix (category x each of the
 * three fiscal fields present, blank, whitespace, null or absent) and asserts
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Checks\NlFleetChecks;
use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins table/legacy verdict parity for the vehicle fiscal-fields rule.
 */
class NlFleetTableParityTest extends TestCase {

	/**
	 * The pinned fixture matrix per object type: label => [object, context].
	 *
	 * @return array<string, array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>>
	 */
	private function fixtures(): array {
		$values = ['set' => 'x', 'zero' => 0, 'blank' => '', 'whitespace' => ' ', 'null' => null, 'absent' => '__absent__'];
		$fields = ['listPrice' => 45000, 'fuelType' => 'electric', 'companyCarTaxCategory' => 'ev-2026'];

		$assets = [];
		foreach (['vehicle', 'laptop', ''] as $category) {
			foreach ($values as $lpLabel => $listPrice) {
				foreach ($values as $ftLabel => $fuelType) {
					foreach ($values as $tcLabel => $taxCategory) {
						$asset = ['category' => $category];
						foreach (array_combine(array_keys($fields), [$listPrice, $fuelType, $taxCategory]) as $field => $value) {
							if ($value === '__absent__') {
								continue;
							}

							$asset[$field] = ($value === 'x' ? $fields[$field] : $value);
						}

						$assets[sprintf('%s listPrice=%s fuelType=%s taxCategory=%s', $category, $lpLabel, $ftLabel, $tcLabel)] = [$asset, []];
					}
				}
			}
		}

		return ['Asset' => $assets];

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
		$tables = NlFleetChecks::tables();
		$legacy = NlFleetChecks::legacyChecks();
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
		foreach (NlFleetChecks::tables() as $objectType => $specs) {
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
	 * Only the bijtelling formula stays a closure, and no rule is registered
	 * both ways.
	 *
	 * @return void
	 */
	public function testOnlyTheBijtellingFormulaStaysAClosure(): void {
		$closures = NlFleetChecks::checks();
		$this->assertSame(['Payslip' => ['nl-bijtelling-auto-privegebruik']], array_map('array_keys', $closures));

		foreach (NlFleetChecks::tables() as $objectType => $specs) {
			$this->assertSame([], array_intersect(array_keys($specs), array_keys($closures[$objectType] ?? [])), $objectType);
		}

	}//end testOnlyTheBijtellingFormulaStaysAClosure()

}//end class
