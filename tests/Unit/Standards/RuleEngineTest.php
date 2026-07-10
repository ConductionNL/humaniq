<?php

/**
 * RuleEngine unit tests
 *
 * Exercises the executable layer over RuleCatalogue: jurisdiction scoping (an
 * NL-only rule does not fire for a US object; an EU-wide rule fires for every EU
 * member state; a `global` rule fires everywhere), the `hasMandatory()` gate, and
 * that `checkedRuleIds()` only returns ids that also appear as machine-checkable
 * catalogue rules.
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
use OCA\Hrmq\Standards\RuleEngine;
use OCA\Hrmq\Standards\Violation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hrmq\Standards\RuleEngine
 */
final class RuleEngineTest extends TestCase
{


    /**
     * Reset memoised engine + catalogue state before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        RuleEngine::reset();
        RuleCatalogue::reset();

    }//end setUp()


    /**
     * Collect the rule ids from a set of violations.
     *
     * @param array<int, Violation> $violations Violations to inspect.
     *
     * @return array<int, string>
     */
    private function ruleIds(array $violations): array
    {
        return array_map(static fn(Violation $v): string => $v->ruleId, $violations);

    }//end ruleIds()


    /**
     * A late NL loonaangifte filing violates the NL-only `nl-loonaangifte-termijn`
     * rule under an NL audit, but NOT under a US audit (the NL rule does not apply
     * outside NL).
     *
     * @return void
     */
    public function testNlOnlyRuleDoesNotFireForUsContext(): void
    {
        // Satisfies tijdvak + retention, but submitted AFTER the deadline.
        $lateFiling = [
            'filingType'          => 'loonaangifte',
            'tijdvak'             => 'maand',
            'electronicallyFiled' => true,
            'period'              => '2026-01',
            'retainedUntil'       => '2033-12-31',
            'deadline'            => '2026-02-27',
            'submittedDate'       => '2026-03-15',
        ];

        $nl = $this->ruleIds(RuleEngine::evaluate('LoonaangifteFiling', $lateFiling, ['jurisdiction' => 'NL']));
        $this->assertContains('nl-loonaangifte-termijn', $nl, 'The NL deadline rule must fire under an NL audit.');

        $us = $this->ruleIds(RuleEngine::evaluate('LoonaangifteFiling', $lateFiling, ['jurisdiction' => 'US']));
        $this->assertNotContains('nl-loonaangifte-termijn', $us, 'The NL-only rule must not fire under a US audit.');

    }//end testNlOnlyRuleDoesNotFireForUsContext()


    /**
     * The EU-wide `eu-a1-posted-worker` rule fires for a posted worker without a
     * valid A1 certificate under EVERY EU member-state audit context.
     *
     * @return void
     */
    public function testEuRuleFiresForEveryEuMemberState(): void
    {
        $postedNoA1 = [
            'postedWorker' => true,
            'startDate'    => '2026-01-01',
            // Deliberately missing a1CertificateNumber / a1ValidUntil.
        ];

        foreach (RuleEngine::EU_MEMBER_STATES as $state) {
            $ids = $this->ruleIds(RuleEngine::evaluate('Employee', $postedNoA1, ['jurisdiction' => $state]));
            $this->assertContains(
                'eu-a1-posted-worker',
                $ids,
                'The EU A1 rule must fire for EU member state '.$state.'.'
            );
        }

    }//end testEuRuleFiresForEveryEuMemberState()


    /**
     * The EU-wide rule does NOT fire for a non-EU jurisdiction (US).
     *
     * @return void
     */
    public function testEuRuleDoesNotFireForNonEuContext(): void
    {
        $postedNoA1 = ['postedWorker' => true, 'startDate' => '2026-01-01'];
        $ids        = $this->ruleIds(RuleEngine::evaluate('Employee', $postedNoA1, ['jurisdiction' => 'US']));
        $this->assertNotContains('eu-a1-posted-worker', $ids, 'The EU A1 rule must not fire under a US audit.');

    }//end testEuRuleDoesNotFireForNonEuContext()


    /**
     * A `global` rule fires under every audit jurisdiction.
     *
     * @return void
     */
    public function testGlobalRuleFiresEverywhere(): void
    {
        // An empty PayrollRun fails the global GL-reconciliation control.
        foreach (['NL', 'US', 'DE', 'FR'] as $jurisdiction) {
            $ids = $this->ruleIds(RuleEngine::evaluate('PayrollRun', [], ['jurisdiction' => $jurisdiction]));
            $this->assertContains(
                'xc-payroll-gl-reconciliation',
                $ids,
                'The global GL-reconciliation rule must fire under a '.$jurisdiction.' audit.'
            );
        }

    }//end testGlobalRuleFiresEverywhere()


    /**
     * hasMandatory() is true iff at least one violation carries the `mandatory`
     * severity.
     *
     * @return void
     */
    public function testHasMandatoryReflectsMandatorySeverity(): void
    {
        $this->assertFalse(RuleEngine::hasMandatory([]), 'No violations => not mandatory.');

        $conditional = [new Violation('r1', 'conditional', 's', ''), new Violation('r2', 'recommended', 's', '')];
        $this->assertFalse(RuleEngine::hasMandatory($conditional), 'Only conditional/recommended => not mandatory.');

        $withMandatory = [new Violation('r1', 'recommended', 's', ''), new Violation('r2', 'mandatory', 's', '')];
        $this->assertTrue(RuleEngine::hasMandatory($withMandatory), 'A mandatory violation => mandatory.');

    }//end testHasMandatoryReflectsMandatorySeverity()


    /**
     * A late NL filing produces a mandatory violation, so hasMandatory() on the
     * engine's own output is true.
     *
     * @return void
     */
    public function testHasMandatoryTrueForLateNlFiling(): void
    {
        $lateFiling = [
            'filingType'          => 'loonaangifte',
            'tijdvak'             => 'maand',
            'electronicallyFiled' => true,
            'period'              => '2026-01',
            'retainedUntil'       => '2033-12-31',
            'deadline'            => '2026-02-27',
            'submittedDate'       => '2026-03-15',
        ];

        $violations = RuleEngine::evaluate('LoonaangifteFiling', $lateFiling, ['jurisdiction' => 'NL']);
        $this->assertTrue(RuleEngine::hasMandatory($violations));

    }//end testHasMandatoryTrueForLateNlFiling()


    /**
     * An empty PayrollRun yields only recommended global violations, so
     * hasMandatory() is false.
     *
     * @return void
     */
    public function testHasMandatoryFalseForRecommendedOnlyViolations(): void
    {
        $violations = RuleEngine::evaluate('PayrollRun', [], ['jurisdiction' => 'NL']);
        $this->assertNotEmpty($violations, 'An empty PayrollRun should still trip the recommended GL controls.');
        $this->assertFalse(RuleEngine::hasMandatory($violations));

    }//end testHasMandatoryFalseForRecommendedOnlyViolations()


    /**
     * Every id returned by checkedRuleIds() is a real machine-checkable catalogue
     * rule id — the engine cannot enforce a rule that is not machine-checkable.
     *
     * @return void
     */
    public function testCheckedRuleIdsAreMachineCheckableCatalogueRules(): void
    {
        $machineIds = array_map(static fn(array $r): string => (string) $r['id'], RuleCatalogue::machineCheckable());
        $checked    = RuleEngine::checkedRuleIds();

        $this->assertNotEmpty($checked);
        foreach ($checked as $ruleId) {
            $this->assertContains(
                $ruleId,
                $machineIds,
                'Enforced rule '.$ruleId.' must be a machine-checkable catalogue rule.'
            );
        }

    }//end testCheckedRuleIdsAreMachineCheckableCatalogueRules()


    /**
     * supportedTypes() covers the compliance-checked object types.
     *
     * @return void
     */
    public function testSupportedTypesCoverKnownObjectTypes(): void
    {
        $types = RuleEngine::supportedTypes();
        foreach (['Employee', 'EmploymentContract', 'Payslip', 'PayrollRun', 'LoonaangifteFiling'] as $expected) {
            $this->assertContains($expected, $types, $expected.' must be a supported object type.');
        }

    }//end testSupportedTypesCoverKnownObjectTypes()


}//end class
