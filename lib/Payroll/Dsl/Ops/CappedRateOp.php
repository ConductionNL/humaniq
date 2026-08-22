<?php

/**
 * Capped Rate Op
 *
 * `cappedRate(base, rate, cap)` = `min(base, cap) * rate / 100`
 * (jurisdiction-packs design.md D3).
 *
 * NL use: Zvw over the capped bijdrageloon, and the four capped employer
 * premiums Awf/Aof/Wko/Whk over the capped premieloon. All five declare
 * `employer-cost` — which is precisely why NL's net comes out as
 * `gross - loonheffing` from the interpreter's incidence fold without the
 * interpreter knowing anything about the Netherlands (design.md D2).
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
 * A percentage of a base, capped at a ceiling.
 */
final class CappedRateOp extends AbstractOp {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'cappedRate';
	}//end name()

	/**
	 * `min(base, cap) * rate / 100`.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 * @param StepContext $ctx The run context.
	 *
	 * @return int|float
	 */
	public function evaluate(array $spec, StepContext $ctx): mixed {
		$base = $this->num($spec, 'base', $ctx);
		$rate = $this->num($spec, 'rate', $ctx);
		$cap = $this->num($spec, 'cap', $ctx);

		return ((min($base, $cap) * $rate) / 100);
	}//end evaluate()

}//end class
