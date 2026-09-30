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
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PayrollGLPostService.
 *
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md
 */
class PayrollGLPostServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double: `findAll()` returns the seeded rows
	 * for the current schema, `saveObject()` records every write (assignable
	 * to a generated id when no uuid is given) and reflects it back into the
	 * seeded rows so a subsequent idempotency probe within the same test sees
	 * it. Optionally throws on the `JournalEntry` schema to simulate shillinq
	 * being unavailable (design.md D7).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param bool $shillinqThrows Whether JournalEntry access throws.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $rowsBySchema = [], bool $shillinqThrows = false): object {
		return new class($rowsBySchema, $shillinqThrows) {
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
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 * @param bool $shillinqThrows Whether JournalEntry access throws.
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
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
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
			public function findAll(array $options = []): array {
				if ($this->schema === 'JournalEntry' && $this->shillinqThrows === true) {
					throw new \RuntimeException('shillinq register unavailable');
				}

				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The object to save.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param string|null $uuid Existing id when updating.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<string, mixed> The saved object (with its id).
			 *
			 * @throws \RuntimeException When simulating shillinq unavailability.
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$targetSchema = ($schema ?? $this->schema);
				if ($targetSchema === 'JournalEntry' && $this->shillinqThrows === true) {
					throw new \RuntimeException('shillinq register unavailable');
				}

				$id = ($uuid ?? ('generated-' . $targetSchema . '-' . $this->nextId++));
				$saved = array_merge($object, ['id' => $id]);

				$this->saved[] = ['schema' => $targetSchema, 'object' => $saved];

				$rows = ($this->rowsBySchema[$targetSchema] ?? []);
				$replaced = false;
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $id) {
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
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param bool $shillinqThrows Whether JournalEntry access throws.
	 * @param bool $shillinqInstalled Whether IAppManager::isInstalled('shillinq') returns true.
	 *
	 * @return array{0: PayrollGLPostService, 1: object}
	 */
	private function service(array $rowsBySchema = [], bool $shillinqThrows = false, bool $shillinqInstalled = true): array {
		$fake = $this->fakeObjectService($rowsBySchema, $shillinqThrows);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($shillinqInstalled);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
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
	private function payrollRun(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'run-1',
				'period' => '2026-05',
				'administrationId' => 'ADM-001',
				'status' => 'approved',
				'totalGross' => 3800.00,
				'totalEmployerCharges' => 649.80,
				'totalLoonheffing' => 1102.00,
				'totalNet' => 2698.00,
			],
			$overrides
		);

	}//end payrollRun()

	/**
	 * Objects saved to a given schema, in save order.
	 *
	 * @param object $fake The fake ObjectService.
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedFor(object $fake, string $schema): array {
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
	public function testBuildLinesProducesTheBalancedEntryFromDesignsWorkedExample(): void {
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

		$debitTotal = array_sum(array_map(static fn (array $l): float => ($l['side'] === 'debit' ? $l['amount'] : 0.0), $built['lines']));
		$creditTotal = array_sum(array_map(static fn (array $l): float => ($l['side'] === 'credit' ? $l['amount'] : 0.0), $built['lines']));
		$this->assertEqualsWithDelta($debitTotal, $creditTotal, 0.001);
		$this->assertEqualsWithDelta(4449.80, $debitTotal, 0.001);

		$this->assertEqualsWithDelta(4449.80, $built['glExpensePosted'], 0.001);
		$this->assertEqualsWithDelta(1751.80, $built['glLiabilityPosted'], 0.001);

	}//end testBuildLinesProducesTheBalancedEntryFromDesignsWorkedExample()

	/**
	 * @return void
	 */
	public function testBuildLinesFailsClosedOnNegativeRemainder(): void {
		[$service] = $this->service();
		$built = $service->buildLines($this->payrollRun(['totalLoonheffing' => 5000.00]));

		$this->assertNotNull($built['error']);
		$this->assertSame([], $built['lines']);
		$this->assertNull($built['glExpensePosted']);

	}//end testBuildLinesFailsClosedOnNegativeRemainder()

	/**
	 * @return void
	 */
	public function testBuildLinesFailsClosedOnMissingTotal(): void {
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
	public function testBuildLinesFailsClosedOnNonNumericTotal(): void {
		[$service] = $this->service();
		$built = $service->buildLines($this->payrollRun(['totalGross' => 'oops']));

		$this->assertNotNull($built['error']);

	}//end testBuildLinesFailsClosedOnNonNumericTotal()

	/**
	 * @return void
	 */
	public function testBuildLinesDropsZeroAmountLines(): void {
		[$service] = $this->service();
		$built = $service->buildLines($this->payrollRun(['totalEmployerCharges' => 0.0]));

		$this->assertNull($built['error']);
		$this->assertNotContains('4002', array_column($built['lines'], 'accountNumber'));
		$this->assertCount(3, $built['lines']);

	}//end testBuildLinesDropsZeroAmountLines()

	/**
	 * @return void
	 */
	public function testPostRunPostsSuccessfullyAndUpdatesTheRun(): void {
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
	 * The journal names humaniq as its sub-ledger, so shillinq's payroll
	 * control-account role lets it post (humaniq#549, shillinq#1776), and the
	 * exact payload passes shillinq's real JournalEntry schema.
	 *
	 * @return void
	 */
	public function testThePayrollJournalNamesHumaniqAsItsSourceLedgerAndFitsShillinqsSchema(): void {
		[$service, $fake] = $this->service();

		$service->postRun($this->payrollRun());

		$journalSaves = $this->savedFor($fake, 'JournalEntry');
		$this->assertCount(1, $journalSaves);
		$this->assertSame('humaniq', ($journalSaves[0]['sourceApp'] ?? null));

		$fixture = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/shillinq/journal-entry-schema.json'), true);
		$schema = $fixture['JournalEntry'];
		$this->assertContains('humaniq', $schema['properties']['sourceApp']['enum']);
		$this->assertSame([], RegisterSchemaValidator::errorsAgainst($schema, $journalSaves[0]));

	}//end testThePayrollJournalNamesHumaniqAsItsSourceLedgerAndFitsShillinqsSchema()

	/**
	 * @return void
	 */
	public function testPostRunFailsClosedOnInconsistentTotalsWithoutTouchingShillinq(): void {
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
	public function testPostRunRecordsSkippedNoShillinqWhenNotInstalled(): void {
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
	public function testPostRunRecordsSkippedNoShillinqWhenRegisterUnresolvable(): void {
		[$service, $fake] = $this->service(shillinqThrows: true);

		$result = $service->postRun($this->payrollRun());

		$this->assertSame('skipped-no-shillinq', $result['status']);
		$this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));

	}//end testPostRunRecordsSkippedNoShillinqWhenRegisterUnresolvable()

	/**
	 * @return void
	 */
	public function testPostRunIsIdempotentOnDoubleInvocation(): void {
		[$service, $fake] = $this->service();
		$run = $this->payrollRun();

		$first = $service->postRun($run);
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
	public function testPostRunAdoptsExistingJournalEntryByNumberInsteadOfDuplicating(): void {
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
	public function testStalePendingGlPostIsSupersededThenAFreshAttemptSucceeds(): void {
		$rows = [
			'PayrollGLPost' => [
				['id' => 'gp-1', 'payrollRunId' => 'run-1', 'period' => '2026-05', 'status' => 'pending'],
			],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->postRun($this->payrollRun());

		$this->assertSame('posted', $result['status']);

		$glPostSaves = $this->savedFor($fake, 'PayrollGLPost');
		$statuses = array_column($glPostSaves, 'status');
		$this->assertContains('failed', $statuses);
		$this->assertContains('posted', $statuses);

	}//end testStalePendingGlPostIsSupersededThenAFreshAttemptSucceeds()

	/**
	 * @return void
	 */
	public function testAlreadyPostedRunIsANoOp(): void {
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
	public function testSkippedNoShillinqIsSupersededByASuccessfulRetryOnceShillinqIsInstalled(): void {
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
	public function testPostApprovedRunsSelectsOnlyApprovedRunsForTheGivenPeriod(): void {
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

	/**
	 * The run's allocation lines, for the cost-allocation journal tests:
	 * 3800.00 gross and 649.80 charges over CC-100 and CC-200/PRJ-7.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function allocationRows(): array {
		return [
			['id' => 'wca-1', 'payrollRunId' => 'run-1', 'payslipId' => 'slip-1', 'costCenter' => 'CC-100', 'projectId' => null, 'gross' => 2280.00, 'employerCharges' => 389.88, 'totalCost' => 2669.88],
			['id' => 'wca-2', 'payrollRunId' => 'run-1', 'payslipId' => 'slip-1', 'costCenter' => 'CC-200', 'projectId' => 'PRJ-7', 'gross' => 1520.00, 'employerCharges' => 259.92, 'totalCost' => 1779.92],
			['id' => 'wca-other', 'payrollRunId' => 'run-other', 'payslipId' => 'slip-9', 'costCenter' => 'CC-900', 'projectId' => null, 'gross' => 999.00, 'employerCharges' => 1.00, 'totalCost' => 1000.00],
		];
	}//end allocationRows()

	/**
	 * payroll-cost-allocation REQ-PCA-002: a run allocated over two cost
	 * centres books a gross and an employer-charges debit line for each,
	 * with its codes; the liability lines stay totals and the journal
	 * balances. Another run's lines are not read.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-002
	 */
	public function testTheJournalCarriesTwoCostCentres(): void {
		[$service] = $this->service();
		$built = $service->buildLines($this->payrollRun(), $this->allocationRows());

		self::assertNull($built['error']);
		$debits = array_values(array_filter($built['lines'], static fn (array $l): bool => $l['side'] === 'debit'));
		self::assertCount(4, $debits);
		self::assertSame(
			[['4001', 2280.0, 'CC-100', null], ['4001', 1520.0, 'CC-200', 'PRJ-7'], ['4002', 389.88, 'CC-100', null], ['4002', 259.92, 'CC-200', 'PRJ-7']],
			array_map(static fn (array $l): array => [$l['accountNumber'], $l['amount'], $l['costCenterCode'] ?? null, $l['projectCode'] ?? null], $debits)
		);
		self::assertArrayNotHasKey('projectCode', $debits[0]);

		$credits = array_values(array_filter($built['lines'], static fn (array $l): bool => $l['side'] === 'credit'));
		self::assertCount(2, $credits);
		self::assertArrayNotHasKey('costCenterCode', $credits[0]);
		self::assertEqualsWithDelta(array_sum(array_column($debits, 'amount')), array_sum(array_column($credits, 'amount')), 0.001);
		self::assertEqualsWithDelta(4449.80, $built['glExpensePosted'], 0.001);
	}//end testTheJournalCarriesTwoCostCentres()

	/**
	 * Allocation lines that do not cover the run's totals (a payslip
	 * without lines) leave the rest as one line without codes, so the
	 * journal still balances.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-002
	 */
	public function testAPartlyAllocatedRunKeepsTheRestUncoded(): void {
		[$service] = $this->service();
		$built = $service->buildLines($this->payrollRun(), [$this->allocationRows()[0]]);

		$gross = array_values(array_filter($built['lines'], static fn (array $l): bool => $l['accountNumber'] === '4001'));
		self::assertSame([2280.0, 1520.0], array_column($gross, 'amount'));
		self::assertArrayNotHasKey('costCenterCode', $gross[1]);
		$debit = array_sum(array_map(static fn (array $l): float => ($l['side'] === 'debit' ? $l['amount'] : 0.0), $built['lines']));
		$credit = array_sum(array_map(static fn (array $l): float => ($l['side'] === 'credit' ? $l['amount'] : 0.0), $built['lines']));
		self::assertEqualsWithDelta($debit, $credit, 0.001);
	}//end testAPartlyAllocatedRunKeepsTheRestUncoded()

	/**
	 * Posting reads the run's allocation lines, and the posted journal and
	 * its humaniq record both fit their real schemas with the codes on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-002
	 */
	public function testThePostedJournalCarriesTheCodesAndFitsBothSchemas(): void {
		[$service, $fake] = $this->service(['WageCostAllocation' => $this->allocationRows()]);

		$service->postRun($this->payrollRun());

		$journal = $this->savedFor($fake, 'JournalEntry')[0];
		self::assertContains('CC-200', array_column($journal['lines'], 'costCenterCode'));
		self::assertContains('PRJ-7', array_column($journal['lines'], 'projectCode'));
		self::assertNotContains('CC-900', array_column($journal['lines'], 'costCenterCode'));
		$fixture = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/shillinq/journal-entry-schema.json'), true);
		self::assertSame([], RegisterSchemaValidator::errorsAgainst($fixture['JournalEntry'], $journal));

		$posts = $this->savedFor($fake, 'PayrollGLPost');
		$record = end($posts);
		unset($record['id']);
		self::assertSame([], RegisterSchemaValidator::errors('PayrollGLPost', $record));
		$glPost = RegisterSchemaValidator::schema('PayrollGLPost');
		self::assertArrayHasKey('costCenterCode', $glPost['properties']['lines']['items']['properties']);
		self::assertArrayHasKey('projectCode', $glPost['properties']['lines']['items']['properties']);
	}//end testThePostedJournalCarriesTheCodesAndFitsBothSchemas()

}//end class
