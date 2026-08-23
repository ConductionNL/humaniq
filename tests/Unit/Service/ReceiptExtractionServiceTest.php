<?php

/**
 * Unit tests for ReceiptExtractionService.
 *
 * Pins the receipt-ocr contract: the corrected docudesk `fields` key mapping
 * (REQ-RCPT-002, see class docblock on ReceiptExtractionService for the real
 * `FinancialExtractionService` contract), prefill-not-overwrite per D4 (each
 * of the four target fields, both empty and already-filled cases), the D5
 * human-in-the-loop guarantee that `status` is never part of the Expense save
 * payload, the D2 duck-typed `skipped-no-docudesk` degradation, the D3
 * idempotency pre-check (double invocation, stale-pending supersession), and
 * the missing-receiptFile fail-closed path. Drives the service through fake
 * ObjectService/FinancialExtractionService doubles (fake collaborators, not
 * fakes of the service logic under test) since the real OpenRegister/docudesk
 * services are sibling-app dependencies not available in this standalone
 * suite -- mirrors the HrDocumentServiceTest pattern.
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
 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\ReceiptExtractionRepository;
use OCA\Humaniq\Service\ReceiptExtractionService;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReceiptExtractionService.
 *
 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md
 */
class ReceiptExtractionServiceTest extends TestCase {

	/**
	 * Build a fake ObjectService double: `findAll()` returns the seeded rows
	 * for the current schema, `saveObject()`/`find()` record every write and
	 * reflect it back into the seeded rows so a subsequent idempotency probe
	 * within the same test sees it.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $rowsBySchema = []): object {
		return new class($rowsBySchema) {
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
			 */
			public function __construct(
				private array $rowsBySchema,
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
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param string $id The object id.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): ?array {
				foreach (($this->rowsBySchema[$schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * @param array<string, mixed> $object The object to save.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name.
			 * @param string|null $uuid Existing id when updating.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<string, mixed> The saved object (with its id).
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
	 * Build a fake docudesk FinancialExtractionService double. Every call's
	 * args are captured on the returned object's public `calls` property.
	 *
	 * @param bool $throws Whether extractFinancial() throws.
	 * @param array<string, mixed>|null $fields The `fields` sub-array of the returned result (real docudesk key names).
	 * @param float|null $overallConfidence The `overallConfidence` of the returned result.
	 *
	 * @return object
	 */
	private function fakeFinancialExtractionService(
		bool $throws = false,
		?array $fields = null,
		?float $overallConfidence = 0.9,
	): object {
		// Default to the standard extractedFields() fixture -- a caller only
		// passes $fields explicitly to test a DIFFERENT extraction result;
		// $fields=null must still mean "the usual fixture", not "empty".
		$resolvedFields = ($fields ?? $this->extractedFields());

		return new class($throws, $resolvedFields, $overallConfidence) {
			/**
			 * Every extractFinancial() call, as `['data' => ..., 'requestedBy' => ...]`.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param bool $throws Whether extractFinancial() throws.
			 * @param array<string, mixed>|null $fields The returned `fields` sub-array.
			 * @param float|null $overallConfidence The returned `overallConfidence`.
			 */
			public function __construct(
				private readonly bool $throws,
				private readonly ?array $fields,
				private readonly ?float $overallConfidence,
			) {

			}//end __construct()

			/**
			 * @param array<string, mixed> $data Request body: fileId|documentUri, docType.
			 * @param string $requestedBy Nextcloud user id.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws \RuntimeException When simulating a docudesk extraction failure.
			 */
			public function extractFinancial(array $data, string $requestedBy): array {
				$this->calls[] = ['data' => $data, 'requestedBy' => $requestedBy];

				if ($this->throws === true) {
					throw new \RuntimeException('docudesk extraction failed');
				}

				return [
					'fields' => ($this->fields ?? []),
					'fieldConfidence' => [],
					'overallConfidence' => $this->overallConfidence,
					'corrections' => [],
				];

			}//end extractFinancial()

		};

	}//end fakeFinancialExtractionService()

	/**
	 * Build a fully-wired ReceiptExtractionService plus its fake ObjectService.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 * @param bool $docudeskInstalled Whether IAppManager::isInstalled('docudesk') returns true.
	 * @param object|null $financialExtraction A fake FinancialExtractionService, or null for the default success double.
	 *
	 * @return array{0: ReceiptExtractionService, 1: object, 2: object}
	 */
	private function service(
		array $rowsBySchema = [],
		bool $docudeskInstalled = true,
		?object $financialExtraction = null,
	): array {
		$fakeObjects = $this->fakeObjectService($rowsBySchema);
		$fakeFin = $financialExtraction ?? $this->fakeFinancialExtractionService();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				['OCA\OpenRegister\Service\ObjectService', $fakeObjects],
				['OCA\DocuDesk\Service\FinancialExtractionService', $fakeFin],
			]
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($docudeskInstalled);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		$repository = new ReceiptExtractionRepository($container, $settings, $logger);

		return [new ReceiptExtractionService($container, $appManager, $repository), $fakeObjects, $fakeFin];
	}//end service()

	/**
	 * The seeded Expense fixture: every prefillable field EMPTY per D4's
	 * definition, with a receiptFile set.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function emptyExpense(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'expense-1',
				'employeeId' => 'emp-1',
				'title' => 'Werklunch',
				'amount' => 0,
				'currency' => 'EUR',
				'expenseDate' => null,
				'receiptFile' => 'receipt-1.pdf',
				'vendor' => null,
				'vatAmount' => null,
				'status' => 'draft',
			],
			$overrides
		);

	}//end emptyExpense()

	/**
	 * The docudesk `fields` sub-array using the REAL
	 * `FinancialExtractionService` key names (totalIncl/issueDate/
	 * supplierName/totalVat), NOT the design.md-assumed amount/date/vendor/
	 * vatAmount names -- this is the corrected contract this suite pins.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function extractedFields(array $overrides = []): array {
		return array_merge(
			[
				'totalIncl' => 32.75,
				'issueDate' => '2026-06-11',
				'supplierName' => 'Grand Café De Kroon',
				'totalVat' => 6.02,
			],
			$overrides
		);

	}//end extractedFields()

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
	 * The last object saved to a given schema (the newest save).
	 *
	 * Assigns the list to a local first so `end()` receives a variable by
	 * reference rather than a function return value ("Only variables should be
	 * passed by reference").
	 *
	 * @param object $fake The fake ObjectService.
	 * @param string $schema The schema name.
	 *
	 * @return array<string, mixed>|false
	 */
	private function lastSavedFor(object $fake, string $schema) {
		$saves = $this->savedFor($fake, $schema);
		return end($saves);
	}//end lastSavedFor()

	// -- REQ-RCPT-002: mapping + prefill-not-overwrite -----------------------

	/**
	 * @return void
	 */
	public function testExtractionMapsTheCorrectedDocudeskFieldKeysOntoAllFourEmptyExpenseFields(): void {
		[$service, $fake] = $this->service(['Expense' => [$this->emptyExpense()]]);

		$result = $service->extractForExpense('expense-1', 'admin');

		$this->assertSame('extracted', $result['status']);

		$expenseSaves = $this->savedFor($fake, 'Expense');
		$final = end($expenseSaves);
		$this->assertSame(32.75, $final['amount']);
		$this->assertSame('2026-06-11', $final['expenseDate']);
		$this->assertSame('Grand Café De Kroon', $final['vendor']);
		$this->assertSame(6.02, $final['vatAmount']);

		$extractionSaves = $this->savedFor($fake, 'ReceiptExtraction');
		$extraction = end($extractionSaves);
		$this->assertSame('extracted', $extraction['status']);
		$this->assertSame(0.9, $extraction['overallConfidence']);
		$this->assertSame('amount,expenseDate,vendor,vatAmount', $extraction['appliedFields']);
		// Raw extracted values recorded regardless of application.
		$this->assertSame(32.75, $extraction['extractedAmount']);
		$this->assertSame('2026-06-11', $extraction['extractedDate']);
		$this->assertSame('Grand Café De Kroon', $extraction['extractedVendor']);
		$this->assertSame(6.02, $extraction['extractedVatAmount']);

	}//end testExtractionMapsTheCorrectedDocudeskFieldKeysOntoAllFourEmptyExpenseFields()

	/**
	 * A pre-filled field is NEVER overwritten by extraction, even when
	 * docudesk returns a different value for it -- the raw value is still
	 * recorded on ReceiptExtraction for audit.
	 *
	 * @return void
	 */
	public function testAPreFilledAmountIsNeverOverwrittenButItsRawExtractedValueIsStillRecorded(): void {
		$expense = $this->emptyExpense(['amount' => 45.50]);
		[$service, $fake] = $this->service(['Expense' => [$expense]]);

		$result = $service->extractForExpense('expense-1', 'admin');

		$this->assertSame('extracted', $result['status']);

		$expenseSaves = $this->savedFor($fake, 'Expense');
		$final = end($expenseSaves);
		// amount stays untouched -- NOT overwritten by the extracted 32.75.
		$this->assertSame(45.50, $final['amount']);
		// The other three empty fields ARE prefilled.
		$this->assertSame('2026-06-11', $final['expenseDate']);
		$this->assertSame('Grand Café De Kroon', $final['vendor']);
		$this->assertSame(6.02, $final['vatAmount']);

		$extractionSaves = $this->savedFor($fake, 'ReceiptExtraction');
		$extraction = end($extractionSaves);
		// appliedFields does NOT include amount.
		$this->assertSame('expenseDate,vendor,vatAmount', $extraction['appliedFields']);
		// But the raw extracted amount is still recorded for audit.
		$this->assertSame(32.75, $extraction['extractedAmount']);

	}//end testAPreFilledAmountIsNeverOverwrittenButItsRawExtractedValueIsStillRecorded()

	/**
	 * @return void
	 */
	public function testAPreFilledExpenseDateIsNeverOverwritten(): void {
		$expense = $this->emptyExpense(['expenseDate' => '2026-06-12']);
		[$service, $fake] = $this->service(['Expense' => [$expense]]);

		$service->extractForExpense('expense-1', 'admin');

		$final = $this->lastSavedFor($fake, 'Expense');
		$this->assertSame('2026-06-12', $final['expenseDate']);

		$extraction = $this->lastSavedFor($fake, 'ReceiptExtraction');
		$this->assertStringNotContainsString('expenseDate', $extraction['appliedFields']);
		$this->assertSame('2026-06-11', $extraction['extractedDate']);

	}//end testAPreFilledExpenseDateIsNeverOverwritten()

	/**
	 * @return void
	 */
	public function testAPreFilledVendorIsNeverOverwritten(): void {
		$expense = $this->emptyExpense(['vendor' => 'Employee-typed BV']);
		[$service, $fake] = $this->service(['Expense' => [$expense]]);

		$service->extractForExpense('expense-1', 'admin');

		$final = $this->lastSavedFor($fake, 'Expense');
		$this->assertSame('Employee-typed BV', $final['vendor']);

		$extraction = $this->lastSavedFor($fake, 'ReceiptExtraction');
		$this->assertSame('amount,expenseDate,vatAmount', $extraction['appliedFields']);
		$this->assertSame('Grand Café De Kroon', $extraction['extractedVendor']);

	}//end testAPreFilledVendorIsNeverOverwritten()

	/**
	 * @return void
	 */
	public function testAPreFilledVatAmountIsNeverOverwritten(): void {
		$expense = $this->emptyExpense(['vatAmount' => 5.68]);
		[$service, $fake] = $this->service(['Expense' => [$expense]]);

		$service->extractForExpense('expense-1', 'admin');

		$final = $this->lastSavedFor($fake, 'Expense');
		$this->assertSame(5.68, $final['vatAmount']);

		$extraction = $this->lastSavedFor($fake, 'ReceiptExtraction');
		$this->assertSame('amount,expenseDate,vendor', $extraction['appliedFields']);
		$this->assertSame(6.02, $extraction['extractedVatAmount']);

	}//end testAPreFilledVatAmountIsNeverOverwritten()

	/**
	 * @return void
	 */
	public function testMissingReceiptFileFailsClosedWithoutCallingDocudesk(): void {
		$expense = $this->emptyExpense(['receiptFile' => null]);
		[$service, $fake, $fin] = $this->service(['Expense' => [$expense]]);

		$result = $service->extractForExpense('expense-1', 'admin');

		$this->assertSame('failed', $result['status']);
		$this->assertCount(0, $fin->calls);
		$this->assertCount(0, $this->savedFor($fake, 'ReceiptExtraction'));

	}//end testMissingReceiptFileFailsClosedWithoutCallingDocudesk()

	// -- REQ-RCPT-003: duck-typed degradation --------------------------------

	/**
	 * @return void
	 */
	public function testDocudeskNotInstalledDegradesToSkippedNoDocudeskAndNeverThrows(): void {
		[$service, $fake, $fin] = $this->service(['Expense' => [$this->emptyExpense()]], docudeskInstalled: false);

		$result = $service->extractForExpense('expense-1', 'admin');

		$this->assertSame('skipped-no-docudesk', $result['status']);
		$this->assertCount(0, $fin->calls);

		$this->assertCount(0, $this->savedFor($fake, 'Expense'), 'the Expense is never saved when docudesk is unavailable');

		$extraction = $this->lastSavedFor($fake, 'ReceiptExtraction');
		$this->assertSame('skipped-no-docudesk', $extraction['status']);

	}//end testDocudeskNotInstalledDegradesToSkippedNoDocudeskAndNeverThrows()

	/**
	 * @return void
	 */
	public function testDocudeskCallThrowingIsCaughtAndRecordedFailedWithoutPropagating(): void {
		[$service, $fake] = $this->service(
			['Expense' => [$this->emptyExpense()]],
			financialExtraction: $this->fakeFinancialExtractionService(throws: true)
		);

		$result = $service->extractForExpense('expense-1', 'admin');

		$this->assertSame('failed', $result['status']);

		$extraction = $this->lastSavedFor($fake, 'ReceiptExtraction');
		$this->assertSame('failed', $extraction['status']);
		$this->assertStringContainsString('docudesk', $extraction['errorMessage']);

		// No Expense field write happened.
		$this->assertCount(0, $this->savedFor($fake, 'Expense'));

	}//end testDocudeskCallThrowingIsCaughtAndRecordedFailedWithoutPropagating()

	// -- REQ-RCPT-004: idempotency --------------------------------------------

	/**
	 * @return void
	 */
	public function testReRunningAfterACompletedExtractionIsANoOp(): void {
		[$service, $fake, $fin] = $this->service(['Expense' => [$this->emptyExpense()]]);

		$first = $service->extractForExpense('expense-1', 'admin');
		$second = $service->extractForExpense('expense-1', 'admin');

		$this->assertSame('extracted', $first['status']);
		$this->assertSame('already-extracted', $second['status']);
		$this->assertCount(1, $fin->calls);

	}//end testReRunningAfterACompletedExtractionIsANoOp()

	/**
	 * @return void
	 */
	public function testAFailedAttemptIsRetryable(): void {
		[$service, , $fin] = $this->service(
			['Expense' => [$this->emptyExpense()]],
			financialExtraction: $this->fakeFinancialExtractionService(throws: true)
		);

		$first = $service->extractForExpense('expense-1', 'admin');
		$this->assertSame('failed', $first['status']);

		$second = $service->extractForExpense('expense-1', 'admin');
		$this->assertSame('failed', $second['status']);

		// Both attempts actually called docudesk -- failed is not sticky.
		$this->assertCount(2, $fin->calls);

	}//end testAFailedAttemptIsRetryable()

	/**
	 * @return void
	 */
	public function testAStalePendingRecordIsSupersededThenARetrySucceeds(): void {
		$rows = [
			'Expense' => [$this->emptyExpense()],
			'ReceiptExtraction' => [
				['id' => 're-1', 'expenseId' => 'expense-1', 'status' => 'pending'],
			],
		];
		[$service, $fake] = $this->service($rows);

		$result = $service->extractForExpense('expense-1', 'admin');

		$this->assertSame('extracted', $result['status']);

		$extractionSaves = $this->savedFor($fake, 'ReceiptExtraction');
		$statuses = array_column($extractionSaves, 'status');
		$this->assertContains('failed', $statuses);
		$this->assertContains('extracted', $statuses);

	}//end testAStalePendingRecordIsSupersededThenARetrySucceeds()

	// -- REQ-RCPT-005: human-in-the-loop, no lifecycle mutation --------------

	/**
	 * @return void
	 */
	public function testExtractionNeverChangesExpenseStatusEvenWhenTheClaimIsInAnAdvancedState(): void {
		$expense = $this->emptyExpense(['status' => 'submitted', 'submittedAt' => '2026-06-12T08:00:00Z']);
		[$service, $fake] = $this->service(['Expense' => [$expense]]);

		$result = $service->extractForExpense('expense-1', 'admin');

		$this->assertSame('extracted', $result['status']);

		$final = $this->lastSavedFor($fake, 'Expense');
		// status is carried forward UNCHANGED -- never a new value, never a
		// lifecycle transition (PUT-semantic saves would NULL it if omitted).
		$this->assertSame('submitted', $final['status']);
		$this->assertSame('2026-06-12T08:00:00Z', $final['submittedAt']);

	}//end testExtractionNeverChangesExpenseStatusEvenWhenTheClaimIsInAnAdvancedState()

	/**
	 * @return void
	 */
	public function testLowConfidenceStillWritesTheEmptyFieldsAndDoesNotBlockTheSave(): void {
		[$service, $fake] = $this->service(
			['Expense' => [$this->emptyExpense()]],
			financialExtraction: $this->fakeFinancialExtractionService(overallConfidence: 0.2)
		);

		$result = $service->extractForExpense('expense-1', 'admin');

		$this->assertSame('extracted', $result['status']);

		$final = $this->lastSavedFor($fake, 'Expense');
		$this->assertSame(32.75, $final['amount']);

		$extraction = $this->lastSavedFor($fake, 'ReceiptExtraction');
		$this->assertSame(0.2, $extraction['overallConfidence']);

	}//end testLowConfidenceStillWritesTheEmptyFieldsAndDoesNotBlockTheSave()

	// -- REQ-RCPT-006: backlog -------------------------------------------------

	/**
	 * @return void
	 */
	public function testBacklogProcessesOnlyExpensesWithAReceiptAndNoActiveExtraction(): void {
		$rows = [
			'Expense' => [
				$this->emptyExpense(['id' => 'expense-1']),
				$this->emptyExpense(['id' => 'expense-2', 'receiptFile' => null]),
				$this->emptyExpense(['id' => 'expense-3']),
			],
			'ReceiptExtraction' => [
				['id' => 're-existing', 'expenseId' => 'expense-3', 'status' => 'extracted'],
			],
		];
		[$service] = $this->service($rows);

		$results = $service->backlog(null, null);

		// expense-2 has no receiptFile (excluded); expense-3 already has an
		// active extraction (its own idempotency pre-check no-ops it, but it
		// IS still in the backlog scan since eligibleBacklogExpenses() also
		// filters actively).
		$this->assertCount(1, $results);
		$this->assertSame('expense-1', $results[0]['expenseId']);
		$this->assertSame('extracted', $results[0]['status']);

	}//end testBacklogProcessesOnlyExpensesWithAReceiptAndNoActiveExtraction()

	/**
	 * @return void
	 */
	public function testBacklogNarrowedToOneExpenseIdIgnoresBacklogScope(): void {
		$expense = $this->emptyExpense(['receiptFile' => null]);
		[$service] = $this->service(['Expense' => [$expense]]);

		$results = $service->backlog('expense-1', null);

		$this->assertCount(1, $results);
		$this->assertSame('failed', $results[0]['status']);

	}//end testBacklogNarrowedToOneExpenseIdIgnoresBacklogScope()

}//end class
