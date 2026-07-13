<?php

/**
 * Unit tests for RuleAuditService's cross-type `related` pre-pass.
 *
 * pension-filing-upa-mvp extends RuleAuditService::audit() with a small
 * pre-pass that populates `$context['related']` before the per-type
 * evaluation loop, so the PensionFiling/PayrollRun predicates in
 * NlPensionFilingChecks can see each other's sibling rows. This test drives
 * `audit()` end-to-end (through a fake ObjectService double, since the real
 * OpenRegister ObjectService is a sibling-app dependency not available in
 * this standalone suite) with the exact pension-filing-upa-mvp seed fixture
 * shapes, and asserts the resulting report matches what `occ
 * hrmq:rules:audit` would show against the seeded register.
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
 * @spec openspec/changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\RuleAuditService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RuleAuditService's PayrollRun/PensionFiling related-context pre-pass.
 *
 * @spec openspec/changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md
 */
class RuleAuditServiceTest extends TestCase
{


    /**
     * GL fields internally consistent with xc-payroll-gl-reconciliation /
     * xc-withholding-liability-clearing, matching the seeded PayrollRun rows.
     *
     * @var array<string, mixed>
     */
    private const GL_FIELDS = [
        'administrationId'            => 'ADM-001',
        'jurisdiction'                => 'NL',
        'totalGross'                  => 3800.00,
        'totalLoonheffing'            => 1102.00,
        'totalEmployerCharges'        => 649.80,
        'totalWithholdings'           => 1102.00,
        'totalNet'                    => 2698.00,
        'glExpensePosted'             => 4449.80,
        'glLiabilityPosted'           => 1751.80,
        'withholdingsClearedToZero'   => true,
        'withholdingLiabilityBalance' => 0.00,
    ];


    /**
     * Build a RuleAuditService backed by a fake ObjectService that returns
     * $rowsBySchema[$schema] for any findAll() call — a fake collaborator,
     * not a fake of the audit logic under test.
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
        $container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('hrmq');

        $logger = $this->createMock(LoggerInterface::class);

        return new RuleAuditService($container, $appConfig, $logger);

    }//end serviceWithRows()


    /**
     * The three pension-filing-upa-mvp seed fixtures (hr-seed.json), fed
     * through audit() as if loaded from the register.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededRows(): array
    {
        $runs = [
            array_merge(self::GL_FIELDS, ['id' => 'payrollrun-2026-05', 'period' => '2026-05', 'status' => 'approved']),
            array_merge(self::GL_FIELDS, ['id' => 'payrollrun-2026-06', 'period' => '2026-06', 'status' => 'approved']),
        ];

        $filings = [
            ['payrollRunId' => 'payrollrun-2026-05', 'period' => '2026-05', 'fund' => 'abp', 'deadline' => '2026-06-30', 'status' => 'verzonden'],
            ['payrollRunId' => 'payrollrun-2026-06', 'period' => '2026-06', 'fund' => 'abp', 'deadline' => '2026-07-31', 'status' => 'concept'],
            ['payrollRunId' => 'payrollrun-2026-05', 'period' => '2026-05', 'fund' => 'spw', 'deadline' => '2026-06-30', 'status' => 'bevestigd'],
        ];

        return [
            'PayrollRun'    => $runs,
            'PensionFiling' => $filings,
        ];

    }//end seededRows()


    /**
     * @return void
     */
    public function testSeededDataHasNoReferenceOrCompletenessViolations(): void
    {
        $service = $this->serviceWithRows($this->seededRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');

        $this->assertNotContains('nl-upa-payrollrun-approved', $ruleIds);
        $this->assertNotContains('nl-upa-monthly-completeness', $ruleIds);

    }//end testSeededDataHasNoReferenceOrCompletenessViolations()


    /**
     * @return void
     */
    public function testSeededDataFlagsExactlyOneOverdueUnsentDeadlineAlert(): void
    {
        $service = $this->serviceWithRows($this->seededRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(1, ($byRule['nl-upa-deadline-alert'] ?? 0));

    }//end testSeededDataFlagsExactlyOneOverdueUnsentDeadlineAlert()


    /**
     * @return void
     */
    public function testDanglingReferenceIsFlaggedByAudit(): void
    {
        $rows = [
            'PayrollRun'    => [],
            'PensionFiling' => [['payrollRunId' => 'no-such-run', 'period' => '2026-05', 'fund' => 'abp', 'deadline' => '2026-06-30', 'status' => 'concept']],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertContains('nl-upa-payrollrun-approved', $ruleIds);

    }//end testDanglingReferenceIsFlaggedByAudit()


    /**
     * @return void
     */
    public function testApprovedRunWithoutAnyFilingIsFlaggedIncomplete(): void
    {
        $rows = [
            'PayrollRun'    => [array_merge(self::GL_FIELDS, ['id' => 'payrollrun-2026-04', 'period' => '2026-04', 'status' => 'approved'])],
            'PensionFiling' => [],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertContains('nl-upa-monthly-completeness', $ruleIds);

    }//end testApprovedRunWithoutAnyFilingIsFlaggedIncomplete()


    // -- onboarding-wizard-mvp: Employee/EmploymentContract related-context --


    /**
     * The onboarding-wizard-mvp hr-seed.json fixture shapes: two Employees
     * (employee-jansen already loonheffingen-complete, employee-visser a fresh
     * hire that is not) and the two Onboarding seeds (onboarding-jansen, a
     * clean afgerond case; onboarding-visser, mid-flow with an overdue WID
     * check), fed through audit() as if loaded from the register.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededOnboardingRows(): array
    {
        $employees = [
            ['id' => 'employee-jansen', 'loonheffingenVerklaringOnFile' => true, 'startDate' => '2024-01-01'],
            ['id' => 'employee-visser', 'loonheffingenVerklaringOnFile' => false, 'startDate' => '2026-07-01'],
        ];

        $onboardings = [
            [
                'employeeId'        => 'employee-visser',
                'startDate'         => '2026-07-01',
                'proeftijdEndDate'  => '2026-08-01',
                'status'            => 'gegevens_gevalideerd',
                'contractSigned'    => true,
                'widCheckDone'      => false,
                'bsnValidated'      => true,
                'ibanVerified'      => true,
                'itProvisioned'     => false,
                'pensioenAangemeld' => false,
            ],
            [
                'employeeId'        => 'employee-jansen',
                'startDate'         => '2024-01-01',
                'proeftijdEndDate'  => '2024-02-01',
                'status'            => 'afgerond',
                'contractSigned'    => true,
                'widCheckDone'      => true,
                'widCheckDate'      => '2023-12-28',
                'bsnValidated'      => true,
                'ibanVerified'      => true,
                'itProvisioned'     => true,
                'pensioenAangemeld' => true,
            ],
        ];

        return [
            'Employee'   => $employees,
            'Onboarding' => $onboardings,
        ];

    }//end seededOnboardingRows()


    /**
     * @return void
     */
    public function testSeededOnboardingDataFlagsExactlyOneWidCheckViolation(): void
    {
        $service = $this->serviceWithRows($this->seededOnboardingRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(1, ($byRule['nl-onboarding-wid-check'] ?? 0));
        $this->assertArrayNotHasKey('nl-onboarding-proeftijd-bewaking', $byRule);
        $this->assertArrayNotHasKey('nl-onboarding-loonheffingenverklaring', $byRule);

    }//end testSeededOnboardingDataFlagsExactlyOneWidCheckViolation()


    /**
     * @return void
     */
    public function testOnboardingLoonheffingenverklaringViolatedWhenEmployeeMissingVerklaringAtReadyStatus(): void
    {
        $rows = [
            'Employee'   => [['id' => 'employee-visser', 'loonheffingenVerklaringOnFile' => false, 'startDate' => '2026-07-01']],
            'Onboarding' => [
                [
                    'employeeId'   => 'employee-visser',
                    'startDate'    => '2020-01-01',
                    'status'       => 'gereed_eerste_werkdag',
                    'widCheckDone' => true,
                ],
            ],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertContains('nl-onboarding-loonheffingenverklaring', $ruleIds);

    }//end testOnboardingLoonheffingenverklaringViolatedWhenEmployeeMissingVerklaringAtReadyStatus()


    /**
     * @return void
     */
    public function testOnboardingProeftijdViolatedWhenContractCapExceeded(): void
    {
        $rows = [
            'EmploymentContract' => [
                ['employeeId' => 'employee-devos', 'type' => 'temporary', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
            ],
            'Onboarding'         => [
                [
                    'employeeId'       => 'employee-devos',
                    'startDate'        => '2026-07-01',
                    'proeftijdEndDate' => '2026-09-01',
                    'status'           => 'contract_getekend',
                    'widCheckDone'     => true,
                ],
            ],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertContains('nl-onboarding-proeftijd-bewaking', $ruleIds);

    }//end testOnboardingProeftijdViolatedWhenContractCapExceeded()


    // -- org-chart-basic: OrgUnit related-context + seeded hierarchy --


    /**
     * The org-chart-basic hr-seed.json fixture shapes: the Directie ->
     * Consultancy/Backoffice hierarchy (three OrgUnits, all active) and three
     * OrgAssignments, one deliberately date-inconsistent (bakker,
     * endDate < startDate), fed through audit() as if loaded from the
     * register.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededOrgRows(): array
    {
        $units = [
            ['id' => 'orgunit-directie', 'name' => 'Directie', 'type' => 'afdeling', 'parentUnitId' => '', 'managerId' => 'employee-jansen', 'active' => true],
            ['id' => 'orgunit-consultancy', 'name' => 'Consultancy', 'type' => 'team', 'parentUnitId' => 'orgunit-directie', 'costCenter' => 'CC-100', 'managerId' => 'employee-devries', 'active' => true],
            ['id' => 'orgunit-backoffice', 'name' => 'Backoffice', 'type' => 'afdeling', 'parentUnitId' => 'orgunit-directie', 'costCenter' => 'CC-200', 'active' => true],
        ];

        $assignments = [
            ['id' => 'orgassignment-jansen-consultancy', 'employeeId' => 'employee-jansen', 'orgUnitId' => 'orgunit-consultancy', 'role' => 'Consultant', 'startDate' => '2024-01-01', 'endDate' => ''],
            ['id' => 'orgassignment-devries-backoffice', 'employeeId' => 'employee-devries', 'orgUnitId' => 'orgunit-backoffice', 'role' => 'Officemanager', 'startDate' => '2025-03-01', 'endDate' => ''],
            ['id' => 'orgassignment-bakker-consultancy', 'employeeId' => 'employee-bakker', 'orgUnitId' => 'orgunit-consultancy', 'role' => 'Junior consultant', 'startDate' => '2026-06-01', 'endDate' => '2026-05-01'],
        ];

        return [
            'OrgUnit'       => $units,
            'OrgAssignment' => $assignments,
        ];

    }//end seededOrgRows()


    /**
     * @return void
     */
    public function testSeededOrgDataFlagsExactlyOneAssignmentConsistencyViolation(): void
    {
        $service = $this->serviceWithRows($this->seededOrgRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(1, ($byRule['nl-org-assignment-consistency'] ?? 0));

    }//end testSeededOrgDataFlagsExactlyOneAssignmentConsistencyViolation()


    /**
     * @return void
     */
    public function testSeededOrgDataHasNoUnitCycleViolations(): void
    {
        $service = $this->serviceWithRows($this->seededOrgRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertNotContains('nl-org-unit-cycle', $ruleIds);

    }//end testSeededOrgDataHasNoUnitCycleViolations()


    /**
     * @return void
     */
    public function testTwoNodeUnitCycleIsFlaggedForBothUnitsThroughAudit(): void
    {
        $rows = [
            'OrgUnit' => [
                ['id' => 'unit-a', 'name' => 'A', 'type' => 'afdeling', 'parentUnitId' => 'unit-b', 'active' => true],
                ['id' => 'unit-b', 'name' => 'B', 'type' => 'afdeling', 'parentUnitId' => 'unit-a', 'active' => true],
            ],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(2, ($byRule['nl-org-unit-cycle'] ?? 0));

    }//end testTwoNodeUnitCycleIsFlaggedForBothUnitsThroughAudit()


    /**
     * @return void
     */
    public function testDanglingOrgUnitReferenceIsFlaggedByAudit(): void
    {
        $rows = [
            'OrgUnit'       => [],
            'OrgAssignment' => [
                ['id' => 'orgassignment-x', 'employeeId' => 'employee-x', 'orgUnitId' => 'no-such-unit', 'startDate' => '2026-01-01', 'endDate' => ''],
            ],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertContains('nl-org-assignment-consistency', $ruleIds);

    }//end testDanglingOrgUnitReferenceIsFlaggedByAudit()


    // -- time-attendance-mvp: AttendanceRecord attendance-context + seeded rows --


    /**
     * The time-attendance-mvp hr-seed.json fixture shapes: a compliant closed
     * day (jansen), a consecutive-day devries pair with an 8h overnight gap
     * (violates nl-atw-dagelijkse-rust on the second record), and an 8h
     * zero-break bakker day (violates nl-atw-pauze), fed through audit() as
     * if loaded from the register.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededAttendanceRows(): array
    {
        $records = [
            [
                'employeeId'   => 'employee-jansen',
                'date'         => '2026-07-09',
                'clockIn'      => '2026-07-09T08:30:00Z',
                'clockOut'     => '2026-07-09T17:00:00Z',
                'breakMinutes' => 30,
                'workedHours'  => 8.0,
                'status'       => 'gesloten',
            ],
            [
                'employeeId'   => 'employee-devries',
                'date'         => '2026-07-08',
                'clockIn'      => '2026-07-08T14:00:00Z',
                'clockOut'     => '2026-07-08T23:00:00Z',
                'breakMinutes' => 30,
                'workedHours'  => 8.5,
                'status'       => 'gesloten',
            ],
            [
                'employeeId'   => 'employee-devries',
                'date'         => '2026-07-09',
                'clockIn'      => '2026-07-09T07:00:00Z',
                'clockOut'     => '2026-07-09T15:30:00Z',
                'breakMinutes' => 30,
                'workedHours'  => 8.0,
                'status'       => 'gesloten',
            ],
            [
                'employeeId'   => 'employee-bakker',
                'date'         => '2026-07-10',
                'clockIn'      => '2026-07-10T08:00:00Z',
                'clockOut'     => '2026-07-10T16:00:00Z',
                'breakMinutes' => 0,
                'workedHours'  => 8.0,
                'status'       => 'gesloten',
            ],
        ];

        return ['AttendanceRecord' => $records];

    }//end seededAttendanceRows()


    /**
     * @return void
     */
    public function testSeededAttendanceDataFlagsExactlyTheIntendedViolations(): void
    {
        $service = $this->serviceWithRows($this->seededAttendanceRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(1, ($byRule['nl-atw-dagelijkse-rust'] ?? 0));
        $this->assertSame(1, ($byRule['nl-atw-pauze'] ?? 0));
        $this->assertArrayNotHasKey('nl-atw-max-werkdag', $byRule);

    }//end testSeededAttendanceDataFlagsExactlyTheIntendedViolations()


    // -- offboarding-wizard-mvp: Employee.endDate/LeaveBalance related-context --


    /**
     * The offboarding-wizard-mvp hr-seed.json fixture shapes: two Employees
     * (employee-jansen, still active with an open 144h holiday leave balance;
     * employee-de-boer, a cleanly departed former employee with endDate
     * matching lastWorkingDay) and the two Offboarding seeds
     * (offboarding-jansen, mid-flow with a missing transitievergoeding;
     * offboarding-de-boer, a clean afgerond case), fed through audit() as if
     * loaded from the register.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededOffboardingRows(): array
    {
        $employees = [
            ['id' => 'employee-jansen', 'loonheffingenVerklaringOnFile' => true, 'startDate' => '2024-01-01', 'endDate' => ''],
            ['id' => 'employee-de-boer', 'loonheffingenVerklaringOnFile' => true, 'startDate' => '2019-02-01', 'endDate' => '2026-05-31'],
        ];

        $leaveBalances = [
            ['employeeId' => 'employee-jansen', 'leaveType' => 'holiday', 'year' => 2026, 'entitledHours' => 160, 'bovenwettelijkHours' => 40, 'usedHours' => 56],
        ];

        $offboardings = [
            [
                'employeeId'               => 'employee-jansen',
                'lastWorkingDay'           => '2026-08-31',
                'reason'                   => 'opzegging-werkgever',
                'status'                   => 'eindafrekening_gereed',
                'exitGesprekDone'          => null,
                'assetsIngeleverd'         => false,
                'toegangIngetrokken'       => false,
                'verlofsaldoUitbetaald'    => false,
                'vakantiegeldAfgerekend'   => true,
                'transitievergoedingBedrag' => null,
                'getuigschriftVerstrekt'   => false,
            ],
            [
                'employeeId'               => 'employee-de-boer',
                'lastWorkingDay'           => '2026-05-31',
                'reason'                   => 'opzegging-werknemer',
                'status'                   => 'afgerond',
                'exitGesprekDone'          => '2026-05-28',
                'assetsIngeleverd'         => true,
                'toegangIngetrokken'       => true,
                'verlofsaldoUitbetaald'    => true,
                'vakantiegeldAfgerekend'   => true,
                'transitievergoedingBedrag' => null,
                'getuigschriftVerstrekt'   => true,
            ],
        ];

        return [
            'Employee'     => $employees,
            'LeaveBalance' => $leaveBalances,
            'Offboarding'  => $offboardings,
        ];

    }//end seededOffboardingRows()


    /**
     * @return void
     */
    public function testSeededOffboardingDataFlagsExactlyOneTransitievergoedingViolation(): void
    {
        $service = $this->serviceWithRows($this->seededOffboardingRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(1, ($byRule['nl-offboarding-transitievergoeding'] ?? 0));
        $this->assertArrayNotHasKey('nl-offboarding-verlofsaldo-uitbetaling', $byRule);
        $this->assertArrayNotHasKey('nl-offboarding-getuigschrift', $byRule);
        $this->assertArrayNotHasKey('nl-offboarding-einddatum-consistentie', $byRule);

    }//end testSeededOffboardingDataFlagsExactlyOneTransitievergoedingViolation()


    /**
     * @return void
     */
    public function testOffboardingVerlofsaldoViolatedWhenAfgerondWithOpenBalanceUnpaid(): void
    {
        $rows = $this->seededOffboardingRows();
        // Complete jansen's case without paying out the still-open 144h balance.
        $rows['Offboarding'][0]['status']                = 'afgerond';
        $rows['Offboarding'][0]['transitievergoedingBedrag'] = 4200.00;
        $rows['Offboarding'][0]['exitGesprekDone']        = '2026-08-25';
        $rows['Offboarding'][0]['assetsIngeleverd']       = true;
        $rows['Offboarding'][0]['toegangIngetrokken']     = true;
        $rows['Offboarding'][0]['getuigschriftVerstrekt'] = true;
        $rows['Employee'][0]['endDate']                   = '2026-08-31';

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertContains('nl-offboarding-verlofsaldo-uitbetaling', $ruleIds);

    }//end testOffboardingVerlofsaldoViolatedWhenAfgerondWithOpenBalanceUnpaid()


    /**
     * @return void
     */
    public function testOffboardingEinddatumViolatedWhenEmployeeEndDateMismatches(): void
    {
        $rows = [
            'Employee'    => [['id' => 'employee-x', 'loonheffingenVerklaringOnFile' => true, 'startDate' => '2020-01-01', 'endDate' => '2026-06-30']],
            'Offboarding' => [
                [
                    'employeeId'               => 'employee-x',
                    'lastWorkingDay'           => '2026-05-31',
                    'reason'                   => 'opzegging-werknemer',
                    'status'                   => 'afgerond',
                    'exitGesprekDone'          => '2026-05-28',
                    'assetsIngeleverd'         => true,
                    'toegangIngetrokken'       => true,
                    'verlofsaldoUitbetaald'    => true,
                    'vakantiegeldAfgerekend'   => true,
                    'transitievergoedingBedrag' => null,
                    'getuigschriftVerstrekt'   => true,
                ],
            ],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertContains('nl-offboarding-einddatum-consistentie', $ruleIds);

    }//end testOffboardingEinddatumViolatedWhenEmployeeEndDateMismatches()


}//end class
