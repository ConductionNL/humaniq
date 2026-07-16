<?php

/**
 * Step Op Interface
 *
 * One operation in the DSL's CLOSED step vocabulary (jurisdiction-packs
 * design.md D3). The set of implementations of this interface registered in
 * `OpRegistry` IS the vocabulary — a pack declaring an op outside it is
 * rejected at validation, naming the op (REQ-JP-002).
 *
 * Every op is a pure function of `(spec, context)`: no IO, no clock, no
 * network, no state carried between steps beyond the context's forward-only
 * bindings (REQ-JP-008).
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
 * One operation in the closed step vocabulary.
 */
interface StepOpInterface
{


    /**
     * The op's declared name, as a pack writes it.
     *
     * @return string
     */
    public function name(): string;


    /**
     * Evaluate the op against its declared spec.
     *
     * @param array<string, mixed> $spec The declared step/binding spec.
     * @param StepContext          $ctx  The run context.
     *
     * @return mixed
     */
    public function evaluate(array $spec, StepContext $ctx): mixed;


}//end interface
