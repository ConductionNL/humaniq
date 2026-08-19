<?php

/**
 * Rate Op
 *
 * `rate(base, rate)` = `base * rate / 100` (jurisdiction-packs design.md D3).
 *
 * NL use: the vakantiegeld reservering (8% of the gross wage) — the step that
 * proves the `reserve` incidence, since it is neither cash to the employee
 * this period nor an employer charge this period.
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
 * A straight percentage of a base.
 */
final class RateOp extends AbstractOp {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'rate';
	}//end name()

	/**
	 * `base * rate / 100`.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 * @param StepContext $ctx The run context.
	 *
	 * @return int|float
	 */
	public function evaluate(array $spec, StepContext $ctx): mixed {
		$base = $this->num($spec, 'base', $ctx);
		$rate = $this->num($spec, 'rate', $ctx);

		return (($base * $rate) / 100);
	}//end evaluate()

}//end class
