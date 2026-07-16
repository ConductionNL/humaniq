<?php

/**
 * Php Step Op
 *
 * `phpStep(handler, params?)` — the named escape hatch (jurisdiction-packs
 * design.md D9, ADR-101 decision 3).
 *
 * This op reads exactly two things from a pack: a handler NAME and a data-only
 * params map. It resolves the name against the compile-time allow-list. There
 * is no branch here that loads a class path, evaluates a string, or invokes a
 * callable supplied by the pack — `PackValidator` additionally rejects any
 * step carrying such a key outright (REQ-JP-005).
 *
 * **It never degrades gracefully.** An unresolvable handler throws rather than
 * skipping the step: a skipped step would silently under-tax someone, and the
 * pack would have been rejected at upload long before reaching here anyway.
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll\Dsl\Ops;

use OCA\Hrmq\Payroll\Dsl\DslException;
use OCA\Hrmq\Payroll\Dsl\RefResolver;
use OCA\Hrmq\Payroll\Dsl\StepContext;
use OCA\Hrmq\Payroll\StepHandlerRegistry;

/**
 * Invoke an allow-listed national-exotica handler by name.
 */
final class PhpStepOp extends AbstractOp
{


    /**
     * @param RefResolver         $refs     The reference resolver.
     * @param StepHandlerRegistry $registry The compile-time handler allow-list.
     */
    public function __construct(RefResolver $refs, private readonly StepHandlerRegistry $registry)
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
        return 'phpStep';

    }//end name()


    /**
     * Resolve the declared handler name and invoke it.
     *
     * @param array<string, mixed> $spec The declared spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return int|float
     *
     * @throws DslException When no handler name is declared, or the name is
     *                      not on the allow-list.
     */
    public function evaluate(array $spec, StepContext $ctx): mixed
    {
        $handler = ($spec['handler'] ?? null);
        if (is_string($handler) === false || trim($handler) === '') {
            throw new DslException('Pack: op "phpStep" mist de verplichte parameter "handler".');
        }

        $params = ($spec['params'] ?? []);
        if (is_array($params) === false) {
            throw new DslException('Pack: de "params" van op "phpStep" moeten een object zijn.');
        }

        return $this->registry->get($handler)->handle($params, $ctx);

    }//end evaluate()


}//end class
