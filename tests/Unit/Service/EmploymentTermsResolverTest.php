<?php

/**
 * EmploymentTermsResolver tests
 *
 * Pins the inheritance direction (CAO is the norm, the contract is the
 * exception), the provenance every resolution carries, and the three refusals:
 * an unexplained override, a negative percentage, and a partial merge that
 * would invent terms nobody agreed to.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Humaniq\Service\EmployeeCostRateService;
use OCA\Humaniq\Service\EmploymentTermsResolver;
use OCA\Humaniq\Standards\CaoRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmploymentTermsResolver.
 */
class EmploymentTermsResolverTest extends TestCase {

	/**
	 * The corpus's fictional example CLA — the only one shipping VERIFIED
	 * leaves, so the only one that can act as a collective floor in a test.
	 *
	 * @var string
	 */
	private const EXAMPLE_CAO = 'cao-voorbeeld';

	/**
	 * Resolver under test.
	 *
	 * @var EmploymentTermsResolver
	 */
	private EmploymentTermsResolver $resolver;

	/**
	 * Build the resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		CaoRegistry::reset();
		$this->resolver = new EmploymentTermsResolver();

	}//end setUp()

	/**
	 * A contract override wins over the CAO, and says so.
	 *
	 * @return void
	 */
	public function testContractOverrideWinsOverTheCao(): void {
		$terms = $this->resolver->resolveOvertimeToeslag(
			[
				'cao' => 'cao-gemeenten',
				'overtimeToeslagPercentages' => ['doordeweeks' => 50, 'zondag' => 100],
				'overtimeTermsOverrideReason' => 'Individueel onderhandeld bij indiensttreding 2026-03',
			]
		);

		$this->assertNotNull($terms);
		$this->assertSame(EmploymentTermsResolver::SOURCE_CONTRACT, $terms['source']);
		$this->assertSame(50.0, $terms['percentages']['doordeweeks']);
		$this->assertSame(100.0, $terms['percentages']['zondag']);
		$this->assertStringContainsString('Individueel onderhandeld', $terms['basis']);

	}//end testContractOverrideWinsOverTheCao()

	/**
	 * An override wins IN FULL — categories it omits are NOT back-filled from
	 * the CAO. A per-category merge would produce a set of terms that exists in
	 * neither document and that nobody agreed to.
	 *
	 * @return void
	 */
	public function testOverrideIsNotMergedPerCategoryWithTheCao(): void {
		$terms = $this->resolver->resolveOvertimeToeslag(
			[
				'cao' => 'cao-gemeenten',
				'overtimeToeslagPercentages' => ['zondag' => 100],
				'overtimeTermsOverrideReason' => 'Alleen zondagstoeslag afwijkend',
			]
		);

		$this->assertSame(['zondag' => 100.0], $terms['percentages']);
		$this->assertArrayNotHasKey('zaterdag', $terms['percentages']);
		$this->assertArrayNotHasKey('doordeweeks', $terms['percentages']);

	}//end testOverrideIsNotMergedPerCategoryWithTheCao()

	/**
	 * Departing from a collective agreement is a decision someone must be able
	 * to justify — an unexplained override is indistinguishable from a
	 * data-entry error.
	 *
	 * @return void
	 */
	public function testUnexplainedOvertimeOverrideIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/must say why/');

		$this->resolver->resolveOvertimeToeslag(
			['cao' => 'cao-gemeenten', 'overtimeToeslagPercentages' => ['zondag' => 100]]
		);

	}//end testUnexplainedOvertimeOverrideIsRefused()

	/**
	 * A negative surcharge is a data error, not a discount.
	 *
	 * @return void
	 */
	public function testNegativePercentageIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/must not be negative/');

		$this->resolver->resolveOvertimeToeslag(
			[
				'cao' => 'cao-gemeenten',
				'overtimeToeslagPercentages' => ['zondag' => -100],
				'overtimeTermsOverrideReason' => 'typo',
			]
		);

	}//end testNegativePercentageIsRefused()

	/**
	 * With no override, the CAO applies. Every shipped CAO's overtime leaf is
	 * currently a placeholder pending transcription against the official
	 * CAO-tekst, so the honest resolution today is null — no uplift rather than
	 * an invented one. This test states that expectation explicitly so that
	 * confirming a CAO's overtime article turns it red, which is the moment
	 * someone should re-read this test.
	 *
	 * @return void
	 */
	public function testUnverifiedCaoOvertimeResolvesToNullNotAGuess(): void {
		$this->assertNull(CaoRegistry::overtimeToeslagPercentages('cao-gemeenten'));
		$this->assertNull($this->resolver->resolveOvertimeToeslag(['cao' => 'cao-gemeenten']));

	}//end testUnverifiedCaoOvertimeResolvesToNullNotAGuess()

	/**
	 * A contract naming no CAO and carrying no override has no terms.
	 *
	 * @return void
	 */
	public function testNoCaoAndNoOverrideYieldsNull(): void {
		$this->assertNull($this->resolver->resolveOvertimeToeslag([]));

	}//end testNoCaoAndNoOverrideYieldsNull()

	/**
	 * An unknown CAO id resolves to null rather than throwing — a contract
	 * naming a CAO the corpus does not carry is a gap, not a crash.
	 *
	 * @return void
	 */
	public function testUnknownCaoResolvesToNull(): void {
		$this->assertNull($this->resolver->resolveOvertimeToeslag(['cao' => 'cao-does-not-exist']));

	}//end testUnknownCaoResolvesToNull()

	/**
	 * The leave override wins and carries its reason.
	 *
	 * @return void
	 */
	public function testLeaveOverrideWinsAndCarriesItsReason(): void {
		$terms = $this->resolver->resolveLeaveEntitlementDays(
			[
				'cao' => 'cao-gemeenten',
				'leaveEntitlementOverrideDays' => [
					'vakantiedagenWettelijk' => 20,
					'vakantiedagenBovenwettelijk' => 13,
				],
				'leaveTermsOverrideReason' => 'Arbeidsvoorwaardelijke afspraak: 33 dagen',
			]
		);

		$this->assertSame(EmploymentTermsResolver::SOURCE_CONTRACT, $terms['source']);
		$this->assertSame(13.0, $terms['days']['vakantiedagenBovenwettelijk']);

	}//end testLeaveOverrideWinsAndCarriesItsReason()

	/**
	 * An unexplained leave override is refused, same as overtime.
	 *
	 * @return void
	 */
	public function testUnexplainedLeaveOverrideIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->resolver->resolveLeaveEntitlementDays(
			[
				'cao' => 'cao-gemeenten',
				'leaveEntitlementOverrideDays' => ['vakantiedagenWettelijk' => 20],
			]
		);

	}//end testUnexplainedLeaveOverrideIsRefused()

	/**
	 * A BELOW-statutory override is REFUSED. No agreement of any kind may go
	 * under BW art. 7:634, so an unlawful term must never become a term of
	 * employment — storing it and reporting on it afterwards is not the same
	 * as not permitting it.
	 *
	 * @return void
	 */
	public function testBelowStatutoryLeaveOverrideIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/statutory minimum/');

		$this->resolver->resolveLeaveEntitlementDays(
			[
				'cao' => 'cao-gemeenten',
				'leaveEntitlementOverrideDays' => ['vakantiedagenWettelijk' => 5],
				'leaveTermsOverrideReason' => 'Poging tot minder verlof dan de wet toestaat',
			]
		);

	}//end testBelowStatutoryLeaveOverrideIsRefused()

	/**
	 * An override AT the statutory floor but BELOW the collective agreement is
	 * refused too — a CLA is a floor an individual contract may improve on,
	 * never undercut.
	 *
	 * @return void
	 */
	public function testLeaveOverrideBelowTheCollectiveAgreementIsRefused(): void {
		// cao-voorbeeld is the corpus's deliberate fixture: a fictional CLA
		// with VERIFIED leaves, granting 25 full-time days — above the
		// statutory 20 — so the collective floor has something to bite on.
		// Every real CLA ships placeholder leaves, which are correctly not
		// floors, so searching for "the first usable one" landed on a CLA at
		// exactly the statutory minimum and quietly skipped.
		$this->assertNotNull(
			CaoRegistry::minLeaveHours(self::EXAMPLE_CAO, 40.0),
			'the example CLA must carry a usable leaveEntitlement or this test proves nothing'
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/never undercut/');

		$this->resolver->resolveLeaveEntitlementDays(
			[
				'cao' => self::EXAMPLE_CAO,
				'leaveEntitlementOverrideDays' => [
					'vakantiedagenWettelijk' => EmploymentTermsResolver::STATUTORY_LEAVE_DAYS_FULLTIME,
					'vakantiedagenBovenwettelijk' => 0,
				],
				'leaveTermsOverrideReason' => 'Minder dan de cao, moet geweigerd worden',
			]
		);

	}//end testLeaveOverrideBelowTheCollectiveAgreementIsRefused()

	/**
	 * An overtime override that UNDERCUTS the collective agreement is refused.
	 *
	 * @return void
	 */
	public function testOvertimeOverrideBelowTheCollectiveAgreementIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/never undercut/');

		$this->resolver->resolveOvertimeToeslag(
			[
				'cao' => self::EXAMPLE_CAO,
				// The example CLA pays 100% on Sunday.
				'overtimeToeslagPercentages' => [
					'doordeweeks' => 25,
					'zaterdag' => 50,
					'zondag' => 50,
					'feestdag' => 100,
				],
				'overtimeTermsOverrideReason' => 'Lagere zondagstoeslag, moet geweigerd worden',
			]
		);

	}//end testOvertimeOverrideBelowTheCollectiveAgreementIsRefused()

	/**
	 * An override that OMITS a category the CLA pays for is refused too —
	 * dropping a surcharge entirely is the quietest way to be worse off, and
	 * because an override wins in full it would otherwise succeed silently.
	 *
	 * @return void
	 */
	public function testOvertimeOverrideOmittingACollectiveCategoryIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/omits "zondag"/');

		$this->resolver->resolveOvertimeToeslag(
			[
				'cao' => self::EXAMPLE_CAO,
				'overtimeToeslagPercentages' => ['doordeweeks' => 25, 'zaterdag' => 50, 'feestdag' => 100],
				'overtimeTermsOverrideReason' => 'Zondag weggelaten, moet geweigerd worden',
			]
		);

	}//end testOvertimeOverrideOmittingACollectiveCategoryIsRefused()

	/**
	 * A BETTER override is accepted — the point of the floor is that
	 * improvements remain possible.
	 *
	 * @return void
	 */
	public function testBetterOverrideIsAccepted(): void {
		$terms = $this->resolver->resolveOvertimeToeslag(
			[
				'cao' => self::EXAMPLE_CAO,
				'overtimeToeslagPercentages' => [
					'doordeweeks' => 50,
					'zaterdag' => 75,
					'zondag' => 100,
					'feestdag' => 150,
				],
				'overtimeTermsOverrideReason' => 'Gunstiger dan de cao, individueel afgesproken',
			]
		);

		$this->assertSame(EmploymentTermsResolver::SOURCE_CONTRACT, $terms['source']);
		$this->assertSame(150.0, $terms['percentages']['feestdag']);

	}//end testBetterOverrideIsAccepted()

	/**
	 * The join between the two halves: resolved terms become a cost ADDITION
	 * the rate service accepts. Expressed as a percentage rather than
	 * pre-computed cents, so it resolves against whichever wage base applies
	 * to the employee rather than one captured at terms-resolution time.
	 *
	 * @return void
	 */
	public function testOvertimeTermsProjectIntoACostAddition(): void {
		$addition = $this->resolver->overtimeAdditionFor(['cao' => self::EXAMPLE_CAO], 'zondag');

		$this->assertNotNull($addition);
		$this->assertSame(EmployeeCostRateService::ADDITION_OVERTIME, $addition['key']);
		$this->assertSame(100.0, $addition['percentageOfWage']);
		$this->assertArrayNotHasKey('centsPerHour', $addition, 'must stay relative to the wage base');
		$this->assertStringContainsString('zondag', $addition['basis']);

	}//end testOvertimeTermsProjectIntoACostAddition()

	/**
	 * A category the terms do not cover yields no addition — not a zero. An
	 * absent addition is a visible gap; a zero looks like an answer.
	 *
	 * @return void
	 */
	public function testUncoveredOvertimeCategoryYieldsNoAddition(): void {
		$this->assertNull(
			$this->resolver->overtimeAdditionFor(['cao' => self::EXAMPLE_CAO], 'schrikkeldag')
		);

	}//end testUncoveredOvertimeCategoryYieldsNoAddition()

	/**
	 * A CLA whose overtime article is still a placeholder produces no uplift
	 * at all, rather than an invented one.
	 *
	 * @return void
	 */
	public function testUnverifiedCaoYieldsNoOvertimeAddition(): void {
		$this->assertNull($this->resolver->overtimeAdditionFor(['cao' => 'cao-gemeenten'], 'zondag'));

	}//end testUnverifiedCaoYieldsNoOvertimeAddition()

	/**
	 * The example CLA resolves real overtime terms, so the machinery can be
	 * demonstrated without waiting on a transcribed CAO text.
	 *
	 * @return void
	 */
	public function testExampleCaoResolvesUsableOvertimeTerms(): void {
		$terms = $this->resolver->resolveOvertimeToeslag(['cao' => self::EXAMPLE_CAO]);

		$this->assertNotNull($terms);
		$this->assertSame(EmploymentTermsResolver::SOURCE_CAO, $terms['source']);
		$this->assertSame(100.0, $terms['percentages']['zondag']);

	}//end testExampleCaoResolvesUsableOvertimeTerms()

	/**
	 * Every shipped CAO file parses and either carries a well-formed overtime
	 * leaf or omits it — a malformed one would resolve to null and look exactly
	 * like an honest placeholder.
	 *
	 * @return void
	 */
	public function testEveryShippedCaoHasAWellFormedOrAbsentOvertimeLeaf(): void {
		// availableCaos() is keyed BY id, with a metadata row as the value.
		$caos = array_keys(CaoRegistry::availableCaos());
		$this->assertNotEmpty($caos);

		foreach ($caos as $caoId) {
			$cao = CaoRegistry::get($caoId);
			$this->assertNotNull($cao, $caoId . ' must load');
			if (array_key_exists('overtime', $cao) === false) {
				continue;
			}

			$leaf = $cao['overtime'];
			$this->assertArrayHasKey('value', $leaf, $caoId . ' overtime leaf needs a value');
			$this->assertArrayHasKey('source', $leaf, $caoId . ' overtime leaf needs a source');
			// The corpus rule: an unconfirmed value must never be silent.
			if (($leaf['verified'] ?? false) !== true || ($leaf['placeholder'] ?? false) === true) {
				$this->assertNotEmpty(
					($leaf['checkAgainst'] ?? ''),
					$caoId . ' has an unverified overtime leaf and must name what to confirm it against'
				);
			}
		}//end foreach

	}//end testEveryShippedCaoHasAWellFormedOrAbsentOvertimeLeaf()
}//end class
