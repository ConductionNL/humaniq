<?php

/**
 * Unit tests for the NL pension filing (UPA) checks.
 *
 * Pins the three pension-filing-upa-mvp predicates: reference integrity
 * (nl-upa-payrollrun-approved, cross-object via the context's PayrollRun
 * index), monthly completeness (nl-upa-monthly-completeness, on PayrollRun),
 * and deadline alerting (nl-upa-deadline-alert, on PensionFiling).
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
 * @spec openspec/changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlPensionFilingChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlPensionFilingChecks.
 *
 * @spec openspec/changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md
 */
class NlPensionFilingChecksTest extends TestCase
{


    /**
     * The registered PensionFiling predicates, keyed by rule id.
     *
     * @var array<string, callable>
     */
    private array $filingChecks;

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
        $checks             = NlPensionFilingChecks::checks();
        $this->filingChecks = $checks['PensionFiling'];
        $this->runChecks    = $checks['PayrollRun'];

    }//end setUp()


    /**
     * A minimal PensionFiling fixture; each test overrides the fields it exercises.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function filing(array $overrides=[]): array
    {
        return array_merge(
            [
                'payrollRunId' => 'run-2026-05',
                'period'       => '2026-05',
                'fund'         => 'abp',
                'deadline'     => '2026-06-30',
                'status'       => 'concept',
            ],
            $overrides
        );

    }//end filing()


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
                'jurisdiction' => 'NL',
                'period'       => '2026-05',
                'status'       => 'approved',
            ],
            $overrides
        );

    }//end payrollRun()


    /**
     * A minimal `context['related']` fixture matching RuleAuditService's
     * pre-pass shape.
     *
     * @param array<string, array<string, mixed>> $runsById     PayrollRun index by id.
     * @param string[]                            $filedPeriods PensionFiling period set.
     *
     * @return array<string, mixed>
     */
    private function context(array $runsById=[], array $filedPeriods=[]): array
    {
        return [
            'related' => [
                'PayrollRun'    => ['byId' => $runsById],
                'PensionFiling' => ['filedPeriods' => $filedPeriods],
            ],
        ];

    }//end context()


    /**
     * @return void
     */
    public function testPayrollRunApprovedSatisfiedWhenRunApproved(): void
    {
        $filing  = $this->filing(['payrollRunId' => 'run-1']);
        $context = $this->context(['run-1' => ['id' => 'run-1', 'period' => '2026-05', 'status' => 'approved']]);

        $this->assertTrue(($this->filingChecks['nl-upa-payrollrun-approved'])($filing, $context));

    }//end testPayrollRunApprovedSatisfiedWhenRunApproved()


    /**
     * @return void
     */
    public function testPayrollRunApprovedSatisfiedWhenRunPostedOrPaid(): void
    {
        $context = $this->context(
            [
                'run-1' => ['id' => 'run-1', 'period' => '2026-05', 'status' => 'posted'],
                'run-2' => ['id' => 'run-2', 'period' => '2026-06', 'status' => 'paid'],
            ]
        );

        $this->assertTrue(($this->filingChecks['nl-upa-payrollrun-approved'])($this->filing(['payrollRunId' => 'run-1']), $context));
        $this->assertTrue(($this->filingChecks['nl-upa-payrollrun-approved'])($this->filing(['payrollRunId' => 'run-2']), $context));

    }//end testPayrollRunApprovedSatisfiedWhenRunPostedOrPaid()


    /**
     * @return void
     */
    public function testPayrollRunApprovedViolatedWhenRunIsDraft(): void
    {
        $filing  = $this->filing(['payrollRunId' => 'run-1']);
        $context = $this->context(['run-1' => ['id' => 'run-1', 'period' => '2026-05', 'status' => 'draft']]);

        $this->assertFalse(($this->filingChecks['nl-upa-payrollrun-approved'])($filing, $context));

    }//end testPayrollRunApprovedViolatedWhenRunIsDraft()


    /**
     * @return void
     */
    public function testPayrollRunApprovedViolatedWhenReferenceDangling(): void
    {
        $filing  = $this->filing(['payrollRunId' => 'no-such-run']);
        $context = $this->context(['run-1' => ['id' => 'run-1', 'period' => '2026-05', 'status' => 'approved']]);

        $this->assertFalse(($this->filingChecks['nl-upa-payrollrun-approved'])($filing, $context));

    }//end testPayrollRunApprovedViolatedWhenReferenceDangling()


    /**
     * @return void
     */
    public function testPayrollRunApprovedViolatedWhenReferenceEmpty(): void
    {
        $filing = $this->filing(['payrollRunId' => '']);

        $this->assertFalse(($this->filingChecks['nl-upa-payrollrun-approved'])($filing, $this->context()));

    }//end testPayrollRunApprovedViolatedWhenReferenceEmpty()


    /**
     * @return void
     */
    public function testMonthlyCompletenessSatisfiedWhenPeriodHasFiling(): void
    {
        $run     = $this->payrollRun(['period' => '2026-05', 'status' => 'approved']);
        $context = $this->context([], ['2026-05']);

        $this->assertTrue(($this->runChecks['nl-upa-monthly-completeness'])($run, $context));

    }//end testMonthlyCompletenessSatisfiedWhenPeriodHasFiling()


    /**
     * @return void
     */
    public function testMonthlyCompletenessViolatedWhenApprovedPeriodHasNoFiling(): void
    {
        $run     = $this->payrollRun(['period' => '2026-04', 'status' => 'approved']);
        $context = $this->context([], ['2026-05']);

        $this->assertFalse(($this->runChecks['nl-upa-monthly-completeness'])($run, $context));

    }//end testMonthlyCompletenessViolatedWhenApprovedPeriodHasNoFiling()


    /**
     * @return void
     */
    public function testMonthlyCompletenessNotYetInScopeForDraftRun(): void
    {
        $run     = $this->payrollRun(['period' => '2026-04', 'status' => 'draft']);
        $context = $this->context([], []);

        $this->assertTrue(($this->runChecks['nl-upa-monthly-completeness'])($run, $context));

    }//end testMonthlyCompletenessNotYetInScopeForDraftRun()


    /**
     * @return void
     */
    public function testMonthlyCompletenessOutOfScopeForNonNlRun(): void
    {
        $run     = $this->payrollRun(['jurisdiction' => 'DE', 'period' => '2026-04', 'status' => 'approved']);
        $context = $this->context([], []);

        $this->assertTrue(($this->runChecks['nl-upa-monthly-completeness'])($run, $context));

    }//end testMonthlyCompletenessOutOfScopeForNonNlRun()


    /**
     * @return void
     */
    public function testDeadlineAlertOverdueUnsentIsAViolation(): void
    {
        $filing = $this->filing(['status' => 'bevestigd', 'deadline' => '2020-01-01']);

        $this->assertFalse(($this->filingChecks['nl-upa-deadline-alert'])($filing, []));

    }//end testDeadlineAlertOverdueUnsentIsAViolation()


    /**
     * @return void
     */
    public function testDeadlineAlertApproachingWithin14DaysIsAViolation(): void
    {
        $filing = $this->filing(['status' => 'concept', 'deadline' => date('Y-m-d', strtotime('+7 days'))]);

        $this->assertFalse(($this->filingChecks['nl-upa-deadline-alert'])($filing, []));

    }//end testDeadlineAlertApproachingWithin14DaysIsAViolation()


    /**
     * @return void
     */
    public function testDeadlineAlertFarFutureIsNotAViolation(): void
    {
        $filing = $this->filing(['status' => 'concept', 'deadline' => date('Y-m-d', strtotime('+30 days'))]);

        $this->assertTrue(($this->filingChecks['nl-upa-deadline-alert'])($filing, []));

    }//end testDeadlineAlertFarFutureIsNotAViolation()


    /**
     * @return void
     */
    public function testDeadlineAlertNeverFiresOnceSent(): void
    {
        $filing = $this->filing(['status' => 'verzonden', 'deadline' => '2020-01-01']);

        $this->assertTrue(($this->filingChecks['nl-upa-deadline-alert'])($filing, []));

    }//end testDeadlineAlertNeverFiresOnceSent()


}//end class
