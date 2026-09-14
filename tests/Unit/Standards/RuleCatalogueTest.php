<?php

/**
 * Unit tests for RuleCatalogue, the read-only accessor over the rule JSON files.
 *
 * Covers the internal consistency of `all()` and `count()`, that
 * `machineCheckable()` is a subset of `all()`, that the `byDomain()`,
 * `byFramework()` and `byJurisdiction()` filters return correctly filtered
 * subsets, that `countByDomain()` sums to the total, and that `version()` is the
 * VERSION constant.
 *
 * Closes task 3.1 of the archived humaniq-test-coverage-baseline change, which
 * was archived with this test still unwritten.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-001
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\RuleCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the RuleCatalogue accessors.
 *
 * @covers \OCA\Humaniq\Standards\RuleCatalogue
 */
final class RuleCatalogueTest extends TestCase {

	/**
	 * Reset the memoised cache before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		RuleCatalogue::reset();
	}//end setUp()

	/**
	 * count() equals the number of loaded rules, and the corpus is not empty.
	 *
	 * @return void
	 */
	public function testCountMatchesAll(): void {
		$all = RuleCatalogue::all();

		$this->assertNotEmpty($all, 'The rule corpus must not be empty.');
		$this->assertSame(count($all), RuleCatalogue::count());
	}//end testCountMatchesAll()

	/**
	 * Every loaded rule carries the required keys, severity and source included.
	 *
	 * @return void
	 */
	public function testEveryRuleIsWellFormed(): void {
		$required = ['id', 'domain', 'jurisdiction', 'framework', 'source', 'statement', 'severity'];

		foreach (RuleCatalogue::all() as $rule) {
			foreach ($required as $key) {
				$this->assertArrayHasKey($key, $rule, 'Every loaded rule must carry ' . $key . '.');
			}
		}
	}//end testEveryRuleIsWellFormed()

	/**
	 * machineCheckable() only returns rules flagged machine-checkable, each of
	 * which also appears in all().
	 *
	 * @return void
	 */
	public function testMachineCheckableIsSubsetOfAll(): void {
		$allIds = array_map(static fn (array $rule): string => (string)$rule['id'], RuleCatalogue::all());
		$machine = RuleCatalogue::machineCheckable();

		$this->assertNotEmpty($machine);
		$this->assertLessThanOrEqual(count($allIds), count($machine));
		foreach ($machine as $rule) {
			$this->assertTrue(($rule['machineCheckable'] ?? false), 'machineCheckable() must only return machine-checkable rules.');
			$this->assertContains((string)$rule['id'], $allIds, 'Every machine-checkable rule id must exist in all().');
		}
	}//end testMachineCheckableIsSubsetOfAll()

	/**
	 * byDomain() returns exactly the rules with the requested domain.
	 *
	 * @return void
	 */
	public function testByDomainReturnsFilteredSubset(): void {
		$domain = (string)RuleCatalogue::all()[0]['domain'];
		$subset = RuleCatalogue::byDomain($domain);
		$expected = count(array_filter(RuleCatalogue::all(), static fn (array $rule): bool => (string)$rule['domain'] === $domain));

		$this->assertNotEmpty($subset);
		$this->assertCount($expected, $subset);
		foreach ($subset as $rule) {
			$this->assertSame($domain, (string)$rule['domain']);
		}
	}//end testByDomainReturnsFilteredSubset()

	/**
	 * byFramework() returns exactly the rules attributed to the requested framework.
	 *
	 * @return void
	 */
	public function testByFrameworkReturnsFilteredSubset(): void {
		$framework = (string)RuleCatalogue::all()[0]['framework'];
		$subset = RuleCatalogue::byFramework($framework);

		$this->assertNotEmpty($subset);
		foreach ($subset as $rule) {
			$this->assertSame($framework, (string)$rule['framework']);
		}
	}//end testByFrameworkReturnsFilteredSubset()

	/**
	 * byJurisdiction('NL') returns NL, EU-wide and global rules, and nothing from a
	 * foreign-only jurisdiction.
	 *
	 * @return void
	 */
	public function testByJurisdictionNlIncludesEuAndGlobalOnly(): void {
		$subset = RuleCatalogue::byJurisdiction('NL');
		$jurisdictions = array_map(static fn (array $rule): string => strtoupper((string)$rule['jurisdiction']), $subset);

		$this->assertContains('NL', $jurisdictions, 'The corpus carries NL rules, so an NL query must return some.');
		foreach ($jurisdictions as $jurisdiction) {
			$this->assertContains($jurisdiction, ['NL', 'EU', 'GLOBAL'], 'An NL query must only return NL, EU or global rules.');
		}
	}//end testByJurisdictionNlIncludesEuAndGlobalOnly()

	/**
	 * byJurisdiction('US') excludes EU-wide rules, since the US is not an EU
	 * member, and still includes global rules.
	 *
	 * @return void
	 */
	public function testByJurisdictionUsExcludesEu(): void {
		$jurisdictions = array_map(
			static fn (array $rule): string => strtoupper((string)$rule['jurisdiction']),
			RuleCatalogue::byJurisdiction('US')
		);

		$this->assertContains('GLOBAL', $jurisdictions, 'Global rules apply to a US query too.');
		foreach ($jurisdictions as $jurisdiction) {
			$this->assertContains($jurisdiction, ['US', 'GLOBAL'], 'A US query must not return EU-wide rules.');
		}
	}//end testByJurisdictionUsExcludesEu()

	/**
	 * countByDomain() sums to the total rule count.
	 *
	 * @return void
	 */
	public function testCountByDomainSumsToTotal(): void {
		$this->assertSame(RuleCatalogue::count(), array_sum(RuleCatalogue::countByDomain()));
	}//end testCountByDomainSumsToTotal()

	/**
	 * version() returns the non-empty VERSION constant.
	 *
	 * @return void
	 */
	public function testVersionIsTheVersionConstant(): void {
		$this->assertNotSame('', RuleCatalogue::version());
		$this->assertSame(RuleCatalogue::VERSION, RuleCatalogue::version());
	}//end testVersionIsTheVersionConstant()
}//end class
