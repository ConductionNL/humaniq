<?php

/**
 * Unit tests for ExprEvaluator — the CLOSED, TOTAL arithmetic grammar.
 *
 * These tests exist to make widening the grammar loud. ADR-101 decision 2
 * forbids turning `expr` into a general language, because "uploaded config"
 * stops being distinguishable from "uploaded code" the moment it can express
 * computation the validator cannot bound. The vocabulary test below pins the
 * exact function list; if someone adds an op, this test fails and they have to
 * argue with the ADR rather than quietly ship it.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Payroll\Dsl
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

namespace OCA\Hrmq\Tests\Unit\Payroll\Dsl;

use OCA\Hrmq\Payroll\Dsl\DslException;
use OCA\Hrmq\Payroll\Dsl\ExprEvaluator;
use OCA\Hrmq\Payroll\Dsl\RefResolver;
use OCA\Hrmq\Payroll\Dsl\StepContext;
use OCA\Hrmq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Grammar-closure tests for the `expr` calculator.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */
class ExprEvaluatorTest extends TestCase
{


    /**
     * The grammar's function list is the ENTIRE vocabulary. Pinning it here
     * makes widening `expr` a deliberate, visible act (ADR-101 decision 2).
     *
     * @return void
     */
    public function testTheFunctionVocabularyIsClosedAndUnchanged(): void
    {
        $this->assertSame(
            ['min', 'max', 'abs', 'round', 'floor', 'ceil'],
            array_keys(ExprEvaluator::FUNCTIONS),
            'The expr grammar was widened. ADR-101 decision 2 forbids this: widening expr voids the "config, not code" trust model. Use the named escape hatch.'
        );

    }//end testTheFunctionVocabularyIsClosedAndUnchanged()


    /**
     * @return void
     */
    public function testArithmeticRespectsPrecedenceAndParentheses(): void
    {
        $this->assertSame(14, $this->eval('2 + 3 * 4'));
        $this->assertSame(20, $this->eval('(2 + 3) * 4'));
        $this->assertSame(-6, $this->eval('-2 * 3'));
        $this->assertSame(2, $this->eval('8 / 4'));
        $this->assertSame(2.5, $this->eval('5 / 2'));

    }//end testArithmeticRespectsPrecedenceAndParentheses()


    /**
     * @return void
     */
    public function testTheAllowedFunctionsEvaluate(): void
    {
        $this->assertSame(2, $this->eval('min(2, 7)'));
        $this->assertSame(7, $this->eval('max(2, 7)'));
        $this->assertSame(3, $this->eval('abs(-3)'));
        $this->assertSame(2.0, $this->eval('floor(2.7)'));
        $this->assertSame(3.0, $this->eval('ceil(2.1)'));
        $this->assertSame(2.35, $this->eval('round(2.34567, 2)'));

    }//end testTheAllowedFunctionsEvaluate()


    /**
     * An unknown identifier is REJECTED, not ignored — there is no route from
     * a pack string to a host function.
     *
     * @return void
     */
    public function testAnUnknownFunctionIsRejected(): void
    {
        $this->expectException(DslException::class);
        $this->expectExceptionMessageMatches('/onbekende functie "system"/');

        $this->eval('system(1, 2)');

    }//end testAnUnknownFunctionIsRejected()


    /**
     * @return void
     */
    public function testUnexpectedCharactersAreRejected(): void
    {
        $this->expectException(DslException::class);

        $this->eval('1 ; 2');

    }//end testUnexpectedCharactersAreRejected()


    /**
     * REQ-JP-008: an over-deep expression is rejected at parse time, and the
     * error names the depth bound.
     *
     * @return void
     */
    public function testAnOverDeepExpressionIsRejectedNamingTheBound(): void
    {
        $deep = str_repeat('(', 40).'1'.str_repeat(')', 40);

        $this->expectException(DslException::class);
        $this->expectExceptionMessageMatches('/nestdiepte van '.ExprEvaluator::MAX_DEPTH.'/');

        (new ExprEvaluator())->parse($deep);

    }//end testAnOverDeepExpressionIsRejectedNamingTheBound()


    /**
     * @return void
     */
    public function testAnOverLongExpressionIsRejected(): void
    {
        $this->expectException(DslException::class);
        $this->expectExceptionMessageMatches('/maximale lengte/');

        (new ExprEvaluator())->parse('1 + '.str_repeat('1 + ', 800).'1');

    }//end testAnOverLongExpressionIsRejected()


    /**
     * @return void
     */
    public function testDivisionByZeroIsRejectedRatherThanProducingInfinity(): void
    {
        $this->expectException(DslException::class);
        $this->expectExceptionMessageMatches('/deling door nul/');

        $this->eval('1 / 0');

    }//end testDivisionByZeroIsRejectedRatherThanProducingInfinity()


    /**
     * Parsing is separate from evaluation, so the validator can bound an
     * expression at upload without executing it.
     *
     * @return void
     */
    public function testRefsAreCollectableWithoutEvaluating(): void
    {
        $expr = new ExprEvaluator();
        $tree = $expr->parse('min(@binding.a, @step.b) + @table.zvw.werkgeversheffing');

        $this->assertSame(['@binding.a', '@step.b', '@table.zvw.werkgeversheffing'], $expr->refsOf($tree));

    }//end testRefsAreCollectableWithoutEvaluating()


    /**
     * Evaluate an expression with no references.
     *
     * @param string $expression The expression.
     *
     * @return int|float
     */
    private function eval(string $expression): int|float
    {
        $expr = new ExprEvaluator();
        $ctx  = new StepContext([], TaxTables::load('nl-2026'), '2026-02', []);

        return $expr->evaluate($expr->parse($expression), $ctx, new RefResolver());

    }//end eval()


}//end class
