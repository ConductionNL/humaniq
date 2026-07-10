<?php

/**
 * RuleCatalogue unit tests
 *
 * Exercises the read-only accessor over the per-domain rule JSON files: internal
 * consistency of `all()`/`count()`, that `machineCheckable()` is a strict subset
 * of `all()`, that the `byDomain()`/`byFramework()`/`byJurisdiction()` filters
 * return correctly-filtered strict subsets, and that `version()` is non-empty.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hrmq-test-coverage-baseline/specs/hrmq-test-coverage-baseline/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards;

use OCA\Hrmq\Standards\RuleCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hrmq\Standards\RuleCatalogue
 */
final class RuleCatalogueTest extends TestCase
{


    /**
     * Reset the memoised cache before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        RuleCatalogue::reset();

    }//end setUp()


    /**
     * count() must equal the number of loaded rules and there must be at least one.
     *
     * @return void
     */
    public function testCountMatchesAll(): void
    {
        $all = RuleCatalogue::all();
        $this->assertNotEmpty($all, 'The rule corpus must not be empty.');
        $this->assertSame(count($all), RuleCatalogue::count());

    }//end testCountMatchesAll()


    /**
     * Every rule carries the required keys (all() skips malformed entries).
     *
     * @return void
     */
    public function testEveryRuleIsWellFormed(): void
    {
        $required = ['id', 'domain', 'jurisdiction', 'framework', 'source', 'statement', 'severity'];
        foreach (RuleCatalogue::all() as $rule) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $rule, 'Every loaded rule must carry '.$key.'.');
            }
        }

    }//end testEveryRuleIsWellFormed()


    /**
     * machineCheckable() is a strict subset of all(): every entry appears in all()
     * (matched by id) and every entry has machineCheckable === true.
     *
     * @return void
     */
    public function testMachineCheckableIsSubsetOfAll(): void
    {
        $allIds     = array_map(static fn(array $r): string => (string) $r['id'], RuleCatalogue::all());
        $machineIds = array_map(static fn(array $r): string => (string) $r['id'], RuleCatalogue::machineCheckable());

        $this->assertLessThanOrEqual(count($allIds), count($machineIds));
        foreach (RuleCatalogue::machineCheckable() as $rule) {
            $this->assertTrue(($rule['machineCheckable'] ?? false), 'machineCheckable() must only return machine-checkable rules.');
            $this->assertContains((string) $rule['id'], $allIds, 'Every machine-checkable rule id must exist in all().');
        }

    }//end testMachineCheckableIsSubsetOfAll()


    /**
     * byDomain() returns exactly the rules with the requested domain.
     *
     * @return void
     */
    public function testByDomainReturnsFilteredSubset(): void
    {
        $domain = (string) RuleCatalogue::all()[0]['domain'];
        $subset = RuleCatalogue::byDomain($domain);

        $this->assertNotEmpty($subset);
        $this->assertLessThanOrEqual(RuleCatalogue::count(), count($subset));
        foreach ($subset as $rule) {
            $this->assertSame($domain, (string) $rule['domain']);
        }

    }//end testByDomainReturnsFilteredSubset()


    /**
     * byFramework() returns exactly the rules attributed to the requested framework.
     *
     * @return void
     */
    public function testByFrameworkReturnsFilteredSubset(): void
    {
        $framework = (string) RuleCatalogue::all()[0]['framework'];
        $subset    = RuleCatalogue::byFramework($framework);

        $this->assertNotEmpty($subset);
        foreach ($subset as $rule) {
            $this->assertSame($framework, (string) $rule['framework']);
        }

    }//end testByFrameworkReturnsFilteredSubset()


    /**
     * byJurisdiction('NL') includes NL, EU-wide and global rules — and nothing
     * from a foreign-only jurisdiction (e.g. a pure US or DE rule).
     *
     * @return void
     */
    public function testByJurisdictionNlIncludesEuAndGlobalOnly(): void
    {
        foreach (RuleCatalogue::byJurisdiction('NL') as $rule) {
            $this->assertContains(
                strtoupper((string) $rule['jurisdiction']),
                ['NL', 'EU', 'GLOBAL'],
                'A NL jurisdiction query must only return NL / EU / global rules.'
            );
        }

    }//end testByJurisdictionNlIncludesEuAndGlobalOnly()


    /**
     * byJurisdiction('US') excludes EU-wide rules (the US is not an EU member) but
     * still includes global rules.
     *
     * @return void
     */
    public function testByJurisdictionUsExcludesEu(): void
    {
        foreach (RuleCatalogue::byJurisdiction('US') as $rule) {
            $this->assertContains(
                strtoupper((string) $rule['jurisdiction']),
                ['US', 'GLOBAL'],
                'A US jurisdiction query must not return EU-wide rules.'
            );
        }

    }//end testByJurisdictionUsExcludesEu()


    /**
     * countByDomain() sums to the total rule count.
     *
     * @return void
     */
    public function testCountByDomainSumsToTotal(): void
    {
        $this->assertSame(RuleCatalogue::count(), array_sum(RuleCatalogue::countByDomain()));

    }//end testCountByDomainSumsToTotal()


    /**
     * version() returns a non-empty string equal to the VERSION constant.
     *
     * @return void
     */
    public function testVersionIsNonEmpty(): void
    {
        $this->assertNotSame('', RuleCatalogue::version());
        $this->assertSame(RuleCatalogue::VERSION, RuleCatalogue::version());

    }//end testVersionIsNonEmpty()


}//end class
