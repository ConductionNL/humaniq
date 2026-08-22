<?php

/**
 * Unit tests for PackInterpreter — the ops, and the incidence fold.
 *
 * The fold tests are the important ones. They assert the ARCHITECTURAL claim
 * of ADR-101 decision 1: that net is derived from declared incidence rather
 * than from a hardcoded jurisdiction rule. `testAContributionThatReducesNetNeedsNoInterpreterChange`
 * is the proof — a pack no Dutch person would recognise produces a correct net
 * through the same interpreter, unmodified.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Dsl
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\PackInterpreter;
use OCA\Humaniq\Payroll\JurisdictionPack;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Op-level and incidence-fold tests.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
 */
class PackInterpreterTest extends TestCase {

	/**
	 * REQ-JP-003: net is `gross - sum(reduces-net)`, and employer charges are
	 * `sum(employer-cost)` — derived, never authored.
	 *
	 * @return void
	 */
	public function testNetIsAFoldOverReducesNetAndIgnoresEmployerCost(): void {
		$result = $this->execute(
			[
				$this->step('tax', 'reduces-net', '@input.gross * 10 / 100'),
				$this->step('employerLevy', 'employer-cost', '@input.gross * 20 / 100'),
				$this->step('note', 'informative', '@input.gross * 30 / 100'),
			]
		);

		$this->assertSame(100000, $result->gross());
		$this->assertSame(90000, $result->net(), 'net = gross - reduces-net only');
		$this->assertSame(20000, $result->employerCharges(), 'employerCharges = sum(employer-cost)');

	}//end testNetIsAFoldOverReducesNetAndIgnoresEmployerCost()

	/**
	 * REQ-JP-003 scenario: "A contribution that reduces net needs no
	 * interpreter change." A country whose social contribution is borne by the
	 * EMPLOYEE — the opposite of the Dutch arrangement the engine was built
	 * around — declares `reduces-net`, and the fold does the rest.
	 *
	 * @return void
	 */
	public function testAContributionThatReducesNetNeedsNoInterpreterChange(): void {
		$result = $this->execute(
			[
				$this->step('tax', 'reduces-net', '@input.gross * 10 / 100'),
				$this->step('employeePension', 'reduces-net', '@input.gross * 5 / 100'),
			]
		);

		$this->assertSame(85000, $result->net(), 'Both reduces-net steps subtract; no interpreter code changed to allow it.');
		$this->assertSame(0, $result->employerCharges());

	}//end testAContributionThatReducesNetNeedsNoInterpreterChange()

	/**
	 * REQ-JP-003 scenario: a `reserve` touches neither net nor employer cost.
	 *
	 * @return void
	 */
	public function testAReservationAffectsNeitherNetNorEmployerCost(): void {
		$result = $this->execute(
			[
				$this->step('tax', 'reduces-net', '@input.gross * 10 / 100'),
				$this->step('holidayAccrual', 'reserve', '@input.gross * 8 / 100'),
			]
		);

		$this->assertSame(90000, $result->net());
		$this->assertSame(0, $result->employerCharges());
		$this->assertSame(8000, $result->cents('holidayAccrual'), 'the reserve is still reported');

	}//end testAReservationAffectsNeitherNetNorEmployerCost()

	/**
	 * REQ-JP-002 scenario: the interpreter is deterministic — the same
	 * (input, pack, tables, period) always yields byte-identical output.
	 *
	 * @return void
	 */
	public function testTheInterpreterIsDeterministic(): void {
		$steps = [$this->step('tax', 'reduces-net', '@input.gross * 37 / 100')];

		$first = $this->execute($steps);
		for ($i = 0; $i < 50; $i++) {
			$this->assertSame($first->net(), $this->execute($steps)->net());
		}

	}//end testTheInterpreterIsDeterministic()

	/**
	 * REQ-JP-002 scenario: `piecewiseAccrue` rounds each term BEFORE capping
	 * it. This pins the exact NL ARK segment-2 case from design.md D5: the
	 * accumulated term is 530001,58 against a cap of 530000, so the cap binds
	 * only after the 5-decimal rounding. Swapping the order changes the answer.
	 *
	 * @return void
	 */
	public function testPiecewiseAccrueRoundsEachTermBeforeCappingIt(): void {
		$result = $this->execute(
			[
				[
					'id' => 'ark',
					'op' => 'piecewiseAccrue',
					'incidence' => 'informative',
					'value' => 4557600,
					'roundTerm' => 3,
					'segments' => [
						[
							'upTo' => 1196500,
							'rate' => 0.08324,
							'cap' => 99600,
						],
						[
							'upTo' => 2584500,
							'rate' => 0.31009,
							'cap' => 530000,
						],
						[
							'upTo' => 4559200,
							'rate' => 0.0195,
							'cap' => 568500,
						],
					],
					'tail' => [
						'from' => 4559200,
						'rate' => 0.0651,
					],
					'zeroAbove' => 13292000,
					'round' => [
						'mode' => 'ceil',
						'unit' => 'euro',
					],
				],
			]
		);

		// Segment 2 caps at 530000 after rounding; segment 3 accrues to
		// 568475,45; ceil-to-euro -> 568500. Exactly arkChain() at HEAD.
		$this->assertSame(568500, $result->cents('ark'));

	}//end testPiecewiseAccrueRoundsEachTermBeforeCappingIt()

	/**
	 * A step gated off by `when` yields 0 WITHOUT resolving its params — which
	 * is what lets NL's OUK reference an AOW-only table column safely.
	 *
	 * @return void
	 */
	public function testAGatedOffStepYieldsZeroWithoutResolvingItsParams(): void {
		$result = $this->execute(
			[
				[
					'id' => 'never',
					'op' => 'expr',
					'incidence' => 'reduces-net',
					'when' => [
						'op' => 'eq',
						'left' => 1,
						'right' => 2,
					],
					'expression' => '@table.this.does.not.exist',
				],
			]
		);

		$this->assertSame(0, $result->cents('never'));
		$this->assertSame(100000, $result->net(), 'a skipped step contributes nothing to the fold');

	}//end testAGatedOffStepYieldsZeroWithoutResolvingItsParams()

	/**
	 * A forward reference cannot resolve at runtime either — the store grows
	 * strictly forward.
	 *
	 * @return void
	 */
	public function testAForwardStepReferenceIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/niet-eerder-gedeclareerde stap "@step.later"/');

		$this->execute(
			[
				$this->step('earlier', 'informative', '@step.later + 1'),
				$this->step('later', 'informative', '1'),
			]
		);

	}//end testAForwardStepReferenceIsRejected()

	/**
	 * A pack cannot name a handler into existence: with an empty allow-list
	 * (the shipped state) every `phpStep` throws rather than being skipped.
	 *
	 * @return void
	 */
	public function testAnUnresolvableHandlerThrowsRatherThanBeingSkipped(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/onbekende phpStep-handler "fr-cotisations-speciales"/');

		$this->execute(
			[
				[
					'id' => 'exotica',
					'op' => 'phpStep',
					'incidence' => 'reduces-net',
					'handler' => 'fr-cotisations-speciales',
				],
			]
		);

	}//end testAnUnresolvableHandlerThrowsRatherThanBeingSkipped()

	/**
	 * A required input must be supplied.
	 *
	 * @return void
	 */
	public function testAMissingRequiredInputIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/verplichte invoer "gross"/');

		(new PackInterpreter())->run([], $this->pack([$this->step('a', 'informative', '1')]), TaxTables::load('nl-2026'), '2026-02');

	}//end testAMissingRequiredInputIsRejected()

	/**
	 * An `expr` step declaring one arithmetic expression.
	 *
	 * @param string $id The step id.
	 * @param string $incidence The declared incidence.
	 * @param string $expression The expression.
	 *
	 * @return array<string, mixed>
	 */
	private function step(string $id, string $incidence, string $expression): array {
		return [
			'id' => $id,
			'op' => 'expr',
			'incidence' => $incidence,
			'expression' => $expression,
			'round' => [
				'mode' => 'nearest',
				'unit' => 'cent',
			],
		];

	}//end step()

	/**
	 * A minimal, deliberately non-Dutch pack.
	 *
	 * @param array<int, array<string, mixed>> $steps The steps.
	 *
	 * @return JurisdictionPack
	 */
	private function pack(array $steps): JurisdictionPack {
		return new JurisdictionPack(
			[
				'id' => 'zz-2026',
				'jurisdiction' => 'ZZ',
				'taxYear' => 2026,
				'packVersion' => '1.0.0',
				'dslVersion' => '1.0',
				'tables' => 'nl-2026',
				'currency' => 'EUR',
				'grossRef' => '@input.gross',
				'inputs' => ['gross' => ['type' => 'cents', 'required' => true]],
				'bindings' => [],
				'steps' => $steps,
				'selfTest' => ['vectors' => []],
			]
		);

	}//end pack()

	/**
	 * Execute a step list against a gross of 100000 cents.
	 *
	 * @param array<int, array<string, mixed>> $steps The steps.
	 *
	 * @return \OCA\Humaniq\Payroll\Dsl\PackRunResult
	 */
	private function execute(array $steps): \OCA\Humaniq\Payroll\Dsl\PackRunResult {
		return (new PackInterpreter())->run(['gross' => 100000], $this->pack($steps), TaxTables::load('nl-2026'), '2026-02');
	}//end execute()

}//end class
