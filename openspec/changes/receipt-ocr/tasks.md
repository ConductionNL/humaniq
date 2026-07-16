# Tasks — receipt-ocr

- [ ] 1. Schema: bump `Expense` to v0.6.0 in `lib/Settings/register.d/hr-expense.json` — additive `vendor` (string, nullable) and `vatAmount` (number, nullable, `minimum: 0`), no `required` change per REQ-RCPT-001
- [ ] 2. Schema: add `ReceiptExtraction` v0.1.0 to the same fragment (`expenseId` $ref required, `status` enum required, `overallConfidence`, `extractedAmount`/`extractedDate`/`extractedVendor`/`extractedVatAmount`, `appliedFields`, `requestedBy`, `errorMessage`, `extractedAt`) per REQ-RCPT-001
- [ ] 3. Service: `lib/Service/ReceiptExtractionService.php` — duck-typed `docudeskAvailable()` probe (`IAppManager::isInstalled('docudesk')` + guarded container resolve of `OCA\DocuDesk\Service\FinancialExtractionService` string FQCN, zero compile-time import) per REQ-RCPT-003
- [ ] 4. Service: `extractForExpense(string $expenseId, ?string $userId)` — resolve the Expense, fail closed with `failed` when `receiptFile` is empty, idempotency pre-check on `expenseId` (existing `extracted` → no-op, stale `pending` → superseded) per REQ-RCPT-004
- [ ] 5. Service: the `extractFinancial({fileId: receiptFile, docType: 'receipt'}, requestedBy)` call and the prefill-not-overwrite field mapping (D4's per-field "empty" rule) — verify the exact `fields` key names against the installed docudesk HEAD, not from memory, before wiring the mapping constants per REQ-RCPT-002
- [ ] 6. Service: record the attempt on `ReceiptExtraction` (raw extracted values for ALL four fields regardless of whether applied, `overallConfidence`, `appliedFields` naming only the fields actually written, `status`, `extractedAt`) per REQ-RCPT-002
- [ ] 7. Service: the write path is a plain field-merge save on `Expense` — assert no code path calls an `x-openregister-lifecycle` transition; `status` is never part of the saved payload per REQ-RCPT-005
- [ ] 8. Service: backlog — every `Expense` with a non-empty `receiptFile` and no active (`pending`/`extracted`) `ReceiptExtraction`, optionally narrowed to one `--expense` id per REQ-RCPT-006
- [ ] 9. Command: `lib/Command/ExpenseExtractReceiptCommand.php` — `hrmq:expense:extract-receipt [--expense <id>]`, default backlog, `--expense` narrows to one (a receiptFile-less Expense → single `failed` outcome, exit 1) per REQ-RCPT-006
- [ ] 10. Controller: `lib/Controller/ExpenseController.php` (NEW) — `extractReceipt(string $expenseId)`, `authorizeExpense()` resolve-first RBAC (404 on not-found/unauthorized) THEN explicit ownership check (`IGroupManager::isAdmin()` OR `Expense.userId === caller uid`, else 403) per REQ-RCPT-007
- [ ] 11. Route: `appinfo/routes.php` — `['name' => 'expense#extract-receipt', 'url' => '/api/expenses/extract-receipt', 'verb' => 'POST']` per REQ-RCPT-007
- [ ] 12. Manifest: `ExpenseDetail` page-level `api-call` action "Extract receipt data" (`{expenseId: "@objectId"}`, confirm, pre-translated toasts, `PayslipDetail`/`generate-loonstrook` shape); `npm run check:manifest` passes per REQ-RCPT-007
- [ ] 13. Seed: one `ReceiptExtraction` example (`status: extracted`, populated confidence/extracted values/`appliedFields`) consistent with a seeded Expense+receiptFile in `hr-expense.json` per REQ-RCPT-001
- [ ] 14. Unit tests: `tests/Unit/Service/ReceiptExtractionServiceTest.php` (mocked container/services per the `HrDocumentServiceTest` setup) — prefill-not-overwrite mapping (each of the 4 fields, both empty and already-filled cases), idempotency no-op/supersede, duck-typed `skipped-no-docudesk` degradation, missing-receiptFile `failed`, and the human-in-the-loop assertion that `status` is absent from every save payload
- [ ] 15. Quality gates: `composer check:strict` green; in the dev container run the register import, `occ hrmq:expense:extract-receipt` with and without docudesk enabled, the ExpenseDetail action end-to-end, and confirm the four letter/loonstrook/jaaropgaaf docudesk flows still pass unchanged (no regression on the rendering leaf)

Acceptance criteria (plain reminders, not tasks):
- no HTTP path to docudesk anywhere; container-resolved PHP service only, string FQCN, zero compile-time import (mirrors the rendering leaf's invariant)
- extraction NEVER overwrites a non-empty Expense field and NEVER changes `Expense.status`
- `skipped-no-docudesk` and `failed` never throw past `ReceiptExtractionService`'s public methods and are always retryable
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH per ADR-007; no Co-Authored-By trailers
