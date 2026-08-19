<?php

/**
 * Receipt Extraction Service
 *
 * The second, opposite-direction docudesk consumption (receipt-ocr design.md
 * D1): where `HrDocumentService` hands hrmq data TO docudesk to render a
 * PDF, this service reads an already-attached `Expense.receiptFile` and asks
 * docudesk to EXTRACT structured financial fields FROM it, then prefills
 * only the Expense fields the employee left empty -- `amount`, `expenseDate`,
 * `vendor`, `vatAmount`. hrmq holds no OCR/text-extraction/confidence-scoring
 * logic of its own: `OCA\DocuDesk\Service\FinancialExtractionService::
 * extractFinancial()`, resolved exclusively by string FQCN through the DI
 * container (no compile-time import, no composer/info.xml dependency on
 * docudesk), owns that surface entirely. Every attempt -- its raw extracted
 * values, confidence, and which fields were actually applied -- is logged on
 * a `ReceiptExtraction` record for provenance (design.md D6) via
 * `ReceiptExtractionRepository` (split out purely to keep this class's own
 * PHPMD complexity within the mechanical quality gate -- the repository is
 * I/O plumbing only; every DECISION stays here).
 *
 * Availability is duck-typed identically to `HrDocumentService`
 * (`IAppManager::isInstalled('docudesk')` + a guarded container resolve
 * inside `try/catch (\Throwable)`, design.md D2): unavailable/unresolvable
 * docudesk records the attempt `skipped-no-docudesk` and is retryable; this
 * service never throws past its public methods.
 *
 * *** Corrected docudesk contract (verified against
 * `docudesk/lib/Service/FinancialExtractionService.php` at the installed
 * HEAD, 2026-07-16 -- design.md D4 flagged the `fields` key names as an
 * UNVERIFIED "natural reading", not a confirmed contract) ***
 * `extractFinancial()` does NOT return `fields` keyed
 * `amount`/`date`/`vendor`/`vatAmount`. The real shaped-field set (its
 * `FIELD_DEFAULTS` constant) is `supplierName`, `supplierIban`,
 * `supplierKvk`, `supplierVatId`, `invoiceNumber`, `issueDate`, `dueDate`,
 * `currency`, `totalExcl`, `totalVat`, `totalIncl`, `vatBreakdown`, `lines`.
 * `self::FIELD_SOURCE_KEYS` below corrects the mapping CONSTANTS onto the
 * real keys (per design.md D4's own instruction: "adjust the constant map,
 * not the prefill/no-overwrite RULE") -- the reimbursement `amount` reads
 * `totalIncl` (VAT-inclusive total, the amount actually paid/claimed), the
 * receipt date reads `issueDate`, the payee reads `supplierName`, and the
 * VAT amount reads `totalVat`. `extractFinancial()`'s `$data` input also
 * takes `fileId` (int) OR `documentUri` (string) plus `docType` (must be
 * `receipt`|`supplier-invoice`) -- `Expense.receiptFile` is a plain nullable
 * string that may hold either an OpenRegister/NC file id or a Files path, so
 * `buildExtractionData()` passes it as `fileId` when purely numeric and as
 * `documentUri` otherwise.
 *
 * Idempotency (design.md D3) collapses to a single key -- unlike
 * `HrDocumentService::activeDocumentFor()`'s documentType-scoped key family,
 * `ReceiptExtraction` has exactly one subject (`Expense`) and one operation:
 * at most one row in `{pending, extracted}` per `expenseId`. An `extracted`
 * row makes a new request a no-op (`already-extracted`); a stale `pending`
 * row is superseded (marked `failed`) before a fresh attempt starts;
 * `failed`/`skipped-no-docudesk` never block a retry.
 *
 * Human-in-the-loop is structural, not a policy note (design.md D5): the
 * write onto `Expense` is a plain field-merge save whose payload's `status`
 * is always the object's CURRENT, unchanged value -- carried forward from
 * the freshly-read `Expense`, never a new value this service decides -- so
 * no `x-openregister-lifecycle` transition is ever invoked. (OpenRegister's
 * `ObjectService::saveObject()` update path applies PUT semantics --
 * `SaveObject::fillMissingSchemaPropertiesWithNull()` nulls any schema
 * property absent from the payload -- so `status` MUST be carried in the
 * payload at its unchanged value; omitting it would NULL the Expense's
 * lifecycle status outright, the opposite of this leaf's human-in-the-loop
 * guarantee. Verified against `openregister/lib/Service/Object/
 * SaveObject.php` at HEAD.)
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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

namespace OCA\Hrmq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * Reads an Expense's attached receipt via docudesk and prefills empty fields.
 */
class ReceiptExtractionService {

	/**
	 * The app id docudesk registers under (IAppManager::isInstalled probe).
	 *
	 * @var string
	 */
	private const DOCUDESK_APP_ID = 'docudesk';

	/**
	 * docudesk's financial-extraction service, resolved by string FQCN only
	 * (no compile-time import -- design.md D2).
	 *
	 * @var string
	 */
	private const FINANCIAL_EXTRACTION_SERVICE_FQCN = 'OCA\DocuDesk\Service\FinancialExtractionService';

	/**
	 * The `docType` passed to `extractFinancial()` -- an Expense's attachment
	 * is always a receipt/bon, never a supplier-invoice, in this leaf.
	 *
	 * @var string
	 */
	private const DOC_TYPE = 'receipt';

	/**
	 * Maps each prefillable Expense field to the docudesk `fields` key that
	 * supplies its value -- CORRECTED against the real
	 * `FinancialExtractionService` contract (see class docblock); design.md
	 * D4's `amount`/`date`/`vendor`/`vatAmount` key names do not exist on the
	 * real response.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_SOURCE_KEYS = [
		'amount' => 'totalIncl',
		'expenseDate' => 'issueDate',
		'vendor' => 'supplierName',
		'vatAmount' => 'totalVat',
	];

	/**
	 * Maps each prefillable Expense field to the `ReceiptExtraction` column
	 * that records its raw extracted value, ALWAYS, regardless of whether it
	 * was applied (design.md D4 audit trail).
	 *
	 * @var array<string, string>
	 */
	private const RAW_FIELD_KEYS = [
		'amount' => 'extractedAmount',
		'expenseDate' => 'extractedDate',
		'vendor' => 'extractedVendor',
		'vatAmount' => 'extractedVatAmount',
	];

	/**
	 * @param ContainerInterface $container DI container for the docudesk resolve (design.md D2).
	 * @param IAppManager $appManager To duck-type-probe docudesk's presence.
	 * @param ReceiptExtractionRepository $repository The OpenRegister persistence layer (Expense/ReceiptExtraction loads and saves; owns its own logging).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly ReceiptExtractionRepository $repository,
	) {

	}//end __construct()

	/**
	 * The occ/controller backlog trigger (design.md D7): with `$expenseId`
	 * null, every Expense with a non-empty `receiptFile` and no active
	 * (`pending`/`extracted`) `ReceiptExtraction` is processed;
	 * `$expenseId` narrows to one Expense (its own `extractForExpense()`
	 * failure/idempotency semantics apply unchanged).
	 *
	 * @param string|null $expenseId Restrict to one Expense id, or null for the backlog.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system' (occ context).
	 *
	 * @return array<int, array<string, mixed>> One outcome array per attempt.
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-006
	 */
	public function backlog(?string $expenseId = null, ?string $userId = null): array {
		$expenseId = ($expenseId !== null && trim($expenseId) !== '') ? trim($expenseId) : null;

		if ($expenseId !== null) {
			return [$this->extractForExpense($expenseId, $userId)];
		}

		$results = [];
		foreach ($this->repository->eligibleBacklogExpenses() as $expense) {
			$id = (string)($expense['id'] ?? $expense['@self']['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$results[] = $this->extractForExpense($id, $userId);
		}

		return $results;
	}//end backlog()

	/**
	 * Extract one Expense's receipt: idempotency pre-check, duck-typed
	 * availability probe, the `extractFinancial()` call, the
	 * prefill-not-overwrite field mapping (design.md D4), and recording the
	 * attempt on a `ReceiptExtraction` at every step. Never throws.
	 *
	 * @param string $expenseId The Expense to extract for.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system' (occ context).
	 *
	 * @return array<string, mixed> Outcome: {expenseId, status, message, receiptExtractionId}.
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-002
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-003
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-004
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-005
	 */
	public function extractForExpense(string $expenseId, ?string $userId = null): array {
		$expenseId = trim($expenseId);
		if ($expenseId === '') {
			return $this->outcome('', 'failed', 'Geen expenseId opgegeven; extractie is geweigerd.');
		}

		$expense = $this->repository->findExpense($expenseId);
		if ($expense === null) {
			return $this->outcome($expenseId, 'failed', 'Expense niet gevonden: ' . $expenseId);
		}

		$receiptFile = trim((string)($expense['receiptFile'] ?? ''));
		if ($receiptFile === '') {
			return $this->outcome($expenseId, 'failed', 'Expense heeft geen receiptFile; extractie is geweigerd.');
		}

		$idempotentOutcome = $this->idempotencyOutcome($expenseId);
		if ($idempotentOutcome !== null) {
			return $idempotentOutcome;
		}

		if ($this->docudeskAvailable() === false) {
			return $this->skipNoDocudesk($expenseId, $userId);
		}

		return $this->runExtraction($expenseId, $expense, $receiptFile, $userId);
	}//end extractForExpense()

	/**
	 * The idempotency pre-check (design.md D3): an active `extracted` row
	 * short-circuits to an `already-extracted` outcome; an active `pending`
	 * row is superseded (marked `failed`) so a fresh attempt can proceed.
	 * Returns null (meaning "proceed with a fresh attempt") when there is no
	 * active row, or after superseding a stale pending one.
	 *
	 * @param string $expenseId The Expense id.
	 *
	 * @return array<string, mixed>|null The `already-extracted` outcome, or null to proceed.
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-004
	 */
	private function idempotencyOutcome(string $expenseId): ?array {
		$active = $this->repository->activeExtractionFor($expenseId);
		if ($active === null) {
			return null;
		}

		if ((string)($active['status'] ?? '') === 'extracted') {
			return $this->outcome(
				$expenseId,
				'already-extracted',
				'Er bestaat al een extractie voor deze declaratie; geen nieuwe poging nodig.',
				$active
			);
		}

		// Stale pending -- supersede, then fall through to a fresh attempt (design.md D3).
		$this->repository->saveReceiptExtraction(
			$active,
			['status' => 'failed', 'errorMessage' => 'Verlopen pending-poging vervangen door een nieuwe poging.']
		);

		return null;
	}//end idempotencyOutcome()

	/**
	 * Record and return the `skipped-no-docudesk` outcome (design.md D2).
	 *
	 * @param string $expenseId The Expense id.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system'.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-003
	 */
	private function skipNoDocudesk(string $expenseId, ?string $userId): array {
		$row = $this->repository->createReceiptExtraction(
			[
				'expenseId' => $expenseId,
				'status' => 'skipped-no-docudesk',
				'requestedBy' => $userId,
				'errorMessage' => 'Docudesk is niet geïnstalleerd of de extractieservice kon niet worden geladen; deze poging kan later opnieuw worden uitgevoerd.',
			]
		);

		return $this->outcome($expenseId, 'skipped-no-docudesk', (string)$row['errorMessage'], $row);
	}//end skipNoDocudesk()

	/**
	 * Create the `pending` row, call `extractFinancial()`, and dispatch to
	 * `applyExtractionResult()` on success -- a caught `\Throwable` records
	 * `failed` and never propagates.
	 *
	 * @param string $expenseId The Expense id.
	 * @param array<string, mixed> $expense The current Expense (pre-write).
	 * @param string $receiptFile The Expense's non-empty receiptFile value.
	 * @param string|null $userId The acting Nextcloud user id, or null for 'system'.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-002
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-003
	 */
	private function runExtraction(string $expenseId, array $expense, string $receiptFile, ?string $userId): array {
		$row = $this->repository->createReceiptExtraction(
			[
				'expenseId' => $expenseId,
				'status' => 'pending',
				'requestedBy' => $userId,
			]
		);

		$requestedBy = ($userId !== null && trim($userId) !== '') ? $userId : 'system';

		try {
			$result = $this->financialExtractionService()->extractFinancial(
				$this->buildExtractionData($receiptFile),
				$requestedBy
			);
		} catch (\Throwable $e) {
			$row = $this->repository->saveReceiptExtraction(
				$row,
				['status' => 'failed', 'errorMessage' => 'Extractie via docudesk is mislukt: ' . $e->getMessage()]
			);

			return $this->outcome($expenseId, 'failed', (string)$row['errorMessage'], $row);
		}

		return $this->applyExtractionResult($expenseId, $expense, $row, $result);
	}//end runExtraction()

	/**
	 * Apply the prefill-not-overwrite mapping (design.md D4) onto the
	 * Expense, then record the completed attempt on `ReceiptExtraction`.
	 * Human-in-the-loop (design.md D5, REQ-RCPT-005): the Expense save NEVER
	 * includes a new status value -- `$mapping['writes']` only ever carries
	 * amount/expenseDate/vendor/vatAmount, so this write can never advance or
	 * alter the claim's lifecycle.
	 *
	 * @param string $expenseId The Expense id.
	 * @param array<string, mixed> $expense The current Expense (pre-write).
	 * @param array<string, mixed> $row The `pending` ReceiptExtraction row.
	 * @param array<string, mixed> $result docudesk's `extractFinancial()` result.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-002
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-005
	 */
	private function applyExtractionResult(string $expenseId, array $expense, array $row, array $result): array {
		$fields = (array)($result['fields'] ?? []);
		$mapping = $this->mapFields($expense, $fields);

		if ($mapping['writes'] !== []) {
			$this->repository->saveExpense($expense, $mapping['writes']);
		}

		$overallConfidence = null;
		if (isset($result['overallConfidence']) === true) {
			$overallConfidence = (float)$result['overallConfidence'];
		}

		$row = $this->repository->saveReceiptExtraction(
			$row,
			array_merge(
				$mapping['raw'],
				[
					'status' => 'extracted',
					'overallConfidence' => $overallConfidence,
					'appliedFields' => ($mapping['applied'] === [] ? null : implode(',', $mapping['applied'])),
					'extractedAt' => gmdate('Y-m-d\TH:i:s\Z'),
					'errorMessage' => null,
				]
			)
		);

		return $this->outcome($expenseId, 'extracted', 'Extractie voltooid.', $row);
	}//end applyExtractionResult()

	/**
	 * Build the `extractFinancial()` `$data` argument: `Expense.receiptFile`
	 * may hold either an OpenRegister/NC file id or a Files path, so a purely
	 * numeric value is passed as `fileId` (int), otherwise as `documentUri`
	 * (string) -- `extractFinancial()` accepts either (class docblock).
	 *
	 * @param string $receiptFile The Expense's `receiptFile` value (non-empty, trimmed).
	 *
	 * @return array{fileId?: int, documentUri?: string, docType: string}
	 */
	private function buildExtractionData(string $receiptFile): array {
		if (ctype_digit($receiptFile) === true) {
			return ['fileId' => (int)$receiptFile, 'docType' => self::DOC_TYPE];
		}

		return ['documentUri' => $receiptFile, 'docType' => self::DOC_TYPE];
	}//end buildExtractionData()

	/**
	 * The prefill-not-overwrite field mapping (design.md D4): for each of the
	 * four target fields, the raw extracted value is ALWAYS recorded (for
	 * audit, even when not applied), but is only written onto the Expense
	 * when the field's CURRENT value is empty per its own per-field
	 * definition -- a field already carrying a value is left untouched
	 * regardless of what was extracted for it.
	 *
	 * @param array<string, mixed> $expense The current Expense (pre-write).
	 * @param array<string, mixed> $fields docudesk's shaped `fields` result.
	 *
	 * @return array{writes: array<string, mixed>, applied: array<int, string>, raw: array<string, mixed>}
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-002
	 */
	private function mapFields(array $expense, array $fields): array {
		$writes = [];
		$applied = [];
		$raw = [];

		foreach (self::FIELD_SOURCE_KEYS as $expenseField => $sourceKey) {
			$rawValue = ($fields[$sourceKey] ?? null);
			$raw[self::RAW_FIELD_KEYS[$expenseField]] = $rawValue;

			if ($rawValue === null || $rawValue === '') {
				continue;
			}

			if ($this->isFieldEmpty($expenseField, ($expense[$expenseField] ?? null)) === false) {
				// Already carries a human-entered value -- never overwritten.
				continue;
			}

			$writes[$expenseField] = $rawValue;
			$applied[] = $expenseField;
		}

		return ['writes' => $writes, 'applied' => $applied, 'raw' => $raw];
	}//end mapFields()

	/**
	 * Per-field "empty" definition (design.md D4): the only condition under
	 * which extraction may write a field.
	 *
	 * @param string $field One of amount/expenseDate/vendor/vatAmount.
	 * @param mixed $currentValue The field's current Expense value.
	 *
	 * @return bool
	 */
	private function isFieldEmpty(string $field, mixed $currentValue): bool {
		return match ($field) {
			'amount' => ((float)($currentValue ?? 0)) === 0.0,
			'vatAmount' => $currentValue === null,
			default => ($currentValue === null || trim((string)$currentValue) === ''),
		};

	}//end isFieldEmpty()

	/**
	 * Duck-typed docudesk availability probe (design.md D2, mirrors
	 * `HrDocumentService::docudeskAvailable()`): docudesk must be installed
	 * AND `FinancialExtractionService` must resolve from the container.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-003
	 */
	private function docudeskAvailable(): bool {
		if ($this->appManager->isInstalled(self::DOCUDESK_APP_ID) === false) {
			return false;
		}

		try {
			$this->financialExtractionService();
		} catch (\Throwable $e) {
			return false;
		}

		return true;
	}//end docudeskAvailable()

	/**
	 * Build the outcome array returned by extractForExpense()/backlog().
	 *
	 * @param string $expenseId The Expense id.
	 * @param string $status The outcome status.
	 * @param string $message A human-readable outcome message.
	 * @param array<string, mixed>|null $extraction The ReceiptExtraction record, if any.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $expenseId, string $status, string $message, ?array $extraction = null): array {
		$extractionId = null;
		if ($extraction !== null) {
			$extractionId = (string)($extraction['id'] ?? $extraction['@self']['id'] ?? '');
			$extractionId = ($extractionId === '' ? null : $extractionId);
		}

		return [
			'expenseId' => $expenseId,
			'status' => $status,
			'message' => $message,
			'receiptExtractionId' => $extractionId,
		];

	}//end outcome()

	/**
	 * @return mixed docudesk's FinancialExtractionService, resolved by string FQCN only (design.md D2).
	 */
	private function financialExtractionService(): mixed {
		return $this->container->get(self::FINANCIAL_EXTRACTION_SERVICE_FQCN);
	}//end financialExtractionService()

}//end class
