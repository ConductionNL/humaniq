<?php

/**
 * Humaniq AccrualRun
 *
 * The state of one piecewise accrual: the subject value, the per-term
 * rounding, the run context and the chain built so far.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Dsl\Ops
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

namespace OCA\Humaniq\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\StepContext;

/**
 * One piecewise accrual being built.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */
final class AccrualRun {

	/**
	 * The subject value.
	 *
	 * @var int|float
	 */
	public int|float $value = 0;

	/**
	 * The per-term decimal rounding.
	 *
	 * @var int
	 */
	public int $roundTerm = 0;

	/**
	 * The run context.
	 *
	 * @var StepContext
	 */
	public StepContext $ctx;

	/**
	 * The accrued chain.
	 *
	 * @var float
	 */
	public float $chain = 0.0;
}//end class
