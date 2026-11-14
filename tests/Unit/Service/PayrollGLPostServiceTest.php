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

use OCA\Humaniq\Service\PayrollBookkeepingSettings;
use OCA\Humaniq\Service\PayrollGLPostService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\Humaniq\Service\Connection\ConnectionReporter;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Humaniq\Tests\Unit\Service\Support\InMemoryLocks;
use OCP\Lock\ILockingProvider;

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
			 * Schemas whose filtered lookups fail; the availability probe is unfiltered.
			 *
			 * @var array<int, string>
			 */
			public array $lookupFails = [];

			/**
			 * Schemas whose save throws, as a store that refuses the write.
			 *
			 * @var array<int, string>
			 */
			public array $saveFails = [];

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

				if (isset($options['filters']) === true && in_array($this->schema, $this->lookupFails, true) === true) {
					throw new \RuntimeException('lookup failed');
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

				if (in_array($targetSchema, $this->saveFails, true) === true) {
					throw new \RuntimeException('shillinq refused the write');
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
	private function service(array $rowsBySchema = [], bool $shillinqThrows = false, bool $shillinqInstalled = true, ?ConnectionReporter $connections = null): array {
		$fake = $this->fakeObjectService($rowsBySchema, $shillinqThrows);

		$container = $this->createMock(ContainerInterface::class);
		$locks = new InMemoryLocks();
		$container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
			'OCA\OpenRegister\Service\ObjectService' => $fake,
			ILockingProvider::class => $locks,
		});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($shillinqInstalled);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		return [new PayrollGLPostService($container, $appManager, $settings, new PayrollBookkeepingSettings($this->createMock(IAppConfig::class)), $logger, $connections), $fake];
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
		$descriptions = [];
		foreach ($built['lines'] as $line) {
			$bySide[$line['accountNumber']] = $line['amount'];
			$descriptions[$line['accountNumber']] = $line['description'];
		}

		$this->assertSame('Loonkosten bruto', $descriptions['4001']);
		$this->assertSame('Werkgeverslasten sociale premies', $descriptions['4002']);
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

		// The run is claimed with a pending GL post before shillinq is
		// written, and that same GL post is then settled as posted.
		$glPostSaves = $this->savedFor($fake, 'PayrollGLPost');
		$this->assertCount(2, $glPostSaves);
		$this->assertSame('pending', $glPostSaves[0]['status']);
		$this->assertSame('posted', $glPostSaves[1]['status']);
		$this->assertSame($glPostSaves[0]['id'], $glPostSaves[1]['id']);

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
	 * Runs whose totals cannot make a journal: one negative total, or
	 * nothing on the debit side.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function unpostableTotals(): array {
		return [
			'negative gross and net' => [['totalGross' => -100.0, 'totalEmployerCharges' => 0.0, 'totalLoonheffing' => 0.0, 'totalNet' => -100.0], 'Negatief runtotaal: PayrollRun-veld "totalGross" is -100.00; een loonjournaalpost boekt geen negatieve bedragen.'],
			'negative employer charges' => [['totalEmployerCharges' => -0.01], 'Negatief runtotaal: PayrollRun-veld "totalEmployerCharges" is -0.01; een loonjournaalpost boekt geen negatieve bedragen.'],
			'negative wage tax' => [['totalLoonheffing' => -50.0], 'Negatief runtotaal: PayrollRun-veld "totalLoonheffing" is -50.00; een loonjournaalpost boekt geen negatieve bedragen.'],
			'negative net' => [['totalLoonheffing' => 1500.0, 'totalNet' => -50.0], 'Negatief runtotaal: PayrollRun-veld "totalNet" is -50.00; een loonjournaalpost boekt geen negatieve bedragen.'],
			'negative net as text' => [['totalNet' => '-12.5'], 'Negatief runtotaal: PayrollRun-veld "totalNet" is -12.50; een loonjournaalpost boekt geen negatieve bedragen.'],
			'negative reimbursements' => [['totalReimbursements' => -25.0], 'Negatief runtotaal: PayrollRun-veld "totalReimbursements" is -25.00; een loonjournaalpost boekt geen negatieve bedragen.'],
			'a missing total before a negative one' => [['totalGross' => -100.0, 'totalNet' => null], 'PayrollRun-veld "totalNet" ontbreekt of is niet numeriek.'],
			'all totals zero' => [['totalGross' => 0, 'totalEmployerCharges' => 0.0, 'totalLoonheffing' => '0', 'totalNet' => 0.0], 'Runtotalen zijn nul: bruto, werkgeverslasten en declaraties samen 0,00; er is geen loonjournaalpost te boeken.'],
			'totals that round to zero' => [['totalGross' => 0.004, 'totalEmployerCharges' => 0.0, 'totalLoonheffing' => 0.0, 'totalNet' => 0.0, 'totalReimbursements' => 0.0], 'Runtotalen zijn nul: bruto, werkgeverslasten en declaraties samen 0,00; er is geen loonjournaalpost te boeken.'],
		];

	}//end unpostableTotals()

	/**
	 * @param array<string, mixed> $totals  The run totals.
	 * @param string               $message The refusal.
	 *
	 * @dataProvider unpostableTotals
	 *
	 * @return void
	 */
	public function testBuildLinesRefusesTotalsThatCannotMakeAJournal(array $totals, string $message): void {
		[$service] = $this->service();
		$built = $service->buildLines($this->payrollRun($totals));

		$this->assertSame(['lines' => [], 'error' => $message, 'glExpensePosted' => null, 'glLiabilityPosted' => null], $built);

	}//end testBuildLinesRefusesTotalsThatCannotMakeAJournal()

	/**
	 * @param array<string, mixed> $totals  The run totals.
	 * @param string               $message The refusal.
	 *
	 * @dataProvider unpostableTotals
	 *
	 * @return void
	 */
	public function testPostRunRefusesTotalsThatCannotMakeAJournalAndLeavesTheRunApproved(array $totals, string $message): void {
		[$service, $fake] = $this->service(['PayrollRun' => [$this->payrollRun($totals)]]);

		$result = $service->postRun($this->payrollRun($totals));

		$this->assertSame('failed', $result['status']);
		$this->assertSame($message, $result['message']);
		$this->assertNull($result['journalEntryId']);
		$this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));
		$this->assertCount(0, $this->savedFor($fake, 'PayrollRun'));
		$this->assertSame([['payrollRunId' => 'run-1', 'period' => '2026-05', 'status' => 'failed', 'errorMessage' => $message, 'id' => 'generated-PayrollGLPost-1']], $this->savedFor($fake, 'PayrollGLPost'));

	}//end testPostRunRefusesTotalsThatCannotMakeAJournalAndLeavesTheRunApproved()

	/**
	 * Zero is refused for the journal as a whole, not per total: a run with
	 * no wage tax, no employer charges, or only reimbursements still posts.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, float>}>
	 */
	public static function postableEdgeTotals(): array {
		return [
			'no wage tax' => [['totalLoonheffing' => 0.0, 'totalNet' => 3800.0], ['4001' => 3800.0, '4002' => 649.8, '1702' => 4449.8]],
			'no employer charges' => [['totalEmployerCharges' => 0.0], ['4001' => 3800.0, '1701' => 1102.0, '1702' => 2698.0]],
			'only reimbursements' => [['totalGross' => 0.0, 'totalEmployerCharges' => 0.0, 'totalLoonheffing' => 0.0, 'totalNet' => 50.0, 'totalReimbursements' => 50.0], ['4010' => 50.0, '1702' => 50.0]],
			'only employer charges' => [['totalGross' => 0.0, 'totalEmployerCharges' => 10.0, 'totalLoonheffing' => 0.0, 'totalNet' => 0.0], ['4002' => 10.0, '1702' => 10.0]],
			'one cent of gross' => [['totalGross' => 0.01, 'totalEmployerCharges' => 0.0, 'totalLoonheffing' => 0.0, 'totalNet' => 0.01], ['4001' => 0.01, '1702' => 0.01]],
			'negative zero' => [['totalEmployerCharges' => -0.0, 'totalReimbursements' => -0.004], ['4001' => 3800.0, '1701' => 1102.0, '1702' => 2698.0]],
		];

	}//end postableEdgeTotals()

	/**
	 * @param array<string, mixed> $totals   The run totals.
	 * @param array<string, float> $expected Amount per account.
	 *
	 * @dataProvider postableEdgeTotals
	 *
	 * @return void
	 */
	public function testBuildLinesStillPostsZeroTotalsInsideAJournalWithADebit(array $totals, array $expected): void {
		[$service] = $this->service();
		$built = $service->buildLines($this->payrollRun($totals));

		$this->assertNull($built['error']);
		$this->assertSame($expected, array_column($built['lines'], 'amount', 'accountNumber'));

	}//end testBuildLinesStillPostsZeroTotalsInsideAJournalWithADebit()

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
		[$builder] = $this->service();
		$rows = [
			'JournalEntry' => [
				['id' => 'je-existing', 'journalNumber' => 'HRMQ-LOON-2026-05-ADM-001', 'lines' => $builder->buildLines($this->payrollRun())['lines']],
			],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->postRun($this->payrollRun());

		$this->assertSame('posted', $result['status']);
		$this->assertSame('je-existing', $result['journalEntryId']);
		$this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));

	}//end testPostRunAdoptsExistingJournalEntryByNumberInsteadOfDuplicating()

	/**
	 * An existing JournalEntry with other totals is not adopted.
	 *
	 * @return void
	 */
	public function testAJournalEntryWithOtherTotalsIsNotAdopted(): void {
		[$builder] = $this->service();
		$lines = $builder->buildLines($this->payrollRun())['lines'];
		$lines[0]['amount'] = ((float)$lines[0]['amount'] + 1);
		[$service, $fake] = $this->service(['JournalEntry' => [['id' => 'je-other', 'journalNumber' => 'HRMQ-LOON-2026-05-ADM-001', 'lines' => $lines]]]);

		$result = $service->postRun($this->payrollRun());

		$this->assertSame('failed', $result['status']);
		$this->assertStringContainsString('niet overgenomen', (string)$result['message']);
		$this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));
	}//end testAJournalEntryWithOtherTotalsIsNotAdopted()

	/**
	 * @return void
	 */
	public function testAFailedJournalEntryLookupCreatesNoJournalEntry(): void {
		[$service, $fake] = $this->service();
		$fake->lookupFails = ['JournalEntry'];

		$result = $service->postRun($this->payrollRun());

		$this->assertSame('failed', $result['status']);
		$this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));
	}//end testAFailedJournalEntryLookupCreatesNoJournalEntry()

	/**
	 * @return void
	 */
	public function testAFailedJournalEntryLookupLeavesAStalePendingGlPostAlone(): void {
		[$service, $fake] = $this->service(['PayrollGLPost' => [['id' => 'gp-1', 'payrollRunId' => 'run-1', 'period' => '2026-05', 'status' => 'pending']]]);
		$fake->lookupFails = ['JournalEntry'];

		$result = $service->postRun($this->payrollRun());

		$this->assertSame('pending', $result['status']);
		$this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));
		$this->assertCount(0, $this->savedFor($fake, 'PayrollGLPost'));
	}//end testAFailedJournalEntryLookupLeavesAStalePendingGlPostAlone()

	/**
	 * @return void
	 */
	public function testAFailedGlPostLookupWritesNothing(): void {
		[$service, $fake] = $this->service();
		$fake->lookupFails = ['PayrollGLPost'];

		$result = $service->postRun($this->payrollRun());

		$this->assertSame('pending', $result['status']);
		$this->assertCount(0, $this->savedFor($fake, 'JournalEntry'));
		$this->assertCount(0, $this->savedFor($fake, 'PayrollGLPost'));
	}//end testAFailedGlPostLookupWritesNothing()

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
		// The fixture's run id is a readable name; the schema wants the uuid a live run has.
		$record['payrollRunId'] = '5b1d3f4e-0000-4000-8000-000000000001';
		self::assertSame([], RegisterSchemaValidator::errors('PayrollGLPost', $record));
		$glPost = RegisterSchemaValidator::schema('PayrollGLPost');
		self::assertArrayHasKey('costCenterCode', $glPost['properties']['lines']['items']['properties']);
		self::assertArrayHasKey('projectCode', $glPost['properties']['lines']['items']['properties']);
	}//end testThePostedJournalCarriesTheCodesAndFitsBothSchemas()


	/**
	 * A journal entry shillinq took reports the ledger as configured (platform-integrations-catalogue D2).
	 *
	 * @return void
	 */
	public function testAPostShillinqTookReportsTheLedgerConfigured(): void {
		$connections = $this->createMock(ConnectionReporter::class);
		$connections->expects($this->once())->method('report')
			->with('shillinq-ledger', 'configured', 'Shillinq took journal entry HRMQ-LOON-2026-05-ADM-001.')
			->willReturn(true);
		[$service] = $this->service(connections: $connections);

		$this->assertSame('posted', $service->postRun($this->payrollRun())['status']);
	}//end testAPostShillinqTookReportsTheLedgerConfigured()

	/**
	 * A journal entry shillinq refused reports the ledger as an error with the reason.
	 *
	 * @return void
	 */
	public function testAJournalEntryShillinqRefusedReportsTheLedgerError(): void {
		$connections = $this->createMock(ConnectionReporter::class);
		$connections->expects($this->once())->method('report')
			->with('shillinq-ledger', 'error', 'Shillinq refused the journal entry: shillinq refused the write')
			->willReturn(true);
		[$service, $fake] = $this->service(connections: $connections);
		$fake->saveFails = ['JournalEntry'];

		$this->assertSame('failed', $service->postRun($this->payrollRun())['status']);
	}//end testAJournalEntryShillinqRefusedReportsTheLedgerError()

	/**
	 * Without shillinq the ledger is reported unavailable.
	 *
	 * @return void
	 */
	public function testAMissingShillinqReportsTheLedgerUnavailable(): void {
		$connections = $this->createMock(ConnectionReporter::class);
		$connections->expects($this->once())->method('report')
			->with('shillinq-ledger', 'unavailable', 'Shillinq is not installed, or its journal entries cannot be reached.')
			->willReturn(true);
		[$service] = $this->service(shillinqInstalled: false, connections: $connections);

		$this->assertSame('skipped-no-shillinq', $service->postRun($this->payrollRun())['status']);
	}//end testAMissingShillinqReportsTheLedgerUnavailable()

	/**
	 * A run humaniq itself refuses never reached shillinq, so it says nothing about the ledger.
	 *
	 * @return void
	 */
	public function testARunHumaniqRefusesReportsNothing(): void {
		$connections = $this->createMock(ConnectionReporter::class);
		$connections->expects($this->never())->method('report');
		[$service] = $this->service(connections: $connections);

		$this->assertSame('failed', $service->postRun($this->payrollRun(['id' => '']))['status']);
	}//end testARunHumaniqRefusesReportsNothing()
}//end class
