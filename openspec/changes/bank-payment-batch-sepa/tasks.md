---
status: proposed
change: bank-payment-batch-sepa
title: SEPA Payment Batch (Salaris-Uitbetaling) — Implementation Tasks
app: hrmq
version: 1.0
date: 2026-05-23
---

# Implementation Tasks: SEPA Payment Batch

All tasks are ordered by implementation sequence (data model → core logic → UI → integrations → tests).

## Phase 1: Database & Data Model

- [ ] **T-101: Create hrmq_payment_batch table**
  - Create migration: `migrations/202605_001_create_payment_batch_table.sql`
  - Columns: id, tenant_id, payroll_run_id, batch_reference, status (enum), total_amount, payment_count, execution_date, pain001_xml_blob_url, pain001_xml_hash_sha256, pain001_message_id, bank_endpoint_id, previous_batch_hash, created_at, updated_at
  - Indices: (tenant_id, payroll_run_id) UNIQUE, (tenant_id, status), (execution_date)
  - Add check constraint: total_amount ≥ 0

- [ ] **T-102: Create hrmq_payment_batch_item table**
  - Create migration: `migrations/202605_002_create_payment_batch_item_table.sql`
  - Columns: id, batch_id, employee_id, iban, bic, amount, currency, omschrijving, end_to_end_id, composition_breakdown (jsonb), status (enum), rejection_reason_code, rejection_reason_description, created_at, updated_at
  - Indices: (batch_id, employee_id) UNIQUE, (batch_id, status), (batch_id, end_to_end_id) UNIQUE
  - FK: batch_id → hrmq_payment_batch.id (ON DELETE CASCADE)
  - Add check: amount ≥ 0, omschrijving length ≤ 140

- [ ] **T-103: Create hrmq_payment_approval table**
  - Create migration: `migrations/202605_003_create_payment_approval_table.sql`
  - Columns: id, batch_id, approver_user_id, role, decision (enum: approved/rejected/pending), timestamp, comment, sca_token_reference, created_at
  - Indices: (batch_id, approver_user_id) to prevent duplicate approval by same user
  - FK: batch_id → hrmq_payment_batch.id (ON DELETE CASCADE)
  - Add check: comment is not null and length > 0

- [ ] **T-104: Create hrmq_payment_bank_endpoint table**
  - Create migration: `migrations/202605_004_create_payment_bank_endpoint_table.sql`
  - Columns: id, tenant_id, bank_name, connection_type (enum: psd2_api/sftp/manual_download), connection_credentials_ref, pain_version, iban_debtor, created_at, updated_at
  - Indices: (tenant_id, bank_name) to support switching between bank endpoints
  - Add check: iban_debtor is valid IBAN format

- [ ] **T-105: Create hrmq_payment_batch_status_update table**
  - Create migration: `migrations/202605_005_create_payment_batch_status_update_table.sql`
  - Columns: id, batch_id, batch_item_id, pain002_message_id, received_at, status_code, status_description, created_at
  - Indices: (batch_id, batch_item_id), (received_at) for sorting by time
  - FKs: batch_id → hrmq_payment_batch.id, batch_item_id → hrmq_payment_batch_item.id

- [ ] **T-106: Create iban_bic_lookup table**
  - Create migration: `migrations/202605_006_create_iban_bic_lookup_table.sql`
  - Columns: id, iban_prefix (first 8 chars), bic, country_code, bank_name, updated_at
  - Indices: (iban_prefix) UNIQUE, (country_code) for country-based filtering
  - Seed initial data from SWIFT BIC lookup (monthly update job to follow)

- [ ] **T-107: Run all migrations**
  - Execute: `php artisan migrate --path=database/migrations/2026_05_*`
  - Verify all tables exist with correct structure
  - Verify all indices and constraints are in place

---

## Phase 2: Core Business Logic

- [ ] **T-201: Implement PaymentBatchGenerator service**
  - File: `app/Services/PaymentBatchGenerator.php`
  - Methods:
    - `generate(payrollRunId: string): PaymentBatch` — main entry point
    - `fetchPayrollData(payrollRunId: string): PayrollData` — calls payroll-engine-nl API
    - `fetchExpenseData(payrollRunId: string): ExpenseData[]` — calls expense-reimbursement API
    - `aggregateByEmployee(payrollData, expenseData): AggregatedData[]` — combines components
    - `validatePaymentData(AggregatedData[]): ValidationResult` — IBAN checksum, country-code
    - `deriveBIC(iban: string): string` — lookup BIC from table or returns null
    - `createBatchRecord(aggregatedData, validationResult): PaymentBatch` — DB insert
    - `createBatchItems(batch, aggregatedData): void` — DB insert items
  - Error handling: Validation errors logged but don't block batch creation (items marked held_invalid_iban)
  - Idempotency: Check unique constraint on (tenant_id, payroll_run_id) before creating

- [ ] **T-202: Implement IBAN validation (ISO 13616 mod-97)**
  - File: `app/Services/IBANValidator.php`
  - Method: `validate(iban: string): ValidationResult` with fields:
    - `isValid: bool`
    - `countryCode: string` (extracted from iban[0:2])
    - `message: string` (error description if invalid)
  - Validate checksum via mod-97 algorithm
  - Validate country-code against SEPA-EU whitelist
  - Unit tests: Valid IBANs (NL, BE, DE), invalid checksums, unsupported countries

- [ ] **T-203: Implement pain.001.001.09 XML generator**
  - File: `app/Services/SEPA/Pain001Generator.php`
  - Depends on: shillinq SEPA-module (shared library)
  - Methods:
    - `generate(batch: PaymentBatch): string` (returns XML)
    - `validateAgainstXSD(xmlString: string): bool` — uses official pain.001.001.09 XSD
  - XML structure:
    - GroupHeader: MsgId, CreDtTm, NbOfTxs, CtrlSum
    - PaymentInformation: single debtor, Dbtr/DbtrAcct, ReqdExctnDt
    - CreditTransferTransactionInformation[] per item: EndToEndId, Amount, Cdtr, CdtrAcct, RmtInf
  - XSD validation must pass before batch is submitted
  - Unit tests: Valid batch (240 items), edge cases (1 item, EUR 0.01), checksum validation

- [ ] **T-204: Implement pain.002.001.10 XML parser**
  - File: `app/Services/SEPA/Pain002Parser.php`
  - Depends on: shillinq SEPA-module
  - Method: `parse(xmlString: string): Payment002Report` with fields:
    - `transactionStatuses: array[]` with OrgnlEndToEndId, TxSts, RjctRsn
  - Extract status codes: ACPT, ACSP, RJCT (and others per ISO 20022)
  - Unit tests: Valid pain.002 (239 accepted, 1 rejected), missing OrgnlEndToEndId handling

- [ ] **T-205: Implement batch approval workflow**
  - File: `app/Services/PaymentBatchApprover.php`
  - Methods:
    - `determineApprovalRoute(batch): string` — returns 'self_approval' | 'finance_review' | 'dual_review' based on amount and tenant-config thresholds
    - `approve(batch, approverUserId, approverRole, comment): void` — creates hrmq_payment_approval record, updates batch.status
    - `reject(batch, approverUserId, approverRole, comment): void` — sets batch.status = 'concept'
    - `validateApprover(batch, user): bool` — check if user has required role
  - Thresholds configurable per tenant: finance_threshold (default EUR 100k), cfo_threshold (default EUR 500k)
  - Comment validation: non-empty, max 1000 chars
  - Audit logging: every approval/rejection decision

- [ ] **T-206: Implement pre-notification email scheduler**
  - File: `app/Jobs/SendPaymentPreNotificationEmails.php`
  - Queued job (runs async, e.g., daily at 08:00 UTC)
  - Logic:
    - Find all approved batches with execution_date = today + 2 business days
    - For each batch, iterate items (status: pending or accepted)
    - For each employee with pre_notification_enabled = true, generate email
    - Email template: `salaris-betaling-vooraf.hbs` (via document-template-engine)
    - Variables: execution_date, amount, iban_last_4, composition_breakdown (formatted as table)
    - Send via mail-queue (Laravel mail)
  - Retry on temporary mail-server failures
  - Audit log: "Pre-notification sent to X employees for batch Y"

- [ ] **T-207: Implement bank submission orchestrator**
  - File: `app/Services/BankSubmissionOrchestrator.php`
  - Methods:
    - `submit(batch, user): void` — main entry point
    - `submitViaPSD2API(batch, endpoint): TransactionId` — calls openconnector adapter
    - `submitViaSFTP(batch, endpoint): void` — uses PHP SFTP library
    - `handleSCAChallenge(endpoint, user): SCAToken` — opens SCA modal, waits for completion
  - Error handling:
    - Network errors: throw exception, batch remains in 'approved' state
    - Bank rejection: log and return error message
  - Update batch.status → 'submitted' only on successful submission
  - Schedule pain.002 polling job immediately after successful submission

- [ ] **T-208: Implement pain.002 reconciliation worker**
  - File: `app/Jobs/ReconcilePaymentBatches.php`
  - Queued job (polls every 1 hour via scheduler)
  - Logic:
    - Find all submitted batches (status = 'submitted' or 'partially_executed')
    - For each batch: fetch pain.002 report from bank (via openconnector adapter)
    - Parse pain.002 XML using Pain002Parser (T-204)
    - For each transaction-status in report:
      - Match OrgnlEndToEndId → hrmq_payment_batch_item
      - Update item.status based on TxSts (ACPT → 'accepted', ACSP → 'accepted_settlement_completed', RJCT → 'rejected_with_reason')
      - If rejected: extract rejection_reason_code and description, create HR work-item
      - Create hrmq_payment_batch_status_update record
    - Recalculate batch.status: all-settled → 'executed', some-settled → 'partially_executed', all-rejected → 'failed'
    - Audit log per batch: "Reconciliation complete; X settled, Y rejected"

- [ ] **T-209: Implement HR work-queue integration**
  - File: `app/Services/HRWorkQueue.php`
  - Method: `createRejectionItem(batchItem, rejectionReason): WorkItem`
  - Work-item fields: title, description, assigned_to (HR-admin role), status (open), due_date
  - Title: "Payment rejection: [Employee Name] ([end_to_end_id])"
  - Description: "Payment for batch [batch_reference] was rejected by bank: [reason]. Action: verify employee bank-details and retry in next batch."
  - Integration: Send notification email to HR-admin group

- [ ] **T-210: Implement audit-trail & hash-chain**
  - File: `app/Services/AuditTrailService.php`
  - Methods:
    - `recordBatchApproval(batch, approver, decision): void` — audit log entry
    - `recordBatchSubmission(batch, submitter): void` — audit log entry
    - `recordBatchReconciliation(batch): void` — audit log entry
    - `calculateBatchHash(batch): string` — SHA256 of approved batch state (includes all approvals, amounts, items)
    - `verifyHashChain(batchYear): VerificationResult` — validate that each batch.previous_batch_hash matches previous batch's hash
  - Audit log schema: batch_id, event_type, actor, timestamp, details (JSON)
  - Hash calculation: Deterministic JSON serialization of batch + approvals, then SHA256

---

## Phase 3: UI & Frontend

- [ ] **T-301: Create payment-batch list page**
  - File: `resources/js/pages/PaymentBatchList.vue`
  - Component structure:
    - Header: "Salarissen › SEPA-batches"
    - Toolbar: [Nieuwe batch genereren] [Filters] [Export] [Settings]
    - Month filter: Dropdown (May 2026 default), or date-range picker
    - Table: batch_reference, status (with icon), total_amount (formatted), payment_count, execution_date
    - Status icons: ✅ Executed, ⏳ Pending, 🚨 Partial, ❌ Failed
    - Row click → detail page
    - Pagination: 20 items per page
  - Data fetching: GET /api/hrmq/payment-batch?month=202605&page=1
  - Responsive: Mobile-friendly (stack columns, hide execution_date on small screens)

- [ ] **T-302: Create payment-batch detail page**
  - File: `resources/js/pages/PaymentBatchDetail.vue`
  - Two-column layout:
    - Left: Batch summary (reference, status, total, count, execution_date)
    - Right: Batch actions ([Approve] [Reject] [Submit] [Download XML] [Audit Report])
  - Tabs:
    - Tab 1: "Payment Items" (table, paginated, sortable by amount/status)
      - Columns: employee (name, ID), iban (masked), bic, amount, status, rejection-reason (if rejected)
      - Row-color: green for accepted, red for rejected, yellow for pending
    - Tab 2: "Approval History" (timeline)
      - Entry: "Approved by [user] on [date] with comment [...]"
      - Entry: "Rejected by [user] on [date] with comment [...]"
    - Tab 3: "Bank Submission" (if submitted)
      - Submission timestamp, transaction_id, bank-name, status
      - [Re-download pain.001.xml]
  - Data fetching: GET /api/hrmq/payment-batch/{batchId}, GET /api/hrmq/payment-batch/{batchId}/items, GET /api/hrmq/payment-batch/{batchId}/approvals
  - Error handling: Show toast notifications on submit failure

- [ ] **T-303: Create batch-generation modal**
  - File: `resources/js/components/GenerateBatchModal.vue`
  - Triggered from list page [Nieuwe batch genereren]
  - Form fields:
    - Payroll-run select (dropdown, populated from payroll-engine-nl API)
    - Bank endpoint select (dropdown, populated from hrmq_payment_bank_endpoint)
    - Execution-date picker (default: next working day after payroll-run period-end)
  - On submit:
    - POST /api/hrmq/payment-batch/generate with payrollRunId, bankEndpointId, executionDate
    - Show spinner with progress: "Generating batch... (fetching payroll data...)" → "Validating IBANs..." → "Creating XML..." → "Batch created!"
    - On success: Redirect to detail page, show toast "Batch generated: X items, EUR Y.YY"
    - On error: Show error toast + details

- [ ] **T-304: Create batch-approval UI**
  - File: `resources/js/components/ApprovalPanel.vue`
  - Displayed in detail-page right panel when batch status is 'concept' or 'pending_approval_*'
  - Display approval-route (self | finance | cfo)
  - Form fields:
    - Comment textarea (required, max 1000 chars)
    - [Approve] [Reject] buttons
  - On approve click:
    - If SCA required: Open SCA modal (via openconnector), get SCA token
    - POST /api/hrmq/payment-batch/{batchId}/approve with comment, scaToken
    - Show spinner + success toast
    - Refresh batch detail
  - On reject click:
    - POST /api/hrmq/payment-batch/{batchId}/reject with comment
    - Show spinner + success toast
    - Batch returns to 'concept', detail page updates

- [ ] **T-305: Create bank-submission UI**
  - File: `resources/js/components/BankSubmissionPanel.vue`
  - Displayed in detail-page right panel when batch.status = 'approved'
  - Display bank endpoint name, connection type
  - Form fields: None (submission is automatic once approve is clicked)
  - Display: "Ready to submit to [bank-name] ([connection-type])"
  - [Submit to bank] button
  - On submit click:
    - If SCA required at submission-time: Open SCA modal
    - POST /api/hrmq/payment-batch/{batchId}/submit with scaToken (if applicable)
    - Show spinner: "Submitting to bank..."
    - On success: Show toast "Batch submitted to [bank]; transaction_id [...]"
    - Batch.status → 'submitted', detail page updates
    - On error: Show error toast with bank-error-message

- [ ] **T-306: Create audit-report generation modal**
  - File: `resources/js/components/AuditReportModal.vue`
  - Triggered from detail page [Audit Report]
  - Form fields:
    - Year select (2026 default)
    - Export format radio: PDF | CSV
  - On submit:
    - POST /api/hrmq/payment-batch/audit-report with year, format
    - Show spinner: "Generating audit-report..."
    - On success: Download PDF or CSV file
    - Show toast: "Audit report downloaded"

- [ ] **T-307: Create invalid-IBAN work-queue UI**
  - File: `resources/js/pages/PaymentBatchRejectionQueue.vue` (sub-page under Salarissen › SEPA-batches › Rejections)
  - Table: batch_reference, employee (name, ID), rejection-reason, iban-in-batch, status (open | resolved)
  - Row actions: [Correct IBAN] → inline-edit + save
    - Saves corrected IBAN to employee record
    - Offers option: "Retry batch generation with corrected data"
  - Resolved items show: "Corrected on [date], resubmitted in batch [ref]"

---

## Phase 4: API Endpoints

- [ ] **T-401: Implement GET /api/hrmq/payment-batch**
  - Query params: tenant_id (implicit from auth), month (optional, format YYYYMM), status (optional), page (optional, default 1), per_page (optional, default 20)
  - Response: paginated list of batches with summary fields (id, batch_reference, status, total_amount, payment_count, execution_date, created_at)
  - Authorization: User must have payroll-admin, finance-controller, or cfo role

- [ ] **T-402: Implement GET /api/hrmq/payment-batch/{batchId}**
  - Response: Full batch object with all fields + related approval-records count
  - Authorization: User must be in same tenant and have read permission

- [ ] **T-403: Implement GET /api/hrmq/payment-batch/{batchId}/items**
  - Query params: page (default 1), per_page (default 50), status (optional), sort_by (optional: amount_desc)
  - Response: paginated list of batch-items with all fields
  - Authorization: Same as T-402

- [ ] **T-404: Implement GET /api/hrmq/payment-batch/{batchId}/approvals**
  - Response: List of hrmq_payment_approval records in chronological order
  - Fields: approver_user_id, approver_name, role, decision, timestamp, comment, sca_token_reference (masked)
  - Authorization: Same as T-402

- [ ] **T-405: Implement POST /api/hrmq/payment-batch/generate**
  - Request body: { payrollRunId: UUID, bankEndpointId: UUID, executionDate: YYYY-MM-DD }
  - Response: { id: UUID, batch_reference: string, status: string, items_count: int, total_amount: float, validation_messages: string[] }
  - Validation: payroll-run must exist and be in 'completed' state; execution-date must be ≥ today + 1 business day
  - Authorization: User must have payroll-admin role
  - Error responses: 400 (validation), 409 (duplicate batch for same payroll-run), 500 (service failure)

- [ ] **T-406: Implement POST /api/hrmq/payment-batch/{batchId}/approve**
  - Request body: { comment: string, scaToken: string (optional) }
  - Response: { id: UUID, status: string, next_status: string (if routing to CFO) }
  - Validation: comment must be non-empty; batch must be in 'concept' or 'pending_approval_*' state
  - Authorization: User must have appropriate role (payroll-admin for self-approve, finance-controller for >100k, cfo for >500k)
  - Side-effects: Create hrmq_payment_approval record; update batch.status
  - If batch routes to next approval-step: Send notification email to next-approvers

- [ ] **T-407: Implement POST /api/hrmq/payment-batch/{batchId}/reject**
  - Request body: { comment: string }
  - Response: { id: UUID, status: string (concept) }
  - Authorization: Same role-checking as approve
  - Side-effects: Create hrmq_payment_approval record with decision='rejected'; batch.status → 'concept'

- [ ] **T-408: Implement POST /api/hrmq/payment-batch/{batchId}/submit**
  - Request body: { scaToken: string (optional) }
  - Response: { id: UUID, status: string (submitted), transaction_id: string, submission_timestamp: ISO8601 }
  - Validation: batch must be in 'approved' state; pain.001.xml must be generated and valid
  - Authorization: User must have payroll-admin or finance-controller role
  - Side-effects: Call BankSubmissionOrchestrator; update batch.status → 'submitted'; schedule pain.002-polling job
  - Error responses: 400 (validation), 503 (bank unavailable), 500 (service failure)

- [ ] **T-409: Implement GET /api/hrmq/payment-batch/audit-report**
  - Query params: year (YYYY, required), format (pdf | csv, default pdf)
  - Response: File download (PDF or CSV)
  - Validation: year must be ≤ current year; user must have auditor or cfo role
  - Authorization: User in same tenant with audit-report read permission
  - Side-effects: Generate audit-trail document (may take 5-10 seconds), trigger docudesk archival

---

## Phase 5: Integration & External Services

- [ ] **T-501: Integrate with payroll-engine-nl API**
  - File: `app/Clients/PayrollEngineClient.php`
  - Method: `fetchEmployeePayroll(payrollRunId: UUID, employeeIds: UUID[]): PayrollData[]`
  - Endpoint: GET `/api/payroll-runs/{payrollRunId}/employee-payroll`
  - Caching: Cache for 1 hour (payroll-run data is immutable after completion)
  - Error handling: Timeout after 30 seconds, retry up to 2 times
  - Response mapping: Extract netto_loon, gross_loon, any eenmalige-uitkeringen

- [ ] **T-502: Integrate with expense-reimbursement API**
  - File: `app/Clients/ExpenseReimbursementClient.php`
  - Method: `fetchPendingReimbursements(payrollRunId: UUID, employeeIds: UUID[]): ExpenseData[]`
  - Endpoint: GET `/api/expenses?status=approved&month=202605`
  - Caching: Cache for 1 hour
  - Error handling: Timeout after 30 seconds, retry up to 2 times
  - Response mapping: Extract amount (net), vat_amount, expense_date, description

- [ ] **T-503: Integrate with document-template-engine**
  - File: `app/Services/DocumentRenderer.php`
  - Methods:
    - `renderEmail(templateName, variables): string` — renders Handlebars template
    - `renderPDF(templateName, variables, cssOverrides): BinaryStream` — renders HTML-to-PDF
  - Templates to create:
    - `salaris-betaling-vooraf.hbs` — pre-notification email
    - `salaris-audit-report.hbs` — audit-trail report PDF
  - Variables: execution_date, amount (formatted), composition_breakdown, employee_name, tenant_branding (logo, colors)
  - Fallback: If template-engine unavailable, use inline HTML templates

- [ ] **T-504: Integrate with docudesk archival**
  - File: `app/Services/DocumentArchiver.php`
  - Method: `archiveBatch(batch): ArchiveLocation`
  - Actions:
    - Upload pain.001.xml blob to S3-like storage (via docudesk)
    - Upload audit-report PDF after batch is settled
    - Store: s3://docudesk-prod/hrmq/{year}/{month}/batch_{batchId}.xml (and .pdf)
  - Metadata: Batch metadata (tenant, reference, amount, items-count, hash)
  - Retention: 7-year keep per Belastingdienst mandate

- [ ] **T-505: Integrate with openconnector (bank adapters)**
  - File: `app/Services/BankAdapters/OpenconnectorBridge.php`
  - Methods:
    - `submitPSD2(endpoint, paymentXml, scaToken): TransactionId`
    - `submitSFTP(endpoint, paymentXml): void`
    - `fetchPain002Report(endpoint, batchReference): Pain002XML`
    - `handleSCAChallenge(endpoint, userId): SCAToken`
  - Supported banks: Rabobank, ING, ABN, Bunq, KNAB (via openconnector adapters)
  - Error handling: Bank-specific error mapping (IBAN error, account-blocked, etc.)
  - Logging: Log all API calls for audit

- [ ] **T-506: Update IBAN→BIC lookup table (scheduled job)**
  - File: `app/Jobs/UpdateIBANBICLookup.php`
  - Scheduled: Monthly, on 1st of month at 02:00 UTC
  - Data source: SWIFT BIC directory (via openconnector or licensed API)
  - Actions: Clear old table entries, insert fresh entries
  - Fallback: If update fails, log warning (old data is still usable)

---

## Phase 6: Testing & QA

- [ ] **T-601: Unit tests for IBAN validation**
  - File: `tests/Unit/IBANValidatorTest.php`
  - Test cases:
    - Valid IBAN (NL, BE, DE): checksum passes
    - Invalid checksum: detects mismatch
    - Invalid country-code: detects unsupported country
    - Edge cases: min/max length, numeric chars only
  - Coverage: ≥95%

- [ ] **T-602: Unit tests for pain.001 XML generation**
  - File: `tests/Unit/Pain001GeneratorTest.php`
  - Test cases:
    - Single item batch: valid XML
    - 240-item batch: valid XML with correct totals
    - EUR 0.01 amount: no rounding errors
    - Special chars in omschrijving: properly escaped
    - XSD validation passes
  - Coverage: ≥95%

- [ ] **T-603: Integration tests for batch generation**
  - File: `tests/Integration/PaymentBatchGeneratorTest.php`
  - Test cases:
    - Generate batch from payroll-run with 240 employees, 187 expenses
    - Verify 240 batch-items created
    - Verify composition_breakdown correctly aggregated
    - Verify zero-balance employees excluded
    - Verify IBAN validation marks 1 item held_invalid_iban
    - Verify duplicate generation returns existing batch (idempotency)
  - Mocks: payroll-engine-nl, expense-reimbursement (return seed data)
  - Database: Use test database with rollback

- [ ] **T-604: Integration tests for approval workflow**
  - File: `tests/Integration/ApprovalWorkflowTest.php`
  - Test cases:
    - EUR 75k batch: self-approve → approved (1 step)
    - EUR 250k batch: finance review → approved (2 steps)
    - EUR 1.25M batch: finance + CFO review → approved (3 steps)
    - Approver rejection: batch returns to concept
    - Missing comment on approval: validation error
  - Mocks: User authentication, role-checking
  - Database: Use test database

- [ ] **T-605: Integration tests for pain.002 reconciliation**
  - File: `tests/Integration/Pain002ReconciliationTest.php`
  - Test cases:
    - pain.002 with 239 ACSP, 1 RJCT: batch status → partially_executed
    - pain.002 with all ACSP: batch status → executed
    - pain.002 with rejected item: HR work-item created
    - Rejection reason-code and description stored
  - Mocks: pain.002 XML parsing
  - Database: Use test database

- [ ] **T-606: End-to-end test (browser-based)**
  - File: `tests/Feature/PaymentBatchE2ETest.php` (or Cypress test)
  - User journey:
    1. Payroll-admin navigates to SEPA-batches page
    2. Clicks [Nieuwe batch genereren]
    3. Selects payroll-run May 2026, bank Rabobank, execution-date 2026-05-28
    4. Batch generated: 240 items, EUR 1.25M
    5. Clicks batch to view detail
    6. Reviews items (one invalid IBAN flagged)
    7. Corrects invalid IBAN in employee record
    8. Regenerates batch
    9. Initiates approval (since > 500k, routes to finance + CFO)
    10. Finance-controller approves with comment
    11. CFO approves with SCA
    12. Batch status → approved
    13. Clicks [Submit to bank]
    14. pain.001.xml sent to Rabobank
    15. Batch status → submitted
    16. (Simulated) pain.002 received from bank (239 ACSP, 1 RJCT)
    17. Reconciliation job runs, batch status → partially_executed
    18. HR reviews rejection work-item, marks resolved
  - Assertions: All UI updates match expected state; database records created; audit-logs recorded

- [ ] **T-607: Load test for batch generation**
  - File: `tests/Performance/PaymentBatchLoadTest.php`
  - Scenario: Generate batch with 5000 employees (stress-test)
  - Assertions:
    - Generation completes in < 30 seconds
    - XML generated, XSD-valid
    - Memory usage < 500MB
    - Database query count < 100

- [ ] **T-608: Manual security testing**
  - Test scenarios:
    - IBAN injection: Try SQL injection in IBAN field → validation rejects
    - Duplicate approval: Same user approves twice → DB unique constraint prevents
    - Bypass role-check: Non-CFO tries to approve EUR 1M batch → API returns 403
    - tampering detection: Hash-chain verification catches batch modification
  - Document findings + fixes

---

## Phase 7: Documentation & Deployment

- [ ] **T-701: Write API documentation (OpenAPI/Swagger)**
  - File: `docs/api/payment-batch.openapi.yaml`
  - Document all 9 endpoints (GET batch list, GET batch detail, GET items, GET approvals, POST generate, POST approve, POST reject, POST submit, GET audit-report)
  - Include request/response examples, error codes

- [ ] **T-702: Write user documentation**
  - File: `docs/user/salaris-batches-nl.md`
  - Dutch language guide: What is SEPA-batch? How to generate? How to approve? What to do on rejection?
  - Screenshots: Batch list, detail, approval flow

- [ ] **T-703: Write runbook for HR on payment rejections**
  - File: `docs/runbook/payment-rejection-resolution.md`
  - Scenarios: IBAN invalid, account blocked, insufficient funds
  - Actions: How to correct IBAN, contact employee, retry batch

- [ ] **T-704: Create database migrations summary**
  - File: `MIGRATION_SUMMARY.md`
  - List all 6 migrations (T-101 to T-106), tables created, schema

- [ ] **T-705: Set up CI/CD pipeline**
  - File: `.github/workflows/payment-batch-tests.yml`
  - Trigger: On push to feature branch, PR, and main branch
  - Steps:
    - Run unit tests (T-601, T-602)
    - Run integration tests (T-603, T-604, T-605)
    - Run static analysis (Laravel Pint, PHPStan)
    - Generate coverage report
    - On main branch: Run E2E test (T-606) + load test (T-607)

- [ ] **T-706: Deploy to staging**
  - Run all migrations on staging database
  - Run smoke tests: Can generate batch? Can approve? Can submit?
  - Verify Rabobank sandbox integration works
  - Verify email sending works (pre-notification templates)

- [ ] **T-707: Deploy to production**
  - Schedule deployment for off-peak (e.g., Saturday 02:00 UTC)
  - Run migrations with rollback plan ready
  - Monitor: Watch for errors in logs, API latency, email delivery
  - Gradual rollout: Enable feature-flag for 10% of tenants, monitor for 24h, then 100%
  - Runbook: Rollback procedure if critical bugs found

- [ ] **T-708: Post-deployment monitoring (week 1)**
  - Track metrics:
    - Batches generated per day
    - Approval rate (how many approved vs rejected)
    - Bank submission success rate
    - pain.002 reconciliation accuracy
    - Email delivery rate (pre-notifications)
  - Alert on anomalies: 0 batches generated for 3+ days, <90% submission success, etc.
  - Daily standup: Review metrics + customer feedback

---

## Phase 8: Post-Launch (Future)

- [ ] **T-801: SFTP batch submission (legacy bank support)**
  - Add configuration UI for SFTP credentials
  - Add SFTP submission logic (if not already in T-207)
  - Test with legacy bank partner

- [ ] **T-802: Manual download batch flow (micro-orgs)**
  - Add "Download as file" button for small organizations that upload to bank web-UI manually
  - Implement in T-305

- [ ] **T-803: Multi-currency support (future expansion)**
  - Design for USD, GBP, CHF SEPA transfers (out of scope for v1)

- [ ] **T-804: Scheduled batch submission (future)**
  - Allow batch to be scheduled for automatic submission at a specific time
  - Useful for organizations with specific bank cutoff times
