---
kind: code
---

# Receipt OCR prefill for Expense claims via docudesk (financial extraction as a second docudesk consumption direction)

## Why

Every Expense already carries a `receiptFile` (`lib/Settings/register.d/hr-expense.json`, v0.5.0) but nothing reads it: an employee attaches a bon/receipt, and every other field on the claim — `amount`, `expenseDate`, and (not yet on the schema) vendor/VAT — is still typed in by hand from the same piece of paper the receipt already shows. hrmq's only existing docudesk integration runs in the opposite direction: `hrmq-docudesk-documents`/`payslip-pdf-docudesk` hand structured data TO docudesk and get a rendered PDF back. Docudesk also exposes the reverse capability — `OCA\DocuDesk\Service\FinancialExtractionService::extractFinancial()` reads an uploaded document and returns structured financial fields with a confidence score — which nothing in hrmq calls yet. This change wires that second direction: attach a receipt, docudesk OCRs it, the extracted amount/date/vendor/VAT prefill the Expense's still-empty fields, and the employee/approver confirms rather than retypes.

hermiq is not a candidate provider here: it exposes only an NC `core:text2text` TaskProcessing provider, no receipt/document field extraction, so it is out of scope for this leaf entirely.

The division of authority this change establishes mirrors the one `hrmq-docudesk-documents` design.md D1 already settled for rendering: docudesk owns the OCR/extraction machinery, hrmq only assembles the request and applies the result — no OCR, no PDF text layer parsing, and no confidence-scoring logic of hrmq's own.

## What Changes

- **`Expense` v0.5.0 → v0.6.0** (`lib/Settings/register.d/hr-expense.json`, additive/non-breaking): two new nullable fields the extraction contract needs and the schema does not yet carry — `vendor` (string) and `vatAmount` (number, minimum 0). `amount` and `expenseDate` already exist and are the other two prefill targets; `category`/`description` are NOT mapped (FinancialExtractionService does not return them — hrmq never invents a value docudesk did not extract).
- **New `ReceiptExtraction` schema v0.1.0** (same fragment): the hrmq-side record of one extraction attempt — `expenseId` ($ref Expense), `status` (`pending|extracted|failed|skipped-no-docudesk`), the raw extracted values (`extractedAmount`/`extractedDate`/`extractedVendor`/`extractedVatAmount`) and `overallConfidence` for audit even when a field was extracted but NOT applied (because the employee had already typed a value), plus `appliedFields` naming which Expense fields this attempt actually wrote — the provenance trail a reviewer reads to see a value was machine-suggested rather than hand-entered. Mirrors `GeneratedDocument`'s role as an attempt-log for the OTHER docudesk direction, but is its own schema — the field shape (extracted values + confidence) has nothing in common with a render-attempt log.
- **New `ReceiptExtractionService`** (`lib/Service/ReceiptExtractionService.php`): duck-typed exactly like `HrDocumentService` (`IAppManager::isInstalled('docudesk')` + guarded string-FQCN container resolve of `OCA\DocuDesk\Service\FinancialExtractionService`, zero compile-time import, zero composer/info.xml dependency on docudesk) — calls `extractFinancial({fileId: receiptFile, docType: 'receipt'}, requestedBy)`, maps the returned `fields` onto the Expense **only where the current Expense value is empty/unset** (never overwrites a human-entered value), and records the attempt on `ReceiptExtraction`. Absent/unresolvable docudesk → `skipped-no-docudesk`, never throws.
- **Idempotent per `(expenseId)`**: at most one `ReceiptExtraction` in `{pending, extracted}` per Expense; re-running is a no-op once `extracted`, and `failed`/`skipped-no-docudesk` are retryable — the same at-most-one-active invariant `HrDocumentService::activeDocumentFor()` already enforces for the rendering direction, narrowed to a single key (one subject type, one operation, no documentType family).
- **Human-in-the-loop, by construction**: the service writes plain Expense field values via a normal save — it NEVER calls an `x-openregister-lifecycle` transition (`submit`/`approve`/`reject`/`reimburse`). `Expense.status` is untouched by extraction; a low-confidence or wrong extraction can prefill a field for review, but cannot silently create an approved reimbursement.
- **`occ hrmq:expense:extract-receipt [--expense <id>]`** (`lib/Command/ExpenseExtractReceiptCommand.php`): no options → backlog of every Expense with `receiptFile` set and no active `ReceiptExtraction`; `--expense` narrows to one.
- **New `ExpenseController::extractReceipt()`** (`lib/Controller/ExpenseController.php`, new — no Expense controller exists yet) — `POST /api/expenses/extract-receipt`, resolve-first RBAC: the posted `expenseId` resolves through OpenRegister's ObjectService under ambient RBAC (404 collapses not-found/unauthorized) BEFORE any docudesk call, THEN an explicit ownership check — admin (the existing `IGroupManager::isAdmin()` admin/HR gate, `PayrollController::isAdminOrHr()` precedent) OR the caller's NC user id equals `Expense.userId` (the same self-service `userId`/`@me` convention already on Expense) — anyone else gets 403.
- **Manifest**: `ExpenseDetail` gains a page-level `api-call` action ("Extract receipt data"), the `PayslipDetail`/`generate-loonstrook` action shape verbatim (`{expenseId: "@objectId"}`, `confirm: true`, pre-translated toasts).
- **Seed**: one `ReceiptExtraction` example consistent with the existing seeded Expense (if any) or a minimal new seeded Expense+receipt pair, so the provenance record has a real example to browse.

### Non-goals

- **No OCR/text-extraction/confidence-scoring logic in hrmq** — docudesk's `extractFinancial()` (and its lower-level `runExtraction(text, docType)`) own that surface entirely; hrmq only assembles the call and applies the result.
- **No auto-approval or lifecycle change on extraction** — extraction only ever prefills draft-state fields; approve/reject/reimburse stay exactly the human-triggered `x-openregister-lifecycle` transitions they already are.
- **No re-extraction-on-receipt-replace flow** — if an employee swaps the attached `receiptFile` after an `extracted` attempt exists, re-triggering extraction for that Expense is a named follow-up, not delivered here (mirrors the `payslip-pdf-docudesk` "aggregate staleness" precedent of accepting the same limitation for its own idempotency key).
- **No category/description mapping** — `FinancialExtractionService` does not return either; mapping them would mean hrmq fabricating a value docudesk never extracted.
- **No hermiq involvement** — hermiq exposes no receipt/document field-extraction surface (`core:text2text` only); this leaf is docudesk-only.

## Capabilities

### New Capabilities

- `receipt-ocr`: the receipt-OCR-prefill leaf — the `Expense` additive fields, the `ReceiptExtraction` attempt log, `ReceiptExtractionService`'s duck-typed call/mapping/idempotency, the occ command, the guarded endpoint + manifest action, and the human-in-the-loop guarantee that extraction never changes Expense lifecycle state.

## Impact

- `lib/Settings/register.d/hr-expense.json` — `Expense` v0.6.0 (`vendor`, `vatAmount` additive fields), NEW `ReceiptExtraction` v0.1.0 schema, 1 seed object.
- `lib/Service/ReceiptExtractionService.php` — NEW: duck-typed probe, `extractFinancial()` call, prefill-not-overwrite mapping, idempotency pre-check, backlog.
- `lib/Command/ExpenseExtractReceiptCommand.php` — NEW: `--expense` option, default backlog.
- `lib/Controller/ExpenseController.php` — NEW: `extractReceipt()`, resolve-first RBAC (admin-or-owner), no route change beyond the one new route.
- `appinfo/routes.php` — new entry `['name' => 'expense#extract-receipt', 'url' => '/api/expenses/extract-receipt', 'verb' => 'POST']`.
- `src/manifest.json` — `ExpenseDetail` page-level `api-call` action.
- `tests/Unit/Service/ReceiptExtractionServiceTest.php` — NEW: mapping/prefill-not-overwrite, idempotency, duck-typed degradation, aggregation-free (no aggregation step in this leaf) unit coverage.
- Cross-app dependency: NEW duck-typed contract — `OCA\DocuDesk\Service\FinancialExtractionService::extractFinancial()`, string-FQCN resolved only, same pattern as the two FQCNs `HrDocumentService` already resolves; no composer/info.xml dependency on docudesk added.
