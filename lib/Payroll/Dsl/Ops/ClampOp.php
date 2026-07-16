<?php

/**
 * Clamp Op
 *
 * `clamp(value, min?, max?)` — bound a value (jurisdiction-packs design.md D3).
 *
 * NL use: the `tvl` binding (`max(0, gross)`), reproducing the HEAD
 * calculator's opening guard against a negative gross wage.
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
use OCA\Hrmq\Payroll\Dsl\StepContext;

/**
 * Bound a value between an optional floor and an optional ceiling.
 */
final class ClampOp extends AbstractOp
{


    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function name(): string
    {
        return 'clamp';

    }//end name()


    /**
     * `min(max(value, min), max)`, applying only the declared bounds.
     *
     * @param array<string, mixed> $spec The declared spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return int|float
     *
     * @throws DslException When neither bound is declared.
     */
    public function evaluate(array $spec, StepContext $ctx): mixed
    {
        $value = $this->num($spec, 'value', $ctx);
        $floor = $this->optionalNum($spec, 'min', $ctx);
        $ceil  = $this->optionalNum($spec, 'max', $ctx);

        if ($floor === null && $ceil === null) {
            throw new DslException('Pack: op "clamp" verwacht ten minste "min" of "max".');
        }

        if ($floor !== null) {
            $value = max($value, $floor);
        }

        if ($ceil !== null) {
            $value = min($value, $ceil);
        }

        return $value;

    }//end evaluate()


}//end class
