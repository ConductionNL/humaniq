<?php

/**
 * Unit tests for ClampOp — bound a value between optional bounds.
 *
 * Both bounds are optional but at least one must be declared: a `clamp` with
 * neither is a no-op a pack author did not mean to write, so it is refused
 * rather than silently passing the value through.
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
use OCA\Humaniq\Payroll\Dsl\Ops\ClampOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `clamp(value, min?, max?)`, applying only the declared bounds.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\ClampOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\ParsedRef
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class ClampOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('clamp', (new ClampOp(new RefResolver()))->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * A value inside both bounds passes through untouched, and its type is
	 * kept — an int in is an int out.
	 *
	 * @return void
	 */
	public function testAValueInsideBothBoundsPassesThroughUnchanged(): void {
		$value = $this->evaluate(
			[
				'value' => 500,
				'min'   => 100,
				'max'   => 1000,
			]
		);

		$this->assertSame(500, $value);

	}//end testAValueInsideBothBoundsPassesThroughUnchanged()

	/**
	 * Below the floor the floor is returned; above the ceiling the ceiling is.
	 * Both bounds are inclusive.
	 *
	 * @return void
	 */
	public function testEachBoundBindsAndIsInclusive(): void {
		$bounds = [
			'min' => 100,
			'max' => 1000,
		];

		$this->assertSame(100, $this->evaluate(array_merge($bounds, ['value' => 40])), 'below the floor');
		$this->assertSame(1000, $this->evaluate(array_merge($bounds, ['value' => 4000])), 'above the ceiling');
		$this->assertSame(100, $this->evaluate(array_merge($bounds, ['value' => 100])), 'at the floor');
		$this->assertSame(1000, $this->evaluate(array_merge($bounds, ['value' => 1000])), 'at the ceiling');

	}//end testEachBoundBindsAndIsInclusive()

	/**
	 * A floor alone leaves the value unbounded above; a ceiling alone leaves
	 * it unbounded below. Only the declared bound is applied.
	 *
	 * @return void
	 */
	public function testOnlyTheDeclaredBoundIsApplied(): void {
		$this->assertSame(
			9999999,
			$this->evaluate(
				[
					'value' => 9999999,
					'min'   => 100,
				]
			),
			'a floor alone does not cap'
		);

		$this->assertSame(
			-9999999,
			$this->evaluate(
				[
					'value' => -9999999,
					'max'   => 1000,
				]
			),
			'a ceiling alone does not floor'
		);

	}//end testOnlyTheDeclaredBoundIsApplied()

	/**
	 * A zero bound is a real bound, not an absent one: `min: 0` is the
	 * negative-to-zero clamp packs rely on, and `array_key_exists` is what
	 * makes that work.
	 *
	 * @return void
	 */
	public function testAZeroBoundIsARealBound(): void {
		$this->assertSame(
			0,
			$this->evaluate(
				[
					'value' => -250,
					'min'   => 0,
				]
			)
		);

	}//end testAZeroBoundIsARealBound()

	/**
	 * A `clamp` with neither bound declared is refused, naming both params.
	 *
	 * @return void
	 */
	public function testAClampWithoutAnyBoundIsRefused(): void {
		try {
			$this->evaluate(['value' => 500]);
			$this->fail('a clamp with no bounds must be refused');
		} catch (DslException $e) {
			$this->assertStringContainsString('op "clamp"', $e->getMessage());
			$this->assertStringContainsString('"min"', $e->getMessage());
			$this->assertStringContainsString('"max"', $e->getMessage());
		}

	}//end testAClampWithoutAnyBoundIsRefused()

	/**
	 * The value and both bounds may be references.
	 *
	 * @return void
	 */
	public function testTheValueAndBoundsMayBeReferences(): void {
		$this->assertSame(
			250,
			$this->evaluate(
				[
					'value' => '@input.amount',
					'min'   => '@binding.floor',
					'max'   => '@binding.ceil',
				]
			)
		);

	}//end testTheValueAndBoundsMayBeReferences()

	/**
	 * A non-numeric value is refused, naming the param.
	 *
	 * @return void
	 */
	public function testANonNumericValueIsRefused(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/parameter "value"/');

		$this->evaluate(
			[
				'value' => 'veel',
				'min'   => 0,
			]
		);

	}//end testANonNumericValueIsRefused()

	/**
	 * Evaluate the op against a spec.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 *
	 * @return mixed
	 */
	private function evaluate(array $spec): mixed {
		$ctx = new StepContext(
			['amount' => 2000],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);
		$ctx->setBinding('floor', 50);
		$ctx->setBinding('ceil', 250);

		return (new ClampOp(new RefResolver()))->evaluate($spec, $ctx);
	}//end evaluate()

}//end class
