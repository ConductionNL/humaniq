# Design — receipt-ocr

## Context

**What already exists (verified against development HEAD, 2026-07-16):**

- **`Expense` v0.5.0** (`lib/Settings/register.d/hr-expense.json`): the onkostendeclaratie claim — `employeeId` ($ref, required), `title`/`description`, `amount` (required, `minimum: 0`), `currency` (default EUR), `category` (enum), `expenseDate` (date, optional), `receiptFile` (string, nullable — "Reference (Nextcloud Files path or OpenRegister file id) to the attached receipt / bon"), `status` (required, `x-openregister-lifecycle`: `draft → submitted → approved/rejected → reimbursed`, guarded by `NoSelfApprovalGuard`), plus `submittedAt`/`approvedBy`/`approvedAt`/`rejectionReason`/`reimbursedAt`, and the self-service/team-scope denormalised strings `userId`, `managerUserId`, `administrationId`. There is **no** `vendor` or `vatAmount` field — the extraction contract needs both and neither exists.
- **The docudesk rendering leaf is live** (`hrmq-docudesk-documents` + `payslip-pdf-docudesk`, both archived): `HrDocumentService` is the template for EVERY convention this change reuses — duck-typed probe (`IAppManager::isInstalled('docudesk')` + guarded container resolve of string FQCNs, zero compile-time import, zero composer/info.xml dependency), `skipped-no-docudesk` degradation, an attempt-log schema (`GeneratedDocument`) recording every outcome, and an at-most-one-active-per-key idempotency invariant (`activeDocumentFor()`) with `failed`/`skipped-no-docudesk` retryable and a terminal outcome (`generated`) a no-op. That leaf calls docudesk to RENDER a document FROM hrmq data. This change calls docudesk in the opposite direction — to EXTRACT data FROM an uploaded file INTO hrmq — via a different, verified docudesk surface: `OCA\DocuDesk\Service\FinancialExtractionService::extractFinancial(array $data, string $requestedBy): array` (`$data` carries `fileId`|`documentUri` + `docType`; returns `{fields, fieldConfidence, overallConfidence, corrections}`). A lower-level `runExtraction(string $text, string $docType): array` also exists on the same service but is not used here — `extractFinancial()` is the documented entry point for a file-backed extraction and already returns confidence, so there is no reason to hand-roll the OCR-to-text step ourselves.
- **`PayrollGLPostService`** (`lib/Service/PayrollGLPostService.php`): the idempotency-key idiom used elsewhere in the app is a deterministic, human-readable string (`journalNumber = sprintf('HRMQ-LOON-%s-%s', $period, $administrationId)`) checked before a write. That idiom fits a *business document number* (a journal entry a human might read on a GL export). `ReceiptExtraction`'s idempotency key is not a printable business number — it is a simple existence check on `(expenseId, status)`, directly analogous to `HrDocumentService::activeDocumentFor()`'s row scan rather than to a generated string key. See D3.
- **hermiq** exposes exactly one NC TaskProcessing provider (`core:text2text`) — no document/receipt field-extraction surface. It is not a candidate provider for this leaf and is not referenced anywhere in this change.
- **Manifest**: `ExpenseDetail` (`src/manifest.json`) has NO page-level `actions` today. `PayslipDetail` shows the exact `api-call` action shape to mirror (`{id, label, type: "api-call", icon, url, method, params: {"...": "@objectId"}, confirm, successMessage, errorMessage}`), added by `payslip-pdf-docudesk` against the identical no-admin-idor controller pattern this change reuses.
- **`PayrollController::isAdminOrHr()`**: the established "no dedicated HR Nextcloud group yet" admin gate — `$this->groupManager->isAdmin($uid)`. This change's ownership check composes that gate with the `userId`/`@me` self-service convention already on Expense (`MijnDeclaraties`) rather than introducing a third access model.

## Goals / Non-Goals

**Goals:** an Expense with an attached receipt gets its empty `amount`/`expenseDate`/`vendor`/`vatAmount` fields prefilled by docudesk's OCR/financial-extraction pass, with confidence and provenance recorded so a reviewer can tell a value was machine-suggested; graceful, non-throwing degradation when docudesk is absent; idempotent per Expense; zero effect on the Expense's approval lifecycle.

**Non-Goals:** OCR/extraction logic of hrmq's own (docudesk's surface entirely); auto-approval or any lifecycle transition triggered by extraction; re-extraction when a receipt file is replaced (follow-up); category/description mapping (not part of the extraction contract); any hermiq involvement.

## Decisions

### D1 — A new `ReceiptExtractionService`, not a `HrDocumentService` extension

The two docudesk directions do not share a shape. `HrDocumentService` selects a template, renders a PDF, and stores a binary via `FileService` — none of which this leaf does. This leaf reads ONE already-stored file (`Expense.receiptFile`), calls a different docudesk service (`FinancialExtractionService`, not `DocumentService`/`TemplateService`), and writes plain scalar fields back onto an existing object — no template selection, no file storage, no `GeneratedDocument` row (that schema's own description scopes it to "one attempt to render a standard HR document... via docudesk's template/rendering engine", which this is not). Folding this into `HrDocumentService` would force every reader of that class to hold two unrelated mental models. New service, new record schema (`ReceiptExtraction`), same duck-typing DISCIPLINE, different SHAPE.

### D2 — Duck-typed availability: identical probe shape, one FQCN

`ReceiptExtractionService::docudeskAvailable()` mirrors `HrDocumentService::docudeskAvailable()` exactly in spirit: `IAppManager::isInstalled('docudesk')` first (cheap, no container churn when docudesk is simply not installed), then a guarded `ContainerInterface::get('OCA\DocuDesk\Service\FinancialExtractionService')` inside a `try/catch (\Throwable)` — any resolution failure (missing service, broken DI registration, whatever) collapses to "unavailable", never to an uncaught exception. Both checks failing OR either one failing → `skipped-no-docudesk`, recorded on `ReceiptExtraction`, retryable, and the calling command/endpoint returns a normal outcome array — it never throws past this boundary. hrmq carries zero composer/info.xml dependency on docudesk (unchanged invariant).

### D3 — Idempotency: one key, not a key family

`HrDocumentService` needs a documentType-scoped key family because one `GeneratedDocument` schema serves six document types with three different subject shapes (contract/payslip/jaaropgaaf). `ReceiptExtraction` has exactly one subject (`Expense`) and exactly one operation, so the key collapses to `expenseId` alone: at most one `ReceiptExtraction` with `status IN {pending, extracted}` per `expenseId`. An existing `extracted` record is a no-op (`already-extracted` outcome, no docudesk call); a stale `pending` record is superseded (marked `failed`) before a fresh attempt starts, identical to `HrDocumentService`'s supersede-then-retry behaviour. `failed`/`skipped-no-docudesk` never block a retry.

### D4 — Field mapping is prefill-only: "empty" is defined per field, and a filled field is never touched

For each of the four mapped fields, "empty" (the ONLY condition under which extraction may write) is:

| Expense field | New/existing | "Empty" condition | Extracted-from |
|---|---|---|---|
| `amount` | existing, required, `minimum: 0` | current value `=== 0` (the only representable "not yet entered" state for a required non-nullable numeric field — a real claim amount is never legitimately zero) | `fields['amount']` |
| `expenseDate` | existing, optional, no `nullable` | key absent OR empty string | `fields['date']` |
| `vendor` | **NEW**, nullable | `null` OR empty string | `fields['vendor']` |
| `vatAmount` | **NEW**, nullable, `minimum: 0` | `null` | `fields['vatAmount']` |

A field already carrying a human-entered (non-empty) value is left untouched even when the extraction produced a different value for it — extraction never overwrites, it only fills gaps. `appliedFields` on the `ReceiptExtraction` record lists exactly which of the four field names this attempt WROTE (a field extracted-but-skipped-because-already-filled does not appear there, even though its raw extracted value is still recorded on `extractedAmount`/`extractedDate`/`extractedVendor`/`extractedVatAmount` for audit). ⚠️ The literal `fields` key names above (`amount`/`date`/`vendor`/`vatAmount`) are the natural reading of the grounded contract's field list, NOT independently verified against a live `extractFinancial()` response — implementation MUST confirm the exact key casing/naming against the installed docudesk HEAD before wiring the mapping (the same caution `payslip-pdf-docudesk` tasks.md#4 already applied to the dataRefs schema-keying contract), and adjust the constant map, not the prefill/no-overwrite RULE, if the names differ.

### D5 — Human-in-the-loop is structural, not a policy note

`ReceiptExtractionService` writes the Expense via a plain field-merge save (the same `objectService()->saveObject(..., uuid: $id, ...)` shape `HrDocumentService::saveGeneratedDocument()` uses) — it never calls anything that walks `Expense`'s `x-openregister-lifecycle` transitions (`submit`/`approve`/`reject`/`reimburse`). There is no code path from "extraction ran" to "status changed": a `draft` Expense with a wrong or low-confidence extraction stays `draft`, visibly holding a suggested value the employee/approver can correct before ever submitting. `overallConfidence` is recorded specifically so a reviewer CAN discount a low-confidence prefill, but nothing in this leaf enforces a confidence threshold that blocks or gates the write — the human is the gate, not a number (consistent with `nl-loonstrook-verplicht`'s calibration precedent of never hard-failing on a machine signal alone).

### D6 — `ReceiptExtraction` is its own schema, in the same fragment as `Expense`

Per ADR-037 (a schema lives in the fragment of the leaf that writes it — the `Jaaropgaaf`-lives-in-`hr-documents.json` precedent), `ReceiptExtraction` is created and updated exclusively by `ReceiptExtractionService` and exists solely to log attempts against `Expense`, so it belongs in `hr-expense.json` alongside `Expense` rather than opening a new fragment file for one schema. `Expense.vendor`/`Expense.vatAmount` are additive, non-breaking fields on the SAME schema (v0.5.0 → v0.6.0) — not new properties on `ReceiptExtraction` — because they are claim data a human can also type directly (vendor/VAT are ordinary expense-claim attributes, not extraction-only metadata); only the attempt's audit trail (confidence, raw extracted values, which fields were applied) lives on `ReceiptExtraction`.

### D7 — Triggers: occ backlog + one guarded endpoint, same shape as the rendering leaf's D5/D6

`occ hrmq:expense:extract-receipt [--expense <id>]`: no options → backlog of every `Expense` with `receiptFile` set (non-empty) AND no active `ReceiptExtraction` (`pending`/`extracted`); `--expense <id>` narrows to one Expense — if that Expense has no `receiptFile`, the single outcome is `failed` with a diagnostic (a per-subject data condition, not a flag-misuse `usage-error`; there is no flag-combination to misuse in this single-key leaf, unlike the rendering leaf's `--period`/`--year` guards). `ExpenseController::extractReceipt()` is a NEW controller (no Expense controller exists yet) — `POST /api/expenses/extract-receipt` takes `expenseId`, resolves it through `ObjectService::find()` under ambient RBAC (404 collapses not-found/unauthorized, no-admin-idor per ADR-005 Rule 3), THEN an explicit ownership check: `IGroupManager::isAdmin($uid)` (the `PayrollController::isAdminOrHr()` precedent) OR `$resolvedExpense['userId'] === $uid` (the self-service convention `MijnDeclaraties` already relies on) — anyone else gets 403 AFTER the object is confirmed to exist (existence is not leaked to an unauthorized caller; the 404 branch is unchanged, only a NEW 403 branch is added for "exists, but I'm not admin and not the owner"). `ExpenseDetail` gains the `PayslipDetail`-shaped `api-call` action, `params: {expenseId: "@objectId"}`.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `Expense.vendor`/`vatAmount` additive fields + `ReceiptExtraction` schema | declarative schema (`hr-expense.json`) | ADR-031 default |
| Duck-typed probe + `extractFinancial()` call + field mapping | **imperative, `ReceiptExtractionService`** | ADR-031 exception already granted to the rendering leaf: cross-app integration, here with a different docudesk surface |
| Idempotency pre-check | imperative (row scan, D3) | same exception class as `HrDocumentService::activeDocumentFor()` |
| Triggers | imperative occ option + one new controller endpoint (D7) | no lifecycle hook on Expense triggers extraction automatically in MVP; both are on-demand |
| `ExpenseDetail` action | declarative manifest | ADR-031 default (`api-call` is a declarative action type) |

### Mixed-spec rationale (kind: code)

`kind: code`: the PHP surface dominates (a new service with a duck-typed probe + field-mapping + idempotency, a new controller with a two-stage authorization guard, a new command, unit tests) while the config surface (two additive fields, one small new schema, one manifest action, one seed) rides along — the identical yellow-flag precedent as `hrmq-docudesk-documents`/`payslip-pdf-docudesk`; splitting the fragment edit into a `kind: config` change would only create an artificial ordering dependency on the service that reads it.

## Schema delta (`lib/Settings/register.d/hr-expense.json`)

**`Expense` 0.5.0 → 0.6.0** (additive, non-breaking — no `required` change): NEW `vendor` (string, nullable — "Vendor / leverancier name, either typed by the employee or prefilled by receipt-OCR extraction") and NEW `vatAmount` (number, nullable, `minimum: 0` — "VAT / BTW amount on the receipt, either typed by the employee or prefilled by receipt-OCR extraction").

**`ReceiptExtraction` v0.1.0** (`icon: TextRecognition`, `x-schema-org: schema:Action` — an automated action performed against a document, not a document itself); required `[expenseId, status]`:

| Field | Type | Notes |
|---|---|---|
| `expenseId` | string, uuid, `$ref` Expense | required — real `$ref`, in-register target per ADR-062 rule 7 |
| `status` | string, enum `pending\|extracted\|failed\|skipped-no-docudesk` | required |
| `overallConfidence` | number, nullable, `minimum: 0`, `maximum: 1` | docudesk's `overallConfidence`; null until an attempt completes |
| `extractedAmount` | number, nullable | raw extracted value, recorded even when NOT applied (amount was already set) |
| `extractedDate` | string, format date, nullable | raw extracted value |
| `extractedVendor` | string, nullable | raw extracted value |
| `extractedVatAmount` | number, nullable | raw extracted value |
| `appliedFields` | string, nullable | comma-separated Expense field names this attempt actually WROTE (D4) — the provenance trail |
| `requestedBy` | string, nullable | acting Nextcloud user id, plain string per ADR-046 (mirrors `Expense.approvedBy`), or null for 'system' (occ context) |
| `errorMessage` | string, nullable | set on `failed`/`skipped-no-docudesk` |
| `extractedAt` | string, format date-time, nullable | when the attempt completed |

## Manifest delta (`src/manifest.json`)

- `ExpenseDetail`: page-level `actions` gains `{id: "extract-receipt", label: "Extract receipt data", type: "api-call", icon: "TextRecognition", url: "/api/expenses/extract-receipt", method: "POST", params: {expenseId: "@objectId"}, confirm: true, successMessage: "Extraction started.", errorMessage: "Extraction failed."}` — the `PayslipDetail`/`generate-loonstrook` shape verbatim (concrete English i18n strings, not placeholders — pre-translated per ADR-007).
- `npm run check:manifest` MUST keep passing.

## Seed Data (ADR-001)

`hr-expense.json` `components.objects` gains one `ReceiptExtraction` example consistent with an existing seeded Expense (or a minimal new seeded Expense carrying a placeholder `receiptFile`, following the `hr-seed.json` placeholder convention — no real file behind the placeholder path, matching the `gendoc-loonstrook-jansen-2026-05` precedent): `status: "extracted"`, `overallConfidence: 0.92`, `extractedAmount`/`extractedVendor` filled, `appliedFields: "amount,vendor"`, `extractedAt` set — the schema's own green example for browsing.

## Risks / Trade-offs

- **`fields` key-name uncertainty (D4)** — the mapping constants are the grounded contract's natural reading, not independently verified against a live docudesk response; implementation confirms against HEAD (tasks.md carries this as an explicit verification step, not an assumption to code against blindly).
- **`amount === 0` as the "unset" sentinel** — a legitimately zero-value claim (rare, but not impossible: e.g. a fully-comped expense) would be treated as "empty" and could be overwritten by extraction. Accepted for MVP: a zero-amount Expense is an edge case the reimbursement lifecycle itself does not obviously support either, and `appliedFields`/`extractedAmount` stay visible for a reviewer to catch and correct it.
- **No re-extraction on receipt replacement** — an Expense whose receipt is swapped after a successful extraction keeps the old `extracted` record and prefilled values; a follow-up change (superseding the old `ReceiptExtraction` when `receiptFile` changes) is not delivered here (Non-Goals).
- **Two authorization models composed (D7)** — admin-group check + owner-string comparison, rather than a single OpenRegister-level RBAC rule. Consistent with how `PayrollController`/`MijnDeclaraties` already do it elsewhere in this app; introducing field-level RBAC is a separate, larger change.

## Open Questions

- None blocking. Follow-ups tracked in Non-Goals: re-extraction-on-receipt-replace, a dedicated HR Nextcloud group (currently reuses the admin gate, per the `PayrollController` precedent).
