<?php

/**
 * Contract tests for the bundled NL jurisdiction pack.
 *
 * This is the change's own acceptance contract, enforced as tests:
 *
 * - the NL pack passes EVERY upload gate, including a self-test dry-run of all
 *   9 golden fixtures — so the machinery gating a third-party pack is the same
 *   machinery proving the NL migration (REQ-JP-006/007);
 * - the NL pack uses ZERO escape hatches and the registry ships empty
 *   (REQ-JP-004/005);
 * - `PayrollCalculator`'s absorbed private helpers are GONE — any survivor
 *   would mean NL logic stayed in PHP (REQ-JP-004).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Payroll
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-001
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-004
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Payroll;

use OCA\Hrmq\Payroll\JurisdictionPack;
use OCA\Hrmq\Payroll\PackRepository;
use OCA\Hrmq\Payroll\PackValidator;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\StepHandlerRegistry;
use OCA\Hrmq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The bundled NL pack's own contract.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-007
 */
class NlPackTest extends TestCase {

	/**
	 * THE acceptance gate. Validating the bundled NL pack runs all 9 golden
	 * fixtures in-process through the interpreter (gate 5). If any component of
	 * any fixture drifts by a single cent, this fails — and it fails in
	 * PRODUCTION code, not in a test someone could later "fix".
	 *
	 * @return void
	 */
	public function testTheBundledNlPackPassesEveryUploadGateIncludingItsNineGoldenVectors(): void {
		$repository = new PackRepository();
		$pack = $repository->resolve('NL', '2026-02');

		$provenance = (new PackValidator())->validate($pack, TaxTables::load($pack->tablesId()), $repository);

		$this->assertSame([], $provenance, 'the NL pack resolves no unverified or placeholder leaf');

	}//end testTheBundledNlPackPassesEveryUploadGateIncludingItsNineGoldenVectors()

	/**
	 * REQ-JP-006 / 30-procent-regeling: the NL pack's self-test block is the 10
	 * existing fixtures (the original 9 plus the 30%-ruling anchor) —
	 * referenced, never copied. If a fixture is added, it belongs here too.
	 *
	 * @return void
	 */
	public function testTheNlPackDeclaresAllTenGoldenFixturesAsItsOwnVectors(): void {
		$vectors = $this->pack()->selfTest()['vectors'];

		$this->assertCount(10, $vectors);
		$this->assertSame(
			[
				'payroll-2026/anchor.json',
				'payroll-2026/aow-age.json',
				'payroll-2026/bracket-3.json',
				'payroll-2026/groen.json',
				'payroll-2026/min-wage.json',
				'payroll-2026/no-korting.json',
				'payroll-2026/part-time.json',
				'payroll-2026/bijtelling-anchor.json',
				'payroll-2026/dga-anchor.json',
				'payroll-2026/thirty-percent-ruling-anchor.json',
			],
			array_column($vectors, '$fixture')
		);

	}//end testTheNlPackDeclaresAllTenGoldenFixturesAsItsOwnVectors()

	/**
	 * REQ-JP-004/005 scenario: the NL pack uses no escape hatch, and the
	 * handler registry ships with zero registered handlers.
	 *
	 * @return void
	 */
	public function testTheNlPackUsesNoEscapeHatchAndTheRegistryShipsEmpty(): void {
		foreach ($this->pack()->steps() as $step) {
			$this->assertNotSame('phpStep', $step['op'], 'NL step "' . $step['id'] . '" must not need the escape hatch');
		}

		$this->assertSame([], (new StepHandlerRegistry())->names(), 'hrmq ships zero escape-hatch handlers');

	}//end testTheNlPackUsesNoEscapeHatchAndTheRegistryShipsEmpty()

	/**
	 * REQ-JP-003: no pack declares a net step — net is the interpreter's fold.
	 * NL's only `reduces-net` step is loonheffing, which is exactly why the
	 * fold reproduces `tvl - loonheffing` without the interpreter knowing
	 * anything about the Netherlands.
	 *
	 * @return void
	 */
	public function testLoonheffingIsTheOnlyStepThatReducesNet(): void {
		$reducers = [];
		foreach ($this->pack()->steps() as $step) {
			$this->assertNotSame('netto', $step['id'], 'a pack must not author a net step');
			if ($step['incidence'] === 'reduces-net') {
				$reducers[] = $step['id'];
			}
		}

		$this->assertSame(['loonheffing'], $reducers);

	}//end testLoonheffingIsTheOnlyStepThatReducesNet()

	/**
	 * REQ-JP-004: `PayrollCalculator`'s absorbed private helpers are deleted.
	 * Deleting them is the proof of the migration — if any survives, some NL
	 * logic stayed in PHP.
	 *
	 * @return void
	 */
	public function testTheAbsorbedNlHelpersAreDeletedFromTheCalculator(): void {
		$methods = [];
		foreach ((new ReflectionClass(PayrollCalculator::class))->getMethods() as $method) {
			$methods[] = $method->getName();
		}

		foreach (['arkChain', 'selectBracket', 'isAowAge', 'schijvenSet', 'floorEuroCents', 'ceilEuroCents', 'round5Cents', 'round2Cents'] as $helper) {
			$this->assertNotContains($helper, $methods, 'PayrollCalculator::' . $helper . '() survived — NL logic stayed in PHP.');
		}

	}//end testTheAbsorbedNlHelpersAreDeletedFromTheCalculator()

	/**
	 * REQ-JP-007: the façade's public contract did not move.
	 *
	 * @return void
	 */
	public function testTheCalculatorPublicContractIsUnchanged(): void {
		$calculate = (new ReflectionClass(PayrollCalculator::class))->getMethod('calculate');

		$this->assertTrue($calculate->isPublic());
		$this->assertSame('OCA\Hrmq\Payroll\CalculationResult', (string)$calculate->getReturnType());
		$this->assertSame(
			['OCA\Hrmq\Payroll\CalculationInput', 'OCA\Hrmq\Payroll\TaxTables'],
			array_map(static fn ($p): string => (string)$p->getType(), $calculate->getParameters())
		);

	}//end testTheCalculatorPublicContractIsUnchanged()

	/**
	 * REQ-JP-001 scenario: the resolver matches on DECLARED fields; no code
	 * path parses the country out of the pack id or the run period.
	 *
	 * @return void
	 */
	public function testThePackIsResolvedByItsDeclaredJurisdictionAndTaxYear(): void {
		$pack = (new PackRepository())->resolve('NL', '2026-02');

		$this->assertSame('NL', $pack->jurisdiction());
		$this->assertSame(2026, $pack->taxYear());
		$this->assertSame('nl-2026@1.1.0', $pack->engineVersion());
		$this->assertTrue($pack->isBundled());

	}//end testThePackIsResolvedByItsDeclaredJurisdictionAndTaxYear()

	/**
	 * REQ-JP-001 scenario: parameters are REFERENCED, never copied — no rate,
	 * bracket or threshold value is duplicated inside the pack.
	 *
	 * @return void
	 */
	public function testThePackReferencesParametersRatherThanCopyingThem(): void {
		$literals = [];
		$this->literalsOf($this->pack()->raw(), $literals);

		// Distinctive verified figures from lib/Standards/tables/nl-2026.json.
		// Every one must arrive via an @table.* ref; none may appear as a
		// literal VALUE in the pack. Prose in `_note` fields is excluded --
		// documenting a rate is not duplicating it.
		foreach ([0.08324, 0.31009, 37.56, 6617.41, 2.74, 6.1, 132920, 3115] as $corpusValue) {
			$this->assertNotContains($corpusValue, $literals, 'the corpus value ' . $corpusValue . ' was copied into the pack instead of referenced via @table.*');
		}

	}//end testThePackReferencesParametersRatherThanCopyingThem()

	/**
	 * Collect every scalar literal value in the pack, skipping `_note`/`_notes`
	 * prose and the reference strings themselves.
	 *
	 * @param mixed $node The pack node.
	 * @param array<int, scalar> $literals The collected literals, by reference.
	 *
	 * @return void
	 */
	private function literalsOf(mixed $node, array &$literals): void {
		if (is_array($node) === false) {
			if (is_int($node) === true || is_float($node) === true) {
				$literals[] = $node;
			}

			return;
		}

		foreach ($node as $key => $child) {
			if ($key === '_note' || $key === '_notes' || $key === 'basedOn') {
				continue;
			}

			$this->literalsOf($child, $literals);
		}

	}//end literalsOf()

	/**
	 * An unresolvable jurisdiction fails loudly rather than falling back to NL.
	 *
	 * @return void
	 */
	public function testAnUnknownJurisdictionIsRejected(): void {
		$this->expectExceptionMessageMatches('/geen jurisdictiepack gevonden voor ZZ 2026/i');

		(new PackRepository())->resolve('ZZ', '2026-02');

	}//end testAnUnknownJurisdictionIsRejected()

	/**
	 * The bundled NL pack.
	 *
	 * @return JurisdictionPack
	 */
	private function pack(): JurisdictionPack {
		return (new PackRepository())->resolve('NL', '2026-02');
	}//end pack()

}//end class
