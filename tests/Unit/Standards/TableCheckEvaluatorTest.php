<?php

/**
 * Unit tests for TableCheckEvaluator — the one door from humaniq's compliance
 * engine to OpenRegister's shared decision-table evaluator.
 *
 * Runs against the REAL evaluation semantics (on a standalone run the
 * bootstrap loads the vendored verbatim copies of OR's pure Dmn classes; on a
 * full checkout the real classes win): the satisfied mapping, the
 * anything-but-true-is-a-violation rule, error propagation, and the loud
 * refusal when OpenRegister is absent (fail closed).
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\TableCheckEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared-evaluator delegate.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
class TableCheckEvaluatorTest extends TestCase {

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		TableCheckEvaluator::reset();

	}//end tearDown()

	/**
	 * A minimal satisfied/violated gate: one number input, FIRST hit policy,
	 * catch-all last row.
	 *
	 * @return array<string, mixed>
	 */
	private function gate(): array {
		return [
			'hitPolicy' => 'FIRST',
			'inputs' => [
				['name' => 'value', 'type' => 'number'],
			],
			'outputs' => [
				['name' => 'satisfied', 'type' => 'boolean'],
			],
			'rules' => [
				['id' => 'pass', 'inputEntries' => ['>=0'], 'outputEntries' => [true]],
				['id' => 'fail', 'inputEntries' => ['-'], 'outputEntries' => [false]],
			],
		];

	}//end gate()

	/**
	 * @return void
	 */
	public function testSatisfiedWhenTheTableDecidesTrue(): void {
		$this->assertTrue(TableCheckEvaluator::satisfied($this->gate(), ['value' => 12.5]));
		$this->assertTrue(TableCheckEvaluator::satisfied($this->gate(), ['value' => 0]));

	}//end testSatisfiedWhenTheTableDecidesTrue()

	/**
	 * @return void
	 */
	public function testUnsatisfiedWhenTheTableDecidesFalse(): void {
		$this->assertFalse(TableCheckEvaluator::satisfied($this->gate(), ['value' => -0.25]));

	}//end testUnsatisfiedWhenTheTableDecidesFalse()

	/**
	 * A table that never declares the `satisfied` output can never pass —
	 * anything but a strict boolean true is unsatisfied.
	 *
	 * @return void
	 */
	public function testTableWithoutSatisfiedOutputNeverPasses(): void {
		$table = $this->gate();
		$table['outputs'] = [['name' => 'verdict', 'type' => 'string']];
		$table['rules'] = [
			['id' => 'pass', 'inputEntries' => ['>=0'], 'outputEntries' => ['ok']],
			['id' => 'fail', 'inputEntries' => ['-'], 'outputEntries' => ['nope']],
		];

		$this->assertFalse(TableCheckEvaluator::satisfied($table, ['value' => 5]));

	}//end testTableWithoutSatisfiedOutputNeverPasses()

	/**
	 * The shared evaluator's typed refusal propagates unchanged (RuleEngine's
	 * throwing-predicate path then converts it into a violation): an
	 * unimplemented hit policy is refused, never silently defaulted.
	 *
	 * @return void
	 */
	public function testEvaluatorRefusalPropagates(): void {
		$table = $this->gate();
		$table['hitPolicy'] = 'RULE ORDER';

		$this->expectException(\RuntimeException::class);
		TableCheckEvaluator::satisfied($table, ['value' => 1]);

	}//end testEvaluatorRefusalPropagates()

	/**
	 * Without a resolvable OpenRegister evaluator the delegate refuses loudly
	 * (fail closed) — it must never fall back to a local matcher or a silent
	 * pass. The class-name override stands in for an absent OpenRegister,
	 * since the vendored test copies make the real name resolvable here.
	 *
	 * @return void
	 */
	public function testMissingOpenRegisterFailsClosed(): void {
		TableCheckEvaluator::overrideEvaluatorClass('OCA\\OpenRegister\\Service\\Dmn\\DoesNotExistAnywhere');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/unavailable/');
		TableCheckEvaluator::satisfied($this->gate(), ['value' => 1]);

	}//end testMissingOpenRegisterFailsClosed()

	/**
	 * reset() restores the default resolution after an override.
	 *
	 * @return void
	 */
	public function testResetRestoresTheDefaultEvaluator(): void {
		TableCheckEvaluator::overrideEvaluatorClass('OCA\\OpenRegister\\Service\\Dmn\\DoesNotExistAnywhere');
		TableCheckEvaluator::reset();

		$this->assertTrue(TableCheckEvaluator::satisfied($this->gate(), ['value' => 3]));

	}//end testResetRestoresTheDefaultEvaluator()

}//end class
