# Spec: receipt-ocr

**Status:** in-progress
**Scope:** hrmq (receipt OCR / financial-extraction prefill for Expense claims via docudesk)
**Kind:** code (service duck-typed integration + mapping + idempotency, controller two-stage RBAC guard, command dominate; two additive schema fields, one small new schema, and one manifest action ride along — see design.md "Mixed-spec rationale")

**OpenSpec changes**
- `receipt-ocr` (2026-07-16)

## Purpose

Give every `Expense` with an attached `receiptFile` an automatic prefill of its still-empty `amount`/`expenseDate`/`vendor`/`vatAmount` fields, extracted by docudesk's `FinancialExtractionService` from the receipt, with confidence and provenance recorded so a reviewer can tell a value was machine-suggested rather than hand-entered — hrmq assembles the request and applies the result, docudesk owns all OCR/extraction logic, and extraction never touches the Expense's approval lifecycle. hermiq is not a provider for this leaf (it exposes only an NC `core:text2text` TaskProcessing provider, no receipt/document field extraction).

## ADDED Requirements

@e2e exclude backend service/controller/command change plus a declarative manifest action; hrmq has no app-level e2e suite yet (tracked by active change hrmq-test-coverage-baseline)

### Requirement: The `Expense` schema SHALL gain additive vendor/VAT fields and a `ReceiptExtraction` attempt log SHALL exist (REQ-RCPT-001)

`lib/Settings/register.d/hr-expense.json` SHALL bump `Expense` from v0.5.0 to v0.6.0 with two additive, non-breaking, nullable fields — `vendor` (string) and `vatAmount` (number, `minimum: 0`) — without changing `required`, and SHALL declare a new `ReceiptExtraction` v0.1.0 schema (`icon: TextRecognition`, `x-schema-org: schema:Action`), required `[expenseId, status]`: `expenseId` (string, uuid, `$ref` Expense), `status` (string, enum `pending|extracted|failed|skipped-no-docudesk`), `overallConfidence` (number, nullable, `minimum: 0`, `maximum: 1`), `extractedAmount`/`extractedDate`/`extractedVendor`/`extractedVatAmount` (the raw extracted values, nullable), `appliedFields` (string, nullable — comma-separated Expense field names this attempt actually wrote), `requestedBy` (string, nullable), `errorMessage` (string, nullable), `extractedAt` (string, date-time, nullable).

#### Scenario: Fragment merges and validates

- **GIVEN** the hrmq register import
- **WHEN** the fragments under `lib/Settings/register.d/` are deep-merged and imported
- **THEN** `Expense` carries `vendor` and `vatAmount`, the `ReceiptExtraction` schema exists in the hrmq register, and `{expenseId: "<uuid>", status: "extracted"}` validates against it

#### Scenario: Existing Expense records stay valid

- **GIVEN** an `Expense` record written before this change, with no `vendor`/`vatAmount` values
- **WHEN** the v0.6.0 schema is applied
- **THEN** the record remains valid (the new fields are optional and nullable — no backfill required)

### Requirement: `ReceiptExtractionService` SHALL call docudesk's `FinancialExtractionService::extractFinancial()` and prefill only empty Expense fields, never overwriting a human-entered value (REQ-RCPT-002)

For an `Expense` with a non-empty `receiptFile`, `ReceiptExtractionService` SHALL call `OCA\DocuDesk\Service\FinancialExtractionService::extractFinancial(['fileId' => $receiptFile, 'docType' => 'receipt'], $requestedBy)` and, for each of `amount`/`expenseDate`/`vendor`/`vatAmount`, SHALL write the corresponding extracted value onto the Expense ONLY when that field's current value is empty (per design.md D4's per-field definition: `amount === 0`, `expenseDate` absent/empty, `vendor` null/empty, `vatAmount` null) — a field already carrying a non-empty value SHALL be left unchanged regardless of what was extracted for it. The raw extracted values and `overallConfidence` SHALL be recorded on a `ReceiptExtraction` row every time, and `appliedFields` SHALL name only the fields this attempt actually wrote.

#### Scenario: All four target fields are empty

- **GIVEN** an Expense with `amount: 0`, no `expenseDate`, `vendor: null`, `vatAmount: null`, and a `receiptFile` set
- **WHEN** extraction runs and docudesk returns all four fields with values
- **THEN** all four Expense fields are written from the extracted values, `appliedFields` lists all four, and the `ReceiptExtraction` row records `status: "extracted"` with `overallConfidence`

#### Scenario: A human-entered value is never overwritten

- **GIVEN** an Expense with `amount: 45.50` already entered by the employee, and `vendor`/`vatAmount`/`expenseDate` empty
- **WHEN** extraction runs and docudesk returns a DIFFERENT amount for the same receipt
- **THEN** `Expense.amount` stays `45.50` (untouched), the other three empty fields are prefilled where extracted, `appliedFields` does NOT include `amount`, and the extraction's raw `extractedAmount` is still recorded on `ReceiptExtraction` for audit

#### Scenario: Missing receiptFile fails closed

- **GIVEN** an Expense with no `receiptFile` set
- **WHEN** extraction is requested for it
- **THEN** the outcome is `failed` with a diagnostic, no `ReceiptExtraction` row with `status: "extracted"` is created, and `extractFinancial()` is never called

### Requirement: docudesk absence SHALL degrade to `skipped-no-docudesk` and SHALL NEVER throw (REQ-RCPT-003)

`ReceiptExtractionService` SHALL probe docudesk's availability duck-typed — `IAppManager::isInstalled('docudesk')` AND a guarded container resolve (string FQCN only, zero compile-time import) of `OCA\DocuDesk\Service\FinancialExtractionService` inside a `try/catch (\Throwable)` — and, when either check fails, SHALL record the attempt `skipped-no-docudesk` on a `ReceiptExtraction` row and return a normal outcome array without throwing past its public methods; the Expense's fields SHALL be left entirely untouched.

#### Scenario: docudesk not installed

- **GIVEN** the docudesk app is not installed
- **WHEN** extraction is requested for an Expense with a receiptFile
- **THEN** the outcome is `skipped-no-docudesk`, no exception propagates, and no Expense field is written

#### Scenario: docudesk installed but the service cannot be resolved

- **GIVEN** docudesk is installed but `FinancialExtractionService` cannot be resolved from the container (e.g. a broken DI registration)
- **WHEN** extraction is requested
- **THEN** the outcome is `skipped-no-docudesk`, the underlying `\Throwable` is caught, and no exception propagates to the caller

### Requirement: Extraction SHALL be idempotent per Expense — at most one active attempt, and a retry after `failed`/`skipped-no-docudesk` SHALL proceed (REQ-RCPT-004)

`ReceiptExtractionService` SHALL enforce at most one `ReceiptExtraction` in `{pending, extracted}` per `expenseId`: an existing `extracted` row SHALL make a new request a no-op (`already-extracted` outcome, no docudesk call, no Expense write), and an existing stale `pending` row SHALL be superseded (marked `failed`) before a fresh attempt starts. A `failed` or `skipped-no-docudesk` row SHALL never block a subsequent attempt for the same Expense.

#### Scenario: Re-running after a completed extraction is a no-op

- **GIVEN** an Expense with an existing `ReceiptExtraction` row in `status: "extracted"`
- **WHEN** extraction is requested again for the same Expense
- **THEN** the outcome is `already-extracted`, `extractFinancial()` is not called, and no second `ReceiptExtraction` row is created

#### Scenario: A failed attempt is retryable

- **GIVEN** an Expense with an existing `ReceiptExtraction` row in `status: "failed"`
- **WHEN** extraction is requested again for the same Expense
- **THEN** a fresh attempt proceeds (docudesk is called again) rather than being blocked by the prior failure

### Requirement: Extraction SHALL never change `Expense.status` or trigger a lifecycle transition — prefilled values are suggestions the human confirms (REQ-RCPT-005)

`ReceiptExtractionService` SHALL write extracted values onto an `Expense` via a plain field save that never includes `status` in its payload and never invokes an `x-openregister-lifecycle` transition (`submit`/`approve`/`reject`/`reimburse`); a low-confidence or incorrect extraction SHALL therefore be able to prefill a field for human review but SHALL NEVER be able to advance the claim's lifecycle or create an approved reimbursement on its own.

#### Scenario: A draft Expense stays draft after extraction

- **GIVEN** an Expense in `status: "draft"` with an attached receipt
- **WHEN** extraction runs and successfully prefills `amount`/`vendor`
- **THEN** `Expense.status` is still `"draft"` — the employee must still explicitly `submit` the claim

#### Scenario: Low confidence does not block or auto-correct the write

- **GIVEN** an extraction whose `overallConfidence` is low (e.g. 0.2)
- **WHEN** the extracted fields are applied
- **THEN** the low-confidence values are still written to the empty Expense fields (visible for review, `overallConfidence` recorded on `ReceiptExtraction`) and `Expense.status` is unaffected — no threshold in this leaf blocks the write or silently discards it

### Requirement: An occ command SHALL trigger extraction, on demand or as a backlog (REQ-RCPT-006)

`occ hrmq:expense:extract-receipt [--expense <id>]` SHALL, with no options, process the backlog of every `Expense` with a non-empty `receiptFile` and no active (`pending`/`extracted`) `ReceiptExtraction`; `--expense <id>` SHALL narrow processing to that one Expense, returning a single `failed` outcome with a diagnostic when that Expense has no `receiptFile`.

#### Scenario: Default backlog run

- **GIVEN** three Expenses with receipts and no active extraction, and one Expense already `extracted`
- **WHEN** `occ hrmq:expense:extract-receipt` runs with no options
- **THEN** the three eligible Expenses are processed and the already-`extracted` one is skipped (its idempotency pre-check makes it a no-op)

#### Scenario: Single-Expense run without a receipt

- **GIVEN** an Expense with no `receiptFile`
- **WHEN** `occ hrmq:expense:extract-receipt --expense <that-id>` runs
- **THEN** the command reports a single `failed` outcome and exits non-zero

### Requirement: A guarded endpoint and manifest action SHALL let admin/HR or the owning employee trigger extraction from the Expense detail page (REQ-RCPT-007)

`POST /api/expenses/extract-receipt` (`ExpenseController::extractReceipt()`) SHALL resolve the posted `expenseId` through OpenRegister's ObjectService under the caller's ambient RBAC BEFORE any docudesk call (unresolvable/unauthorized SHALL both return 404, never leaking existence), THEN SHALL apply an explicit ownership check — the caller SHALL be a Nextcloud admin OR the resolved `Expense.userId` SHALL equal the caller's Nextcloud user id — and SHALL return 403 for any other caller; `ExpenseDetail` in `src/manifest.json` SHALL expose a page-level `api-call` action ("Extract receipt data") posting `{expenseId: "@objectId"}` to this endpoint, mirroring the `PayslipDetail`/`generate-loonstrook` action shape.

#### Scenario: The owning employee can trigger extraction

- **GIVEN** an Expense whose `userId` equals the calling user's Nextcloud user id
- **WHEN** that user calls `POST /api/expenses/extract-receipt` with the Expense's id
- **THEN** the request is authorized and `ReceiptExtractionService::extractForExpense()` runs

#### Scenario: An unrelated employee is rejected

- **GIVEN** an Expense whose `userId` does NOT equal the calling user's Nextcloud user id, and the caller is not an admin
- **WHEN** that user calls `POST /api/expenses/extract-receipt` with the Expense's id
- **THEN** the response is 403 and `ReceiptExtractionService` is never invoked

#### Scenario: Manifest stays valid

- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0 and the `ExpenseDetail` "Extract receipt data" action is present
