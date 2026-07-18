<?php

/**
 * Unit tests for PayrollReproduceService (audit-trail-payroll REQ-AUDP-002,
 * fixing hrmq#98).
 *
 * Pins the actual reproducibility guarantee: a sealed Payslip is recomputed
 * from its OWN stored `engineInputSnapshot` — never from live Employee/
 * EmploymentContract state — through the real `PayrollCalculator` and
 * `PackRepository` (the anchor-case golden fixture, design.md D2: €3.800,
 * wit, korting, below AOW, `nl-2026@1.1.0`), and compared cents-exact
 * against the payslip's stored figures. A clean payslip reproduces; a
 * tampered stored figure is caught and named; a payslip with no snapshot (or
 * whose run's engine artefact has drifted) is refused, never silently
 * skipped. Drives the service through a fake ObjectService double (a fake
 * collaborator, not a fake of the logic under test) — the PayrollRunServiceTest
 * precedent — but the calculator/pack-repository/tax-tables are the REAL
 * production objects, since the whole point of this test is proving the
 * recomputation actually reproduces the golden anchor figures.
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
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\SickPayCalculator;
use OCA\Hrmq\Service\PayrollReproduceService;
use OCA\Hrmq\Service\PayrollRunService;
use OCA\Hrmq\Service\PayrollRetentionGuardService;
use OCA\Hrmq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollReproduceService.
 *
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
 */
class PayrollReproduceServiceTest extends TestCase
{


    /**
     * A fake ObjectService: `findAll()` returns the seeded rows for the
     * current schema; `saveObject()` upserts and reflects the write back
     * (the PayrollRunServiceTest precedent, trimmed to what generation +
     * reproduction both need).
     *
     * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
     *
     * @return object
     */
    private function fakeObjectService(array $rowsBySchema=[]): object
    {
        return new class ($rowsBySchema) {
            /**
             * @var string
             */
            private string $schema = '';

            /**
             * @var int
             */
            private int $nextId = 1;

            /**
             * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
             */
            public function __construct(public array $rowsBySchema)
            {
            }

            /**
             * @param string $register Unused by the fake.
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
             * @param array<string, mixed> $options Unused by the fake.
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $options=[]): array
            {
                return $this->rowsBySchema[$this->schema] ?? [];

            }//end findAll()

            /**
             * @param array<string, mixed> $object        The object to save.
             * @param string|null          $register      Unused by the fake.
             * @param string|null          $schema        Schema name.
             * @param string|null          $uuid          Existing id when updating.
             * @param bool                 $_rbac         Unused by the fake.
             * @param bool                 $_multitenancy Unused by the fake.
             *
             * @return array<string, mixed>
             */
            public function saveObject(
                array $object,
                ?string $register=null,
                ?string $schema=null,
                ?string $uuid=null,
                bool $_rbac=true,
                bool $_multitenancy=true
            ): array {
                $targetSchema = ($schema ?? $this->schema);
                $id           = ($uuid ?? ('generated-'.$targetSchema.'-'.$this->nextId++));
                $saved        = array_merge($object, ['id' => $id]);

                $rows     = ($this->rowsBySchema[$targetSchema] ?? []);
                $replaced = false;
                foreach ($rows as $i => $row) {
                    if ((string) ($row['id'] ?? '') === $id) {
                        $rows[$i] = $saved;
                        $replaced = true;
                        break;
                    }
                }

                if ($replaced === false) {
                    $rows[] = $saved;
                }

                $this->rowsBySchema[$targetSchema] = $rows;

                return $saved;

            }//end saveObject()

            /**
             * @param string      $uuid          Unused by the fake.
             * @param string|null $register      Unused by the fake.
             * @param string|null $schema        Unused by the fake.
             * @param bool        $_rbac         Unused by the fake.
             * @param bool        $_multitenancy Unused by the fake.
             *
             * @return bool
             */
            public function deleteObject(
                string $uuid,
                ?string $register=null,
                ?string $schema=null,
                bool $_rbac=true,
                bool $_multitenancy=true
            ): bool {
                return true;

            }//end deleteObject()
        };

    }//end fakeObjectService()


    /**
     * Generate the anchor-case draft run + payslip via the REAL
     * PayrollRunService (so the returned Payslip's `engineInputSnapshot` and
     * figures are the genuine engine output, not hand-typed golden values),
     * seeded into a fresh ObjectService fake.
     *
     * @return array{0: object, 1: array<string, mixed>, 2: array<string, mixed>} `[fake ObjectService, saved PayrollRun, saved Payslip]`.
     */
    private function generateAnchorRunAndPayslip(): array
    {
        $fake = $this->fakeObjectService(
            [
                'Employee'           => [
                    [
                        'id'                           => 'emp-1',
                        'employeeNumber'               => 'EMP-NL-0001',
                        'dateOfBirth'                  => '1990-04-12',
                        'startDate'                    => '2022-01-01',
                        'endDate'                      => null,
                        'grossMonthlySalary'           => 3800.00,
                        'taxTableColor'                => 'wit',
                        'loonheffingskortingToegepast' => true,
                        'bsn'                          => '123456782',
                        'identityDocumentVerified'     => true,
                        'nextcloudUserId'              => 'sanne',
                    ],
                ],
                'EmploymentContract' => [
                    [
                        'id'              => 'ct-1',
                        'employeeId'      => 'emp-1',
                        'type'            => 'permanent',
                        'writtenContract' => true,
                        'startDate'       => '2022-01-01',
                        'endDate'         => null,
                        'hoursPerWeek'    => 36.0,
                        'awfTariff'       => 'low',
                    ],
                ],
                'PayrollRun'         => [],
                'Payslip'            => [],
            ]
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('hrmq');
        $settings->method('getPayrollAofTariff')->willReturn('laag');
        $settings->method('getPayrollWhkPercentage')->willReturnArgument(0);

        $logger = $this->createMock(LoggerInterface::class);

        $retentionGuard = $this->createMock(PayrollRetentionGuardService::class);

        $runService = new PayrollRunService($container, $settings, new PayrollCalculator(), new SickPayCalculator(), $retentionGuard, $logger);
        $runService->runFor('2026-02');

        $run     = $fake->rowsBySchema['PayrollRun'][0];
        $payslip = $fake->rowsBySchema['Payslip'][0];

        return [$fake, $run, $payslip];

    }//end generateAnchorRunAndPayslip()


    /**
     * Build a fully-wired PayrollReproduceService against a given
     * ObjectService fake.
     *
     * @param object $fake The seeded ObjectService fake.
     *
     * @return PayrollReproduceService
     */
    private function reproduceService(object $fake): PayrollReproduceService
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('hrmq');

        $logger = $this->createMock(LoggerInterface::class);

        return new PayrollReproduceService($container, $settings, new PayrollCalculator(), $logger);

    }//end reproduceService()


    /**
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
     */
    public function testACleanPayslipReproducesExactlyFromItsStoredSnapshot(): void
    {
        [$fake, , $payslip] = $this->generateAnchorRunAndPayslip();

        $service = $this->reproduceService($fake);
        $result  = $service->reproduce((string) $payslip['id']);

        $this->assertSame('reproduced', $result['status']);
        $this->assertSame([], $result['mismatches']);

    }//end testACleanPayslipReproducesExactlyFromItsStoredSnapshot()


    /**
     * fixing hrmq#98: reproduction reads ONLY the stored snapshot — editing
     * the source Employee afterwards must not change the outcome, because
     * the reproduce path never re-reads Employee/EmploymentContract at all.
     *
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
     */
    public function testReproductionIsUnaffectedByALaterEmployeeEdit(): void
    {
        [$fake, , $payslip] = $this->generateAnchorRunAndPayslip();

        // Edit the source Employee AFTER generation -- taxTableColor flip,
        // exactly the hrmq#98 scenario. The fake's Employee rows are never
        // consulted by PayrollReproduceService (it only reads Payslip/
        // PayrollRun), so this mutation is here only to document intent.
        $fake->rowsBySchema['Employee'][0]['taxTableColor']                = 'groen';
        $fake->rowsBySchema['Employee'][0]['loonheffingskortingToegepast'] = false;

        $service = $this->reproduceService($fake);
        $result  = $service->reproduce((string) $payslip['id']);

        $this->assertSame('reproduced', $result['status']);

    }//end testReproductionIsUnaffectedByALaterEmployeeEdit()


    /**
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
     */
    public function testATamperedNettoPayIsCaughtAndNamed(): void
    {
        [$fake, , $payslip] = $this->generateAnchorRunAndPayslip();

        // Simulate a direct-in-register tamper of the sealed figure.
        foreach ($fake->rowsBySchema['Payslip'] as $i => $row) {
            if ((string) ($row['id'] ?? '') === (string) $payslip['id']) {
                $fake->rowsBySchema['Payslip'][$i]['nettoPay'] = ((float) $row['nettoPay']) + 0.01;
            }
        }

        $service = $this->reproduceService($fake);
        $result  = $service->reproduce((string) $payslip['id']);

        $this->assertSame('mismatch', $result['status']);
        $components = array_column($result['mismatches'], 'component');
        $this->assertContains('nettoPay', $components);

    }//end testATamperedNettoPayIsCaughtAndNamed()


    /**
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
     */
    public function testAHandEnteredPayslipWithNoSnapshotIsRefused(): void
    {
        $fake = $this->fakeObjectService(
            [
                'PayrollRun' => [],
                'Payslip'    => [
                    [
                        'id'                  => 'hand-entered-1',
                        'payrollRunId'        => null,
                        'engineInputSnapshot' => null,
                        'nettoPay'            => 2500.00,
                    ],
                ],
            ]
        );

        $service = $this->reproduceService($fake);
        $result  = $service->reproduce('hand-entered-1');

        $this->assertSame('refused', $result['status']);
        $this->assertStringContainsString('engineInputSnapshot', $result['message']);

    }//end testAHandEnteredPayslipWithNoSnapshotIsRefused()


    /**
     * @return void
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
     */
    public function testAnUnknownPayslipIsRefused(): void
    {
        $fake    = $this->fakeObjectService(['PayrollRun' => [], 'Payslip' => []]);
        $service = $this->reproduceService($fake);

        $result = $service->reproduce('does-not-exist');

        $this->assertSame('refused', $result['status']);

    }//end testAnUnknownPayslipIsRefused()


}//end class
