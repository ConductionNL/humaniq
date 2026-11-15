<?php

/**
 * Unit tests for QuantizeOp — round a value to a multiple of a step.
 *
 * The op carries two paths: an exact integer path (no float division, so no
 * precision loss) and a float path. They must agree on every mode, including
 * for negative values, where `intdiv` truncates toward zero and the floor/ceil
 * corrections are what make the answers match. That agreement is pinned here
 * by running the same cases through both paths.
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
use OCA\Humaniq\Payroll\Dsl\Ops\QuantizeOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\Rounder;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `quantize(value, step, mode)`.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\QuantizeOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\ParsedRef
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class QuantizeOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('quantize', (new QuantizeOp(new RefResolver()))->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * The integer path returns an int, and each mode moves the value to the
	 * expected multiple of the step.
	 *
	 * @return void
	 */
	public function testTheIntegerPathQuantizesPerModeAndStaysAnInt(): void {
		$cases = [
			['floor', 1234, 100, 1200],
			['ceil', 1234, 100, 1300],
			['nearest', 1234, 100, 1200],
			['nearest', 1250, 100, 1300],
			['nearest', 1251, 100, 1300],
		];

		foreach ($cases as [$mode, $value, $step, $expected]) {
			$result = $this->evaluate(
				[
					'value' => $value,
					'step'  => $step,
					'mode'  => $mode,
				]
			);

			$this->assertSame($expected, $result, $mode . ' of ' . $value . ' by ' . $step);
		}

	}//end testTheIntegerPathQuantizesPerModeAndStaysAnInt()

	/**
	 * A value already on a multiple of the step is left alone by every mode —
	 * `ceil` must not push it up a step.
	 *
	 * @return void
	 */
	public function testAnExactMultipleIsLeftAloneByEveryMode(): void {
		foreach (Rounder::MODES as $mode) {
			$this->assertSame(
				1300,
				$this->evaluate(
					[
						'value' => 1300,
						'step'  => 100,
						'mode'  => $mode,
					]
				),
				$mode . ' leaves an exact multiple alone'
			);
		}

	}//end testAnExactMultipleIsLeftAloneByEveryMode()

	/**
	 * Negative values quantize away from zero on `floor` and toward zero on
	 * `ceil` — the corrections around `intdiv`'s truncation.
	 *
	 * @return void
	 */
	public function testNegativeValuesQuantizeInTheRightDirection(): void {
		$cases = [
			['floor', -1234, 100, -1300],
			['ceil', -1234, 100, -1200],
			['nearest', -1234, 100, -1200],
			['floor', -1300, 100, -1300],
			['ceil', -1300, 100, -1300],
		];

		foreach ($cases as [$mode, $value, $step, $expected]) {
			$this->assertSame(
				$expected,
				$this->evaluate(
					[
						'value' => $value,
						'step'  => $step,
						'mode'  => $mode,
					]
				),
				$mode . ' of ' . $value
			);
		}

	}//end testNegativeValuesQuantizeInTheRightDirection()

	/**
	 * The float path agrees with the integer path: the same value and step
	 * expressed as floats land on the same multiples.
	 *
	 * @return void
	 */
	public function testTheFloatPathAgreesWithTheIntegerPath(): void {
		foreach ([['floor', 1200.0], ['ceil', 1300.0], ['nearest', 1200.0]] as [$mode, $expected]) {
			$float = $this->evaluate(
				[
					'value' => 1234.0,
					'step'  => 100,
					'mode'  => $mode,
				]
			);

			$this->assertIsFloat($float, 'a float value keeps the float path');
			$this->assertEqualsWithDelta($expected, $float, 1.0e-6, $mode . ' on the float path');
			$this->assertEqualsWithDelta(
				$this->evaluate(
					[
						'value' => 1234,
						'step'  => 100,
						'mode'  => $mode,
					]
				),
				$float,
				1.0e-6,
				$mode . ': both paths agree'
			);
		}

	}//end testTheFloatPathAgreesWithTheIntegerPath()

	/**
	 * A fractional step quantizes to that fraction — the decimals case.
	 *
	 * @return void
	 */
	public function testAFractionalStepQuantizesToThatFraction(): void {
		$this->assertEqualsWithDelta(
			1234.5,
			$this->evaluate(
				[
					'value' => 1234.56,
					'step'  => 0.5,
					'mode'  => 'floor',
				]
			),
			1.0e-6
		);

	}//end testAFractionalStepQuantizesToThatFraction()

	/**
	 * The mode is required and must be one the rounder knows; the refusal
	 * lists the allowed modes.
	 *
	 * @return void
	 */
	public function testAnUnknownOrAbsentModeIsRefusedListingTheAllowedModes(): void {
		foreach ([['mode' => 'bankers'], []] as $extra) {
			try {
				$this->evaluate(
					array_merge(
						[
							'value' => 1234,
							'step'  => 100,
						],
						$extra
					)
				);
				$this->fail('an unknown mode must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('op "quantize"', $e->getMessage());
				$this->assertStringContainsString(implode(', ', Rounder::MODES), $e->getMessage());
			}
		}

	}//end testAnUnknownOrAbsentModeIsRefusedListingTheAllowedModes()

	/**
	 * A zero or negative step is refused rather than dividing by zero.
	 *
	 * @return void
	 */
	public function testAZeroOrNegativeStepIsRefused(): void {
		foreach ([0, -100, -0.5] as $step) {
			try {
				$this->evaluate(
					[
						'value' => 1234,
						'step'  => $step,
						'mode'  => 'floor',
					]
				);
				$this->fail('a non-positive step must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('positieve "step"', $e->getMessage());
			}
		}

	}//end testAZeroOrNegativeStepIsRefused()

	/**
	 * The value and step may be references.
	 *
	 * @return void
	 */
	public function testTheValueAndStepMayBeReferences(): void {
		$this->assertSame(
			1200,
			$this->evaluate(
				[
					'value' => '@input.amount',
					'step'  => '@binding.step',
					'mode'  => 'floor',
				]
			)
		);

	}//end testTheValueAndStepMayBeReferences()

	/**
	 * Evaluate the op against a spec.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 *
	 * @return mixed
	 */
	private function evaluate(array $spec): mixed {
		$ctx = new StepContext(
			['amount' => 1234],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);
		$ctx->setBinding('step', 100);

		return (new QuantizeOp(new RefResolver()))->evaluate($spec, $ctx);
	}//end evaluate()

}//end class
