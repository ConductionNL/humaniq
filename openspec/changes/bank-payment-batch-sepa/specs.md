---
status: proposed
change: bank-payment-batch-sepa
title: SEPA Payment Batch (Salaris-Uitbetaling) — Specifications
app: hrmq
version: 1.0
date: 2026-05-23
---

# Specifications: SEPA Payment Batch

All requirements use GIVEN/WHEN/THEN format. Each scenario is a discrete test case.

## REQ-001: Batch Aggregation per Employee

**Specification:** Combine netto-loon, onkostendeclaraties, and bonussen into a single payment per employee per month. Preserve composition breakdown for audit. Exclude zero-balance employees.

### REQ-001-A: Aggregate Multiple Components

**GIVEN**
- A payroll-run for May 2026 with 240 employees
- 187 approved onkostendeclaraties covering 95 of those employees
- 12 bonussen (eenmalige-uitkering) distributed to 8 employees
- Netto-loon amounts fetched from payroll-engine-nl per employee

**WHEN**
- The batch-generator is triggered for this payroll-run

**THEN**
- The system produces exactly 240 batch-items (one per employee, not per payroll-run-component)
- Each batch-item contains:
  - `amount` = netto_loon + onkosten (if any) + bonus (if any)
  - `composition_breakdown` JSON with per-component amounts and total
- An employee without expenses or bonus receives only their netto-loon amount
- 95 items have non-zero onkosten in their breakdown
- 8 items have non-zero bonus in their breakdown
- 152 items have zero-values for both onkosten and bonus but nonzero netto_loon
- All totals are exact (no rounding errors)

### REQ-001-B: Exclude Zero-Balance Employees

**GIVEN**
- A payroll-run with 240 employees
- 3 employees with netto_loon = 0, no expenses, no bonus (zero balance)
- 237 employees with at least netto_loon > 0

**WHEN**
- The batch-generator is triggered

**THEN**
- The system produces 237 batch-items (not 240)
- The 3 zero-balance employees are excluded from the batch entirely
- No batch-item record is created for them
- They do not appear in payment_count

### REQ-001-C: Expense VAT Netting

**GIVEN**
- An employee with approved expense: "Flight ticket EUR 121 incl. 21% VAT"
- The system needs to pay out the net amount (EUR 100), withhold VAT (EUR 21) for employer's VAT-return

**WHEN**
- The batch-item for this employee is assembled

**THEN**
- The batch-item amount includes EUR 100 (net expense)
- `composition_breakdown.onkosten` = 100.00
- A GL entry is created for the employer's VAT-deferred account (EUR 21 to "VAT-to-return")
- The expense-reimbursement record is marked as "netto_paid_out" (not "fully_paid_out")
- Pre-notification email shows "Onkosten EUR 100" (net to employee)

---

## REQ-002: pain.001.001.09 XML Generation

**Specification:** Generate ISO 20022-compliant SEPA Credit Transfer XML (pain.001.001.09). Validate against official XSD before storage.

### REQ-002-A: Valid GroupHeader

**GIVEN**
- An approved batch with:
  - batch_reference = "HRMQ-11c1-202605-001"
  - N = 240 payment-items
  - total_amount = EUR 1,250,000.00
  - execution_date = 2026-05-28
  - created_at = 2026-05-22 14:30:00 UTC

**WHEN**
- The XML-generator is invoked

**THEN**
- The pain.001 XML contains a valid GroupHeader with:
  - MsgId = "HRMQ-11c1-202605-001"
  - CreDtTm = ISO 8601 timestamp (2026-05-22T14:30:00Z or current time)
  - NbOfTxs = "240"
  - CtrlSum = "1250000.00"
  - GrpHdr/InitgPty/Nm = tenant's registered name
- The GroupHeader is valid per pain.001.001.09 XSD

### REQ-002-B: Single PaymentInformation Block

**GIVEN**
- A batch with 240 items, all using the same debtor-IBAN (employer's account)

**WHEN**
- The XML-generator processes the batch

**THEN**
- The pain.001 XML contains exactly one PaymentInformation block (PmtInf)
- All 240 CreditTransferTransactionInformation entries fall under this single PmtInf
- PmtInf.PmtInfId = unique identifier per batch
- PmtInf.Dbtr (debtor) = employer name & IBAN
- PmtInf.ReqdExctnDt = execution_date (2026-05-28)

### REQ-002-C: CreditTransferTransactionInformation Per Item

**GIVEN**
- A batch-item:
  - employee_id = "eeee0001-eeee-eeee-eeee-eeeeeeeeeeee"
  - amount = 5208.33 EUR
  - iban = NL91ABNA0417164300
  - name = "Jan Janssen"
  - omschrijving = "Salaris mei 2026"
  - end_to_end_id = "EMP-eeee0001-20260522-001"

**WHEN**
- The XML-generator formats this item

**THEN**
- The CreditTransferTransactionInformation contains:
  - CdtTrfTxInf/PmtId/EndToEndId = "EMP-eeee0001-20260522-001"
  - CdtTrfTxInf/Amt/InstdAmt/Ccy = "EUR"
  - CdtTrfTxInf/Amt/InstdAmt = "5208.33"
  - CdtTrfTxInf/Cdtr/Nm = "Jan Janssen"
  - CdtTrfTxInf/CdtrAcct/Id/IBAN = "NL91ABNA0417164300"
  - CdtTrfTxInf/RmtInf/Ustrd = "Salaris mei 2026" (max 140 chars per SEPA spec)
  - CdtTrfTxInf/CdtrAgt/FinInstnId/BIC = BIC for this IBAN (if available)

### REQ-002-D: XSD Validation

**GIVEN**
- A generated pain.001 XML file
- The official pain.001.001.09 XSD (from swift.org or equivalent)

**WHEN**
- The system validates the XML against the XSD

**THEN**
- The validation result is VALID (zero XSD errors)
- The XML is eligible for bank submission
- If validation fails, the batch.status remains `concept`, batch is not submitted, error is logged with details

---

## REQ-003: IBAN & BIC Validation

**Specification:** Validate IBAN checksum and country-code. Derive BIC from lookup-table if needed. Invalid IBANs do not block batch but are flagged for manual review.

### REQ-003-A: IBAN Checksum Validation

**GIVEN**
- A batch-item with iban = "NL91ABNA0417164300" (valid checksum)

**WHEN**
- The batch-item is constructed

**THEN**
- The system validates the IBAN checksum via ISO 13616 mod-97 algorithm
- The checksum is correct
- The item status = `pending` (not `held_invalid_iban`)

### REQ-003-B: IBAN Checksum Rejection

**GIVEN**
- A batch-item with iban = "NL91ABNA0417164301" (invalid checksum, last digit is 1 not 0)

**WHEN**
- The batch-item is constructed

**THEN**
- The system detects checksum mismatch
- The item status = `held_invalid_iban`
- batch.status remains `concept` (item is not blocking; batch can be submitted after manual review)
- The item is added to a manual-review work-queue for HR to correct

### REQ-003-C: Country-Code Validation

**GIVEN**
- A batch with 5 items:
  - 3 with NL IBAN (Netherlands)
  - 1 with BE IBAN (Belgium)
  - 1 with DE IBAN (Germany)

**WHEN**
- The batch-generator validates all items

**THEN**
- All 5 pass country-code validation (NL, BE, DE are SEPA-EU countries)
- All have status `pending`
- The employer's tenant is configured for SEPA-EU countries (NL, BE, DE, AT, FR, IT, ES, PT, GR, CY, LV, LT, LU, MT, SI, SK, EE)

### REQ-003-D: BIC Lookup & Derivation

**GIVEN**
- A batch-item with iban = "NL91ABNA0417164300" and no explicit bic
- A local IBAN→BIC lookup table (updated monthly from SWIFT)

**WHEN**
- The batch-item is constructed

**THEN**
- The system looks up the BIC from the iban prefix (NL91ABNA → "ABNANL2A")
- The item.bic is set to "ABNANL2A"
- The pain.001 XML includes this BIC

### REQ-003-E: Employee-Provided BIC Override

**GIVEN**
- A batch-item with iban = "NL91ABNA0417164300" and explicit bic = "ABNANL99" (non-standard, but employee provided)

**WHEN**
- The batch-item is constructed

**THEN**
- The system respects the employee-provided BIC
- The item.bic = "ABNANL99" (not overwritten from lookup)
- The pain.001 XML uses "ABNANL99"

---

## REQ-004: Approval Workflow with Threshold

**Specification:** Route batches through finance-controller (>EUR 100k mandatory) and CFO (>EUR 500k mandatory). Back-send with reason. Record approvals with user, timestamp, comment, SCA reference.

### REQ-004-A: Self-Approval for Small Batches

**GIVEN**
- Tenant approval-threshold configuration: finance = EUR 100.000, cfo = EUR 500.000
- A batch with total_amount = EUR 75.000
- Triggering user = payroll-admin (eeee0001)

**WHEN**
- The batch is in `concept` state and approver initiates approval

**THEN**
- The batch automatically transitions to `approved` (no intermediate approval step)
- A hrmq_payment_approval record is created with:
  - role = "payroll_admin"
  - decision = "approved"
  - approver_user_id = eeee0001
  - timestamp = now()
- The batch is immediately eligible for submission to bank

### REQ-004-B: Finance-Controller Review

**GIVEN**
- A batch with total_amount = EUR 250.000 (between 100k and 500k thresholds)
- Current state = `concept`

**WHEN**
- The payroll-admin initiates approval

**THEN**
- The batch status → `pending_approval_finance`
- A notification email is sent to all finance-controller role users
- Finance-controller reviews the batch (click "Approve" or "Reject")
  - If approve: batch.status → `approved`, hrmq_payment_approval record created, submission becomes available
  - If reject: batch.status → `concept`, hrmq_payment_approval record created with decision=rejected + comment, batch can be edited and resubmitted

### REQ-004-C: Dual Approval (CFO Gate)

**GIVEN**
- A batch with total_amount = EUR 1.250.000 (exceeds EUR 500k threshold)
- Current state = `concept`

**WHEN**
- The payroll-admin initiates approval

**THEN**
- Batch.status → `pending_approval_finance`
- After finance-controller approval (see REQ-004-B): batch.status → `pending_approval_cfo` (not `approved` yet)
- CFO receives approval-request notification
- When CFO approves:
  - Batch.status → `approved`
  - hrmq_payment_approval record created with role = "cfo"
  - Submission becomes available
- If CFO rejects: batch.status → `concept`, batch can be edited

### REQ-004-D: Approval Record with SCA Token

**GIVEN**
- A CFO approving a batch with SCA challenge (PSD2 corporate-API configured)
- CFO completes SCA flow (SMS code, FIDO2, or embedded-SCA modal)
- SCA token = "sca_20260523_001_token_hash_abc123"

**WHEN**
- CFO clicks "Approve"

**THEN**
- A hrmq_payment_approval record is created with:
  - sca_token_reference = "sca_20260523_001_token_hash_abc123"
- This token is sent with the subsequent bank-API submission call (authorizes the payment)

### REQ-004-E: Approval Comment Required

**GIVEN**
- A finance-controller reviewing a batch

**WHEN**
- Controller clicks "Approve" with empty comment field

**THEN**
- The UI shows an error: "Comment is required for all approvals"
- Approval is not recorded
- Controller must enter a comment (e.g., "Verified against payroll summary")

---

## REQ-005: Pre-Notification Email to Employees

**Specification:** Send email D-2 (2 business days) before execution-date with expected receipt-date, amount, IBAN-last-4, component breakdown. Tenant-branded. Opt-out supported in self-service.

### REQ-005-A: Email Timing (D-2)

**GIVEN**
- A batch with execution_date = 2026-05-28 (Wednesday)
- 2 business days before = 2026-05-26 (Monday)
- System clock = 2026-05-26 08:00:00 UTC (morning of D-2)

**WHEN**
- The scheduled email job runs (e.g., once per day at 08:00 UTC)

**THEN**
- The system identifies all `approved` batches with execution_date = 2026-05-28
- For each batch, iterates over all batch-items with status `pending` or `accepted`
- For each employee with pre_notification_enabled = true (self-service setting), enqueues email:
  - To: employee.email
  - Template: `salaris-betaling-vooraf.hbs`
  - Variables: execution_date, amount, iban_last_4, composition_breakdown
- Email is sent within 1 hour of the job trigger

### REQ-005-B: Email Content

**GIVEN**
- An employee Jan Janssen (jan@example.com)
- Batch-item for Jan:
  - amount = 5208.33 EUR
  - iban = NL91ABNA0417164300
  - composition_breakdown:
    - netto_loon: 4500.00
    - onkosten: 208.33
    - bonus: 500.00
  - execution_date = 2026-05-28

**WHEN**
- The pre-notification email is generated

**THEN**
- Email subject includes: "Salaris mei 2026 — verwacht 28 mei"
- Email body includes:
  - "Uw salaris van mei 2026 zal op 28 mei op uw rekening worden overgeboekt."
  - "Bedrag: EUR 5.208,33"
  - "Ontvangend rekeningnummer (laatste 4 cijfers): 4300"
  - Breakdown table:
    - Netto loon: EUR 4.500,00
    - Onkosten: EUR 208,33
    - Bonus: EUR 500,00
    - **Totaal: EUR 5.208,33**
  - "Vragen? Neem contact op met HR."
- Email uses tenant's logo/branding (from document-template-engine)

### REQ-005-C: Opt-Out Support

**GIVEN**
- An employee with pre_notification_enabled = false in self-service settings

**WHEN**
- The pre-notification email job runs

**THEN**
- No email is sent to this employee, even if batch-item exists and is approved
- A audit log entry records: "Pre-notification skipped for employee X (opt-out)"

### REQ-005-D: Rejection Item Handling

**GIVEN**
- A batch-item with status = `held_invalid_iban` (in manual-review state)

**WHEN**
- The pre-notification email job runs

**THEN**
- No pre-notification email is sent for this item
- HR is expected to correct the IBAN and re-trigger the batch before execution

---

## REQ-006: Bank Submission via PSD2 Corporate-API

**Specification:** Submit approved batch to bank via PSD2 corporate-API. Capture submission-acknowledgement. SCA handled in approval stage. Batch carries SCA token.

### REQ-006-A: PSD2 API Submission

**GIVEN**
- A batch in `approved` state with sca_token_reference from approval stage
- Bank endpoint configured: Rabobank Direct Connect
- Connection credentials in vault: `vault://rabo-direct-connect-prod`
- pain.001 XML ready at blob URL

**WHEN**
- Payroll-admin clicks "Submit to bank"

**THEN**
- System fetches pain.001 XML from blob storage
- System constructs HTTP POST to Rabobank API:
  - Endpoint: (from openconnector adapter config)
  - Headers: OAuth 2.0 authorization + SCA proof (sca_token_reference)
  - Body: pain.001 XML
- Rabobank returns HTTP 200 + response JSON with:
  - transaction_id = "RABO-20260522-001-abc123"
  - submission_timestamp = "2026-05-22T14:35:00Z"
  - message = "File received and queued for processing"

- System records:
  - batch.status → `submitted`
  - batch.pain001_message_id = "RABO-20260522-001-abc123"
  - batch.updated_at = now()
  - Audit log: "Batch submitted to Rabobank; transaction_id = RABO-20260522-001-abc123"

### REQ-006-B: SFTP Submission (Legacy)

**GIVEN**
- A batch in `approved` state
- Bank endpoint configured: SFTP (legacy)
- Connection credentials in vault: `vault://bank-sftp-credentials`

**WHEN**
- Payroll-admin clicks "Submit to bank"

**THEN**
- System fetches pain.001 XML from blob storage
- System connects to bank SFTP server using credentials
- System uploads pain.001.xml to `/incoming/salaris/` directory
  - Filename: `HRMQ-11c1-202605-001.xml`
- SFTP transfer succeeds (no errors)
- System records:
  - batch.status → `submitted`
  - batch.pain001_message_id = batch_reference (no bank transaction_id for SFTP)
  - Audit log: "Batch uploaded to bank SFTP"

### REQ-006-C: SCA Challenge (Optional)

**GIVEN**
- A PSD2 API endpoint requiring SCA at submission-time (not approval-time)
- Batch in `approved` state

**WHEN**
- Payroll-admin clicks "Submit to bank"

**THEN**
- System detects SCA requirement from bank-endpoint config
- System opens SCA challenge modal (reuses openconnector SCA-modal)
- User completes SCA (SMS code, push-app, FIDO2, or embedded form)
- SCA token obtained
- System includes SCA token in API call headers
- Submission proceeds (see REQ-006-A)

---

## REQ-007: pain.002 Reconciliation

**Specification:** Ingest pain.002 status reports from bank. Match OrgnlEndToEndId against batch-items. Update per-item status. HR work-queue for rejections.

### REQ-007-A: pain.002 Parsing and Matching

**GIVEN**
- A submitted batch with 240 items, end_to_end_ids = ["EMP-eeee0001-...", "EMP-eeee0002-...", ... ]
- Bank pain.002 status-report received with 240 TxInfo elements:
  - OrgnlEndToEndId = "EMP-eeee0001-20260522-001"
  - TxSts = "ACPT" (accepted)
  - (same for items 2-239, one item rejected below)
  - Item 240:
    - OrgnlEndToEndId = "EMP-eeee0240-20260522-001"
    - TxSts = "RJCT" (rejected)
    - RjctRsn/Cd = "IBAN" (invalid account)
    - RjctRsn/AddtlInf = "Account number is invalid"

**WHEN**
- The pain.002 parsing job runs

**THEN**
- System parses pain.002 XML and extracts 240 transaction-status entries
- For each entry, system matches OrgnlEndToEndId against hrmq_payment_batch_item.end_to_end_id
- Item 1-239: matched successfully, TxSts = "ACPT"
- Item 240: matched successfully, TxSts = "RJCT"
- No unmatched entries in pain.002 (all 240 accounted for)

### REQ-007-B: Status Update (Accepted)

**GIVEN**
- pain.002 entry with TxSts = "ACPT" (accepted, awaiting settlement)

**WHEN**
- System processes this entry

**THEN**
- hrmq_payment_batch_item.status → `accepted`
- hrmq_payment_batch_item.updated_at = now()
- A hrmq_payment_batch_status_update record is created:
  - batch_item_id = reference to this item
  - status_code = "ACPT"
  - received_at = pain002_message_date

### REQ-007-C: Status Update (Accepted, Settlement Completed)

**GIVEN**
- pain.002 entry with TxSts = "ACSP" (accepted for settlement, funds moved)

**WHEN**
- System processes this entry

**THEN**
- hrmq_payment_batch_item.status → `accepted_settlement_completed`
- hrmq_payment_batch_status_update record created:
  - status_code = "ACSP"

### REQ-007-D: Status Update (Rejected)

**GIVEN**
- pain.002 entry with TxSts = "RJCT", RjctRsn/Cd = "IBAN", RjctRsn/AddtlInf = "Account number is invalid"

**WHEN**
- System processes this entry

**THEN**
- hrmq_payment_batch_item.status → `rejected_with_reason`
- hrmq_payment_batch_item.rejection_reason_code = "IBAN"
- hrmq_payment_batch_item.rejection_reason_description = "Account number is invalid"
- A work-item is created in HR work-queue:
  - Title: "Payment rejection: Jan Janssen (EMP-eeee0240)"
  - Assigned to: HR-admin
  - Details: "IBAN rejected by bank. Action: verify employee IBAN and resubmit batch."
- Payroll-admin receives notification: "1 payment rejected in batch HRMQ-11c1-202605-001"

### REQ-007-E: Batch Status Update (Fully Executed)

**GIVEN**
- pain.002 report received for all 240 items in a batch
- 239 items have TxSts = "ACSP" (settlement completed)
- 1 item has TxSts = "RJCT" (rejected)

**WHEN**
- System finishes processing all status-updates from pain.002

**THEN**
- Batch.status is updated based on item-counts:
  - All items settled? → batch.status = `executed`
  - Some items settled, some pending/rejected? → batch.status = `partially_executed`
  - All items rejected? → batch.status = `failed`
- In this case: batch.status = `partially_executed` (239/240 settled)
- Batch.updated_at = now()
- Audit log: "Batch reconciliation complete; 239 accepted, 1 rejected"

---

## REQ-008: Idempotency & Duplicate Prevention

**Specification:** Accidental duplicate batch-generation requests return existing batch. Failed batches can be explicitly retried with new batch-reference.

### REQ-008-A: Idempotent Batch Generation

**GIVEN**
- A payroll-run (may-2026) in state `completed`
- Batch already exists for this payroll-run in state `concept`

**WHEN**
- Payroll-admin accidentally clicks "Generate batch" again (same payroll-run)

**THEN**
- System detects unique constraint on (tenant_id, payroll_run_id)
- System returns the existing batch (status = `concept`) instead of creating a duplicate
- No error is shown to user; UI shows the existing batch detail
- Audit log: "Duplicate batch generation detected; returned existing batch"

### REQ-008-B: Idempotency with Non-Failed State

**GIVEN**
- An existing batch for payroll-run may-2026 with status = `pending_approval_finance`

**WHEN**
- Payroll-admin clicks "Generate batch" for the same payroll-run

**THEN**
- System detects existing batch in non-failed state
- System returns the existing batch, no duplicate is created
- Message: "Batch already exists for this payroll-run (status: pending approval)"

### REQ-008-C: Retry Failed Batch with New Reference

**GIVEN**
- An existing batch for payroll-run may-2026 with status = `failed` (all items rejected by bank, e.g., wrong debtor IBAN)

**WHEN**
- HR corrects the debtor IBAN and payroll-admin clicks "Retry batch"

**THEN**
- System creates a **new** batch-reference (batch_reference increments: HRMQ-11c1-202605-002)
- New batch.payroll_run_id = same payroll-run
- New batch-items are regenerated from the corrected payroll-run data
- Previous failed batch remains in database with status = `failed` (audit trail)
- New batch is now in state `concept`, ready for approval

---

## REQ-009: Expense VAT Splitting

**Specification:** When expense includes VAT, net amount is paid to employee; VAT is written to employer's VAT-return account.

### REQ-009-A: VAT Extraction from Expense

**GIVEN**
- An employee-submitted expense:
  - Description: "Flight ticket"
  - Gross amount: EUR 121.00 (incl. 21% VAT)
  - VAT rate: 21%
  - Status: approved in expense-reimbursement app

**WHEN**
- The batch-generator fetches this expense as a reimbursable item

**THEN**
- System calculates:
  - Net amount: EUR 100.00
  - VAT amount: EUR 21.00
- The batch-item includes only EUR 100.00 in the payment amount
- composition_breakdown.onkosten = 100.00
- A GL entry is created:
  - Debit: "Salaris uitbetaling" (EUR 100.00)
  - Credit: "Onkosten reimbursement" (EUR 100.00)
  - Credit: "VAT to return" (EUR 21.00)

### REQ-009-B: Multiple VAT Rates

**GIVEN**
- An employee with two expenses:
  - Expense A: EUR 36.30 (EUR 30 net + 21% VAT)
  - Expense B: EUR 24.20 (EUR 22 net + 9% VAT on books)

**WHEN**
- The batch-item is assembled

**THEN**
- composition_breakdown.onkosten = 52.00 (30 + 22)
- Two GL-posting VAT-lines are created:
  - "VAT 21% to return": EUR 6.30
  - "VAT 9% to return": EUR 2.20
- Pre-notification email shows:
  - Onkosten EUR 52,00 (net total)
  - No VAT breakdown shown to employee (employer matter)

---

## REQ-010: Audit-Trail & SOX Compliance

**Specification:** Generate audit-report per calendar-year with batch-detail, approvals, hash-chain integrity. Export as CSV/PDF. Enable post-hoc tampering detection.

### REQ-010-A: Audit Report Generation

**GIVEN**
- Audit-request for calendar-year 2026
- 12 batches in this year (one per month, all executed)

**WHEN**
- Finance-auditor clicks "Generate audit report" for 2026

**THEN**
- System generates a report PDF (via document-template-engine) containing:
  - **Header:** "Salaris Audit Report 2026 — [Tenant Name]"
  - **Summary:** Total payroll for year, number of batches, number of payments, total amount
  - **Per-batch table:**
    | Batch-ref | Payroll-run | Status | Amount | Count | Approvals | Submitted | Executed |
    | HRMQ-11c1-202601-001 | 2026-01 | executed | EUR 1,125,000 | 237 | finance(2026-01-15), cfo(2026-01-15) | 2026-01-22 | 2026-01-28 |
    | ... | ... | ... | ... | ... | ... | ... | ... |
    | HRMQ-11c1-202612-001 | 2026-12 | executed | EUR 1,200,000 | 240 | finance(2026-12-15), cfo(2026-12-15) | 2026-12-22 | 2026-12-27 |

  - **Approvals detail:** Full approval history (per batch, per approver, timestamp, comment)
  - **Hash chain integrity section:**
    - Batch 1: previous_batch_hash = NULL (first of year)
    - Batch 2: previous_batch_hash = SHA256(batch 1 approved-state)
    - Batch 3: previous_batch_hash = SHA256(batch 2 approved-state)
    - ... (chain continues through year)
    - At bottom: "Hash chain verified intact: ✅ NO TAMPERING DETECTED"

- PDF is digitally signed by system (HRMQ digital-signature cert)
- Report is also exported as CSV (for auditor to load into their tools)
- Audit log: "Audit report generated for 2026"

### REQ-010-B: Hash Chain Integrity

**GIVEN**
- Batch 1 approved on 2026-01-15, payload locked with SHA256 hash = `abc123...`
- Batch 2 approved on 2026-02-15, stores previous_batch_hash = `abc123...`
- An attacker attempts to modify Batch 1 approval-timestamp from 2026-01-15 to 2026-01-10

**WHEN**
- Audit-verifier re-computes SHA256 of modified Batch 1

**THEN**
- Modified hash ≠ original hash
- Batch 2.previous_batch_hash = original hash ≠ recalculated hash
- Audit report detects mismatch: "❌ HASH CHAIN BROKEN at Batch 2"
- Auditor is alerted to tampering; batch is flagged for investigation

### REQ-010-C: Rejection Work-Queue in Audit Trail

**GIVEN**
- A batch with 3 rejected items (per pain.002 reconciliation)

**WHEN**
- Audit report is generated

**THEN**
- Report includes section: "Rejected Payments (3 total)"
  - EMP-eeee0001: IBAN invalid (2026-05-23 rejected, HR corrected on 2026-05-24, resubmitted as new batch)
  - EMP-eeee0240: Account blocked (2026-05-23 rejected, HR contacted employee, marked as "await resolution")
  - EMP-eeee0241: Insufficient funds (2026-06-01 rejected, HR contacted employee, paid out in next batch)
- Retention: All rejection records kept for 7 years (Belastingdienst mandate)

---

## End-to-End Scenario: Monthly Payroll Batch

**User:** Payroll-admin Sarah
**Date:** 2026-05-22 (morning)

**Story:**
1. Sarah navigates to Salarissen › SEPA-batches
2. She clicks "Nieuwe batch genereren" for payroll-run May 2026
3. System generates batch: 240 items, EUR 1,250,000 total, 1 held-invalid-iban
4. Batch status = `concept`; Sarah reviews the list
5. Sarah clicks batch detail → sees all 240 items, one flagged for manual IBAN review
6. Sarah corrects the invalid IBAN in employee record, regenerates batch with "Retry batch"
7. New batch: 240 items, EUR 1,250,000, all pending
8. Sarah clicks "Goedkeuringsstroom starten"
9. Since EUR 1,250,000 > 500k threshold, system routes to finance-controller first
10. Finance-controller Patricia receives approval-email
11. Patricia logs in, reviews batch (compares total against payroll-summary), clicks "Approve — verified"
12. Batch status → `pending_approval_cfo`
13. CFO Henk receives approval-email + SCA challenge modal
14. Henk completes SCA (SMS code), clicks "Approve"
15. Batch status → `approved`
16. Sarah clicks "Indienen bij bank" (Rabobank PSD2 API configured)
17. System sends pain.001 XML to Rabobank; receives transaction_id
18. Batch status → `submitted`
19. System schedules pre-notification email job for 2026-05-26 (D-2)
20. On 2026-05-26 morning, 240 employees receive pre-notification emails
21. On 2026-05-28 (execution-date), Rabobank processes the batch
22. On 2026-05-29, Rabobank sends pain.002 status-report (239 ACSP, 1 RJCT)
23. System reconciles, updates item-statuses, creates HR work-item for rejected payment
24. Batch status → `partially_executed`
25. Sarah receives notification; clicks batch to see 1 rejection
26. HR corrects the rejected employee's bank-details, resubmits corrected data in next month's batch

**Success:**
- 239 employees received salaries on time (28-mei)
- 1 rejection was triaged and resolved within 24 hours
- Complete audit-trail for SOX compliance
- Hash-chain integrity verified
