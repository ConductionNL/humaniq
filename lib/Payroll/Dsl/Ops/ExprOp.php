<?php

/**
 * Expr Op
 *
 * `expr(expression)` — the closed, total arithmetic grammar
 * (jurisdiction-packs design.md D3, ADR-101 decision 2).
 *
 * `expr` is the LAST RESORT inside the DSL, not its centre: the named ops
 * carry intent (`taper` says "this is a phase-out"), while `expr` says only
 * "some arithmetic". NL uses it for the genuinely arithmetic joins — the
 * annualisation, the loonheffing tijdvakbedrag, the informative
 * volksverzekeringen split and the applied-rate percentage.
 *
 * Constraining this op's grammar to a total calculator is what keeps
 * "uploaded config" from becoming "uploaded code". See `ExprEvaluator` for the
 * grammar and why widening it is forbidden.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll\Dsl\Ops
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

namespace OCA\Hrmq\Payroll\Dsl\Ops;

use OCA\Hrmq\Payroll\Dsl\DslException;
use OCA\Hrmq\Payroll\Dsl\ExprEvaluator;
use OCA\Hrmq\Payroll\Dsl\RefResolver;
use OCA\Hrmq\Payroll\Dsl\StepContext;

/**
 * Evaluate a closed, total arithmetic expression.
 */
final class ExprOp extends AbstractOp
{


    /**
     * @param RefResolver   $refs The reference resolver.
     * @param ExprEvaluator $expr The closed-grammar expression evaluator.
     */
    public function __construct(RefResolver $refs, private readonly ExprEvaluator $expr)
    {
        parent::__construct($refs);

    }//end __construct()


    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function name(): string
    {
        return 'expr';

    }//end name()


    /**
     * Parse and evaluate the declared expression.
     *
     * @param array<string, mixed> $spec The declared spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return int|float
     *
     * @throws DslException When the expression is absent or malformed.
     */
    public function evaluate(array $spec, StepContext $ctx): mixed
    {
        $expression = ($spec['expression'] ?? null);
        if (is_string($expression) === false || trim($expression) === '') {
            throw new DslException('Pack: op "expr" mist de verplichte parameter "expression".');
        }

        return $this->expr->evaluate($this->expr->parse($expression), $ctx, $this->refs);

    }//end evaluate()


}//end class
