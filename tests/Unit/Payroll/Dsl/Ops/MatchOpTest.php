<?php

/**
 * Unit tests for MatchOp — select a value by an input or binding.
 *
 * This is the op that makes a jurisdiction's table-set switches DATA rather
 * than interpreter branches (REQ-JP-004), so the subject's canonical string
 * form and the no-match refusal both matter: a silently defaulted case would
 * pick the wrong tariff column without anyone noticing.
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\Ops\MatchOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `match(on, cases{}, default?)`.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\MatchOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\ParsedRef
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class MatchOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('match', (new MatchOp(new RefResolver()))->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * A string subject selects its case, and the case's value passes through
	 * as the literal it is.
	 *
	 * @return void
	 */
	public function testAStringSubjectSelectsItsCase(): void {
		$this->assertSame(
			'laag',
			$this->evaluate(
				[
					'on'    => '@input.contract',
					'cases' => [
						'vast'    => 'laag',
						'tijdelijk' => 'hoog',
					],
				]
			)
		);

	}//end testAStringSubjectSelectsItsCase()

	/**
	 * An int subject matches the case key JSON writes as a decimal string —
	 * the cohort-year switch.
	 *
	 * @return void
	 */
	public function testAnIntSubjectMatchesItsDecimalCaseKey(): void {
		$this->assertSame(
			'cohort-1960',
			$this->evaluate(
				[
					'on'    => 1960,
					'cases' => [
						'1960' => 'cohort-1960',
						'1961' => 'cohort-1961',
					],
				]
			)
		);

	}//end testAnIntSubjectMatchesItsDecimalCaseKey()

	/**
	 * A bool subject matches the `"true"` / `"false"` case keys JSON can
	 * express — the AOW switch.
	 *
	 * @return void
	 */
	public function testABoolSubjectMatchesTheTrueAndFalseCaseKeys(): void {
		$cases = [
			'cases' => [
				'true'  => 'aow',
				'false' => 'pre-aow',
			],
		];

		$this->assertSame('aow', $this->evaluate(array_merge($cases, ['on' => '@binding.aow'])));
		$this->assertSame('pre-aow', $this->evaluate(array_merge($cases, ['on' => false])));

	}//end testABoolSubjectMatchesTheTrueAndFalseCaseKeys()

	/**
	 * The matched case's value may itself be a reference, so a case can select
	 * another binding or a table leaf.
	 *
	 * @return void
	 */
	public function testTheMatchedCasesValueMayItselfBeAReference(): void {
		$this->assertSame(
			2795,
			$this->evaluate(
				[
					'on'    => '@input.contract',
					'cases' => [
						'vast'      => '@binding.laag',
						'tijdelijk' => '@binding.hoog',
					],
				]
			)
		);

	}//end testTheMatchedCasesValueMayItselfBeAReference()

	/**
	 * With no matching case the declared default is taken, and it too may be
	 * a reference.
	 *
	 * @return void
	 */
	public function testWithNoMatchingCaseTheDeclaredDefaultIsTaken(): void {
		$this->assertSame(
			2795,
			$this->evaluate(
				[
					'on'      => 'onbekend',
					'cases'   => ['vast' => 'laag'],
					'default' => '@binding.laag',
				]
			)
		);

	}//end testWithNoMatchingCaseTheDeclaredDefaultIsTaken()

	/**
	 * A declared `null` default is a real default, not an absent one: the op
	 * asks `array_key_exists`, so the case genuinely resolves to null instead
	 * of being refused.
	 *
	 * @return void
	 */
	public function testADeclaredNullDefaultIsARealDefault(): void {
		$this->assertNull(
			$this->evaluate(
				[
					'on'      => 'onbekend',
					'cases'   => ['vast' => 'laag'],
					'default' => null,
				]
			)
		);

	}//end testADeclaredNullDefaultIsARealDefault()

	/**
	 * With neither a matching case nor a default the op refuses, naming the
	 * key it could not place — it never falls back to a guess.
	 *
	 * @return void
	 */
	public function testWithoutAMatchOrADefaultItRefusesNamingTheKey(): void {
		try {
			$this->evaluate(
				[
					'on'    => 'onbekend',
					'cases' => ['vast' => 'laag'],
				]
			);
			$this->fail('an unmatched subject with no default must be refused');
		} catch (DslException $e) {
			$this->assertStringContainsString('geen case voor "onbekend"', $e->getMessage());
			$this->assertStringContainsString('"default"', $e->getMessage());
		}

	}//end testWithoutAMatchOrADefaultItRefusesNamingTheKey()

	/**
	 * A missing `on`, and a `cases` map that is absent, empty or not a map at
	 * all, are each refused naming the op.
	 *
	 * @param array<string, mixed> $spec     The malformed spec.
	 * @param string               $expected The fragment the refusal must carry.
	 *
	 * @dataProvider malformedSpecs
	 *
	 * @return void
	 */
	public function testAMalformedMatchIsRefused(array $spec, string $expected): void {
		try {
			$this->evaluate($spec);
			$this->fail('a malformed match must be refused');
		} catch (DslException $e) {
			$this->assertStringContainsString('op "match"', $e->getMessage());
			$this->assertStringContainsString($expected, $e->getMessage());
		}

	}//end testAMalformedMatchIsRefused()

	/**
	 * The malformed specs and the fragment each refusal must carry.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function malformedSpecs(): array {
		return [
			'no subject'     => [['cases' => ['vast' => 'laag']], '"on"'],
			'no cases'       => [['on' => 'vast'], '"cases"'],
			'empty cases'    => [
				[
					'on'    => 'vast',
					'cases' => [],
				],
				'"cases"',
			],
			'cases not a map' => [
				[
					'on'    => 'vast',
					'cases' => 'laag',
				],
				'"cases"',
			],
			'float subject'  => [
				[
					'on'    => 1.5,
					'cases' => ['vast' => 'laag'],
				],
				'string, int of bool',
			],
			'array subject'  => [
				[
					'on'    => ['vast'],
					'cases' => ['vast' => 'laag'],
				],
				'string, int of bool',
			],
			'null subject'   => [
				[
					'on'    => null,
					'cases' => ['vast' => 'laag'],
				],
				'string, int of bool',
			],
		];

	}//end malformedSpecs()

	/**
	 * Evaluate the op against a spec.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 *
	 * @return mixed
	 */
	private function evaluate(array $spec): mixed {
		$ctx = new StepContext(
			['contract' => 'vast'],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);
		$ctx->setBinding('aow', true);
		$ctx->setBinding('laag', 2795);
		$ctx->setBinding('hoog', 7795);

		return (new MatchOp(new RefResolver()))->evaluate($spec, $ctx);
	}//end evaluate()

}//end class
