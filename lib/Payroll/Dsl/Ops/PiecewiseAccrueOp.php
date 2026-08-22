<?php

/**
 * Piecewise Accrue Op
 *
 * `piecewiseAccrue(value, segments[{upTo, rate, cap}], tail{from, rate}, zeroAbove, roundTerm)`
 * — a capped piecewise-linear build-up (jurisdiction-packs design.md D3).
 *
 * NL use: the ARK (arbeidskorting) opbouw min-chain, i.e. `arkChain()` at HEAD.
 *
 * **The ordering is the whole point.** Each segment's accumulated term is
 * rounded to `roundTerm` decimals FIRST and capped at that segment's own
 * ceiling SECOND, then accumulation continues; after the segments, the
 * descending `tail` subtracts per unit of excess; finally `zeroAbove` hard-zeroes
 * the chain. Swapping the round and the cap changes the answer: for the NL
 * anchor, segment 2's raw term is 530001,58 against a cap of 530000, so the cap
 * binds only after the 5-decimal rounding. That is exactly why `roundTerm` and
 * `cap` are separate declared knobs rather than a generic taper (design.md D5).
 *
 * A segment is only entered while `value` exceeds the previous segment's
 * boundary, reproducing HEAD's nested `if ($lCents > $ark['g1'])` guards — a
 * span may never go negative.
 *
 * **On probation (ADR-101).** This primitive was designed by staring at
 * `arkChain()`; its round-then-cap ordering is Rekenvoorschriften arcana.
 * Phase-in/phase-out credit schedules are a plausibly general shape, but the
 * generality is UNPROVEN until a second country lands on it. Country two either
 * validates this op or exposes it as NL-shaped. That is this change's central
 * unproven claim, and it is recorded rather than hidden.
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

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\Rounder;
use OCA\Humaniq\Payroll\Dsl\StepContext;

/**
 * A capped piecewise-linear accrual with a descending tail.
 */
final class PiecewiseAccrueOp extends AbstractOp {

	/**
	 * @param RefResolver $refs The reference resolver.
	 * @param Rounder $rounder The rounding modifier applier (for `roundTerm`).
	 */
	public function __construct(
		RefResolver $refs,
		private readonly Rounder $rounder,
	) {
		parent::__construct($refs);

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'piecewiseAccrue';
	}//end name()

	/**
	 * Build up the chain segment by segment, then apply the tail and the
	 * hard-zero ceiling.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 * @param StepContext $ctx The run context.
	 *
	 * @return float
	 *
	 * @throws DslException When the segment list is malformed.
	 */
	public function evaluate(array $spec, StepContext $ctx): mixed {
		$value = $this->num($spec, 'value', $ctx);
		if ($value <= 0) {
			return 0.0;
		}

		$segments = ($spec['segments'] ?? null);
		if (is_array($segments) === false || $segments === []) {
			throw new DslException('Pack: op "piecewiseAccrue" verwacht een niet-lege "segments"-lijst.');
		}

		$chain = $this->accrue($segments, $value, (int)($spec['roundTerm'] ?? 0), $ctx);
		$chain = $this->tail(($spec['tail'] ?? null), $chain, $value, (int)($spec['roundTerm'] ?? 0), $ctx);

		$zeroAbove = $this->optionalNum($spec, 'zeroAbove', $ctx);
		if ($zeroAbove !== null && $value > $zeroAbove) {
			return 0.0;
		}

		return max(0.0, $chain);
	}//end evaluate()

	/**
	 * Accumulate the segment terms, rounding each term BEFORE capping it.
	 *
	 * @param array<int, array<string, mixed>> $segments The declared segments.
	 * @param int|float $value The subject value.
	 * @param int $roundTerm The per-term decimal rounding.
	 * @param StepContext $ctx The run context.
	 *
	 * @return float
	 */
	private function accrue(array $segments, int|float $value, int $roundTerm, StepContext $ctx): float {
		$chain = 0.0;
		$previous = 0;

		foreach ($segments as $index => $segment) {
			if ($index > 0 && $value <= $previous) {
				return $chain;
			}

			$upTo = $this->num($segment, 'upTo', $ctx);
			$rate = $this->num($segment, 'rate', $ctx);
			$cap = $this->num($segment, 'cap', $ctx);

			$span = (min($value, $upTo) - $previous);
			$term = $this->round(($chain + ($span * $rate)), $roundTerm);
			$chain = min($term, (float)$cap);

			$previous = $upTo;
		}

		return $chain;
	}//end accrue()

	/**
	 * Apply the descending tail above its `from` boundary.
	 *
	 * @param array<string, mixed>|null $tail The declared tail, or null.
	 * @param float $chain The accrued chain.
	 * @param int|float $value The subject value.
	 * @param int $roundTerm The per-term decimal rounding.
	 * @param StepContext $ctx The run context.
	 *
	 * @return float
	 */
	private function tail(?array $tail, float $chain, int|float $value, int $roundTerm, StepContext $ctx): float {
		if ($tail === null) {
			return $chain;
		}

		$from = $this->num($tail, 'from', $ctx);
		if ($value <= $from) {
			return $chain;
		}

		$rate = $this->num($tail, 'rate', $ctx);

		return $this->round(($chain - (($value - $from) * $rate)), $roundTerm);
	}//end tail()

	/**
	 * Round one term to the declared decimal precision.
	 *
	 * @param float $term The raw term.
	 * @param int $roundTerm The decimal places.
	 *
	 * @return float
	 */
	private function round(float $term, int $roundTerm): float {
		return (float)$this->rounder->apply(
			$term,
			[
				'mode' => 'nearest',
				'unit' => 'decimals',
				'decimals' => $roundTerm,
			]
		);

	}//end round()

}//end class
