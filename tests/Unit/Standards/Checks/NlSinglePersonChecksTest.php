<?php

/**
 * Unit tests for the DGA-single-person headcount check (NlSinglePersonChecks).
 *
 * Pins the `nl-single-person-mode-employee-count` predicate
 * (single-person-modes design.md D4, spec.md REQ-SPM-005): VACUOUS for a
 * standard/eenmanszaak_no_payroll administratie regardless of headcount;
 * satisfied for a dga_single_person administratie only when EXACTLY one
 * active Employee (endDate empty) is scoped to its administrationId AND that
 * one Employee has isDga true; violated at 0, 2+, or 1-but-not-DGA. The suite
 * closes with a REAL `RuleEngine::evaluate()` integration test (catalogue +
 * auto-discovered CheckProviders) proving the rule is genuinely reachable via
 * `occ hrmq:rules:audit`, at recommended severity, and never an orphaned
 * capability.
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
 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlSinglePersonChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlSinglePersonChecks (raw predicate + through the REAL RuleEngine).
 *
 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-005
 */
class NlSinglePersonChecksTest extends TestCase
{

    /**
     * The registered predicates.
     *
     * @var array<string, array<string, callable>>
     */
    private array $checks;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        RuleEngine::reset();
        $this->checks = NlSinglePersonChecks::checks();

    }//end setUp()


    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RuleEngine::reset();

    }//end tearDown()


    /**
     * Build an evaluation context carrying a `payroll.employeesById` index
     * from the given Employee rows (keyed by their `id`) — the exact shape
     * `RuleAuditService::buildPayrollContext()` populates.
     *
     * @param array<int, array<string, mixed>> $employees Employee rows.
     *
     * @return array<string, mixed>
     */
    private function context(array $employees): array
    {
        $byId = [];
        foreach ($employees as $employee) {
            $byId[(string) ($employee['id'] ?? uniqid('emp-', true))] = $employee;
        }

        return ['payroll' => ['employeesById' => $byId]];

    }//end context()


    /**
     * REQ-SPM-005 "A standard-mode administratie is never evaluated": vacuous
     * regardless of how many employees are scoped to it.
     *
     * @return void
     */
    public function testStandardModeIsVacuousRegardlessOfHeadcount(): void
    {
        $check = $this->checks['Administration']['nl-single-person-mode-employee-count'];

        $context = $this->context(
            [
                ['id' => 'e1', 'administrationId' => 'ADM-001', 'isDga' => false],
                ['id' => 'e2', 'administrationId' => 'ADM-001', 'isDga' => false],
                ['id' => 'e3', 'administrationId' => 'ADM-001', 'isDga' => false],
            ]
        );

        $this->assertTrue($check(['administrationId' => 'ADM-001', 'mode' => 'standard'], $context));
        // Absent mode resolves to the standard default — also vacuous.
        $this->assertTrue($check(['administrationId' => 'ADM-001'], $context));

    }//end testStandardModeIsVacuousRegardlessOfHeadcount()


    /**
     * An eenmanszaak_no_payroll administratie is also out of scope (the mode
     * carries no headcount expectation) — vacuous even with zero employees.
     *
     * @return void
     */
    public function testEenmanszaakModeIsVacuous(): void
    {
        $check = $this->checks['Administration']['nl-single-person-mode-employee-count'];

        $this->assertTrue($check(['administrationId' => 'ADM-005', 'mode' => 'eenmanszaak_no_payroll'], $this->context([])));

    }//end testEenmanszaakModeIsVacuous()


    /**
     * REQ-SPM-005 "Exactly one DGA employee passes": a dga_single_person
     * administratie with exactly one active DGA scoped to it is satisfied.
     *
     * @return void
     */
    public function testExactlyOneActiveDgaPasses(): void
    {
        $check = $this->checks['Administration']['nl-single-person-mode-employee-count'];

        $context = $this->context(
            [
                ['id' => 'dga', 'administrationId' => 'ADM-004', 'isDga' => true],
                // A different administratie's employees never contribute.
                ['id' => 'other', 'administrationId' => 'ADM-001', 'isDga' => false],
            ]
        );

        $this->assertTrue($check(['administrationId' => 'ADM-004', 'mode' => 'dga_single_person'], $context));

    }//end testExactlyOneActiveDgaPasses()


    /**
     * REQ-SPM-005 "A second employee is flagged": two active employees scoped
     * to a dga_single_person administratie violate.
     *
     * @return void
     */
    public function testTwoEmployeesAreFlagged(): void
    {
        $check = $this->checks['Administration']['nl-single-person-mode-employee-count'];

        $context = $this->context(
            [
                ['id' => 'dga', 'administrationId' => 'ADM-004', 'isDga' => true],
                ['id' => 'second', 'administrationId' => 'ADM-004', 'isDga' => false],
            ]
        );

        $this->assertFalse($check(['administrationId' => 'ADM-004', 'mode' => 'dga_single_person'], $context));

    }//end testTwoEmployeesAreFlagged()


    /**
     * Zero employees scoped to a dga_single_person administratie violate.
     *
     * @return void
     */
    public function testZeroEmployeesIsFlagged(): void
    {
        $check = $this->checks['Administration']['nl-single-person-mode-employee-count'];

        $this->assertFalse($check(['administrationId' => 'ADM-004', 'mode' => 'dga_single_person'], $this->context([])));

    }//end testZeroEmployeesIsFlagged()


    /**
     * Exactly one employee, but NOT a DGA, violates (the single active person
     * must be the DGA).
     *
     * @return void
     */
    public function testOneNonDgaEmployeeIsFlagged(): void
    {
        $check = $this->checks['Administration']['nl-single-person-mode-employee-count'];

        $context = $this->context([['id' => 'e1', 'administrationId' => 'ADM-004', 'isDga' => false]]);

        $this->assertFalse($check(['administrationId' => 'ADM-004', 'mode' => 'dga_single_person'], $context));

    }//end testOneNonDgaEmployeeIsFlagged()


    /**
     * An ended (offboarded) employment does NOT count toward the active
     * headcount: one active DGA + one ended non-DGA is still satisfied.
     *
     * @return void
     */
    public function testEndedEmploymentIsExcludedFromHeadcount(): void
    {
        $check = $this->checks['Administration']['nl-single-person-mode-employee-count'];

        $context = $this->context(
            [
                ['id' => 'dga', 'administrationId' => 'ADM-004', 'isDga' => true],
                ['id' => 'left', 'administrationId' => 'ADM-004', 'isDga' => false, 'endDate' => '2025-12-31'],
            ]
        );

        $this->assertTrue($check(['administrationId' => 'ADM-004', 'mode' => 'dga_single_person'], $context));

    }//end testEndedEmploymentIsExcludedFromHeadcount()


    /**
     * REQ-SPM-005 — the drift case driven through the REAL
     * `RuleEngine::evaluate()` (catalogue + auto-discovered CheckProviders),
     * proving `nl-single-person-mode-employee-count` is genuinely reachable
     * via `occ hrmq:rules:audit` at recommended severity — not an orphaned
     * capability.
     *
     * @return void
     */
    public function testRealRuleEngineFiresAtRecommendedSeverityForDrift(): void
    {
        $context = $this->context(
            [
                ['id' => 'dga', 'administrationId' => 'ADM-004', 'isDga' => true],
                ['id' => 'second', 'administrationId' => 'ADM-004', 'isDga' => false],
            ]
        );

        $violations = RuleEngine::evaluate('Administration', ['administrationId' => 'ADM-004', 'mode' => 'dga_single_person'], $context);

        $ruleIds = array_map(static fn($v) => $v->ruleId, $violations);
        $this->assertContains('nl-single-person-mode-employee-count', $ruleIds, 'The real RuleEngine must fire the headcount rule for a drifted dga_single_person administratie.');

        $violation = $violations[array_search('nl-single-person-mode-employee-count', $ruleIds, true)];
        $this->assertSame('recommended', $violation->severity, 'The headcount rule must be recommended severity — a data-quality lamp, never a block.');

        $this->assertContains('nl-single-person-mode-employee-count', RuleEngine::checkedRuleIds());
        $this->assertContains('Administration', RuleEngine::supportedTypes());

    }//end testRealRuleEngineFiresAtRecommendedSeverityForDrift()


    /**
     * The mirror-image REAL RuleEngine check: a standard administratie with
     * five employees fires NO headcount violation (vacuous — out of scope).
     *
     * @return void
     */
    public function testRealRuleEngineIsSilentForStandardMode(): void
    {
        $context = $this->context(
            [
                ['id' => 'e1', 'administrationId' => 'ADM-001', 'isDga' => false],
                ['id' => 'e2', 'administrationId' => 'ADM-001', 'isDga' => false],
                ['id' => 'e3', 'administrationId' => 'ADM-001', 'isDga' => false],
                ['id' => 'e4', 'administrationId' => 'ADM-001', 'isDga' => false],
                ['id' => 'e5', 'administrationId' => 'ADM-001', 'isDga' => false],
            ]
        );

        $violations = RuleEngine::evaluate('Administration', ['administrationId' => 'ADM-001', 'mode' => 'standard'], $context);

        $ruleIds = array_map(static fn($v) => $v->ruleId, $violations);
        $this->assertNotContains('nl-single-person-mode-employee-count', $ruleIds);

    }//end testRealRuleEngineIsSilentForStandardMode()


}//end class
