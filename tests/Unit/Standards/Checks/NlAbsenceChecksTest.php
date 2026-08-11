<?php

/**
 * Unit tests for the NL verzuim / Wet verbetering poortwachter checks.
 *
 * Pins the three leave-verzuim-mvp predicates on SickLeaveCase: milestone
 * derivation (Due = firstSickDay + rule-parameterised week offset, no null
 * Due on an open case), milestone overdue/approaching (evaluated against the
 * audit run date, the nl-loonaangifte-deadline-alert pattern), and the 70%
 * loondoorbetaling floor on open cases. Also pins the week-7 and week-41 seed
 * fixtures' exact derivation math (REQ-VWP-006), plus sick-pay-calc's
 * `nl-loondoorbetaling-floor` predicate on Payslip (REQ-SICK-006) — including
 * an end-to-end `RuleEngine::evaluate()` assertion proving the predicate is
 * actually auto-discovered and reachable, not just callable in isolation.
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
 * @spec openspec/changes/leave-verzuim-mvp/specs/verzuim-wvp/spec.md
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlAbsenceChecks;
use OCA\Hrmq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlAbsenceChecks.
 *
 * @spec openspec/changes/leave-verzuim-mvp/specs/verzuim-wvp/spec.md
 */
class NlAbsenceChecksTest extends TestCase
{


    /**
     * The registered SickLeaveCase predicates, keyed by rule id.
     *
     * @var array<string, callable>
     */
    private array $checks;

    /**
     * The registered Payslip predicates, keyed by rule id (sick-pay-calc).
     *
     * @var array<string, callable>
     */
    private array $payslipChecks;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->checks        = NlAbsenceChecks::checks()['SickLeaveCase'];
        $this->payslipChecks = NlAbsenceChecks::checks()['Payslip'];

    }//end setUp()


    /**
     * A minimal, fully-compliant open SickLeaveCase fixture; each test
     * overrides the fields it exercises.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function sickCase(array $overrides=[]): array
    {
        return array_merge(
            [
                'employeeId'                 => 'employee-devries',
                'firstSickDay'                => '2026-05-25',
                'status'                      => 'gemeld',
                'loondoorbetalingPercentage'  => 70,
                'probleemanalyseDue'          => '2026-07-06',
                'probleemanalyseDone'         => null,
                'planVanAanpakDue'            => '2026-07-20',
                'planVanAanpakDone'           => null,
                'uwv42WeekMeldingDue'         => '2027-03-15',
                'uwv42WeekMeldingDone'        => null,
                'eerstejaarsevaluatieDue'     => '2027-05-24',
                'eerstejaarsevaluatieDone'    => null,
            ],
            $overrides
        );

    }//end sickCase()


    /**
     * @return void
     */
    public function testDerivationSatisfiedForExactSeededWeek7Dates(): void
    {
        // Exact seed fixture: sickcase-devries-week7 (REQ-VWP-006).
        $case = $this->sickCase();
        $this->assertTrue(($this->checks['nl-wvp-milestone-derivation'])($case));

    }//end testDerivationSatisfiedForExactSeededWeek7Dates()


    /**
     * @return void
     */
    public function testDerivationSatisfiedForExactSeededWeek41Dates(): void
    {
        // Exact seed fixture: sickcase-bakker-longterm (REQ-VWP-006).
        $case = $this->sickCase(
            [
                'firstSickDay'              => '2025-09-29',
                'probleemanalyseDue'        => '2025-11-10',
                'probleemanalyseDone'       => '2025-11-06',
                'planVanAanpakDue'          => '2025-11-24',
                'planVanAanpakDone'         => '2025-11-20',
                'uwv42WeekMeldingDue'       => '2026-07-20',
                'uwv42WeekMeldingDone'      => null,
                'eerstejaarsevaluatieDue'   => '2026-09-28',
                'eerstejaarsevaluatieDone'  => null,
            ]
        );
        $this->assertTrue(($this->checks['nl-wvp-milestone-derivation'])($case));

    }//end testDerivationSatisfiedForExactSeededWeek41Dates()


    /**
     * @return void
     */
    public function testDerivationViolatedWhenDueIsAWeekLate(): void
    {
        // 6 weeks after 2026-05-25 is 2026-07-06, not 2026-07-13.
        $case = $this->sickCase(['probleemanalyseDue' => '2026-07-13']);
        $this->assertFalse(($this->checks['nl-wvp-milestone-derivation'])($case));

    }//end testDerivationViolatedWhenDueIsAWeekLate()


    /**
     * @return void
     */
    public function testDerivationViolatedWhenDueIsNullOnOpenCase(): void
    {
        $case = $this->sickCase(['probleemanalyseDue' => null]);
        $this->assertFalse(($this->checks['nl-wvp-milestone-derivation'])($case));

    }//end testDerivationViolatedWhenDueIsNullOnOpenCase()


    /**
     * @return void
     */
    public function testDerivationSatisfiedWhenAllDueNullOnHersteldCase(): void
    {
        // Exact seed fixture: sickcase-jansen-flu (recovered before any clock mattered).
        $case = $this->sickCase(
            [
                'firstSickDay'               => '2026-05-04',
                'status'                     => 'hersteld',
                'probleemanalyseDue'         => null,
                'planVanAanpakDue'           => null,
                'uwv42WeekMeldingDue'        => null,
                'eerstejaarsevaluatieDue'    => null,
            ]
        );
        $this->assertTrue(($this->checks['nl-wvp-milestone-derivation'])($case));

    }//end testDerivationSatisfiedWhenAllDueNullOnHersteldCase()


    /**
     * @return void
     */
    public function testOverdueViolatedWhenPastDueWithNoDone(): void
    {
        // Seed fixture sickcase-devries-week7: probleemanalyseDue is in the past
        // relative to any audit run date after 2026-07-06.
        $case = $this->sickCase(['probleemanalyseDue' => date('Y-m-d', strtotime('-30 days'))]);
        $this->assertFalse(($this->checks['nl-wvp-milestone-overdue'])($case));

    }//end testOverdueViolatedWhenPastDueWithNoDone()


    /**
     * @return void
     */
    public function testOverdueViolatedWhenDueWithinFourteenDays(): void
    {
        // Seed fixture sickcase-bakker-longterm: uwv42WeekMeldingDue within 14 days.
        $case = $this->sickCase(
            [
                'probleemanalyseDue'      => date('Y-m-d', strtotime('+60 days')),
                'planVanAanpakDue'        => date('Y-m-d', strtotime('+60 days')),
                'uwv42WeekMeldingDue'     => date('Y-m-d', strtotime('+7 days')),
                'eerstejaarsevaluatieDue' => date('Y-m-d', strtotime('+60 days')),
            ]
        );
        $this->assertFalse(($this->checks['nl-wvp-milestone-overdue'])($case));

    }//end testOverdueViolatedWhenDueWithinFourteenDays()


    /**
     * @return void
     */
    public function testOverdueSatisfiedWhenDoneEvenIfPast(): void
    {
        $case = $this->sickCase(
            [
                'probleemanalyseDue'  => date('Y-m-d', strtotime('-30 days')),
                'probleemanalyseDone' => date('Y-m-d', strtotime('-25 days')),
                'planVanAanpakDue'    => date('Y-m-d', strtotime('+60 days')),
                'uwv42WeekMeldingDue' => date('Y-m-d', strtotime('+60 days')),
                'eerstejaarsevaluatieDue' => date('Y-m-d', strtotime('+60 days')),
            ]
        );
        $this->assertTrue(($this->checks['nl-wvp-milestone-overdue'])($case));

    }//end testOverdueSatisfiedWhenDoneEvenIfPast()


    /**
     * @return void
     */
    public function testOverdueSatisfiedWhenAllDuesFarInFuture(): void
    {
        $case = $this->sickCase(
            [
                'probleemanalyseDue'      => date('Y-m-d', strtotime('+60 days')),
                'planVanAanpakDue'        => date('Y-m-d', strtotime('+60 days')),
                'uwv42WeekMeldingDue'     => date('Y-m-d', strtotime('+60 days')),
                'eerstejaarsevaluatieDue' => date('Y-m-d', strtotime('+60 days')),
            ]
        );
        $this->assertTrue(($this->checks['nl-wvp-milestone-overdue'])($case));

    }//end testOverdueSatisfiedWhenAllDuesFarInFuture()


    /**
     * @return void
     */
    public function testOverdueNeverFiresOnHersteldCase(): void
    {
        $case = $this->sickCase(['status' => 'hersteld', 'probleemanalyseDue' => '2020-01-01']);
        $this->assertTrue(($this->checks['nl-wvp-milestone-overdue'])($case));

    }//end testOverdueNeverFiresOnHersteldCase()


    /**
     * @return void
     */
    public function testLoondoorbetalingViolatedBelowSeventyPercent(): void
    {
        $case = $this->sickCase(['loondoorbetalingPercentage' => 65]);
        $this->assertFalse(($this->checks['nl-loondoorbetaling-minimum'])($case));

    }//end testLoondoorbetalingViolatedBelowSeventyPercent()


    /**
     * @return void
     */
    public function testLoondoorbetalingSatisfiedAtExactlySeventyPercent(): void
    {
        $case = $this->sickCase(['loondoorbetalingPercentage' => 70]);
        $this->assertTrue(($this->checks['nl-loondoorbetaling-minimum'])($case));

    }//end testLoondoorbetalingSatisfiedAtExactlySeventyPercent()


    /**
     * @return void
     */
    public function testLoondoorbetalingViolatedWhenMissing(): void
    {
        $case = $this->sickCase(['loondoorbetalingPercentage' => null]);
        $this->assertFalse(($this->checks['nl-loondoorbetaling-minimum'])($case));

    }//end testLoondoorbetalingViolatedWhenMissing()


    /**
     * @return void
     */
    public function testLoondoorbetalingNeverFiresOnHersteldCase(): void
    {
        $case = $this->sickCase(['status' => 'hersteld', 'loondoorbetalingPercentage' => 0]);
        $this->assertTrue(($this->checks['nl-loondoorbetaling-minimum'])($case));

    }//end testLoondoorbetalingNeverFiresOnHersteldCase()


    /**
     * @return void
     */
    public function testAllThreeRuleIdsAreRegistered(): void
    {
        $this->assertArrayHasKey('nl-wvp-milestone-derivation', $this->checks);
        $this->assertArrayHasKey('nl-wvp-milestone-overdue', $this->checks);
        $this->assertArrayHasKey('nl-loondoorbetaling-minimum', $this->checks);

    }//end testAllThreeRuleIdsAreRegistered()


    /**
     * A minimal sick-pay Payslip fixture; each test overrides the fields it
     * exercises (sick-pay-calc REQ-SICK-006).
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function sickPayslip(array $overrides=[]): array
    {
        return array_merge(
            [
                'sickLeaveCaseId'         => 'case-1',
                'doorbetaaldLoon'         => 2660.00,
                'sickPayReferenceWage'    => 3800.00,
                'sickPayPercentage'       => 70.0,
                'sickPayMinimumWageFloor' => 2294.40,
                'sickPayYearOne'          => true,
            ],
            $overrides
        );

    }//end sickPayslip()


    /**
     * The nl-loondoorbetaling-floor rule id is registered under Payslip
     * (sick-pay-calc REQ-SICK-006).
     *
     * @return void
     */
    public function testLoondoorbetalingFloorRuleIdIsRegisteredUnderPayslip(): void
    {
        $this->assertArrayHasKey('nl-loondoorbetaling-floor', $this->payslipChecks);

    }//end testLoondoorbetalingFloorRuleIdIsRegisteredUnderPayslip()


    /**
     * Vacuous on a normal payslip (null sickLeaveCaseId).
     *
     * @return void
     */
    public function testLoondoorbetalingFloorVacuousOnNormalPayslip(): void
    {
        $payslip = ['sickLeaveCaseId' => null, 'doorbetaaldLoon' => 100.00];
        $this->assertTrue(($this->payslipChecks['nl-loondoorbetaling-floor'])($payslip));

    }//end testLoondoorbetalingFloorVacuousOnNormalPayslip()


    /**
     * A correctly-floored sick-pay payslip (design.md D2 anchor) satisfies
     * the check.
     *
     * @return void
     */
    public function testLoondoorbetalingFloorSatisfiedAtTheAnchorFigures(): void
    {
        $payslip = $this->sickPayslip();
        $this->assertTrue(($this->payslipChecks['nl-loondoorbetaling-floor'])($payslip));

    }//end testLoondoorbetalingFloorSatisfiedAtTheAnchorFigures()


    /**
     * The year-1 floor-binding case (€3.000,00 -> €2.294,40) satisfies the
     * check exactly at the floor (design.md D2 cross-check row).
     *
     * @return void
     */
    public function testLoondoorbetalingFloorSatisfiedExactlyAtTheYearOneFloor(): void
    {
        $payslip = $this->sickPayslip(
            [
                'doorbetaaldLoon'         => 2294.40,
                'sickPayReferenceWage'    => 3000.00,
                'sickPayMinimumWageFloor' => 2294.40,
                'sickPayYearOne'          => true,
            ]
        );
        $this->assertTrue(($this->payslipChecks['nl-loondoorbetaling-floor'])($payslip));

    }//end testLoondoorbetalingFloorSatisfiedExactlyAtTheYearOneFloor()


    /**
     * A sub-floor payslip (REQ-SICK-006 scenario: doorbetaaldLoon hand-edited
     * to €2.000,00 while sickPayReferenceWage €3.800,00 / 70% floor
     * €2.660,00 still stands) violates the check.
     *
     * @return void
     */
    public function testLoondoorbetalingFloorViolatedWhenHandEditedBelowTheFloor(): void
    {
        $payslip = $this->sickPayslip(['doorbetaaldLoon' => 2000.00]);
        $this->assertFalse(($this->payslipChecks['nl-loondoorbetaling-floor'])($payslip));

    }//end testLoondoorbetalingFloorViolatedWhenHandEditedBelowTheFloor()


    /**
     * A year-2 payslip is checked against the bare 70% floor only (no WML
     * term) — a doorbetaaldLoon below the WML but at/above 70% still passes.
     *
     * @return void
     */
    public function testLoondoorbetalingFloorYearTwoDropsTheWmlTerm(): void
    {
        $payslip = $this->sickPayslip(
            [
                'doorbetaaldLoon'         => 2100.00,
                'sickPayReferenceWage'    => 3000.00,
                'sickPayMinimumWageFloor' => 2294.40,
                'sickPayYearOne'          => false,
            ]
        );
        $this->assertTrue(($this->payslipChecks['nl-loondoorbetaling-floor'])($payslip));

    }//end testLoondoorbetalingFloorYearTwoDropsTheWmlTerm()


    /**
     * End-to-end via RuleEngine::evaluate() — proves the predicate is
     * actually auto-discovered (RuleEngine::providers() globs
     * lib/Standards/Checks/*.php) and reachable under the real
     * nl-loondoorbetaling-floor catalogue rule, not merely callable in
     * isolation (no orphaned capability).
     *
     * @return void
     */
    public function testLoondoorbetalingFloorFiresThroughTheRealRuleEngine(): void
    {
        RuleEngine::reset();

        $violations = RuleEngine::evaluate('Payslip', $this->sickPayslip(['doorbetaaldLoon' => 2000.00]));

        $ruleIds = array_map(static fn($v) => $v->ruleId, $violations);
        $this->assertContains('nl-loondoorbetaling-floor', $ruleIds, 'The predicate must fire through the real, auto-discovered RuleEngine.');

        foreach ($violations as $violation) {
            if ($violation->ruleId === 'nl-loondoorbetaling-floor') {
                $this->assertSame('mandatory', $violation->severity);
            }
        }

        $compliant = RuleEngine::evaluate('Payslip', $this->sickPayslip());
        $compliantRuleIds = array_map(static fn($v) => $v->ruleId, $compliant);
        $this->assertNotContains('nl-loondoorbetaling-floor', $compliantRuleIds, 'A correctly-floored payslip must report zero violations.');

    }//end testLoondoorbetalingFloorFiresThroughTheRealRuleEngine()


}//end class
