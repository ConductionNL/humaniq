<?php

/**
 * Unit tests for the NL ABP mandatory-affiliation check (abp-aansluiting).
 *
 * Pins the fund- and tenant-scoped nl-abp-fund-required predicate on
 * PayrollRun: an obligated administratie's own unfiled period violates, its
 * own filed period passes, and every vacuous branch (non-NL, draft, empty
 * period, non-obligated/unresolvable administratie) never fires. Exercises
 * both the raw NlAbpChecks::checks() predicate (the NlPensionFilingChecksTest
 * precedent) AND the REAL RuleEngine::evaluate() (catalogue + provider
 * auto-discovery wired end-to-end), per tasks.md #12.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md#REQ-ABP-003
 * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md#REQ-ABP-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlAbpChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlAbpChecks.
 *
 * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md
 */
class NlAbpChecksTest extends TestCase
{


    /**
     * The registered PayrollRun predicates contributed by this provider,
     * keyed by rule id.
     *
     * @var array<string, callable>
     */
    private array $runChecks;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->runChecks = NlAbpChecks::checks()['PayrollRun'];

    }//end setUp()


    /**
     * A minimal PayrollRun fixture; each test overrides the fields it exercises.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function payrollRun(array $overrides=[]): array
    {
        return array_merge(
            [
                'jurisdiction'     => 'NL',
                'period'           => '2026-06',
                'status'           => 'approved',
                'administrationId' => 'ADM-003',
            ],
            $overrides
        );

    }//end payrollRun()


    /**
     * A `context['related']` fixture matching RuleAuditService's pre-pass
     * shape (Administration.abpPlichtigByAdministrationId,
     * PensionFiling.abpFiledPeriodsByAdministrationId).
     *
     * @param array<string, bool>               $plichtigByAdministrationId Administration obligation map.
     * @param array<string, array<string, bool>> $filedByAdministrationId   Filed-periods-by-administratie map.
     *
     * @return array<string, mixed>
     */
    private function context(array $plichtigByAdministrationId=[], array $filedByAdministrationId=[]): array
    {
        return [
            'related' => [
                'Administration' => ['abpPlichtigByAdministrationId' => $plichtigByAdministrationId],
                'PensionFiling'   => ['abpFiledPeriodsByAdministrationId' => $filedByAdministrationId],
            ],
        ];

    }//end context()


    /**
     * REQ-ABP-003 scenario "An obligated administratie's unfiled period is
     * flagged".
     *
     * @return void
     */
    public function testViolatedWhenObligatedAdministratieHasNoOwnAbpFiling(): void
    {
        $run     = $this->payrollRun(['administrationId' => 'ADM-003', 'period' => '2026-06']);
        $context = $this->context(['ADM-003' => true], []);

        $this->assertFalse(($this->runChecks['nl-abp-fund-required'])($run, $context));

    }//end testViolatedWhenObligatedAdministratieHasNoOwnAbpFiling()


    /**
     * REQ-ABP-003 scenario "An obligated administratie with its own ABP
     * filing passes".
     *
     * @return void
     */
    public function testSatisfiedWhenObligatedAdministratieHasItsOwnAbpFiling(): void
    {
        $run     = $this->payrollRun(['administrationId' => 'ADM-001', 'period' => '2026-06']);
        $context = $this->context(['ADM-001' => true], ['ADM-001' => ['2026-06' => true]]);

        $this->assertTrue(($this->runChecks['nl-abp-fund-required'])($run, $context));

    }//end testSatisfiedWhenObligatedAdministratieHasItsOwnAbpFiling()


    /**
     * REQ-ABP-003 scenario "The global fund-blind rule stays silent for the
     * same run the new rule flags": a DIFFERENT administratie's ABP filing
     * for the same period must not satisfy this administratie-scoped check.
     *
     * @return void
     */
    public function testViolatedWhenOnlyADifferentAdministratieFiledTheSamePeriod(): void
    {
        $run     = $this->payrollRun(['administrationId' => 'ADM-003', 'period' => '2026-06']);
        // ADM-001 filed 2026-06 for itself; ADM-003 has no entry at all.
        $context = $this->context(
            ['ADM-003' => true, 'ADM-001' => true],
            ['ADM-001' => ['2026-06' => true]]
        );

        $this->assertFalse(($this->runChecks['nl-abp-fund-required'])($run, $context));

    }//end testViolatedWhenOnlyADifferentAdministratieFiledTheSamePeriod()


    /**
     * REQ-ABP-003 scenario "A non-obligated administratie never violates".
     *
     * @return void
     */
    public function testVacuousWhenAdministratieIsNotObligated(): void
    {
        $run     = $this->payrollRun(['administrationId' => 'ADM-002', 'period' => '2026-06']);
        $context = $this->context(['ADM-002' => false], []);

        $this->assertTrue(($this->runChecks['nl-abp-fund-required'])($run, $context));

    }//end testVacuousWhenAdministratieIsNotObligated()


    /**
     * The administratie's obligation entry is simply absent from the index
     * (an administrationId that resolves to no Administration row at all) —
     * vacuous, same as an explicit `false`.
     *
     * @return void
     */
    public function testVacuousWhenAdministrationIdDoesNotResolve(): void
    {
        $run     = $this->payrollRun(['administrationId' => 'no-such-adm', 'period' => '2026-06']);
        $context = $this->context(['ADM-001' => true], []);

        $this->assertTrue(($this->runChecks['nl-abp-fund-required'])($run, $context));

    }//end testVacuousWhenAdministrationIdDoesNotResolve()


    /**
     * @return void
     */
    public function testVacuousWhenAdministrationIdEmpty(): void
    {
        $run = $this->payrollRun(['administrationId' => '']);

        $this->assertTrue(($this->runChecks['nl-abp-fund-required'])($run, $this->context(['ADM-003' => true])));

    }//end testVacuousWhenAdministrationIdEmpty()


    /**
     * REQ-ABP-003 scenario "A draft run is out of scope".
     *
     * @return void
     */
    public function testVacuousForDraftRun(): void
    {
        $run     = $this->payrollRun(['status' => 'draft']);
        $context = $this->context(['ADM-003' => true], []);

        $this->assertTrue(($this->runChecks['nl-abp-fund-required'])($run, $context));

    }//end testVacuousForDraftRun()


    /**
     * Posted and paid runs are in scope exactly like approved (the
     * NlPensionFilingChecks APPROVED_OR_LATER precedent).
     *
     * @return void
     */
    public function testViolatedForPostedOrPaidObligatedUnfiledRun(): void
    {
        $context = $this->context(['ADM-003' => true], []);

        $this->assertFalse(($this->runChecks['nl-abp-fund-required'])($this->payrollRun(['status' => 'posted']), $context));
        $this->assertFalse(($this->runChecks['nl-abp-fund-required'])($this->payrollRun(['status' => 'paid']), $context));

    }//end testViolatedForPostedOrPaidObligatedUnfiledRun()


    /**
     * @return void
     */
    public function testVacuousForNonNlRun(): void
    {
        $run     = $this->payrollRun(['jurisdiction' => 'DE']);
        $context = $this->context(['ADM-003' => true], []);

        $this->assertTrue(($this->runChecks['nl-abp-fund-required'])($run, $context));

    }//end testVacuousForNonNlRun()


    /**
     * @return void
     */
    public function testVacuousForEmptyPeriod(): void
    {
        $run     = $this->payrollRun(['period' => '']);
        $context = $this->context(['ADM-003' => true], []);

        $this->assertTrue(($this->runChecks['nl-abp-fund-required'])($run, $context));

    }//end testVacuousForEmptyPeriod()


    /**
     * Degrades to vacuous when the pre-pass has not populated the related
     * context at all (e.g. the Administration schema does not exist yet).
     *
     * @return void
     */
    public function testVacuousWhenRelatedContextMissingEntirely(): void
    {
        $run = $this->payrollRun();

        $this->assertTrue(($this->runChecks['nl-abp-fund-required'])($run, []));

    }//end testVacuousWhenRelatedContextMissingEntirely()


    // -- REAL RuleEngine + catalogue wiring (tasks.md #12) --


    /**
     * The rule is a real catalogue entry with a real registered predicate —
     * RuleEngine::evaluate() (provider auto-discovery + RuleCatalogue
     * lookup), not just the raw predicate array.
     *
     * @return void
     */
    public function testRealRuleEngineFlagsTheObligatedUnfiledRun(): void
    {
        $run     = $this->payrollRun(['administrationId' => 'ADM-003', 'period' => '2026-06']);
        $context = $this->context(['ADM-003' => true], []);

        $violations = RuleEngine::evaluate('PayrollRun', $run, $context);
        $ruleIds    = array_map(static fn($v) => $v->ruleId, $violations);

        $this->assertContains('nl-abp-fund-required', $ruleIds);
        $this->assertContains('nl-abp-fund-required', RuleEngine::checkedRuleIds());

    }//end testRealRuleEngineFlagsTheObligatedUnfiledRun()


    /**
     * The real RuleEngine reports no nl-abp-fund-required violation for an
     * obligated administratie's own filed period.
     *
     * @return void
     */
    public function testRealRuleEngineStaysCleanForTheObligatedFiledRun(): void
    {
        $run     = $this->payrollRun(['administrationId' => 'ADM-001', 'period' => '2026-06']);
        $context = $this->context(['ADM-001' => true], ['ADM-001' => ['2026-06' => true]]);

        $violations = RuleEngine::evaluate('PayrollRun', $run, $context);
        $ruleIds    = array_map(static fn($v) => $v->ruleId, $violations);

        $this->assertNotContains('nl-abp-fund-required', $ruleIds);

    }//end testRealRuleEngineStaysCleanForTheObligatedFiledRun()


}//end class
