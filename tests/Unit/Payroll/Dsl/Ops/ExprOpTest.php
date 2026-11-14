<?php

/**
 * Unit tests for ExprOp — the closed arithmetic grammar's step op.
 *
 * The op itself is thin: it refuses anything that is not a non-blank
 * expression string and otherwise hands the parsed tree to the evaluator with
 * the op's own resolver. The refusals are what matter — `expr` is the last
 * resort inside the DSL, and an absent expression must not evaluate to zero.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\ExprEvaluator;
use OCA\Humaniq\Payroll\Dsl\Ops\ExprOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `expr(expression)`.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\ExprOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\ExprEvaluator
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\ParsedRef
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class ExprOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('expr', $this->op()->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * The declared expression is parsed and evaluated, references and
	 * precedence included.
	 *
	 * @return void
	 */
	public function testTheDeclaredExpressionIsParsedAndEvaluated(): void {
		$this->assertEqualsWithDelta(37000.0, $this->evaluate('@input.gross * 37 / 100'), 1.0e-6);
		$this->assertEqualsWithDelta(12.0, $this->evaluate('2 + 2 * 5'), 1.0e-6, 'precedence, not left-to-right');
		$this->assertEqualsWithDelta(20.0, $this->evaluate('(2 + 2) * 5'), 1.0e-6);

	}//end testTheDeclaredExpressionIsParsedAndEvaluated()

	/**
	 * The op resolves references through the resolver it was constructed with,
	 * so a binding written by an earlier step is visible to the expression.
	 *
	 * @return void
	 */
	public function testAnEarlierBindingIsVisibleToTheExpression(): void {
		$this->assertEqualsWithDelta(1500.0, $this->evaluate('@binding.months * 125'), 1.0e-6);

	}//end testAnEarlierBindingIsVisibleToTheExpression()

	/**
	 * An absent, blank or non-string expression is refused, naming the op and
	 * the param — it never evaluates to zero.
	 *
	 * @return void
	 */
	public function testAnAbsentBlankOrNonStringExpressionIsRefused(): void {
		foreach ([[], ['expression' => ''], ['expression' => "  \n"], ['expression' => 42], ['expression' => null], ['expression' => ['1+1']]] as $spec) {
			try {
				$this->op()->evaluate($spec, $this->context());
				$this->fail('a malformed expression must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('op "expr"', $e->getMessage());
				$this->assertStringContainsString('"expression"', $e->getMessage());
			}
		}

	}//end testAnAbsentBlankOrNonStringExpressionIsRefused()

	/**
	 * An expression outside the closed grammar is refused by the evaluator
	 * through this op, rather than being executed as PHP.
	 *
	 * @return void
	 */
	public function testAnExpressionOutsideTheClosedGrammarIsRefused(): void {
		foreach (['phpinfo()', '@input.gross ** 2', '1 + '] as $expression) {
			try {
				$this->evaluate($expression);
				$this->fail('"' . $expression . '" is outside the grammar and must be refused');
			} catch (DslException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

	}//end testAnExpressionOutsideTheClosedGrammarIsRefused()

	/**
	 * Evaluate one expression.
	 *
	 * @param string $expression The declared expression.
	 *
	 * @return mixed
	 */
	private function evaluate(string $expression): mixed {
		return $this->op()->evaluate(['expression' => $expression], $this->context());
	}//end evaluate()

	/**
	 * The op under test.
	 *
	 * @return ExprOp
	 */
	private function op(): ExprOp {
		return new ExprOp(new RefResolver(), new ExprEvaluator());
	}//end op()

	/**
	 * A run context carrying one input and one binding.
	 *
	 * @return StepContext
	 */
	private function context(): StepContext {
		$ctx = new StepContext(
			['gross' => 100000],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);
		$ctx->setBinding('months', 12);

		return $ctx;
	}//end context()

}//end class
