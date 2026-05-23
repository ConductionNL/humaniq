---
status: proposed
change: bank-payment-batch-sepa
title: SEPA Payment Batch (Salaris-Uitbetaling)
app: hrmq
owner: hrmq-payroll
version: 1.0
date: 2026-05-23
---

# SEPA Payment Batch (Salaris-Uitbetaling) — Proposal

## Executive Summary

Implement SEPA Credit Transfer (SCT) batch generation for monthly payroll runs in HRMQ. Each payroll cycle generates a single pain.001.001.09 XML file aggregating netto-loon, onkostendeclaraties, and bonussen per employee into one payment per person. The batch is approval-gated (finance controller + CFO above thresholds), submitted to bank via PSD2 API/SFTP, and reconciled against pain.002 status reports.

## Problem Statement

Dutch employers currently handle salary payments via fragmented processes:
- Multiple separate uploads: one for netto-loon, separate for expenses, another for bonuses
- Manual processing via internet banking (CSV upload or per-person transfers)
- High error rate: wrong amount to wrong IBAN = 2-3 days administrative rework
- Compliance risk: wage must arrive by agreed date (usually 25th) or employer faces statutory wage-increase claims (art. 7:625 BW, up to 50%)
- Corporate clients face dual-control + SCA requirements; SOX-compliance tenants need complete audit-trail

## Features

### F-001: Batch Aggregation per Employee (Demand: Critical)
Combine netto-loon, expense reimbursements, and bonuses into a single payment per employee per month. Preserves composition breakdown for audit and pre-notification emails. Excludes employees with zero net balance.

**Rationale:** One line per employee on employee payslip = clarity. Reduces per-transaction costs at banks with transaction-based pricing. Simplifies employee communication.

### F-002: pain.001.001.09 XML Generation (Demand: Critical)
Generate ISO 20022 SEPA Credit Transfer XML compliant with pain.001.001.09 standard. Validates against official XSD before storage.

**Rationale:** Bank submission requires standards-compliant XML. XSD validation prevents downstream rejection.

### F-003: IBAN & BIC Validation (Demand: Critical)
Validate IBAN checksum (ISO 13616 mod-97) and country-code. Derive BIC from lookup table if not provided by employee. Invalid IBANs hold for manual review rather than blocking batch.

**Rationale:** SEPA requires valid IBAN/BIC pairs. Lookup-table BIC derivation reduces employee data-entry burden.

### F-004: Approval Workflow with Threshold (Demand: High)
Route batches through finance-controller review (all batches >EUR 100k), then CFO approval for batches >EUR 500k (threshold configurable). Back-send capability with reason. Approval records include user, timestamp, comment, and SCA token reference.

**Rationale:** Legal requirement (art. 7:625 BW) + corporate governance (dual-control, audit-trail). Threshold allows self-approval for small organizations.

### F-005: Pre-Notification Email to Employees (Demand: High)
Two business days before execution-date, send employee a pre-notification with expected receipt-date, amount, last-4 IBAN digits, and component breakdown (netto EUR X, expenses EUR Y, bonus EUR Z). Tenant-branded via document-template-engine. Employees can opt-out in self-service.

**Rationale:** Trust = employees who know when and how much they're receiving spot discrepancies immediately. Reduces HR ticket volume.

### F-006: Bank Submission via PSD2 Corporate-API (Demand: High)
Submit approved batch to bank via PSD2 corporate-API (Rabobank Direct Connect, ING Sandbox, ABN Corporate Banking). Captures submission-acknowledgement and transaction-ID. SCA handled in approval stage; batch carries SCA token.

**Rationale:** Corporate clients mandate API submission over SFTP for audit-trail. PSD2 is EU standard for corporate payment flows.

### F-007: pain.002 Reconciliation (Demand: High)
Ingest pain.002 status reports from bank. Match OrgnlEndToEndId against batch items. Update per-item status (accepted, accepted_settlement_completed, rejected_with_reason). Rejected items appear in HR work-queue with bank reason-code and suggested remediation.

**Rationale:** Bank reports rejections (bad IBAN, blocked account, insufficient funds at debtor). HR must triage and resolve.

### F-008: Idempotency & Duplicate Prevention (Demand: Medium)
Detect accidental duplicate batch-generation requests for same payroll-run via unique constraint on payroll_run_id. Return existing batch instead of creating duplicate. Failed batches can be explicitly retried with new batch-reference.

**Rationale:** Prevents double-payment risk and reduces manual de-duplication work.

### F-009: Expense VAT Splitting (Demand: Medium)
When employee submits expense with VAT (e.g., EUR 121 incl. 21% VAT), system nets the VAT (EUR 100 payout), writes VAT to employer's VAT-return GL account, and includes component in composition-breakdown.

**Rationale:** Tax-correct: VAT comes back via employer's quarterly VAT return, not via employee.

### F-010: Audit-Trail & SOX Compliance (Demand: High)
Generate audit report per calendar-year: per-batch detail including payroll-run reference, total-amount, payment-count, all approvals (user, timestamp, comment), submission-time, execution-time, rejections with reasons. Hash-chain integrity: each approved batch references SHA256 of previous approved batch, enabling post-hoc tampering detection. Export as CSV and PDF.

**Rationale:** SOX section 404 + multi-national governance. Audit trail is non-repudiable.

## Target Users

**Primary:**
- Payroll-admins: Generate and submit batches monthly
- Finance-controllers: Review and approve batches >EUR 100k
- CFOs: Approve batches >EUR 500k
- Employees: Receive pre-notification emails; manage own IBAN in self-service

**Secondary:**
- Payroll-service-providers: 50-500 clients, consistent batch-process
- Bank ecosystem-partners: HRMQ as corporate-API client
- Tenant-admins: Configure bank connections, approval-workflows
- Security-teams: Monitor PSD2 credential rotation, SCA-flow
- Industry partners: Implementation support for new bank-APIs

**Tertiary:**
- Group Treasury (multinationals): Consolidated view across BV's with ERP reconciliation
- Internal/external auditors: Compliance audit-reports
- HR: Triage rejected payments; employee pre-notification

## Stakeholder Responsibilities

| Role | Responsibility |
|------|-----------------|
| hrmq-payroll (owner) | Batch generation, approval-workflow, pain.002-reconciliation |
| payroll-admin | Monthly batch trigger, HR coordination on rejections |
| finance-controller | Review batch totals vs payroll-summary (>EUR 100k) |
| CFO | Final approval signature (>EUR 500k); audit-trail sign-off |
| Finance auditor (internal) | Monthly audit of batch-submission vs payment-execution |
| External auditor | Annual SOX 404 compliance spot-check |
| Bank operations | PSD2 API endpoint stability; pain.002-reporting SLA |
| Employee | Opt-in/out pre-notification; IBAN maintenance in self-service |

## Data Model (Summary)

**hrmq_payment_batch**: `id`, `tenant_id`, `payroll_run_id` (unique), `batch_reference` (unique per bank), `status` (concept → pending_approval_finance → pending_approval_cfo → approved → submitted → partially_executed / executed / failed), `total_amount`, `payment_count`, `execution_date`, `pain001_xml_blob_url`, `pain001_xml_hash_sha256`, `pain001_message_id`, `bank_endpoint_id` FK, `previous_batch_hash` (for integrity chain).

**hrmq_payment_batch_item**: `batch_id`, `employee_id`, `iban`, `bic`, `amount`, `currency` (EUR), `omschrijving`, `end_to_end_id` (unique per item), `composition_breakdown` (JSON: netto_loon, onkosten, bonus, eenmalige_uitkering, terugvordering), `status` (pending → accepted → accepted_settlement_completed | rejected_with_reason | held_invalid_iban), `rejection_reason_code`, `rejection_reason_description`.

**hrmq_payment_approval**: `batch_id`, `approver_user_id`, `role`, `decision` (approved | rejected | pending), `timestamp`, `comment`, `sca_token_reference`.

**hrmq_payment_bank_endpoint**: `id`, `tenant_id`, `bank_name`, `connection_type` (psd2_api | sftp | manual_download), `connection_credentials_ref`, `pain_version`, `iban_debtor`.

**hrmq_payment_batch_status_update**: `batch_id`, `batch_item_id`, `pain002_message_id`, `received_at`, `status_code`, `status_description`.

## Cross-App Dependencies

| App | Role |
|-----|------|
| payroll-engine-nl | Source for netto-loon amounts per employee |
| expense-reimbursement | Source for declaraties-to-be-reimbursed |
| shillinq (SEPA-module) | Shared library for pain.001/pain.002 XML handling |
| document-template-engine | Pre-notification emails, approval-requests, audit-reports |
| openconnector | Adapters for bank PSD2-APIs (Rabo, ING, ABN, Bunq, KNAB) |
| docudesk | Archive XML-files and audit-reports |

## Standards

- ISO 20022 pain.001.001.09 (SEPA Credit Transfer Customer Initiation)
- ISO 20022 pain.002.001.10 (Customer Payment Status Report)
- ISO 13616 (IBAN)
- ISO 9362 (BIC)
- PSD2 (EU 2015/2366) corporate-API + SCA-flow
- GDPR/AVG (rechtmatige grondslag: employer legitimate interest + employment contract)
- NL tax-law: 7-year payroll-administration retention (Belastingdienst)
- SOX section 404 (multinational tenants)
- art. 7:625 BW (statutory wage-increase on late payment)

## Out of Scope (Future Specs)

- SEPA Direct Debit (employee-to-employer reversals, e.g., overpayment recovery)
- Non-EUR payments (US, UK, crypto payroll)
- Cross-border non-SEPA (handled by separate international wire-transfer spec)

## Success Criteria

1. ✅ Batch generation produces pain.001.001.09 XML valid against official XSD
2. ✅ Approval-workflow enforces finance + CFO sign-off above thresholds
3. ✅ Pre-notification emails sent 2 business days before execution
4. ✅ pain.002 reconciliation matches all accepted + rejected items
5. ✅ Audit-trail includes hash-chain integrity (each batch refs previous)
6. ✅ Idempotency: duplicate batch-generation returns existing batch, not new one
7. ✅ No late payments: batches submitted with enough lead-time to execute by promised date
