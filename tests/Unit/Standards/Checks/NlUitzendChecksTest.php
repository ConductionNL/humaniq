<?php

/**
 * Unit tests for the agency-worker (uitzendkracht) checks (NlUitzendChecks).
 *
 * Pins the two hr-uitzend predicates on EmploymentContract, both raw and
 * through the REAL RuleEngine + RuleCatalogue corpus (so the test also proves
 * the corpus rules exist, are machine-checkable, carry severity `mandatory`,
 * and are reachable -- i.e. NOT an orphaned capability, REQ-UITZ-002/-003):
 *
 * - nl-uitzendbeding-alleen-fase-a: on an agency contract, uitzendbeding true
 *   requires fase A; fase B + beding true violates; beding false (any fase) is
 *   vacuous; a NON-agency contract is never evaluated (the `type === 'agency'`
 *   guard).
 * - nl-inlenersbeloning-onderbouwing-vereist: on an agency contract with a
 *   populated hourlyWage the inlenersbeloningReferentie must be present; absent
 *   with a wage violates; absent without a wage is vacuous; a NON-agency
 *   contract is never evaluated.
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
 * @spec openspec/changes/uitzend-flexpool/specs/uitzend-flexpool/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlUitzendChecks;
use OCA\Hrmq\Standards\RuleCatalogue;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlUitzendChecks (raw predicates + the two rules through the real
 * RuleEngine).
 *
 * @spec openspec/changes/uitzend-flexpool/specs/uitzend-flexpool/spec.md
 */
class NlUitzendChecksTest extends TestCase
{

    /**
     * The registered EmploymentContract predicates, keyed by rule id.
     *
     * @var array<string, callable>
     */
    private array $checks;


    /**
     * Reset the statically-memoised engine/catalogue so each test loads the
     * real corpus fresh.
     *
     * @return void
     */
    protected function setUp(): void
    {
        RuleEngine::reset();
        RuleCatalogue::reset();
        $this->checks = NlUitzendChecks::checks()['EmploymentContract'];

    }//end setUp()


    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RuleEngine::reset();
        RuleCatalogue::reset();

    }//end tearDown()


    /**
     * A minimal agency EmploymentContract fixture; each test overrides the
     * fields it exercises.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function contract(array $overrides=[]): array
    {
        return array_merge(
            [
                'employeeId'                 => 'employee-uitzend-abu',
                'type'                       => 'agency',
                'uitzendFase'                => 'A',
                'uitzendbedingVanToepassing' => true,
                'hourlyWage'                 => 16.50,
                'inlenersbeloningReferentie' => 'Inlener loonschaal referentie, schaal 3',
            ],
            $overrides
        );

    }//end contract()


    /**
     * Whether the evaluated violations contain a given rule id.
     *
     * @param array<int, \OCA\Hrmq\Standards\Violation> $violations The violations.
     * @param string                                    $ruleId     The rule id to look for.
     *
     * @return bool
     */
    private function hasViolation(array $violations, string $ruleId): bool
    {
        foreach ($violations as $violation) {
            if ($violation->ruleId === $ruleId) {
                return true;
            }
        }

        return false;

    }//end hasViolation()


    /**
     * The single violation for a given rule id, or null.
     *
     * @param array<int, \OCA\Hrmq\Standards\Violation> $violations The violations.
     * @param string                                    $ruleId     The rule id.
     *
     * @return \OCA\Hrmq\Standards\Violation|null
     */
    private function violationFor(array $violations, string $ruleId): mixed
    {
        foreach ($violations as $violation) {
            if ($violation->ruleId === $ruleId) {
                return $violation;
            }
        }

        return null;

    }//end violationFor()


    // -- reachability --


    /**
     * Both rules are registered against EmploymentContract AND wired to the
     * corpus -- reachable, not orphaned predicates.
     *
     * @return void
     */
    public function testBothChecksAreReachableFromTheEngine(): void
    {
        $this->assertArrayHasKey('nl-uitzendbeding-alleen-fase-a', $this->checks);
        $this->assertArrayHasKey('nl-inlenersbeloning-onderbouwing-vereist', $this->checks);
        $this->assertContains('nl-uitzendbeding-alleen-fase-a', RuleEngine::checkedRuleIds());
        $this->assertContains('nl-inlenersbeloning-onderbouwing-vereist', RuleEngine::checkedRuleIds());

        $machineCheckable = array_column(RuleCatalogue::machineCheckable(), 'id');
        $this->assertContains('nl-uitzendbeding-alleen-fase-a', $machineCheckable);
        $this->assertContains('nl-inlenersbeloning-onderbouwing-vereist', $machineCheckable);

    }//end testBothChecksAreReachableFromTheEngine()


    // -- nl-uitzendbeding-alleen-fase-a (REQ-UITZ-002) --


    /**
     * @return void
     */
    public function testBedingTrueInFaseAPasses(): void
    {
        $this->assertTrue(($this->checks['nl-uitzendbeding-alleen-fase-a'])($this->contract(['uitzendFase' => 'A', 'uitzendbedingVanToepassing' => true])));

    }//end testBedingTrueInFaseAPasses()


    /**
     * @return void
     */
    public function testBedingTrueInFaseBViolatesAtMandatorySeverity(): void
    {
        $contract = $this->contract(['uitzendFase' => 'B', 'uitzendbedingVanToepassing' => true]);
        $this->assertFalse(($this->checks['nl-uitzendbeding-alleen-fase-a'])($contract));

        $violations = RuleEngine::evaluate('EmploymentContract', $contract, ['jurisdiction' => 'NL']);
        $violation  = $this->violationFor($violations, 'nl-uitzendbeding-alleen-fase-a');
        $this->assertNotNull($violation, 'Beding true past fase A must raise nl-uitzendbeding-alleen-fase-a.');
        $this->assertSame('mandatory', $violation->severity);

    }//end testBedingTrueInFaseBViolatesAtMandatorySeverity()


    /**
     * @return void
     */
    public function testBedingFalseInAnyFasePassesVacuously(): void
    {
        foreach (['A', 'B', 'C', null] as $fase) {
            $contract = $this->contract(['uitzendFase' => $fase, 'uitzendbedingVanToepassing' => false]);
            $this->assertTrue(
                ($this->checks['nl-uitzendbeding-alleen-fase-a'])($contract),
                sprintf('beding false with fase %s must pass vacuously', var_export($fase, true))
            );
        }

    }//end testBedingFalseInAnyFasePassesVacuously()


    /**
     * The `type === 'agency'` guard: a NON-agency contract carrying
     * uitzendbedingVanToepassing:true past fase A is NEVER evaluated by
     * nl-uitzendbeding-alleen-fase-a -- neither the raw predicate nor the real
     * RuleEngine raises a violation (spec.md REQ-UITZ-002 "Non-agency contracts
     * are never evaluated").
     *
     * @return void
     */
    public function testNonAgencyContractIsNeverEvaluatedByUitzendbedingRule(): void
    {
        foreach (['permanent', 'temporary', 'minijob'] as $type) {
            $contract = $this->contract(['type' => $type, 'uitzendFase' => 'B', 'uitzendbedingVanToepassing' => true]);

            $this->assertTrue(
                ($this->checks['nl-uitzendbeding-alleen-fase-a'])($contract),
                sprintf('a %s contract must never be evaluated by the uitzendbeding rule', $type)
            );

            $violations = RuleEngine::evaluate('EmploymentContract', $contract, ['jurisdiction' => 'NL']);
            $this->assertFalse(
                $this->hasViolation($violations, 'nl-uitzendbeding-alleen-fase-a'),
                sprintf('a %s contract must raise no nl-uitzendbeding-alleen-fase-a violation', $type)
            );
        }

    }//end testNonAgencyContractIsNeverEvaluatedByUitzendbedingRule()


    // -- nl-inlenersbeloning-onderbouwing-vereist (REQ-UITZ-003) --


    /**
     * @return void
     */
    public function testInlenersbeloningPresentPasses(): void
    {
        $contract = $this->contract(['hourlyWage' => 16.50, 'inlenersbeloningReferentie' => 'Klant loonschaal referentie, functie magazijnmedewerker, schaal 3']);
        $this->assertTrue(($this->checks['nl-inlenersbeloning-onderbouwing-vereist'])($contract));

    }//end testInlenersbeloningPresentPasses()


    /**
     * @return void
     */
    public function testInlenersbeloningAbsentWithWageViolatesAtMandatorySeverity(): void
    {
        foreach ([null, '', '   '] as $ref) {
            $contract = $this->contract(['hourlyWage' => 16.50, 'inlenersbeloningReferentie' => $ref]);
            $this->assertFalse(
                ($this->checks['nl-inlenersbeloning-onderbouwing-vereist'])($contract),
                sprintf('a set wage with reference %s must violate', var_export($ref, true))
            );
        }

        $contract   = $this->contract(['hourlyWage' => 16.50, 'inlenersbeloningReferentie' => null]);
        $violations = RuleEngine::evaluate('EmploymentContract', $contract, ['jurisdiction' => 'NL']);
        $violation  = $this->violationFor($violations, 'nl-inlenersbeloning-onderbouwing-vereist');
        $this->assertNotNull($violation, 'A set wage with no reference must raise nl-inlenersbeloning-onderbouwing-vereist.');
        $this->assertSame('mandatory', $violation->severity);

    }//end testInlenersbeloningAbsentWithWageViolatesAtMandatorySeverity()


    /**
     * @return void
     */
    public function testInlenersbeloningAbsentWithoutWageIsVacuous(): void
    {
        foreach ([null, ''] as $wage) {
            $contract = $this->contract(['hourlyWage' => $wage, 'inlenersbeloningReferentie' => null]);
            $this->assertTrue(
                ($this->checks['nl-inlenersbeloning-onderbouwing-vereist'])($contract),
                sprintf('no wage (%s) means nothing decidable -- must pass vacuously', var_export($wage, true))
            );
        }

    }//end testInlenersbeloningAbsentWithoutWageIsVacuous()


    /**
     * The `type === 'agency'` guard: a NON-agency contract with a set wage and
     * no inlenersbeloningReferentie is NEVER evaluated by
     * nl-inlenersbeloning-onderbouwing-vereist.
     *
     * @return void
     */
    public function testNonAgencyContractIsNeverEvaluatedByInlenersbeloningRule(): void
    {
        foreach (['permanent', 'temporary', 'minijob'] as $type) {
            $contract = $this->contract(['type' => $type, 'hourlyWage' => 16.50, 'inlenersbeloningReferentie' => null]);

            $this->assertTrue(
                ($this->checks['nl-inlenersbeloning-onderbouwing-vereist'])($contract),
                sprintf('a %s contract must never be evaluated by the inlenersbeloning rule', $type)
            );

            $violations = RuleEngine::evaluate('EmploymentContract', $contract, ['jurisdiction' => 'NL']);
            $this->assertFalse(
                $this->hasViolation($violations, 'nl-inlenersbeloning-onderbouwing-vereist'),
                sprintf('a %s contract must raise no nl-inlenersbeloning-onderbouwing-vereist violation', $type)
            );
        }

    }//end testNonAgencyContractIsNeverEvaluatedByInlenersbeloningRule()


}//end class
