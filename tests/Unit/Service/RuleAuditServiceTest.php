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
 * hrmq:rules:audit` would show against the seeded register. mss-team-scope
 * (round 3) adds coverage for the Employee.nextcloudUserId/OrgUnit.managerId
 * index extensions and the new OrgAssignment.byEmployeeId index, through the
 * exact seeded Timesheet/Expense/LeaveRequest managerUserId stamps.
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
 * @spec openspec/changes/mss-team-scope/specs/mss-team-scope/spec.md#REQ-MSS-005
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

        // Pre-existing bug fixed in passing (hrmq#99 touched this suite):
        // these three deadlines were hardcoded absolute dates. nl-upa-deadline-alert
        // fires on any status other than `verzonden` whose deadline is past OR
        // within 14 days -- the `bevestigd` row's hardcoded 2026-06-30 deadline
        // has since drifted into the past relative to today, making it a SECOND
        // alert this test never intended (only the `concept` row should alert).
        // Dates are now relative to today so the fixture's intent (exactly one
        // alerting filing) holds regardless of when the suite runs.
        $filings = [
            ['payrollRunId' => 'payrollrun-2026-05', 'period' => '2026-05', 'fund' => 'abp', 'deadline' => '2026-06-30', 'status' => 'verzonden'],
            ['payrollRunId' => 'payrollrun-2026-06', 'period' => '2026-06', 'fund' => 'abp', 'deadline' => '2026-07-31', 'status' => 'concept'],
            // Pre-existing test-fixture fix (encountered while adding
            // audit-trail-payroll's own rule, unrelated to it): `bevestigd`
            // is a mid-lifecycle state (concept -> gecontroleerd -> bevestigd
            // -> verzonden), NOT exempted by `deadlineNotAlerting()` (only
            // `verzonden` is) -- this row's fixed 2026-06-30 deadline was
            // compliant only while "today" stayed before it, then time-bombed
            // once the real date passed it. `verzonden` is this fixture's
            // actually-intended "already handled, never alerts" state
            // (identical to the `abp`/2026-05 row above), so it is now
            // date-invariant rather than merely date-lucky.
            ['payrollRunId' => 'payrollrun-2026-05', 'period' => '2026-05', 'fund' => 'spw', 'deadline' => '2026-06-30', 'status' => 'verzonden'],
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


    // -- asset-management-mvp: Asset/Offboarding related-context + seeded rows --


    /**
     * The asset-management-mvp hr-seed.json fixture shapes: three Assets (a
     * laptop and a voertuig `uitgegeven`, a telefoon `beschikbaar`) and two
     * AssetAssignments, one closed and consistent (visser/bus) and one
     * deliberately open on the `beschikbaar` telefoon (jansen/telefoon), fed
     * through audit() as if loaded from the register. No Offboarding rows in
     * this fixture -- the parallel offboarding-wizard-mvp related-context is
     * exercised separately below.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededAssetRows(): array
    {
        $assets = [
            ['id' => 'asset-laptop-latitude', 'name' => 'Dell Latitude 5450', 'category' => 'laptop', 'status' => 'uitgegeven', 'active' => true],
            ['id' => 'asset-telefoon-fairphone', 'name' => 'Fairphone 5', 'category' => 'telefoon', 'status' => 'beschikbaar', 'active' => true],
            ['id' => 'asset-bus-transit', 'name' => 'Ford Transit bedrijfsbus', 'category' => 'voertuig', 'status' => 'uitgegeven', 'active' => true],
        ];

        $assignments = [
            ['id' => 'assetassignment-visser-bus', 'assetId' => 'asset-bus-transit', 'employeeId' => 'employee-visser', 'uitgifteDatum' => '2025-01-06', 'innameDatum' => '2025-12-19', 'uitgifteBonSigned' => true],
            ['id' => 'assetassignment-jansen-telefoon', 'assetId' => 'asset-telefoon-fairphone', 'employeeId' => 'employee-jansen', 'uitgifteDatum' => '2026-06-15', 'innameDatum' => null, 'uitgifteBonSigned' => false],
        ];

        return [
            'Asset'           => $assets,
            'AssetAssignment' => $assignments,
        ];

    }//end seededAssetRows()


    /**
     * @return void
     */
    public function testSeededAssetDataFlagsExactlyOneAssignmentConsistencyViolation(): void
    {
        $service = $this->serviceWithRows($this->seededAssetRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(1, ($byRule['nl-asset-assignment-consistency'] ?? 0));
        $this->assertArrayNotHasKey('nl-asset-inname-bij-offboarding', $byRule);

    }//end testSeededAssetDataFlagsExactlyOneAssignmentConsistencyViolation()


    /**
     * @return void
     */
    public function testDanglingAssetReferenceIsFlaggedByAudit(): void
    {
        $rows = [
            'Asset'           => [],
            'AssetAssignment' => [
                ['id' => 'assetassignment-x', 'assetId' => 'no-such-asset', 'employeeId' => 'employee-x', 'uitgifteDatum' => '2026-01-01', 'innameDatum' => null],
            ],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertContains('nl-asset-assignment-consistency', $ruleIds);

    }//end testDanglingAssetReferenceIsFlaggedByAudit()


    /**
     * The full asset-management-mvp + offboarding-wizard-mvp hr-seed.json
     * fixture: the seeded assets/assignments (seededAssetRows()) plus an
     * employee whose non-cancelled Offboarding case's lastWorkingDay is
     * already in the past, fed through audit() as if loaded from the
     * register.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededAssetAndOverdueOffboardingRows(): array
    {
        $rows                  = $this->seededAssetRows();
        $rows['Offboarding']   = [
            ['id' => 'offboarding-jansen', 'employeeId' => 'employee-jansen', 'lastWorkingDay' => date('Y-m-d', strtotime('-5 days')), 'reason' => 'opzegging-werkgever', 'status' => 'eindafrekening_gereed'],
        ];

        return $rows;

    }//end seededAssetAndOverdueOffboardingRows()


    /**
     * @return void
     */
    public function testOpenAssignmentPastOverdueOffboardingIsFlaggedByAudit(): void
    {
        $service = $this->serviceWithRows($this->seededAssetAndOverdueOffboardingRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertContains('nl-asset-inname-bij-offboarding', $ruleIds);

    }//end testOpenAssignmentPastOverdueOffboardingIsFlaggedByAudit()


    /**
     * @return void
     */
    public function testCancelledOffboardingIsExcludedFromThePlannedCompletionIndex(): void
    {
        $rows                = $this->seededAssetRows();
        $rows['Offboarding'] = [
            ['id' => 'offboarding-jansen', 'employeeId' => 'employee-jansen', 'lastWorkingDay' => date('Y-m-d', strtotime('-5 days')), 'reason' => 'opzegging-werkgever', 'status' => 'geannuleerd'],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertNotContains('nl-asset-inname-bij-offboarding', $ruleIds);

    }//end testCancelledOffboardingIsExcludedFromThePlannedCompletionIndex()


    /**
     * @return void
     */
    public function testSeededOffboardingJansenLastWorkingDayInTheFutureDoesNotFlagTheOpenTelefoonUitgifte(): void
    {
        // The real offboarding-jansen seed (hr-seed.json): eindafrekening_gereed,
        // lastWorkingDay 2026-08-31 -- in the future relative to the audit
        // run date, so the open jansen/telefoon uitgifte from
        // seededAssetRows() must NOT be flagged by nl-asset-inname-bij-offboarding
        // even though a (non-vacuous) Offboarding index entry now resolves for
        // employee-jansen.
        $rows                = $this->seededAssetRows();
        $rows['Offboarding'] = [
            ['id' => 'offboarding-jansen', 'employeeId' => 'employee-jansen', 'lastWorkingDay' => '2026-08-31', 'reason' => 'opzegging-werkgever', 'status' => 'eindafrekening_gereed'],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertNotContains('nl-asset-inname-bij-offboarding', $ruleIds);

    }//end testSeededOffboardingJansenLastWorkingDayInTheFutureDoesNotFlagTheOpenTelefoonUitgifte()


    // -- hr-signals: EmploymentContract signals-context + seeded devries contract --


    /**
     * The hr-signals `contract-devries-tijdelijk` hr-seed.json fixture: a
     * temporary contract 11 months long, ending 19 days from today, with no
     * successor for the same employee and no recorded `aanzegdOn`, fed
     * through audit() as if loaded from the register.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededSignalsRows(): array
    {
        return [
            'EmploymentContract' => [
                [
                    'id'                    => 'contract-devries-tijdelijk',
                    'employeeId'            => 'employee-devries',
                    'type'                  => 'temporary',
                    'writtenContract'       => true,
                    'startDate'             => date('Y-m-d', strtotime('-11 months -13 days')),
                    'endDate'               => date('Y-m-d', strtotime('+19 days')),
                    'hoursPerWeek'          => 32,
                    'hourlyWage'            => 21.00,
                    'awfTariff'             => 'high',
                    'aanzegdOn'             => null,
                    'workingTimeDocumented' => true,
                    'overtimeMultiplier'    => 1.5,
                    'ftePartOfYearMinijob'  => false,
                    'dpaeFiledBeforeStart'  => true,
                ],
            ],
        ];

    }//end seededSignalsRows()


    /**
     * @return void
     */
    public function testSeededSignalsDataFlagsExactlyTheIntendedTwoViolations(): void
    {
        $service = $this->serviceWithRows($this->seededSignalsRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(1, ($byRule['nl-signaal-contract-verloopt'] ?? 0));
        $this->assertSame(1, ($byRule['nl-aanzegtermijn-bewaking'] ?? 0));
        // Non-regression (design.md Seed Data): the seed's Awf tariff, wage
        // and hours never trip the pre-existing payroll/contract rules.
        $this->assertArrayNotHasKey('nl-awf-laag-hoog-tarief', $byRule);
        $this->assertArrayNotHasKey('nl-minimumloon-2026', $byRule);
        $this->assertArrayNotHasKey('nl-minimumuurloon-wet', $byRule);
        $this->assertArrayNotHasKey('nl-contract-schriftelijk', $byRule);

    }//end testSeededSignalsDataFlagsExactlyTheIntendedTwoViolations()


    /**
     * @return void
     */
    public function testSignalsSuccessorClearsTheExpiryViolationThroughAudit(): void
    {
        $rows                        = $this->seededSignalsRows();
        $rows['EmploymentContract'][] = [
            'id'         => 'contract-devries-vervolg',
            'employeeId' => 'employee-devries',
            'type'       => 'temporary',
            'startDate'  => date('Y-m-d', strtotime('+20 days')),
            'endDate'    => null,
            // Long enough / recent aanzegdOn so this successor row itself
            // does not introduce a NEW aanzegtermijn violation to confuse
            // the assertion below.
            'aanzegdOn'  => date('Y-m-d'),
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertArrayNotHasKey('nl-signaal-contract-verloopt', $byRule);

    }//end testSignalsSuccessorClearsTheExpiryViolationThroughAudit()


    /**
     * @return void
     */
    public function testSeededSignalsDataReportsBumpedCatalogueVersionAndBothNewRulesEnforceable(): void
    {
        $service = $this->serviceWithRows($this->seededSignalsRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $this->assertSame(\OCA\Hrmq\Standards\RuleCatalogue::VERSION, $report['catalogueVersion']);
        $enforceable = \OCA\Hrmq\Standards\RuleEngine::checkedRuleIds();
        $this->assertContains('nl-signaal-contract-verloopt', $enforceable);
        $this->assertContains('nl-aanzegtermijn-bewaking', $enforceable);

    }//end testSeededSignalsDataReportsBumpedCatalogueVersionAndBothNewRulesEnforceable()


    /**
     * @return void
     */
    public function testSignalsContextDegradesToEmptyWhenEmploymentContractSchemaAbsent(): void
    {
        $service = $this->serviceWithRows([]);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $this->assertSame(0, $report['objectsChecked']);
        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertNotContains('nl-signaal-contract-verloopt', $ruleIds);
        $this->assertNotContains('nl-aanzegtermijn-bewaking', $ruleIds);

    }//end testSignalsContextDegradesToEmptyWhenEmploymentContractSchemaAbsent()


    // -- mss-team-scope: Employee.nextcloudUserId/OrgUnit.managerId/OrgAssignment.byEmployeeId related-context --


    /**
     * The mss-team-scope hr-seed.json fixture shapes after the schema bumps
     * and seed edits: employee-jansen (nextcloudUserId `admin`) actively
     * placed in Consultancy (managerId re-pointed to employee-jansen), and
     * the three stamped submitted seeds (`managerUserId: "admin"` on
     * Timesheet/Expense/LeaveRequest), fed through audit() as if loaded from
     * the register.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededMssRows(): array
    {
        return [
            'Employee'      => [
                ['id' => 'employee-jansen', 'nextcloudUserId' => 'admin'],
                ['id' => 'employee-devries'],
            ],
            'OrgUnit'       => [
                ['id' => 'orgunit-directie', 'parentUnitId' => '', 'managerId' => 'employee-jansen', 'active' => true],
                ['id' => 'orgunit-consultancy', 'parentUnitId' => 'orgunit-directie', 'managerId' => 'employee-jansen', 'active' => true],
                ['id' => 'orgunit-backoffice', 'parentUnitId' => 'orgunit-directie', 'active' => true],
            ],
            'OrgAssignment' => [
                ['employeeId' => 'employee-jansen', 'orgUnitId' => 'orgunit-consultancy', 'startDate' => '2024-01-01', 'endDate' => ''],
                ['employeeId' => 'employee-devries', 'orgUnitId' => 'orgunit-backoffice', 'startDate' => '2025-03-01', 'endDate' => ''],
            ],
            'Timesheet'     => [
                ['id' => 'timesheet-jansen-2026-05', 'employeeId' => 'employee-jansen', 'status' => 'submitted', 'managerUserId' => 'admin'],
                ['id' => 'timesheet-devries-2026-05', 'employeeId' => 'employee-devries', 'status' => 'approved'],
            ],
            'Expense'       => [
                ['id' => 'expense-jansen-hotel', 'employeeId' => 'employee-jansen', 'status' => 'submitted', 'managerUserId' => 'admin'],
                ['id' => 'expense-devries-train', 'employeeId' => 'employee-devries', 'status' => 'approved'],
            ],
            'LeaveRequest'  => [
                ['id' => 'leave-jansen-zomer', 'employeeId' => 'employee-jansen', 'status' => 'submitted', 'managerUserId' => 'admin'],
            ],
        ];

    }//end seededMssRows()


    /**
     * @return void
     */
    public function testSeededMssDataFlagsZeroManagerConsistencyViolations(): void
    {
        $service = $this->serviceWithRows($this->seededMssRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertNotContains('nl-mss-manager-consistency', $ruleIds);

    }//end testSeededMssDataFlagsZeroManagerConsistencyViolations()


    /**
     * @return void
     */
    public function testUnstampedRecordsAreNeverEvaluatedByManagerConsistency(): void
    {
        // The seeded approved records carry no managerUserId at all —
        // vacuous pass, never flagged.
        $service = $this->serviceWithRows($this->seededMssRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $this->assertGreaterThan(0, $report['objectsCompliant']);
        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertNotContains('nl-mss-manager-consistency', $ruleIds);

    }//end testUnstampedRecordsAreNeverEvaluatedByManagerConsistency()


    /**
     * @return void
     */
    public function testMismatchingManagerStampIsFlaggedThroughAudit(): void
    {
        $rows                            = $this->seededMssRows();
        $rows['Timesheet'][0]['managerUserId'] = 'someone-else';

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(1, ($byRule['nl-mss-manager-consistency'] ?? 0));

    }//end testMismatchingManagerStampIsFlaggedThroughAudit()


    /**
     * @return void
     */
    public function testVacuousWhenEmployeeHasNoActiveAssignmentThroughAudit(): void
    {
        $rows                = $this->seededMssRows();
        $rows['OrgAssignment'] = [];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertNotContains('nl-mss-manager-consistency', $ruleIds);

    }//end testVacuousWhenEmployeeHasNoActiveAssignmentThroughAudit()


    /**
     * @return void
     */
    public function testMssContextDegradesToEmptyWhenOrgSchemasAbsent(): void
    {
        $rows = [
            'Timesheet' => [
                ['id' => 'timesheet-x', 'employeeId' => 'employee-x', 'status' => 'submitted', 'managerUserId' => 'admin'],
            ],
        ];

        $service = $this->serviceWithRows($rows);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertNotContains('nl-mss-manager-consistency', $ruleIds);

    }//end testMssContextDegradesToEmptyWhenOrgSchemasAbsent()


    /**
     * @return void
     */
    public function testSeededMssDataReportsBumpedCatalogueVersionAndRuleEnforceable(): void
    {
        $service = $this->serviceWithRows($this->seededMssRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $this->assertSame(\OCA\Hrmq\Standards\RuleCatalogue::VERSION, $report['catalogueVersion']);
        $enforceable = \OCA\Hrmq\Standards\RuleEngine::checkedRuleIds();
        $this->assertContains('nl-mss-manager-consistency', $enforceable);

    }//end testSeededMssDataReportsBumpedCatalogueVersionAndRuleEnforceable()


    // -- mileage-rules: Expense.travelType/distanceKm onbelast-tarief check --


    /**
     * Expense rows fed through audit() as if loaded from the register: one
     * over-rate mileage claim (EUR 0,30/km) and the actual hr-seed.json
     * compliant mileage fixture (EUR 0,23/km, at the onbelast rate) --
     * matching mileage-rules design.md's Seed Data note.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function seededMileageRows(): array
    {
        return [
            'Expense' => [
                [
                    'id'         => 'expense-devries-mileage',
                    'category'   => 'travel',
                    'travelType' => 'business',
                    'distanceKm' => 150,
                    'amount'     => 34.50,
                ],
                [
                    'id'         => 'expense-over-rate-mileage',
                    'category'   => 'travel',
                    'travelType' => 'business',
                    'distanceKm' => 100,
                    'amount'     => 30.00,
                ],
                // The existing seed row is still travel-category but carries no
                // travelType/distanceKm -- vacuous, must not be counted as
                // checked-and-failed (design.md, "every previously stored
                // Expense stays valid").
                [
                    'id'       => 'expense-devries-train',
                    'category' => 'travel',
                    'amount'   => 27.40,
                ],
            ],
        ];

    }//end seededMileageRows()


    /**
     * The seeded compliant mileage claim raises no violation; the over-rate
     * fixture is flagged exactly once (REQ-MILE-003 "Over-rate mileage claim
     * violates" / "At-or-under-rate mileage claim passes", through the real
     * audit() aggregation rather than the raw predicate).
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testSeededMileageDataFlagsOnlyTheOverRateExpenseThroughAudit(): void
    {
        $service = $this->serviceWithRows($this->seededMileageRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $byRule = [];
        foreach ($report['topViolatedRules'] as $entry) {
            $byRule[$entry['ruleId']] = $entry['count'];
        }

        $this->assertSame(1, ($byRule['nl-reiskosten-onbelast-tarief'] ?? 0));

    }//end testSeededMileageDataFlagsOnlyTheOverRateExpenseThroughAudit()


    /**
     * occ hrmq:rules:audit reports the rule as enforced: it counts toward
     * both machine-checkable and enforceable coverage, and the catalogue
     * version is the bumped one (REQ-MILE-002 / REQ-MILE-003).
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-002
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-003
     */
    public function testSeededMileageDataReportsBumpedCatalogueVersionAndRuleEnforceable(): void
    {
        $service = $this->serviceWithRows($this->seededMileageRows());
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $this->assertSame(\OCA\Hrmq\Standards\RuleCatalogue::VERSION, $report['catalogueVersion']);

        $machineCheckable = array_column(\OCA\Hrmq\Standards\RuleCatalogue::machineCheckable(), 'id');
        $this->assertContains('nl-reiskosten-onbelast-tarief', $machineCheckable);

        $enforceable = \OCA\Hrmq\Standards\RuleEngine::checkedRuleIds();
        $this->assertContains('nl-reiskosten-onbelast-tarief', $enforceable);

    }//end testSeededMileageDataReportsBumpedCatalogueVersionAndRuleEnforceable()


    /**
     * The vacuous pre-existing (travel, no travelType/distanceKm) row is
     * checked (counted) but never counted as a violation of this rule --
     * REQ-MILE-001 "Existing Expense stays valid without the new fields",
     * companion at audit level.
     *
     * @return void
     *
     * @spec openspec/changes/mileage-rules/specs/mileage-rules/spec.md#REQ-MILE-001
     */
    public function testPreExistingTravelExpenseWithNoMileageFieldsIsNeverFlagged(): void
    {
        $service = $this->serviceWithRows(['Expense' => [['id' => 'expense-devries-train', 'category' => 'travel', 'amount' => 27.40]]]);
        $report  = $service->audit(['jurisdiction' => 'NL']);

        $this->assertSame(1, $report['objectsChecked']);
        $this->assertSame(1, $report['objectsCompliant']);
        $ruleIds = array_column($report['topViolatedRules'], 'ruleId');
        $this->assertNotContains('nl-reiskosten-onbelast-tarief', $ruleIds);

    }//end testPreExistingTravelExpenseWithNoMileageFieldsIsNeverFlagged()


}//end class
