<?php

/**
 * Quantize Op
 *
 * `quantize(value, step, mode: floor|ceil|nearest)` — round a value to a
 * multiple of `step` (jurisdiction-packs design.md D3).
 *
 * NL use: the tabelloon `L = floor(annualised / Lv) * Lv`. Because `Lv` is a
 * whole-euro multiple, the result is automatically a whole-euro amount — the
 * HEAD calculator relied on that same property (no separate floorEuro there).
 *
 * Integer operands take an exact integer path (`intdiv`), reproducing HEAD's
 * `intdiv($annualised, $lv) * $lv` bit-for-bit rather than round-tripping the
 * value through a float division that could lose precision on large wages.
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
use OCA\Hrmq\Payroll\Dsl\Rounder;
use OCA\Hrmq\Payroll\Dsl\StepContext;

/**
 * Round a value to a multiple of a declared step.
 */
final class QuantizeOp extends AbstractOp {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'quantize';
	}//end name()

	/**
	 * Round `value` to a multiple of `step`.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 * @param StepContext $ctx The run context.
	 *
	 * @return int|float
	 *
	 * @throws DslException When the step is not positive or the mode is unknown.
	 */
	public function evaluate(array $spec, StepContext $ctx): mixed {
		$value = $this->num($spec, 'value', $ctx);
		$step = $this->num($spec, 'step', $ctx);
		$mode = (string)($spec['mode'] ?? '');

		if (in_array($mode, Rounder::MODES, true) === false) {
			throw new DslException('Pack: op "quantize" kent de modus "' . $mode . '" niet (toegestaan: ' . implode(', ', Rounder::MODES) . ').');
		}

		if ($step <= 0) {
			throw new DslException('Pack: op "quantize" verwacht een positieve "step".');
		}

		if (is_int($value) === true && is_int($step) === true) {
			return $this->exact($value, $step, $mode);
		}

		return ($this->quotient(($value / $step), $mode) * $step);
	}//end evaluate()

	/**
	 * The exact integer path — no float division, so no precision loss.
	 *
	 * @param int $value The value.
	 * @param int $step The step.
	 * @param string $mode One of floor/ceil/nearest.
	 *
	 * @return int
	 */
	private function exact(int $value, int $step, string $mode): int {
		$quotient = intdiv($value, $step);
		$exact = (($quotient * $step) === $value);

		if ($mode === 'floor' && $exact === false && $value < 0) {
			$quotient--;
		}

		if ($mode === 'ceil' && $exact === false && $value > 0) {
			$quotient++;
		}

		if ($mode === 'nearest') {
			return ((int)round($value / $step) * $step);
		}

		return ($quotient * $step);
	}//end exact()

	/**
	 * Apply the mode to a float quotient.
	 *
	 * @param float $quotient The raw quotient.
	 * @param string $mode One of floor/ceil/nearest.
	 *
	 * @return float
	 */
	private function quotient(float $quotient, string $mode): float {
		return match ($mode) {
			'floor' => floor($quotient),
			'ceil' => ceil($quotient),
			default => round($quotient),
		};

	}//end quotient()

}//end class
