<?php

/**
 * Unit tests for PayrollGLPostService.
 *
 * Pins the payroll-glpost-shillinq contract: the D2 balanced-entry math
 * (including the remainder and zero-line-dropping edge cases), the D7
 * duck-typed skip path when shillinq is absent, the D6 idempotency pre-check
 * (double invocation, stale-pending recovery, journalNumber adoption), and
 * the failed-closed path on inconsistent run totals. Drives the service
 * through a fake ObjectService double (a fake collaborator, not a fake of
 * the service logic under test) since the real OpenRegister ObjectService is
 * a sibling-app dependency not available in this standalone suite.
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
 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\PayrollGLPostService;
use OCA\Hrmq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollGLPostService.
 *
 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md
 */
class PayrollGLPostServiceTest extends TestCase
{


    /**
     * Build a fake ObjectService double: `findAll()` returns the seeded rows
     * for the current schema, `saveObject()` records every write (assignable
     * to a generated id when no uuid is given) and reflects it back into the
     * seeded rows so a subsequent idempotency probe within the same test sees
     * it. Optionally throws on the `JournalEntry` schema to simulate shillinq
     * being unavailable (design.md D7).
     *
     * @param array<string, array<int, array<string, mixed>>> $rowsBySchema   Seed rows keyed by schema.
     * @param bool                                              $shillinqThrows Whether JournalEntry access throws.
     *
     * @return object The fake ObjectService.
     */
    private function fakeObjectService(array $rowsBySchema=[], bool $shillinqThrows=false): object
    {
        return new class ($rowsBySchema, $shillinqThrows) {

            /**
             * @var string
             */
            private string $schema = '';

            /**
             * @var int
             */
            private int $nextId = 1;

            /**
             * Every saveObject() call, as `['schema' => ..., 'object' => ...]`.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $saved = [];

            /**
             * @param array<string, array<int, array<string, mixed>>> $rowsBySchema   Seed rows keyed by schema.
             * @param bool                                              $shillinqThrows Whether JournalEntry access throws.
             */
            public function __construct(
                private array $rowsBySchema,
                private readonly bool $shillinqThrows,
            ) {

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
             *
             * @throws \RuntimeException When simulating shillinq unavailability.
             */
            public function findAll(array $options=[]): array
            {
                if ($this->schema === 'JournalEntry' && $this->shillinqThrows === true) {
                    throw new \RuntimeException('shillinq register unavailable');
                }

                return $this->rowsBySchema[$this->schema] ?? [];

            }//end findAll()


            /**
             * @param array<string, mixed> $object        The object to save.
             * @param string|null          $register      Register slug (unused by the fake).
             * @param string|null          $schema        Schema name.
             * @param string|null          $uuid          Existing id when updating.
             * @param bool                 $_rbac         Unused by the fake.
             * @param bool                 $_multitenancy Unused by the fake.
             *
             * @return array<string, mixed> The saved object (with its id).
             *
             * @throws \RuntimeException When simulating shillinq unavailability.
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
                if ($targetSchema === 'JournalEntry' && $this->shillinqThrows === true) {
                    throw new \RuntimeException('shillinq register unavailable');
                }

                $id    = ($uuid ?? ('generated-'.$targetSchema.'-'.$this->nextId++));
                $saved = array_merge($object, ['id' => $id]);

                $this->saved[] = ['schema' => $targetSchema, 'object' => $saved];

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


        };

    }//end fakeObjectService()


    /**
     * Build a fully-wired PayrollGLPostService plus its fake ObjectService
     * double (for assertions on what was saved).
     *
     * @param array<string, array<int, array<string, mixed>>> $rowsBySchema      Seed rows keyed by schema.
     * @param bool                                              $shillinqThrows    Whether JournalEntry access throws.
     * @param bool                                              $shillinqInstalled Whether IAppManager::isInstalled('shillinq') returns true.
     *
     * @return array{0: PayrollGLPostService, 1: object}
     */
    private function service(array $rowsBySchema=[], bool $shillinqThrows=false, bool $shillinqInstalled=true): array
    {
        $fake = $this->fakeObjectService($rowsBySchema, $shillinqThrows);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn($shillinqInstalled);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('hrmq');
        $settings->method('getGlPostAccountGross')->willReturn('4001');
        $settings->method('getGlPostAccountEmployerCharges')->willReturn('4002');
        $settings->method('getGlPostAccountWageTaxLiability')->willReturn('1701');
        $settings->method('getGlPostAccountNetWagesLiability')->willReturn('1702');

        $logger = $this->createMock(LoggerInterface::class);

        return [new PayrollGLPostService($container, $appManager, $settings, $logger), $fake];

    }//end service()


    /**
     * The seeded 2026-05 approved-run fixture (design.md's worked example
     * totals), overridable per test.
     *
     * @param array<string, mixed> $overrides Fields to override.
     *
     * @return array<string, mixed>
     */
    private function payrollRun(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'                   => 'run-1',
                'period'               => '2026-05',
                'administrationId'     => 'ADM-001',
                'status'               => 'approved',
                'totalGross'           => 3800.00,
                'totalEmployerCharges' => 649.80,
                'totalLoonheffing'     => 1102.00,
                'totalNet'             => 2698.00,
            ],
            $overrides
        );

    }//end payrollRun()


    /**
     * Objects saved to a given schema, in save order.
     *
     * @param object $fake   The fake ObjectService.
     * @param string $schema The schema name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function savedFor(object $fake, string $schema): array
    {
        $out = [];
        foreach ($fake->saved as $entry) {
            if ($entry['schema'] === $schema) {
                $out[] = $entry['object'];
            }
        }

        return $out;

    }//end savedFor()


    /**
     * @return void
     */
    public function testBuildLinesProducesTheBalancedEntryFromDesignsWorkedExample(): void
    {
        [$service] = $this->service();
        $built = $service->buildLines($this->payrollRun());

        $this->assertNull($built['error']);
        $this->assertCount(4, $built['lines']);

        $bySide = [];
        foreach ($built['lines'] as $line) {
            $bySide[$line['accountNumber']] = $line['amount'];
        }

        $this->assertEqualsWithDelta(3800.00, $bySide['4001'], 0.001);
        $this->assertEqualsWithDelta(649.80, $bySide['4002'], 0.001);
        $this->assertEqualsWithDelta(1102.00, $bySide['1701'], 0.001);
        $this->assertEqualsWithDelta(3347.80, $bySide['1702'], 0.001);

        $debitTotal  = array_sum(array_map(static fn(array $l): float => ($l['side'] === 'debit' ? $l['amount'] : 0.0), $built['lines']));
        $creditTotal = array_sum(array_map(static fn(array $l): float => ($l['side'] === 'credit' ? $l['amount'] : 0.0), $built['lines']));
        $this->assertEqualsWithDelta($debitTotal, $creditTotal, 0.001);
        $this->assertEqualsWithDelta(4449.80, $debitTotal, 0.001);

        $this->assertEqualsWithDelta(4449.80, $built['glExpensePosted'], 0.001);
        $this->assertEqualsWithDelta(1751.80, $built['glLiabilityPosted'], 0.001);

    }//end testBuildLinesProducesTheBalancedEntryFromDesignsWorkedExample()


    /**
     * @return void
     */
    public function testBuildLinesFailsClosedOnNegativeRemainder(): void
    {
        [$service] = $this->service();
        $built = $service->buildLines($this->payrollRun(['totalLoonheffing' => 5000.00]));

        $this->assertNotNull($built['error']);
        $this->assertSame([], $built['lines']);
        $this->assertNull($built['glExpensePosted']);

    }//end testBuildLinesFailsClosedOnNegativeRemainder()


    /**
     * @return void
     */
    public function testBuildLinesFailsClosedOnMissingTotal(): void
    {
        [$service] = $this->service();
        $run = $this->payrollRun();
        unset($run['totalNet']);

        $built = $service->buildLines($run);

        $this->assertNotNull($built['error']);
        $this->assertSame([], $built['lines']);

    }//end testBuildLinesFailsClosedOnMissingTotal()


    /**
     * @return void
     */
    public function testBuildLinesFailsClosedOnNonNumericTotal(): void
    {
        [$service] = $this->service();
        $built = $service->buildLines($this->payrollRun(['totalGross' => 'oops']));

        $this->assertNotNull($built['error']);

    }//end testBuildLinesFailsClosedOnNonNumericTotal()


    /**
     * @return void
     */
    public function testBuildLinesDropsZeroAmountLines(): void
    {
        [$service] = $this->service();
        $built = $service->buildLines($this->payrollRun(['totalEmployerCharges' => 0.0]));

        $this->assertNull($built['error']);
        $this->assertNotContains('4002', array_column($built['lines'], 'accountNumber'));
        $this->assertCount(3, $built['lines']);

    }//end testBuildLinesDropsZeroAmountLines()


    /**
     * @return void
     */
    public function testPostRunPostsSuccessfullyAndUpdatesTheRun(): void
    {
        [$service, $fake] = $this->service();

        $result = $service->postRun($this->payrollRun());

        $this->assertSame('posted', $result['status']);
        $this->assertNotNull($result['journalEntryId']);

        $journalSaves = $this->savedFor($fake, 'JournalEntry');
        $this->assertCount(1, $journalSaves);
        $this->assertSame('HRMQ-LOON-2026-05-ADM-001', $journalSaves[0]['journalNumber']);
        $this->assertSame('draft', $journalSaves[0]['state']);
        $this->assertSame('manual', $journalSaves[0]['journalType']);
        $this->assertSame('ADM-001', $journalSaves[0]['administrationId']);

        $runSaves = $this->savedFor($fake, 'PayrollRun');
        $this->assertCount(1, $runSaves);
        $this->assertEqualsWithDelta(4449.80, $runSaves[0]['glExpensePosted'], 0.001);
        $this->assertEqualsWithDelta(1751.80, $runSaves[0]['glLiabilityPosted'], 0.001);
        $this->assertSame('posted', $runSaves[0]['status']);

        $glPostSaves = $this->savedFor($fake, 'PayrollGLPost');
        $this->assertCount(1, $glPostSaves);
        $this->assertSame('posted', $glPostSaves[0]['status']);

    }//end testPostRunPostsSuccessfullyAndUpdatesTheRun()


    /**
     * @return void
     */
    public function testPostRunFailsClosedOnInconsistentTotalsWithoutTouchingShillinq(): void
    {
        [$service, $fake] = $this->service();

        $result = $service->postRun($this->payrollRun(['totalLoonheffing' => 5000.00]));

        $this->assertSame('failed', $result['status']);
        $this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));

        $glPostSaves = $this->savedFor($fake, 'PayrollGLPost');
        $this->assertCount(1, $glPostSaves);
        $this->assertSame('failed', $glPostSaves[0]['status']);
        $this->assertNotEmpty($glPostSaves[0]['errorMessage']);

    }//end testPostRunFailsClosedOnInconsistentTotalsWithoutTouchingShillinq()


    /**
     * @return void
     */
    public function testPostRunRecordsSkippedNoShillinqWhenNotInstalled(): void
    {
        [$service, $fake] = $this->service(shillinqInstalled: false);

        $result = $service->postRun($this->payrollRun());

        $this->assertSame('skipped-no-shillinq', $result['status']);
        $this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));

        $glPostSaves = $this->savedFor($fake, 'PayrollGLPost');
        $this->assertCount(1, $glPostSaves);
        $this->assertSame('skipped-no-shillinq', $glPostSaves[0]['status']);

        // The run stays approved (retryable) -- no PayrollRun write on the skip path.
        $this->assertCount(0, $this->savedFor($fake, 'PayrollRun'));

    }//end testPostRunRecordsSkippedNoShillinqWhenNotInstalled()


    /**
     * @return void
     */
    public function testPostRunRecordsSkippedNoShillinqWhenRegisterUnresolvable(): void
    {
        [$service, $fake] = $this->service(shillinqThrows: true);

        $result = $service->postRun($this->payrollRun());

        $this->assertSame('skipped-no-shillinq', $result['status']);
        $this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));

    }//end testPostRunRecordsSkippedNoShillinqWhenRegisterUnresolvable()


    /**
     * @return void
     */
    public function testPostRunIsIdempotentOnDoubleInvocation(): void
    {
        [$service, $fake] = $this->service();
        $run = $this->payrollRun();

        $first  = $service->postRun($run);
        $second = $service->postRun($run);

        $this->assertSame('posted', $first['status']);
        $this->assertSame('posted', $second['status']);
        $this->assertSame($first['journalEntryId'], $second['journalEntryId']);

        // Exactly one shillinq JournalEntry ever gets created, despite two invocations.
        $this->assertCount(1, $this->savedFor($fake, 'JournalEntry'));

    }//end testPostRunIsIdempotentOnDoubleInvocation()


    /**
     * @return void
     */
    public function testPostRunAdoptsExistingJournalEntryByNumberInsteadOfDuplicating(): void
    {
        $rows = [
            'JournalEntry' => [
                ['id' => 'je-existing', 'journalNumber' => 'HRMQ-LOON-2026-05-ADM-001'],
            ],
        ];
        [$service, $fake] = $this->service($rows);

        $result = $service->postRun($this->payrollRun());

        $this->assertSame('posted', $result['status']);
        $this->assertSame('je-existing', $result['journalEntryId']);
        $this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));

    }//end testPostRunAdoptsExistingJournalEntryByNumberInsteadOfDuplicating()


    /**
     * @return void
     */
    public function testStalePendingGlPostIsSupersededThenAFreshAttemptSucceeds(): void
    {
        $rows = [
            'PayrollGLPost' => [
                ['id' => 'gp-1', 'payrollRunId' => 'run-1', 'period' => '2026-05', 'status' => 'pending'],
            ],
        ];
        [$service, $fake] = $this->service($rows);

        $result = $service->postRun($this->payrollRun());

        $this->assertSame('posted', $result['status']);

        $glPostSaves = $this->savedFor($fake, 'PayrollGLPost');
        $statuses    = array_column($glPostSaves, 'status');
        $this->assertContains('failed', $statuses);
        $this->assertContains('posted', $statuses);

    }//end testStalePendingGlPostIsSupersededThenAFreshAttemptSucceeds()


    /**
     * @return void
     */
    public function testAlreadyPostedRunIsANoOp(): void
    {
        $rows = [
            'PayrollGLPost' => [
                ['id' => 'gp-1', 'payrollRunId' => 'run-1', 'period' => '2026-05', 'status' => 'posted', 'journalEntryId' => 'je-1'],
            ],
        ];
        [$service, $fake] = $this->service($rows);

        $result = $service->postRun($this->payrollRun());

        $this->assertSame('posted', $result['status']);
        $this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));
        $this->assertCount(0, $this->savedFor($fake, 'PayrollGLPost'));
        $this->assertCount(0, $this->savedFor($fake, 'PayrollRun'));

    }//end testAlreadyPostedRunIsANoOp()


    /**
     * @return void
     */
    public function testSkippedNoShillinqIsSupersededByASuccessfulRetryOnceShillinqIsInstalled(): void
    {
        // First invocation: shillinq absent -> skipped-no-shillinq, run stays approved.
        $rowsBySchema = [];
        [$serviceWithoutShillinq, $fakeWithoutShillinq] = $this->service($rowsBySchema, false, false);
        $first = $serviceWithoutShillinq->postRun($this->payrollRun());

        $this->assertSame('skipped-no-shillinq', $first['status']);

        // Second invocation, same PayrollGLPost history carried over: shillinq is now
        // installed, so the retry supersedes the skip and posts successfully
        // (design.md D6/D7 -- a skip must not become permanent).
        $rowsBySchema = ['PayrollGLPost' => $this->savedFor($fakeWithoutShillinq, 'PayrollGLPost')];
        [$serviceWithShillinq, $fakeWithShillinq] = $this->service($rowsBySchema, false, true);
        $second = $serviceWithShillinq->postRun($this->payrollRun());

        $this->assertSame('posted', $second['status']);
        $this->assertCount(1, $this->savedFor($fakeWithShillinq, 'JournalEntry'));

    }//end testSkippedNoShillinqIsSupersededByASuccessfulRetryOnceShillinqIsInstalled()


    /**
     * @return void
     */
    public function testPostApprovedRunsSelectsOnlyApprovedRunsForTheGivenPeriod(): void
    {
        $rows = [
            'PayrollRun' => [
                $this->payrollRun(['id' => 'run-a', 'period' => '2026-04']),
                $this->payrollRun(['id' => 'run-b', 'period' => '2026-05']),
                $this->payrollRun(['id' => 'run-c', 'period' => '2026-05', 'status' => 'draft']),
            ],
        ];
        [$service] = $this->service($rows);

        $results = $service->postApprovedRuns('2026-05');

        $this->assertCount(1, $results);
        $this->assertSame('run-b', $results[0]['runId']);

    }//end testPostApprovedRunsSelectsOnlyApprovedRunsForTheGivenPeriod()


}//end class
