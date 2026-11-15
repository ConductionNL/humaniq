<?php

/**
 * Unit tests for TaperOp — a linear phase-out above a threshold.
 *
 * `taper` exists as its own named op for intent and auditability rather than
 * being folded into `expr`, so its shape is worth pinning exactly: nothing
 * happens below the threshold, the phase-out is linear in the excess only,
 * and the floor is a hard bound that defaults to zero.
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
use OCA\Humaniq\Payroll\Dsl\Ops\TaperOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `taper(base, value, threshold, rate, floor?)`.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\TaperOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\ParsedRef
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class TaperOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('taper', (new TaperOp(new RefResolver()))->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * At or below the threshold the full base survives — and as a float, which
	 * the op's return contract promises unconditionally.
	 *
	 * @return void
	 */
	public function testAtOrBelowTheThresholdTheFullBaseSurvives(): void {
		foreach ([0, 100000, 300000] as $value) {
			$result = $this->evaluate(
				[
					'base'      => 350000,
					'value'     => $value,
					'threshold' => 300000,
					'rate'      => 0.0633,
				]
			);

			$this->assertSame(350000.0, $result, 'value ' . $value . ' is not above the threshold');
			$this->assertIsFloat($result);
		}

	}//end testAtOrBelowTheThresholdTheFullBaseSurvives()

	/**
	 * Above the threshold the base is reduced by `excess * rate`, linear in
	 * the excess alone: a value 100000 over at 6,33% drops the base by 6330.
	 *
	 * @return void
	 */
	public function testAboveTheThresholdTheExcessIsPhasedOutLinearly(): void {
		$this->assertEqualsWithDelta(
			343670.0,
			$this->evaluate(
				[
					'base'      => 350000,
					'value'     => 400000,
					'threshold' => 300000,
					'rate'      => 0.0633,
				]
			),
			1.0e-6
		);

	}//end testAboveTheThresholdTheExcessIsPhasedOutLinearly()

	/**
	 * The phase-out stops at the floor: a value far above the threshold
	 * reaches the declared floor and stays there.
	 *
	 * @return void
	 */
	public function testThePhaseOutStopsAtTheDeclaredFloor(): void {
		$spec = [
			'base'      => 350000,
			'value'     => 9000000,
			'threshold' => 300000,
			'rate'      => 0.5,
			'floor'     => 1200,
		];

		$this->assertSame(1200.0, $this->evaluate($spec));
		$this->assertSame(
			1200.0,
			$this->evaluate(array_merge($spec, ['value' => 90000000])),
			'further above the threshold changes nothing once the floor binds'
		);

	}//end testThePhaseOutStopsAtTheDeclaredFloor()

	/**
	 * Without a declared floor the phase-out bottoms out at zero rather than
	 * turning into a negative korting.
	 *
	 * @return void
	 */
	public function testWithoutADeclaredFloorItBottomsOutAtZero(): void {
		$this->assertSame(
			0.0,
			$this->evaluate(
				[
					'base'      => 350000,
					'value'     => 9000000,
					'threshold' => 300000,
					'rate'      => 0.5,
				]
			)
		);

	}//end testWithoutADeclaredFloorItBottomsOutAtZero()

	/**
	 * A negative declared floor is honoured — the floor is the pack's bound,
	 * not an assumed zero.
	 *
	 * @return void
	 */
	public function testANegativeDeclaredFloorIsHonoured(): void {
		$this->assertSame(
			-500.0,
			$this->evaluate(
				[
					'base'      => 350000,
					'value'     => 9000000,
					'threshold' => 300000,
					'rate'      => 0.5,
					'floor'     => -500,
				]
			)
		);

	}//end testANegativeDeclaredFloorIsHonoured()

	/**
	 * Every param may be a reference, the floor included.
	 *
	 * @return void
	 */
	public function testEveryParamMayBeAReference(): void {
		$this->assertEqualsWithDelta(
			199000.0,
			$this->evaluate(
				[
					'base'      => '@binding.base',
					'value'     => '@input.income',
					'threshold' => '@binding.threshold',
					'rate'      => '@binding.rate',
					'floor'     => '@binding.floor',
				]
			),
			1.0e-6
		);

	}//end testEveryParamMayBeAReference()

	/**
	 * The four non-floor params are required; each refusal names the missing
	 * one and the op.
	 *
	 * @return void
	 */
	public function testTheFourNonFloorParamsAreRequired(): void {
		$full = [
			'base'      => 350000,
			'value'     => 400000,
			'threshold' => 300000,
			'rate'      => 0.0633,
		];

		foreach (array_keys($full) as $key) {
			$spec = $full;
			unset($spec[$key]);

			try {
				$this->evaluate($spec);
				$this->fail('a missing ' . $key . ' must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('"' . $key . '"', $e->getMessage());
				$this->assertStringContainsString('op "taper"', $e->getMessage());
			}
		}

	}//end testTheFourNonFloorParamsAreRequired()

	/**
	 * Evaluate the op against a spec.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 *
	 * @return mixed
	 */
	private function evaluate(array $spec): mixed {
		$ctx = new StepContext(
			['income' => 400000],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);
		$ctx->setBinding('base', 200000);
		$ctx->setBinding('threshold', 300000);
		$ctx->setBinding('rate', 0.01);
		$ctx->setBinding('floor', 1000);

		return (new TaperOp(new RefResolver()))->evaluate($spec, $ctx);
	}//end evaluate()

}//end class
