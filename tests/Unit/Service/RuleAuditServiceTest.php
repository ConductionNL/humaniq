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


}//end class
