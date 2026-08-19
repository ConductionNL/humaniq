<?php

/**
 * Jurisdiction Step Handler Interface
 *
 * The named PHP escape hatch for genuine national exotica (jurisdiction-packs
 * design.md D9, ADR-101 decision 3).
 *
 * A pack step may declare `{"op": "phpStep", "handler": "some-name"}`. The
 * handler NAME is resolved against a compile-time allow-list of classes
 * implementing this interface that already ship inside hrmq. **A pack supplies
 * a name and parameters; it can never supply code, a class path, a callable,
 * or a file.**
 *
 * Resolution happens at PACK-VALIDATION time, never at runtime. A pack naming
 * a handler that does not exist is rejected at upload with the offending name
 * in the error — it never reaches a payroll run to fail silently, and it never
 * "degrades gracefully" into a skipped step that quietly under-taxes someone.
 * This is the orphaned-capability defect class, and payroll is the worst
 * possible place to meet it.
 *
 * **hrmq ships ZERO implementations of this interface.** No NL step needs one
 * — all of NL is expressible in the declarative vocabulary. The registry
 * exists so the wall is built before the first country hits it; the honest
 * expectation (ADR-101) is that NL itself will be the first customer, at VCR.
 *
 * If a future pack's step list is mostly `phpStep`, the DSL has failed and
 * that is a finding to escalate, not to route around.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll
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

namespace OCA\Hrmq\Payroll;

use OCA\Hrmq\Payroll\Dsl\StepContext;

/**
 * One allow-listed national-exotica handler.
 */
interface JurisdictionStepHandlerInterface {

	/**
	 * The handler's allow-list name, as a pack's `handler` field writes it.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Compute this step's amount.
	 *
	 * @param array<string, mixed> $params The pack-declared params (data only — never code).
	 * @param StepContext $ctx The run context.
	 *
	 * @return int|float The amount, in integer cents.
	 */
	public function handle(array $params, StepContext $ctx): int|float;

}//end interface
