<?php

/**
 * Unit tests for RateOp — a straight percentage of a base.
 *
 * Also the place where `AbstractOp`'s shared parameter resolution is pinned,
 * since `rate` is the thinnest op that exercises it: a required numeric param
 * that is either a literal or a reference, and a refusal that names both the
 * op and the param.
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
use OCA\Humaniq\Payroll\Dsl\Ops\RateOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `rate(base, rate)` = `base * rate / 100`.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\RateOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\ParsedRef
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class RateOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('rate', (new RateOp(new RefResolver()))->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * The percentage is taken off the base: the NL vakantiegeld reservering of
	 * 8% over a 1000,00 gross is 80,00.
	 *
	 * @return void
	 */
	public function testThePercentageIsTakenOffTheBase(): void {
		$this->assertSame(
			8000,
			$this->evaluate(
				[
					'base' => 100000,
					'rate' => 8,
				]
			)
		);

	}//end testThePercentageIsTakenOffTheBase()

	/**
	 * A fractional percentage keeps its fraction — no rounding happens in the
	 * op; rounding is the step's own declared modifier.
	 *
	 * @return void
	 */
	public function testAFractionalPercentageIsNotRounded(): void {
		$value = $this->evaluate(
			[
				'base' => 100000,
				'rate' => 8.325,
			]
		);

		$this->assertIsFloat($value);
		$this->assertEqualsWithDelta(8325.0, $value, 1.0e-6, 'the 8,325% Zvw-shaped rate lands on 83,25 euro');
		$this->assertNotSame(8325.0, $value, 'and it keeps the raw binary float: rounding is the step modifier, not the op');

	}//end testAFractionalPercentageIsNotRounded()

	/**
	 * Either param may be a reference instead of a literal, resolved against
	 * the run context.
	 *
	 * @return void
	 */
	public function testEitherParamMayBeAReference(): void {
		$this->assertSame(
			5000,
			$this->evaluate(
				[
					'base' => '@input.gross',
					'rate' => '@binding.pct',
				]
			)
		);

	}//end testEitherParamMayBeAReference()

	/**
	 * A missing required param is refused, naming the op and the param — the
	 * shared `AbstractOp::num()` refusal.
	 *
	 * @return void
	 */
	public function testAMissingParamIsRefusedNamingTheOpAndParam(): void {
		try {
			$this->evaluate(['rate' => 8]);
			$this->fail('a missing base must be refused');
		} catch (DslException $e) {
			$this->assertStringContainsString('op "rate"', $e->getMessage());
			$this->assertStringContainsString('"base"', $e->getMessage());
		}

	}//end testAMissingParamIsRefusedNamingTheOpAndParam()

	/**
	 * A param that resolves to something other than a number is refused
	 * rather than coerced — a numeric string included.
	 *
	 * @return void
	 */
	public function testANonNumericParamIsRefusedRatherThanCoerced(): void {
		foreach (['acht', '8', true, null, []] as $value) {
			try {
				$this->evaluate(
					[
						'base' => 100000,
						'rate' => $value,
					]
				);
				$this->fail('a non-numeric rate must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('parameter "rate"', $e->getMessage());
			}
		}

	}//end testANonNumericParamIsRefusedRatherThanCoerced()

	/**
	 * Evaluate the op against a spec.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 *
	 * @return mixed
	 */
	private function evaluate(array $spec): mixed {
		$ctx = new StepContext(
			['gross' => 100000],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);
		$ctx->setBinding('pct', 5);

		return (new RateOp(new RefResolver()))->evaluate($spec, $ctx);
	}//end evaluate()

}//end class
