---
status: tasks
created: 2026-05-23
---

# Tasks: Implementation Checklist

## 1. Data Layer (Entities & Mappers)

- [ ] 1.1 Create `lib/Db/Declaratie.php` entity with properties from context-brief (soort, categorie, wkr_classificatie, bedrag_incl_btw, status, ingediend_op, goedgekeurd_op, audit_trail_id, etc.)
- [ ] 1.2 Create `lib/Db/DeclaratieMapper.php` extending QBMapper with methods:
  - `find(int $id)` — single declaratie
  - `findByEmployeeAndPeriod(string $empId, string $dateFrom, string $dateTo)` — period-scoped list
  - `findByStatus(string $status, int $limit, int $offset)` — filterable approval queue
  - `findDuplicateReceipt(string $sha256Hash, string $empId, string $withinMonths)` — duplicate detection
- [ ] 1.3 Create `lib/Db/Bonnetje.php` entity with properties (declaratie_id, document_id, filename, mime_type, ocr_confidence, ocr_velden, hash_sha256, duplicate_check_resultaat)
- [ ] 1.4 Create `lib/Db/BonnetjeMapper.php` with methods:
  - `find(int $id)` — single receipt
  - `findByDeclaratieId(string $decId)` — receipts for a declaratie
  - `findBySHA256(string $hash)` — duplicate lookup
- [ ] 1.5 Create `lib/Db/KilometerRit.php` entity with properties (declaratie_id, datum, vertrek_adres, aankomst_adres, afstand_km, zakelijk_doel, tarief_belastingvrij_per_km, bedrag_belastingvrij, bedrag_belast, tracking_methode, gps_log_id)
- [ ] 1.6 Create `lib/Db/KilometerRitMapper.php` with methods:
  - `find(int $id)` — single rit
  - `findByDeclaratieId(string $decId)` — rits for a declaratie (may be multiple for bulk import)
- [ ] 1.7 Create `lib/Db/VasteVergoeding.php` entity with properties (employee_id, soort, bedrag_per_maand, wkr_classificatie, wkr_grondslag, ingangsdatum, einddatum, steekproef_laatste, steekproef_volgende_vereist_voor, onderbouwing_document_id)
- [ ] 1.8 Create `lib/Db/VasteVergoedingMapper.php` with methods:
  - `find(int $id)` — single allowance
  - `findByEmployeeId(string $empId)` — all active allowances for an employee
  - `findNeedingSampling(string $asOfDate)` — allowances due for sampling audit
- [ ] 1.9 Create `lib/Db/ApprovalStap.php` entity with properties (declaratie_id, stap_nummer, rol, approver_user_id, status, beslissing, beslissing_op, opmerking, delegatie_van_user_id, created_at, updated_at)
- [ ] 1.10 Create `lib/Db/ApprovalStapMapper.php` with methods:
  - `findByDeclaratieId(string $decId)` — all steps for a declaratie
  - `findPendingByApproverAndRole(string $userId, string $role)` — queue of pending approvals
  - `findOverdueByAge(int $workdaysThreshold)` — escalation candidates
- [ ] 1.11 Create `lib/Db/WKRBudget.php` entity with properties (id, kalenderjaar, loonsom_grondslag, vrije_ruimte_percentage_eerste_400k, vrije_ruimte_percentage_boven_400k, vrije_ruimte_beschikbaar, vrije_ruimte_verbruikt_ytd, vrije_ruimte_verbruikt_pct, waarschuwing_75pct_verzonden, waarschuwing_100pct_verzonden, eindheffing_verschuldigd, last_updated)
- [ ] 1.12 Create `lib/Db/WKRBudgetMapper.php` with methods:
  - `findByYear(int $year)` — single year's budget
  - `updateConsumption(int $year, float $deltaBedrag)` — increment YTD usage

---

## 2. Business Logic Services

- [ ] 2.1 Create `lib/Service/DeclaratieService.php` with methods:
  - `createDraft(string $empId, array $payload)` — create in `bewerken` status (no submission yet)
  - `submitDeclaratieWithValidation(string $decId)` — validate bewijsstuk, duplicate, WKR-class, then set ingediend_op + trigger approval-workflow
  - `approveDeclaratieStep(string $decId, int $stepNum, string $approverId, bool $approved, ?string $comment)` — record approval, create next step or finalize
  - `rejectDeclaratieWithReason(string $decId, string $reason)` — move to `afgewezen` status
  - `getDeclaratieWithAuditTrail(string $decId)` — return declaratie + full lifecycle log
- [ ] 2.2 Create `lib/Service/BonnetjeOCRService.php` with methods:
  - `uploadAndOCR(string $decId, IFile $file)` — save file, trigger docudesk OCR, store confidence + field metadata
  - `detectDuplicate(string $sha256, string $empId)` — check hash against last 12 months
  - `applyOCRFieldsToDeclaratieForm(string $decId, array $ocrResult, float $confidence)` — pre-populate form fields with gecorrigeerd_door_gebruiker tracking
- [ ] 2.3 Create `lib/Service/KilometerService.php` with methods:
  - `createManualRit(string $decId, string $fromAddr, string $toAddr, string $date)` — call openconnector for distance, create KilometerRit
  - `createGPSTrackedRit(string $decId, array $gpsPoints, string $date)` — validate per-rit consent, save GPS trace, calculate distance
  - `bulkImportRitsFromCSV(string $decId, IFile $file)` — parse lease-system CSV, create multiple KilometerRit rows
  - `calculateTaxFreeAndTaxedSplit(float $claimedRate, float $kmDistance, int $year)` — fetch year tariff, calculate split
- [ ] 2.4 Create `lib/Service/ApprovalWorkflowService.php` with methods:
  - `determineApprovalStepsForDeclaratieXML(string $decId, string $buId)` — read BU config, apply rules (threshold, category, WKR-class, role), return list of (step_num, role)
  - `createApprovalSteps(string $decId, array $steps)` — create ApprovalStap records, start with step 1
  - `escalateOverdueSteps()` — async job, find overdue steps, escalate to next role or finance
- [ ] 2.5 Create `lib/Service/WKRBudgetService.php` with methods:
  - `initializeYearBudget(int $year, float $loonsom_grondslag, string $buId)` — create WKRBudget record, calculate vrije_ruimte_beschikbaar
  - `consumeVrijRuimte(string $decId, float $bedrag)` — update vrije_ruimte_verbruikt_ytd, check 75%/100% thresholds, send alerts
  - `checkBudgetOverageAndCalculateEindheffing(int $year)` — at year-end, calculate 80% eindheffing on overage
  - `projectedConsumption(int $year, int $forecastMonths)` — simple monthly extrapolation for dashboard
- [ ] 2.6 Create `lib/Service/CurrencyService.php` with methods:
  - `fetchECBRate(string $valuta, string $date)` — call openconnector for reference rate, cache for 24h
  - `convertToEUR(float $amount, string $valuta, string $date)` — apply rate + return EUR equivalent
  - `promptManualRate(string $valuta)` — return user-prompt UI field (out-of-scope for this task; return stub)
- [ ] 2.7 Create `lib/Service/DeclaratieRoutingService.php` with methods:
  - `routeToShillinqOrPayroll(string $decId)` — inspect WKR-class + YTD budget, create shillinq AP entry or payroll bijtelling, record routing decision in declaratie
  - `createShillinqAPEntry(string $decId, string $glAccount)` — format AP-ready payload (creditor IBAN, amount, GL, invoice ref) for handoff to shillinq integration
  - `createPayrollBitelling(string $decId, float $amount)` — prepare payroll-engine-nl bijtelling record
- [ ] 2.8 Create `lib/Service/DeclaratieExportService.php` with methods:
  - `exportToCSV(array $filters)` — filters: {empId?, dateFrom?, dateTo?, kategorie?, wkr_classificatie?}, return CSV stream with audit columns
  - `exportToJSON(array $filters)` — same filters, return JSON array with full audit_trail sub-documents

---

## 3. API Controllers

- [ ] 3.1 Create `lib/Controller/DeclaratieController.php` with routes:
  - `GET /api/declaraties` — list, filters: {status, empId, period}, pagination
  - `POST /api/declaraties` — create draft
  - `GET /api/declaraties/{id}` — detail view + audit trail
  - `PATCH /api/declaraties/{id}` — edit pre-submission fields
  - `POST /api/declaraties/{id}/submit` — finalize + trigger approval
  - `POST /api/declaraties/{id}/approve` — approval action (approver-only)
  - `POST /api/declaraties/{id}/reject` — rejection (approver-only)
- [ ] 3.2 Create `lib/Controller/BonnetjeController.php` with routes:
  - `POST /api/bonnetjes` — upload receipt file + trigger OCR
  - `GET /api/bonnetjes/{id}` — fetch receipt metadata + preview (if PDF/image)
  - `DELETE /api/bonnetjes/{id}` — remove receipt (pre-submission only)
- [ ] 3.3 Create `lib/Controller/KilometerController.php` with routes:
  - `POST /api/kilometers` — create manual rit (from-addr, to-addr, date) or GPS-tracked or bulk CSV
  - `GET /api/kilometers/{id}` — rit detail
  - `PATCH /api/kilometers/{id}` — edit pre-submission
  - `DELETE /api/kilometers/{id}` — remove rit (pre-submission only)
- [ ] 3.4 Create `lib/Controller/VasteVergoedingController.php` with routes:
  - `GET /api/vaste-vergoedingen` — list (employee or manager filtered)
  - `POST /api/vaste-vergoedingen` — create (admin/HR only)
  - `GET /api/vaste-vergoedingen/{id}` — detail
  - `PATCH /api/vaste-vergoedingen/{id}` — edit allowance (admin/HR only)
  - `POST /api/vaste-vergoedingen/{id}/sampling-audit` — attach onderbouwing_document_id + mark as audited
- [ ] 3.5 Create `lib/Controller/WKRBudgetController.php` with routes:
  - `GET /api/wkr-budget` — current year budget + YTD consumption
  - `GET /api/wkr-budget/{year}` — historical year budget
- [ ] 3.6 Create `lib/Controller/DeclaratieExportController.php` with routes:
  - `GET /api/export/audit-csv` — query params: {from, to, empId?, category?}
  - `GET /api/export/audit-json` — same params, JSON response

---

## 4. Integrations (Stubs & Placeholders)

- [ ] 4.1 Create `lib/Integration/Docudesk.php` (stub) — `ocrReceipt(IFile $file): array` with mocked confidence + field output
- [ ] 4.2 Create `lib/Integration/OpenConnector.php` (stub) — `fetchECBRate(string $valuta, string $date): float`, `calculateDistance(string $fromAddr, string $toAddr): float`
- [ ] 4.3 Create `lib/Integration/Shillinq.php` (stub) — `createAPEntry(array $payload): string` returns invoice-ref
- [ ] 4.4 Create `lib/Integration/PayrollEngine.php` (stub) — `createBijtelling(array $payload): string` returns run-ref, `fetchLoonsom(int $year): float`

---

## 5. Background Jobs & Async Tasks

- [ ] 5.1 Create `lib/BackgroundJob/ApprovalEscalationJob.php` — daily job: find overdue approval-steps, escalate to next level
- [ ] 5.2 Create `lib/BackgroundJob/WKRBudgetAlertJob.php` — daily job: check 75% + 100% thresholds, send notifications
- [ ] 5.3 Create `lib/BackgroundJob/SamplingAuditReminderJob.php` — weekly job: find VasteVergoedings due for sampling, send HR reminders
- [ ] 5.4 Create `lib/BackgroundJob/DeclaratieRoutingJob.php` — hourly job: find approved declaraties not yet routed, call DeclaratieRoutingService

---

## 6. Migrations & Schema

- [ ] 6.1 Create `lib/Migration/Version*.php` to create tables:
  - `hrmq_declaratie` (id, employee_id, soort, categorie, wkr_classificatie, bedrag_incl_btw, status, ingediend_op, goedgekeurd_op, afgewezen_op, audit_trail_id, created_at, updated_at, indexes on employee_id + status + ingediend_op)
  - `hrmq_bonnetje` (id, declaratie_id, document_id, mime_type, size_bytes, ocr_confidence, ocr_velden (JSON), hash_sha256, created_at, indexes on declaratie_id + hash_sha256 + employee_id-derived)
  - `hrmq_kilometer_rit` (id, declaratie_id, datum, vertrek_adres, aankomst_adres, afstand_km, bedrag_belastingvrij, bedrag_belast, gps_log_id, created_at)
  - `hrmq_vaste_vergoeding` (id, employee_id, soort, bedrag_per_maand, wkr_classificatie, ingangsdatum, einddatum, steekproef_volgende_vereist_voor, created_at, updated_at, indexes on employee_id)
  - `hrmq_approval_stap` (id, declaratie_id, stap_nummer, rol, approver_user_id, status, beslissing, opmerking, created_at, updated_at, indexes on declaratie_id + approver_user_id)
  - `hrmq_wkr_budget` (id, kalenderjaar, vrije_ruimte_beschikbaar, vrije_ruimte_verbruikt_ytd, vrije_ruimte_verbruikt_pct, created_at, updated_at, unique on kalenderjaar)

---

## 7. Tests (Unit + Integration)

- [ ] 7.1 Create `tests/Unit/Service/DeclaratieServiceTest.php` with cases:
  - `testCreateDraftDeclaratieWithValidPayload()` — status = bewerken
  - `testSubmitDeclaratieRequiresBewijsstuk()` — blocks >€10 without receipt
  - `testSubmitDeclaratieAllowsNoReceiptFor≤€10WithReason()` — accepts with geen_bewijsstuk_reden
  - `testDetectDuplicateReceiptOnSubmit()` — blocks if SHA-256 matches prior entry
  - `testWKRClassificatieRequired()` — blocks submit without wkr_classificatie
  - `testApprovalWorkflowTriggeredOnSubmit()` — creates ApprovalStap records
- [ ] 7.2 Create `tests/Unit/Service/BonnetjeOCRServiceTest.php`:
  - `testOCRPreFillsFormFields()` — confidence ≥0.80 auto-populates, marks as editable
  - `testOCRLowConfidenceWarning()` — confidence < 0.80 flags fields for manual review
  - `testDuplicateSHA256Detection()` — returns duplicate ref within 12-month window
- [ ] 7.3 Create `tests/Unit/Service/KilometerServiceTest.php`:
  - `testManualRitCalculatesTaxFreeAndTaxedSplit()` — €0.30/km claim, €0.23/km free → €23 free + €7 taxed for 100km
  - `testBulkCSVImport()` — parses lease CSV, creates multiple KilometerRit rows
- [ ] 7.4 Create `tests/Unit/Service/ApprovalWorkflowServiceTest.php`:
  - `testDetermineStepsBasedOnThreshold()` — <€50 → 1 step, €50-€500 → 2 steps
  - `testEscalateOverdueSteps()` — steps age > 5 workdays move to next level
- [ ] 7.5 Create `tests/Unit/Service/WKRBudgetServiceTest.php`:
  - `testConsumeVrijRuimteUpdatesYTD()` — verbruikt_ytd increments, pct recalculated
  - `testWarn75And100Percent()` — notifications sent at thresholds
  - `testCalculateEindheffingAt80()` — overage × 0.80 recorded at year-end
  - `testProjectedConsumption()` — monthly extrapolation for dashboard
- [ ] 7.6 Create `tests/Integration/DeclaratieLifecycleTest.php`:
  - `testFullDeclaratieWorkflow()` — from submission → approval → routing → shillinq export

---

## 8. Documentation & Examples

- [ ] 8.1 Update `README.md` with Declaratie section: overview, key entities, API examples
- [ ] 8.2 Create `docs/declaratie/mobile-scan-flow.md` — step-by-step screenshot guide (future: with UI)
- [ ] 8.3 Create `docs/declaratie/wkr-guide.md` — Belastingdienst classification guide + examples
- [ ] 8.4 Create `docs/declaratie/api-examples.md` — cURL/Postman examples for each endpoint

---

## 9. Verify & Quality Gates

- [ ] 9.1 Run `composer test` — all unit + integration tests pass
- [ ] 9.2 Run `composer check:strict` — PHP lint, PHPCS, PHPMD, Psalm, PHPStan all pass
- [ ] 9.3 Verify `@spec` tags on all public classes/methods linking to this change's tasks.md
- [ ] 9.4 Verify app starts (`make local-run` or equiv) without errors
- [ ] 9.5 Manual test: Mobile scan workflow completes in ≤30 seconds
- [ ] 9.6 Manual test: Approval workflow routing works (shillinq + payroll stubs receive correct payload)
- [ ] 9.7 Manual test: WKR-budget tracking increments and warnings trigger at 75%/100%

---

