<?php

/**
 * Taper Op
 *
 * `taper(base, value, threshold, rate, floor)` =
 * `max(floor, base - max(0, value - threshold) * rate)`
 * (jurisdiction-packs design.md D3).
 *
 * NL use: the AHK (algemene heffingskorting) and OUK (ouderenkorting)
 * phase-outs.
 *
 * The op is kept as its own name rather than folded into `expr` for intent and
 * auditability (design.md D3): `taper` says "this is a phase-out"; the
 * equivalent `expr` says "some arithmetic". A named op is individually
 * validatable and diffable across countries.
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

use OCA\Hrmq\Payroll\Dsl\StepContext;

/**
 * A linear phase-out above a threshold, bounded below by a floor.
 */
final class TaperOp extends AbstractOp
{


    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function name(): string
    {
        return 'taper';

    }//end name()


    /**
     * `max(floor, base - max(0, value - threshold) * rate)`.
     *
     * @param array<string, mixed> $spec The declared spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return int|float
     */
    public function evaluate(array $spec, StepContext $ctx): mixed
    {
        $base      = $this->num($spec, 'base', $ctx);
        $value     = $this->num($spec, 'value', $ctx);
        $threshold = $this->num($spec, 'threshold', $ctx);
        $rate      = $this->num($spec, 'rate', $ctx);
        $floor     = $this->optionalNum($spec, 'floor', $ctx, 0);

        $excess  = max(0, ($value - $threshold));
        $tapered = ($base - ($excess * $rate));

        return max((float) $floor, (float) $tapered);

    }//end evaluate()


}//end class
