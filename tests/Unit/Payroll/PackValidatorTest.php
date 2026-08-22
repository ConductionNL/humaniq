<?php

/**
 * Unit tests for PackValidator — every blocking gate.
 *
 * These are the tests that make an uploaded pack safe to pay wages with. Each
 * one asserts a REJECTION, because the failure mode that matters here is a bad
 * pack being ACCEPTED, not a good one being refused.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\JurisdictionPack;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PackValidator;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Rejection tests for every upload gate.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 */
class PackValidatorTest extends TestCase {

	/**
	 * A well-formed pack that reproduces its own vectors passes every gate.
	 *
	 * @return void
	 */
	public function testAWellFormedPackWithPassingVectorsValidates(): void {
		$provenance = $this->validate($this->pack());

		$this->assertSame([], $provenance, 'this pack resolves no unverified leaf');

	}//end testAWellFormedPackWithPassingVectorsValidates()

	/**
	 * Gate 2 — REQ-JP-002 scenario: an unknown op is rejected rather than
	 * ignored, and the error names it.
	 *
	 * @return void
	 */
	public function testAnUnknownOpIsRejectedNamingIt(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/onbekende op "eval"/');

		$this->validate($this->pack(['steps' => [['id' => 'x', 'op' => 'eval', 'incidence' => 'informative']]]));

	}//end testAnUnknownOpIsRejectedNamingIt()

	/**
	 * Gate 3 — REQ-JP-006 scenario: a dangling table reference is rejected,
	 * naming the reference.
	 *
	 * @return void
	 */
	public function testADanglingTableReferenceIsRejectedNamingIt(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/@table\.loonheffing\.doesNotExist/');

		$this->validate($this->pack(['steps' => [$this->exprStep('x', 'informative', '@table.loonheffing.doesNotExist')]]));

	}//end testADanglingTableReferenceIsRejectedNamingIt()

	/**
	 * Gate 3 — a forward `@step.*` reference is rejected. Since references may
	 * only name EARLIER steps, a cycle cannot be expressed at all.
	 *
	 * @return void
	 */
	public function testAForwardStepReferenceIsRejectedSoCyclesCannotExist(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/niet eerder is gedeclareerd/');

		$this->validate(
			$this->pack(
				[
					'steps' => [
						$this->exprStep('first', 'informative', '@step.second + 1'),
						$this->exprStep('second', 'informative', '1'),
					],
				]
			)
		);

	}//end testAForwardStepReferenceIsRejectedSoCyclesCannotExist()

	/**
	 * Gate 4 — REQ-JP-005 scenario: a pack naming a missing handler is
	 * rejected loudly at UPLOAD, with the handler name in the error. It never
	 * reaches a payroll run.
	 *
	 * @return void
	 */
	public function testAPackNamingAMissingHandlerIsRejectedAtUploadNamingIt(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/fr-cotisations-speciales/');

		$this->validate(
			$this->pack(
				[
					'steps' => [
						[
							'id' => 'exotica',
							'op' => 'phpStep',
							'incidence' => 'reduces-net',
							'handler' => 'fr-cotisations-speciales',
						],
					],
				]
			)
		);

	}//end testAPackNamingAMissingHandlerIsRejectedAtUploadNamingIt()

	/**
	 * REQ-JP-005 scenario: a pack cannot supply executable code. A step
	 * carrying a class path, a callable or an inline code string is refused by
	 * the per-op key allow-list — the payload never becomes an artefact.
	 *
	 * @param string $key The forbidden key.
	 *
	 * @return void
	 *
	 * @dataProvider executableKeyProvider
	 */
	public function testAPackCannotSupplyExecutableCode(string $key): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/nooit code, een class-pad of een callable/');

		$step = $this->exprStep('x', 'informative', '1');
		$step[$key] = 'OCA\\Humaniq\\Payroll\\PayrollCalculator::calculate';

		$this->validate($this->pack(['steps' => [$step]]));

	}//end testAPackCannotSupplyExecutableCode()

	/**
	 * The shapes a pack might try to smuggle code through.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function executableKeyProvider(): array {
		return [['class'], ['callable'], ['code'], ['handler'], ['eval']];
	}//end executableKeyProvider()

	/**
	 * Gate 5 — REQ-JP-006 scenario: a pack whose self-test declares an
	 * expected value its own steps do not produce is rejected, reporting the
	 * component, the expected value and the computed value.
	 *
	 * @return void
	 */
	public function testAPackWhoseSelfTestsFailIsRejectedReportingBothValues(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/verwacht 99999 cent, berekend 90000 cent/');

		$this->validate($this->pack(['selfTest' => ['vectors' => [$this->vector(99999)]]]));

	}//end testAPackWhoseSelfTestsFailIsRejectedReportingBothValues()

	/**
	 * Gate 5 — REQ-JP-006 scenario: a pack with no self-test vectors is
	 * rejected for carrying no golden vectors.
	 *
	 * @return void
	 */
	public function testAPackWithNoSelfTestVectorsIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/ten minste één golden vector/');

		$this->validate($this->pack(['selfTest' => ['vectors' => []]]));

	}//end testAPackWithNoSelfTestVectorsIsRejected()

	/**
	 * Gate 9 — REQ-JP-006 scenario: an upload cannot silently replace the
	 * bundled NL pack.
	 *
	 * @return void
	 */
	public function testAnUploadCannotSilentlyShadowTheBundledNlPack(): void {
		$shadow = $this->pack(
			[
				'id' => 'nl-2026',
				'jurisdiction' => 'NL',
				'taxYear' => 2026,
			]
		);

		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/beheerder moet deze override expliciet activeren/');

		(new PackValidator())->validate($shadow, TaxTables::load('nl-2026'), new PackRepository(), false);

	}//end testAnUploadCannotSilentlyShadowTheBundledNlPack()

	/**
	 * Gate 9 — the same upload is accepted once an admin explicitly activates
	 * it as a recorded override, and it still passes every other gate.
	 *
	 * @return void
	 */
	public function testAnExplicitlyActivatedOverrideIsAccepted(): void {
		$shadow = $this->pack(
			[
				'id' => 'nl-2026',
				'jurisdiction' => 'NL',
				'taxYear' => 2026,
			]
		);

		$this->assertSame([], (new PackValidator())->validate($shadow, TaxTables::load('nl-2026'), new PackRepository(), true));

	}//end testAnExplicitlyActivatedOverrideIsAccepted()

	/**
	 * Gate 8 — REQ-JP-008 scenario: an over-deep expression is rejected and
	 * the error names the depth bound.
	 *
	 * @return void
	 */
	public function testAnOverDeepExpressionIsRejectedAtUploadNamingTheBound(): void {
		$deep = str_repeat('(', 40) . '1' . str_repeat(')', 40);

		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/nestdiepte/');

		$this->validate($this->pack(['steps' => [$this->exprStep('x', 'informative', $deep)]]));

	}//end testAnOverDeepExpressionIsRejectedAtUploadNamingTheBound()

	/**
	 * Gate 8 — a pack over the step-count bound is rejected, naming the bound.
	 *
	 * @return void
	 */
	public function testAPackOverTheStepBoundIsRejected(): void {
		$steps = [];
		for ($i = 0; $i <= PackValidator::MAX_STEPS; $i++) {
			$steps[] = $this->exprStep('s' . $i, 'informative', '1');
		}

		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/bovengrens van ' . PackValidator::MAX_STEPS . '/');

		$this->validate($this->pack(['steps' => $steps]));

	}//end testAPackOverTheStepBoundIsRejected()

	/**
	 * REQ-JP-003: a step must declare a known incidence, and no pack may
	 * declare a `net` step — net is the interpreter's fold.
	 *
	 * @return void
	 */
	public function testAStepDeclaringAnUnknownIncidenceIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/incidence "net"/');

		$this->validate($this->pack(['steps' => [$this->exprStep('x', 'net', '1')]]));

	}//end testAStepDeclaringAnUnknownIncidenceIsRejected()

	/**
	 * An uploaded pack may not reference a repository fixture — it must carry
	 * its own vectors, so its recipient can prove it before paying anyone.
	 *
	 * @return void
	 */
	public function testAnUploadedPackCannotReferenceARepositoryFixture(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/alleen een meegeleverd pack mag naar een fixture verwijzen/');

		$this->validate(
			$this->pack(
				[
					'selfTest' => [
						'fixtureMap' => ['nettoPay' => '@net'],
						'vectors' => [['$fixture' => 'payroll-2026/anchor.json']],
					],
				]
			)
		);

	}//end testAnUploadedPackCannotReferenceARepositoryFixture()

	/**
	 * Gate 6 — provenance is STAMPED, not blocking: a pack resolving the
	 * corpus's `placeholder: true` employer-Whk leaf still validates, and the
	 * validator reports that leaf so a run can stamp it.
	 *
	 * @return void
	 */
	public function testAPackResolvingAPlaceholderLeafValidatesAndReportsItsProvenance(): void {
		$provenance = $this->validate(
			$this->pack(
				[
					'steps' => [$this->exprStep('levy', 'reduces-net', '@input.gross * @table.werknemersverzekeringen.whk / 100')],
					'selfTest' => ['vectors' => [$this->vector(98480)]],
				]
			)
		);

		$this->assertCount(1, $provenance, 'the placeholder leaf is reported, not silenced');
		$this->assertSame('werknemersverzekeringen.whk', $provenance[0]['path']);
		$this->assertTrue($provenance[0]['placeholder']);

	}//end testAPackResolvingAPlaceholderLeafValidatesAndReportsItsProvenance()

	/**
	 * Validate a pack against the real NL tables.
	 *
	 * @param JurisdictionPack $pack The pack.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function validate(JurisdictionPack $pack): array {
		return (new PackValidator())->validate($pack, TaxTables::load('nl-2026'));
	}//end validate()

	/**
	 * An `expr` step.
	 *
	 * @param string $id The step id.
	 * @param string $incidence The incidence.
	 * @param string $expression The expression.
	 *
	 * @return array<string, mixed>
	 */
	private function exprStep(string $id, string $incidence, string $expression): array {
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

	}//end exprStep()

	/**
	 * An inline golden vector expecting a given net.
	 *
	 * @param int $net The expected net, in cents.
	 *
	 * @return array<string, mixed>
	 */
	private function vector(int $net): array {
		return [
			'period' => '2026-02',
			'input' => ['gross' => 100000],
			'expected' => ['@net' => $net],
		];

	}//end vector()

	/**
	 * A minimal, valid uploaded pack.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return JurisdictionPack
	 */
	private function pack(array $overrides = []): JurisdictionPack {
		$data = array_merge(
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
				'steps' => [$this->exprStep('tax', 'reduces-net', '@input.gross * 10 / 100')],
				'selfTest' => ['vectors' => [$this->vector(90000)]],
			],
			$overrides
		);

		return new JurisdictionPack($data, JurisdictionPack::ORIGIN_UPLOADED);
	}//end pack()

}//end class
