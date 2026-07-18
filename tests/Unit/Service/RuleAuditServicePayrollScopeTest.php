<?php

/**
 * Unit tests for RuleAuditService::auditPayrollRunScope().
 *
 * Pins the `occ hrmq:payroll:verify` semantics (payroll-core-engine design.md
 * D7 / REQ-PCE-006) end-to-end through a fake ObjectService double: a freshly
 * generated engine run (the design.md D2 anchor figures) carries ZERO
 * mandatory violations (recommended advisories like the not-yet-generated
 * loonstrook PDF may remain — they do not block), a payslip whose `nettoPay`
 * was tampered raises the mandatory `nl-engine-output-consistency` violation,
 * a run stamped with a non-existent table version raises
 * `nl-engine-table-version`, and the scope is EXACTLY the period's run(s) +
 * their payslips (other periods / hand-entered payslips stay out).
 * `enginePayslip()`'s fixture also carries a valid `engineInputSnapshot`
 * (audit-trail-payroll REQ-AUDP-005, fixing hrmq#98) so the "zero mandatory
 * violations" baseline stays genuinely clean under the new
 * `nl-engine-provenance-complete` rule, not just the pre-existing two.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-006
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\RuleAuditService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the run-scoped payroll corpus audit.
 *
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-006
 */
class RuleAuditServicePayrollScopeTest extends TestCase
{


    /**
     * Build a RuleAuditService backed by a fake ObjectService that returns
     * $rowsBySchema[$schema] for any findAll() call.
     *
     * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows keyed by schema name.
     *
     * @return RuleAuditService
     */
    private function serviceWithRows(array $rowsBySchema): RuleAuditService
    {
        $objectService = new class ($rowsBySchema) {

            /**
             * @var string
             */
            private string $schema = '';

            /**
             * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows keyed by schema name.
             */
            public function __construct(private readonly array $rowsBySchema)
            {

            }//end __construct()


            /**
             * @param string $register Register slug (unused by the fake).
             *
             * @return self
             */
            public function setRegister(string $register): self
            {
                return $this;

            }//end setRegister()


            /**
             * @param string $schema Schema name.
             *
             * @return self
             */
            public function setSchema(string $schema): self
            {
                $this->schema = $schema;
                return $this;

            }//end setSchema()


            /**
             * @param array<string, mixed> $options Query options (unused by the fake).
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $options=[]): array
            {
                return $this->rowsBySchema[$this->schema] ?? [];

            }//end findAll()


        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('hrmq');

        $logger = $this->createMock(LoggerInterface::class);

        return new RuleAuditService($container, $appConfig, $logger);

    }//end serviceWithRows()


    /**
     * A freshly generated engine PayrollRun (the design.md D2 anchor totals).
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function engineRun(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'                   => 'run-1',
                'period'               => '2026-02',
                'administrationId'     => 'ADM-001',
                'jurisdiction'         => 'NL',
                'status'               => 'draft',
                'totalGross'           => 3800.00,
                'totalLoonheffing'     => 718.83,
                'totalEmployerCharges' => 650.94,
                'totalWithholdings'    => 718.83,
                'totalNet'             => 3081.17,
                'engineVersion'        => 'nl-2026',
                'calculatedAt'         => '2026-07-14T10:00:00Z',
            ],
            $overrides
        );

    }//end engineRun()


    /**
     * The engine payslip PayrollRunService stamps for the anchor employee.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function enginePayslip(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'                       => 'ps-1',
                'employeeId'               => 'emp-1',
                'userId'                   => 'sanne',
                'payrollRunId'             => 'run-1',
                'period'                   => '2026-02',
                'jurisdiction'             => 'NL',
                'currency'                 => 'EUR',
                'grossPay'                 => 3800.00,
                'loonheffing'              => 718.83,
                'arbeidskorting'           => 473.75,
                'volksverzekeringen'       => 470.86,
                'werknemersverzekeringen'  => 419.14,
                'zvw'                      => 231.80,
                'zvwMode'                  => 'werkgeversheffing',
                'zvwRate'                  => 6.10,
                'anoniementariefApplied'   => false,
                'appliedTaxRate'           => 18.92,
                'nettoPay'                 => 3081.17,
                'vakantiegeldReserved'     => 304.00,
                'vakantiegeldRate'         => 8.0,
                'wkrUsed'                  => 0.0,
                'wkrVrijeRuimteRemaining'  => 0.0,
                'wkrExcess'                => 0.0,
                'pensionContribution'      => 0.0,
                'statementProvided'        => true,
                'showsGrossWage'           => true,
                'showsDeductionBasis'      => true,
                'showsMinimumWage'         => true,
                'showsEmployerEmployeeIds' => true,
                'hoursWorked'              => 156.0,
                // audit-trail-payroll REQ-AUDP-001/REQ-AUDP-005 (fixing
                // hrmq#98): the resolved CalculationInput PayrollRunService
                // stamps on every engine-produced payslip going forward.
                'engineInputSnapshot'      => '{"aofTariff":"laag","awfTariff":"low","dateOfBirth":"1990-04-12","grossMonthlySalaryCents":380000,"jurisdiction":"NL","loonheffingskortingToegepast":true,"period":"2026-02","taxTableColor":"wit","verzekeringsplichtig":true,"whkPercentage":1.52}',
            ],
            $overrides
        );

    }//end enginePayslip()


    /**
     * @return void
     */
    public function testFreshEngineRunHasZeroMandatoryViolations(): void
    {
        $service = $this->serviceWithRows(
            [
                'PayrollRun' => [$this->engineRun()],
                'Payslip'    => [$this->enginePayslip()],
            ]
        );

        $report = $service->auditPayrollRunScope('2026-02', null, ['jurisdiction' => 'NL']);

        $this->assertSame(1, $report['runsChecked']);
        $this->assertSame(1, $report['payslipsChecked']);
        $this->assertSame(0, $report['mandatoryViolations'], 'A freshly generated engine run must verify clean on every mandatory rule.');

        foreach ($report['violations'] as $violation) {
            $this->assertNotSame('mandatory', $violation['severity'], $violation['ruleId'].' must not fire on a fresh engine run.');
        }

    }//end testFreshEngineRunHasZeroMandatoryViolations()


    /**
     * @return void
     */
    public function testTamperedNettoPayRaisesTheMandatoryConsistencyViolation(): void
    {
        $service = $this->serviceWithRows(
            [
                'PayrollRun' => [$this->engineRun()],
                'Payslip'    => [$this->enginePayslip(['nettoPay' => 3000.00])],
            ]
        );

        $report = $service->auditPayrollRunScope('2026-02', null, ['jurisdiction' => 'NL']);

        $this->assertGreaterThan(0, $report['mandatoryViolations']);

        $ruleIds = array_column($report['violations'], 'ruleId');
        $this->assertContains('nl-engine-output-consistency', $ruleIds);

    }//end testTamperedNettoPayRaisesTheMandatoryConsistencyViolation()


    /**
     * @return void
     */
    public function testNonExistentTableVersionRaisesTheMandatoryTableVersionViolation(): void
    {
        $service = $this->serviceWithRows(
            [
                'PayrollRun' => [$this->engineRun(['engineVersion' => 'nl-2031'])],
                'Payslip'    => [],
            ]
        );

        $report = $service->auditPayrollRunScope('2026-02', null, ['jurisdiction' => 'NL']);

        $this->assertGreaterThan(0, $report['mandatoryViolations']);
        $this->assertContains('nl-engine-table-version', array_column($report['violations'], 'ruleId'));

    }//end testNonExistentTableVersionRaisesTheMandatoryTableVersionViolation()


    /**
     * @return void
     */
    public function testScopeIsExactlyThePeriodsRunsAndTheirPayslips(): void
    {
        $service = $this->serviceWithRows(
            [
                'PayrollRun' => [
                    $this->engineRun(),
                    $this->engineRun(['id' => 'run-other', 'period' => '2026-01', 'engineVersion' => 'nl-2031']),
                ],
                'Payslip'    => [
                    $this->enginePayslip(),
                    // Another run's payslip and a hand-entered one — both out of scope.
                    $this->enginePayslip(['id' => 'ps-other', 'payrollRunId' => 'run-other', 'period' => '2026-01', 'nettoPay' => 1.00]),
                    $this->enginePayslip(['id' => 'ps-hand', 'payrollRunId' => null, 'nettoPay' => 2.00]),
                ],
            ]
        );

        $report = $service->auditPayrollRunScope('2026-02', 'ADM-001', ['jurisdiction' => 'NL']);

        $this->assertSame(1, $report['runsChecked'], 'Only the requested period/administration is in scope.');
        $this->assertSame(1, $report['payslipsChecked'], 'Only the scoped run\'s payslips are audited.');
        $this->assertSame(0, $report['mandatoryViolations'], 'The out-of-scope broken run/payslips must not leak into the report.');

    }//end testScopeIsExactlyThePeriodsRunsAndTheirPayslips()


}//end class
