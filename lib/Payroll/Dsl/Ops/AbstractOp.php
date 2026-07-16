<?php

/**
 * Abstract Op
 *
 * Shared parameter resolution for the DSL's step ops (jurisdiction-packs
 * design.md D3): every declared param is either a literal or a reference, and
 * money params must resolve to a number.
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
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll\Dsl\Ops;

use OCA\Hrmq\Payroll\Dsl\DslException;
use OCA\Hrmq\Payroll\Dsl\RefResolver;
use OCA\Hrmq\Payroll\Dsl\StepContext;

/**
 * Base class carrying the reference resolver and param helpers.
 */
abstract class AbstractOp implements StepOpInterface
{


    /**
     * @param RefResolver $refs The reference resolver.
     */
    public function __construct(protected readonly RefResolver $refs)
    {

    }//end __construct()


    /**
     * Resolve a required numeric param.
     *
     * @param array<string, mixed> $spec The declared spec.
     * @param string               $key  The param name.
     * @param StepContext          $ctx  The run context.
     *
     * @return int|float
     *
     * @throws DslException When the param is absent or non-numeric.
     */
    protected function num(array $spec, string $key, StepContext $ctx): int|float
    {
        if (array_key_exists($key, $spec) === false) {
            throw new DslException('Pack: op "'.$this->name().'" mist de verplichte parameter "'.$key.'".');
        }

        $value = $this->refs->value($spec[$key], $ctx);

        if (is_int($value) === true || is_float($value) === true) {
            return $value;
        }

        throw new DslException('Pack: parameter "'.$key.'" van op "'.$this->name().'" levert geen getal op.');

    }//end num()


    /**
     * Resolve an optional numeric param.
     *
     * @param array<string, mixed> $spec     The declared spec.
     * @param string               $key      The param name.
     * @param StepContext          $ctx      The run context.
     * @param int|float|null       $fallback The value when the param is absent.
     *
     * @return int|float|null
     */
    protected function optionalNum(array $spec, string $key, StepContext $ctx, int|float|null $fallback=null): int|float|null
    {
        if (array_key_exists($key, $spec) === false) {
            return $fallback;
        }

        return $this->num($spec, $key, $ctx);

    }//end optionalNum()


}//end class
