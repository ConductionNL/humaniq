<?php

/**
 * Unit tests for PiecewiseAccrueOp — a capped piecewise accrual with a tail.
 *
 * `PackInterpreter`'s own tests pin the NL ARK chain end to end. These go at
 * the op directly, for the branches a pack can reach that the NL chain does
 * not: a non-positive value, a malformed segment list, the early return when
 * the value is already inside an earlier segment, a cap that binds before the
 * tail, a tail that drives the chain below zero, and the `zeroAbove` ceiling.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops
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

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\Ops\AccrualRun;
use OCA\Humaniq\Payroll\Dsl\Ops\PiecewiseAccrueOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\Rounder;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `piecewiseAccrue(value, segments[], tail?, roundTerm?, zeroAbove?)`.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\PiecewiseAccrueOp
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\AccrualRun
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\ParsedRef
 * @uses   \OCA\Humaniq\Payroll\Dsl\Rounder
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class PiecewiseAccrueOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('piecewiseAccrue', $this->op()->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * A non-positive value accrues nothing, and nothing in the segment list is
	 * even looked at — which is what makes a zero-grondslag step safe.
	 *
	 * @return void
	 */
	public function testANonPositiveValueAccruesNothingWithoutReadingTheSegments(): void {
		foreach ([0, -1, -500000] as $value) {
			$this->assertSame(
				0.0,
				$this->op()->evaluate(
					[
						'value'    => $value,
						'segments' => 'niet eens een lijst',
					],
					$this->context()
				),
				'value ' . $value
			);
		}

	}//end testANonPositiveValueAccruesNothingWithoutReadingTheSegments()

	/**
	 * Inside the first segment the accrual is `value * rate`, rounded to the
	 * declared per-term precision.
	 *
	 * @return void
	 */
	public function testInsideTheFirstSegmentTheAccrualIsValueTimesRate(): void {
		$this->assertEqualsWithDelta(
			250.0,
			$this->evaluate(
				[
					'value'     => 500,
					'roundTerm' => 3,
					'segments'  => [
						[
							'upTo' => 1000,
							'rate' => 0.5,
							'cap'  => 400,
						],
					],
				]
			),
			1.0e-6
		);

	}//end testInsideTheFirstSegmentTheAccrualIsValueTimesRate()

	/**
	 * A segment's cap bounds its own term, and the next segment accrues only
	 * over the span above the previous boundary.
	 *
	 * @return void
	 */
	public function testEachSegmentIsCappedAndTheNextAccruesOnlyOverItsOwnSpan(): void {
		$segments = [
			[
				'upTo' => 1000,
				'rate' => 0.5,
				'cap'  => 400,
			],
			[
				'upTo' => 2000,
				'rate' => 0.25,
				'cap'  => 600,
			],
		];

		// Segment 1 accrues 500 but caps at 400; segment 2 adds 500*0,25 = 125.
		$this->assertEqualsWithDelta(
			525.0,
			$this->evaluate(
				[
					'value'     => 1500,
					'roundTerm' => 3,
					'segments'  => $segments,
				]
			),
			1.0e-6
		);

		// Above the last boundary the chain stops at segment 2's own cap.
		$this->assertEqualsWithDelta(
			600.0,
			$this->evaluate(
				[
					'value'     => 5000,
					'roundTerm' => 3,
					'segments'  => $segments,
				]
			),
			1.0e-6
		);

	}//end testEachSegmentIsCappedAndTheNextAccruesOnlyOverItsOwnSpan()

	/**
	 * Once the value sits inside an earlier segment, the later segments are
	 * not entered at all — the loop returns rather than accruing a negative
	 * span.
	 *
	 * @return void
	 */
	public function testLaterSegmentsAreNotEnteredOnceTheValueIsInsideAnEarlierOne(): void {
		$this->assertEqualsWithDelta(
			250.0,
			$this->evaluate(
				[
					'value'     => 500,
					'roundTerm' => 3,
					'segments'  => [
						[
							'upTo' => 1000,
							'rate' => 0.5,
							'cap'  => 400,
						],
						[
							'upTo' => 2000,
							'rate' => 0.25,
							'cap'  => 600,
						],
						[
							'upTo' => 3000,
							'rate' => 0.1,
							'cap'  => 900,
						],
					],
				]
			),
			1.0e-6,
			'only segment 1 contributed; segments 2 and 3 were skipped'
		);

	}//end testLaterSegmentsAreNotEnteredOnceTheValueIsInsideAnEarlierOne()

	/**
	 * Above the tail's `from` the chain descends by `(value - from) * rate`,
	 * and the result never goes below zero.
	 *
	 * @return void
	 */
	public function testTheTailDescendsAboveItsBoundaryAndNeverGoesBelowZero(): void {
		$spec = [
			'roundTerm' => 3,
			'segments'  => [
				[
					'upTo' => 1000,
					'rate' => 0.5,
					'cap'  => 400,
				],
			],
			'tail'      => [
				'from' => 2000,
				'rate' => 0.1,
			],
		];

		$this->assertEqualsWithDelta(400.0, $this->evaluate(array_merge($spec, ['value' => 2000])), 1.0e-6, 'at the boundary the tail does not bite');
		$this->assertEqualsWithDelta(300.0, $this->evaluate(array_merge($spec, ['value' => 3000])), 1.0e-6, '1000 over at 10%');
		$this->assertSame(0.0, $this->evaluate(array_merge($spec, ['value' => 90000])), 'the tail bottoms out at zero, never negative');

	}//end testTheTailDescendsAboveItsBoundaryAndNeverGoesBelowZero()

	/**
	 * `zeroAbove` is a hard ceiling: above it the whole accrual is nothing,
	 * whatever the chain had reached, and at it the chain survives.
	 *
	 * @return void
	 */
	public function testZeroAboveIsAHardCeiling(): void {
		$spec = [
			'roundTerm' => 3,
			'segments'  => [
				[
					'upTo' => 1000,
					'rate' => 0.5,
					'cap'  => 400,
				],
			],
			'zeroAbove' => 8000,
		];

		$this->assertEqualsWithDelta(400.0, $this->evaluate(array_merge($spec, ['value' => 8000])), 1.0e-6, 'at the ceiling it still accrues');
		$this->assertSame(0.0, $this->evaluate(array_merge($spec, ['value' => 8001])), 'one cent above it, nothing');

	}//end testZeroAboveIsAHardCeiling()

	/**
	 * The per-term rounding is applied to each term as it accrues, so a
	 * coarser `roundTerm` changes the answer — the rounding is not cosmetic.
	 *
	 * @return void
	 */
	public function testThePerTermRoundingIsAppliedToEachTerm(): void {
		$spec = [
			'value'    => 777,
			'segments' => [
				[
					'upTo' => 1000,
					'rate' => 0.3333,
					'cap'  => 999,
				],
			],
		];

		$this->assertEqualsWithDelta(258.9741, $this->evaluate(array_merge($spec, ['roundTerm' => 4])), 1.0e-9);
		$this->assertEqualsWithDelta(259.0, $this->evaluate(array_merge($spec, ['roundTerm' => 0])), 1.0e-9);

	}//end testThePerTermRoundingIsAppliedToEachTerm()

	/**
	 * A segment list that is absent, empty or not a list is refused, naming
	 * the op and the param.
	 *
	 * @return void
	 */
	public function testAMalformedSegmentListIsRefused(): void {
		foreach ([[], ['segments' => []], ['segments' => 'een'], ['segments' => null]] as $extra) {
			try {
				$this->evaluate(array_merge(['value' => 1500], $extra));
				$this->fail('a malformed segment list must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('op "piecewiseAccrue"', $e->getMessage());
				$this->assertStringContainsString('"segments"', $e->getMessage());
			}
		}

	}//end testAMalformedSegmentListIsRefused()

	/**
	 * Each segment's own three params are required, and the refusal names the
	 * missing one.
	 *
	 * @return void
	 */
	public function testEachSegmentsParamsAreRequired(): void {
		$full = [
			'upTo' => 1000,
			'rate' => 0.5,
			'cap'  => 400,
		];

		foreach (array_keys($full) as $key) {
			$segment = $full;
			unset($segment[$key]);

			try {
				$this->evaluate(
					[
						'value'    => 500,
						'segments' => [$segment],
					]
				);
				$this->fail('a segment missing ' . $key . ' must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('"' . $key . '"', $e->getMessage());
			}
		}

	}//end testEachSegmentsParamsAreRequired()

	/**
	 * A fresh run starts at zero on every field, so a step's chain never
	 * carries over from an earlier one.
	 *
	 * @return void
	 */
	public function testAFreshRunStartsEmpty(): void {
		$run = new AccrualRun();

		$this->assertSame(0, $run->value);
		$this->assertSame(0, $run->roundTerm);
		$this->assertSame(0.0, $run->chain);

	}//end testAFreshRunStartsEmpty()

	/**
	 * Evaluate the op against a spec.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 *
	 * @return mixed
	 */
	private function evaluate(array $spec): mixed {
		return $this->op()->evaluate($spec, $this->context());
	}//end evaluate()

	/**
	 * The op under test.
	 *
	 * @return PiecewiseAccrueOp
	 */
	private function op(): PiecewiseAccrueOp {
		return new PiecewiseAccrueOp(new RefResolver(), new Rounder());
	}//end op()

	/**
	 * A run context.
	 *
	 * @return StepContext
	 */
	private function context(): StepContext {
		return new StepContext(
			['gross' => 100000],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);

	}//end context()

}//end class
