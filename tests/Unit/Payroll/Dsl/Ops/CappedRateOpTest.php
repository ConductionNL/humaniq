<?php

/**
 * Unit tests for CappedRateOp — a percentage of a base capped at a ceiling.
 *
 * The cap binds the BASE, not the result: `min(base, cap) * rate / 100`. That
 * is the NL capped bijdrageloon/premieloon shape, and swapping the order
 * (capping the outcome instead) silently changes every premium, so the
 * ordering is pinned here with a case where the two differ.
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
use OCA\Humaniq\Payroll\Dsl\Ops\CappedRateOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `cappedRate(base, rate, cap)` = `min(base, cap) * rate / 100`.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\CappedRateOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\ParsedRef
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class CappedRateOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('cappedRate', (new CappedRateOp(new RefResolver()))->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * Below the cap the op is a plain percentage.
	 *
	 * @return void
	 */
	public function testBelowTheCapItIsAPlainPercentage(): void {
		$this->assertSame(
			6500.0,
			$this->evaluate(
				[
					'base' => 100000,
					'rate' => 6.5,
					'cap'  => 500000,
				]
			)
		);

	}//end testBelowTheCapItIsAPlainPercentage()

	/**
	 * Above the cap the CAP is what the rate applies to, so the result stops
	 * growing with the base — the capped-premieloon behaviour.
	 *
	 * @return void
	 */
	public function testAboveTheCapTheRateAppliesToTheCapNotTheBase(): void {
		$capped = $this->evaluate(
			[
				'base' => 900000,
				'rate' => 10,
				'cap'  => 500000,
			]
		);

		$this->assertSame(50000, $capped, 'min(base, cap) * rate / 100');
		$this->assertSame(
			$capped,
			$this->evaluate(
				[
					'base' => 1200000,
					'rate' => 10,
					'cap'  => 500000,
				]
			),
			'a higher base above the cap yields the same amount'
		);

	}//end testAboveTheCapTheRateAppliesToTheCapNotTheBase()

	/**
	 * At exactly the cap, the cap is inclusive.
	 *
	 * @return void
	 */
	public function testAtExactlyTheCapTheCapIsInclusive(): void {
		$this->assertSame(
			25000,
			$this->evaluate(
				[
					'base' => 500000,
					'rate' => 5,
					'cap'  => 500000,
				]
			)
		);

	}//end testAtExactlyTheCapTheCapIsInclusive()

	/**
	 * The base, rate and cap may each be a reference.
	 *
	 * @return void
	 */
	public function testEveryParamMayBeAReference(): void {
		$this->assertSame(
			40000,
			$this->evaluate(
				[
					'base' => '@input.gross',
					'rate' => '@binding.pct',
					'cap'  => '@binding.cap',
				]
			)
		);

	}//end testEveryParamMayBeAReference()

	/**
	 * Every one of the three params is required; each refusal names the one
	 * that is missing.
	 *
	 * @return void
	 */
	public function testEachOfTheThreeParamsIsRequired(): void {
		$full = [
			'base' => 100000,
			'rate' => 5,
			'cap'  => 500000,
		];

		foreach (['base', 'rate', 'cap'] as $key) {
			$spec = $full;
			unset($spec[$key]);

			try {
				$this->evaluate($spec);
				$this->fail('a missing ' . $key . ' must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('"' . $key . '"', $e->getMessage());
				$this->assertStringContainsString('op "cappedRate"', $e->getMessage());
			}
		}

	}//end testEachOfTheThreeParamsIsRequired()

	/**
	 * Evaluate the op against a spec.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 *
	 * @return mixed
	 */
	private function evaluate(array $spec): mixed {
		$ctx = new StepContext(
			['gross' => 900000],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);
		$ctx->setBinding('pct', 10);
		$ctx->setBinding('cap', 400000);

		return (new CappedRateOp(new RefResolver()))->evaluate($spec, $ctx);
	}//end evaluate()

}//end class
