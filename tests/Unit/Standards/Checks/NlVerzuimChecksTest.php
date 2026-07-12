<?php

/**
 * Unit tests for the NL verzuim / Wet verbetering poortwachter checks.
 *
 * Pins the three leave-verzuim-mvp predicates on SickLeaveCase: milestone
 * derivation (Due = firstSickDay + rule-parameterised week offset, no null
 * Due on an open case), milestone overdue/approaching (evaluated against the
 * audit run date, the nl-loonaangifte-deadline-alert pattern), and the 70%
 * loondoorbetaling floor on open cases. Also pins the week-7 and week-41 seed
 * fixtures' exact derivation math (REQ-VWP-006).
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
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlVerzuimChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlVerzuimChecks.
 *
 * @spec openspec/changes/leave-verzuim-mvp/specs/verzuim-wvp/spec.md
 */
class NlVerzuimChecksTest extends TestCase
{


    /**
     * The registered SickLeaveCase predicates, keyed by rule id.
     *
     * @var array<string, callable>
     */
    private array $checks;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->checks = NlVerzuimChecks::checks()['SickLeaveCase'];

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


}//end class
