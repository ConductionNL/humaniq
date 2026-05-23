---
status: proposed
change: bank-payment-batch-sepa
title: SEPA Payment Batch (Salaris-Uitbetaling) — Design
app: hrmq
version: 1.0
date: 2026-05-23
---

# Design: SEPA Payment Batch

## Placement & Information Architecture

**Placement Type:** `SUB_PAGE` under top-level menu entry `Salarissen`.

**Route:** `/app/hrmq/salarissen/sepa-batches`

**Lives at:** Salarissen › SEPA-batches

**Rationale:** Per ADR-001 Rule 5 (Aangiftes en compliance) — payment batches are external verantwoording surfaces alongside UPA, pensioen, WNT. However, salary-submission is daily operational flow (not compliance-report), so it lives under `Salarissen` (operational revenue cycle), not under `Aangiftes & compliance` (external reporting). Rationale: the payroll-admin's mental model is loon-cycle-driven, not compliance-deadline-driven.

## Feature Architecture

### Batch Generation Pipeline

```
payroll-run (completed) 
  ↓
  ├─ Fetch netto-loon per employee from payroll-engine-nl
  ├─ Fetch onkostendeclaraties from expense-reimbursement
  ├─ Fetch bonussen from payroll-engine-nl
  ↓
[Aggregation Phase]
  ├─ Deduplicate: one row per employee with summed amounts
  ├─ Apply composition_breakdown (netto/onkosten/bonus/eenmalige/terugvordering)
  ├─ Exclude zero-balance employees
  ↓
[Validation Phase]
  ├─ Validate IBAN (mod-97, country-code)
  ├─ Lookup BIC from iban→bic table if not provided
  ├─ Flag invalid IBANs as held_invalid_iban (not blocking)
  ↓
[pain.001 XML Generation]
  ├─ Via shillinq SEPA-module (shared with invoicing batches)
  ├─ GroupHeader: MsgId=batch_reference, CreDtTm=now, NbOfTxs=N, CtrlSum=total
  ├─ PaymentInformation (single debtor, N credit transfers)
  ├─ Per item: CreditTransferTransactionInformation with EndToEndId, Amount, Cdtr, CdtrAcct, Ustrd
  ├─ Validate XML against pain.001.001.09 XSD before storage
  ↓
[Storage]
  └─ blob: pain.001 XML to docudesk (archival)
  └─ db: hrmq_payment_batch (status=concept)
  └─ db: hrmq_payment_batch_item[] (status=pending)
```

### Approval Workflow

```
concept
  ├─ If total ≤ 100k EUR: auto-approve (payroll-admin self-approval)
  │  └─ → approved
  ├─ If 100k < total ≤ 500k: route to finance-controller
  │  ├─ controller may approve → pending_approval_cfo (if > 100k) or → approved (if ≤ 500k)
  │  └─ controller may reject → concept (with reason)
  ├─ If total > 500k: route to finance-controller, then CFO
  │  ├─ controller → approved (passes to CFO) or → concept (rejects)
  │  ├─ CFO → approved or → concept (rejects)
  └─ Per approval, write hrmq_payment_approval record

approved
  ├─ Submission can proceed
  └─ Pre-notification emails queued for D-2 (2 business days before execution_date)
```

### Submission to Bank

**PSD2 Corporate-API Flow:**
1. Approver initiates submission (batch in `approved` state)
2. System constructs OAuth 2.0 / MTLS request to bank-API (via openconnector adapter for bank_endpoint_id)
3. Bank returns SCA challenge (SMS, push-notification, or embedded form)
4. Approver completes SCA in a modal (reuses openconnector SCA-modal)
5. System submits pain.001 XML + SCA-proof to bank
6. Bank returns submission-acknowledgement (transaction-ID, submission-timestamp)
7. hrmq_payment_batch.status → `submitted`
8. Polling-job scheduled to fetch pain.002 status-updates every 1h (configurable)

**SFTP Batch Flow (Legacy):**
1. Approver initiates submission
2. System SFTP's pain.001.xml to configured directory on bank's SFTP
3. Bank manual-processes and returns pain.002 via SFTP (on next day, or scheduled)
4. Polling reads SFTP directory for pain.002 files matching batch_reference

**Manual Download Flow (Micro-orgs):**
1. Approver initiates submission
2. System downloads pain.001.xml to admin's browser
3. Admin uploads to bank's web-UI
4. Bank processes overnight; pain.002 typically returned via email → manual upload to hrmq

### pain.002 Reconciliation

```
pain.002 (status-report) arrives from bank
  ↓
[Parsing]
  ├─ Extract OrgnlEndToEndId, StatusCode, RejectReason per transaction
  ├─ Match OrgnlEndToEndId → hrmq_payment_batch_item
  ↓
[Status Update]
  ├─ ACCEPTED (no funds move yet, awaiting settlement)
  │  └─ hrmq_payment_batch_item.status = `accepted`
  ├─ ACCEPTED_SETTLEMENT_COMPLETED (funds moved)
  │  └─ hrmq_payment_batch_item.status = `accepted_settlement_completed`
  ├─ REJECTED_WITH_REASON (bank-side rejection)
  │  ├─ hrmq_payment_batch_item.status = `rejected_with_reason`
  │  ├─ hrmq_payment_batch_item.rejection_reason_code = bank-code
  │  ├─ hrmq_payment_batch_item.rejection_reason_description = bank message
  │  └─ Add to HR work-queue for triage
  ↓
[Batch Status]
  ├─ If all items ACCEPTED_SETTLEMENT_COMPLETED → batch.status = `executed`
  ├─ If all items ACCEPTED or SETTLEMENT_COMPLETED → batch.status = `partially_executed`
  ├─ If all items REJECTED → batch.status = `failed`
```

## Data Model & Seed Data

### Entity: hrmq_payment_batch

| Column | Type | Constraint | Example |
|--------|------|-----------|---------|
| id | UUID | PK | `550e8400-e29b-41d4-a716-446655440000` |
| tenant_id | UUID | FK(tenant) | `11c1b15f-5e57-4c6e-8f2e-9e9c8f8f8f8f` |
| payroll_run_id | UUID | FK(payroll_run), UNIQUE per tenant | `aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee` |
| batch_reference | VARCHAR(64) | UNIQUE | `HRMQ-11c1-202605-001` |
| status | ENUM | one of: concept, pending_approval_finance, pending_approval_cfo, approved, submitted, partially_executed, executed, failed | `approved` |
| total_amount | NUMERIC(15,2) | NOT NULL | `1250000.00` |
| payment_count | INT | NOT NULL | `240` |
| execution_date | DATE | NOT NULL | `2026-05-28` |
| pain001_xml_blob_url | VARCHAR(512) | NOT NULL after submission | `s3://docudesk-prod/hrmq/2026-05/550e8400.xml` |
| pain001_xml_hash_sha256 | VARCHAR(64) | NOT NULL | `a1b2c3d4...` |
| pain001_message_id | VARCHAR(64) | NOT NULL | `20260523-001` |
| bank_endpoint_id | UUID | FK(hrmq_payment_bank_endpoint) | `77777777-8888-9999-aaaa-bbbbbbbbbbbb` |
| previous_batch_hash | VARCHAR(64) | NULLABLE (null for first batch) | `z9y8x7w6...` |
| created_at | TIMESTAMP | NOT NULL, DEFAULT NOW() | `2026-05-22 14:30:00 UTC` |
| updated_at | TIMESTAMP | NOT NULL, DEFAULT NOW() | `2026-05-22 15:45:00 UTC` |

**Seed Data Example:**
```sql
INSERT INTO hrmq_payment_batch (
  id, tenant_id, payroll_run_id, batch_reference, status, total_amount, payment_count,
  execution_date, pain001_xml_blob_url, pain001_xml_hash_sha256, pain001_message_id,
  bank_endpoint_id, previous_batch_hash, created_at, updated_at
) VALUES
(
  'b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0',
  '11c1b15f-5e57-4c6e-8f2e-9e9c8f8f8f8f',
  'pr0a0a0a-0a0a-0a0a-0a0a-0a0a0a0a0a0a',
  'HRMQ-11c1-202605-001',
  'executed',
  1250000.00,
  240,
  '2026-05-28',
  's3://docudesk-prod/hrmq/2026-05/b0d0b0d0.xml',
  'abc123def456abc123def456abc123def456abc123def456abc123def456abc1',
  '20260522-001',
  '77777777-8888-9999-aaaa-bbbbbbbbbbbb',
  'prev0prev-prev0-prev0-prev0-prev0prev0prev0',
  '2026-05-22 14:30:00 UTC',
  '2026-05-23 16:15:00 UTC'
);
```

### Entity: hrmq_payment_batch_item

| Column | Type | Constraint | Example |
|--------|------|-----------|---------|
| id | UUID | PK | `550e8401-e29b-41d4-a716-446655440001` |
| batch_id | UUID | FK(hrmq_payment_batch) | `b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0` |
| employee_id | UUID | FK(employee) | `eeee0001-eeee-eeee-eeee-eeeeeeeeeeee` |
| iban | VARCHAR(34) | NOT NULL | `NL91ABNA0417164300` |
| bic | VARCHAR(11) | NULLABLE | `ABNANL2A` |
| amount | NUMERIC(15,2) | NOT NULL | `5208.33` |
| currency | VARCHAR(3) | DEFAULT 'EUR' | `EUR` |
| omschrijving | VARCHAR(140) | NOT NULL | `Salaris mei 2026` |
| end_to_end_id | VARCHAR(35) | UNIQUE per batch | `EMP-eeee0001-20260522-001` |
| composition_breakdown | JSONB | NOT NULL | see below |
| status | ENUM | pending, accepted, accepted_settlement_completed, rejected_with_reason, held_invalid_iban | `accepted_settlement_completed` |
| rejection_reason_code | VARCHAR(4) | NULLABLE | `IBAN` |
| rejection_reason_description | TEXT | NULLABLE | `Account number is invalid` |
| created_at | TIMESTAMP | NOT NULL | `2026-05-22 14:30:15 UTC` |
| updated_at | TIMESTAMP | NOT NULL | `2026-05-23 16:15:22 UTC` |

**composition_breakdown JSON example:**
```json
{
  "netto_loon": 4500.00,
  "onkosten": 208.33,
  "bonus": 500.00,
  "eenmalige_uitkering": 0.00,
  "terugvordering": 0.00,
  "total": 5208.33
}
```

**Seed Data Example (3 items):**
```sql
INSERT INTO hrmq_payment_batch_item (
  id, batch_id, employee_id, iban, bic, amount, currency, omschrijving,
  end_to_end_id, composition_breakdown, status, rejection_reason_code, rejection_reason_description
) VALUES
(
  '550e8401-e29b-41d4-a716-446655440001',
  'b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0',
  'eeee0001-eeee-eeee-eeee-eeeeeeeeeeee',
  'NL91ABNA0417164300',
  'ABNANL2A',
  5208.33,
  'EUR',
  'Salaris mei 2026',
  'EMP-eeee0001-20260522-001',
  '{"netto_loon": 4500.00, "onkosten": 208.33, "bonus": 500.00, "eenmalige_uitkering": 0.00, "terugvordering": 0.00, "total": 5208.33}',
  'accepted_settlement_completed',
  NULL,
  NULL
),
(
  '550e8402-e29b-41d4-a716-446655440002',
  'b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0',
  'eeee0002-eeee-eeee-eeee-eeeeeeeeeeee',
  'NL74RABO0123456789',
  'RABONL2U',
  3750.00,
  'EUR',
  'Salaris mei 2026',
  'EMP-eeee0002-20260522-001',
  '{"netto_loon": 3500.00, "onkosten": 0.00, "bonus": 250.00, "eenmalige_uitkering": 0.00, "terugvordering": 0.00, "total": 3750.00}',
  'accepted_settlement_completed',
  NULL,
  NULL
),
(
  '550e8403-e29b-41d4-a716-446655440003',
  'b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0',
  'eeee0003-eeee-eeee-eeee-eeeeeeeeeeee',
  'NL39RABO0300065264',
  'RABONL2U',
  4250.00,
  'EUR',
  'Salaris mei 2026',
  'EMP-eeee0003-20260522-001',
  '{"netto_loon": 4000.00, "onkosten": 0.00, "bonus": 0.00, "eenmalige_uitkering": 250.00, "terugvordering": 0.00, "total": 4250.00}',
  'rejected_with_reason',
  'IBAN',
  'Account number is invalid'
);
```

### Entity: hrmq_payment_approval

| Column | Type | Constraint | Example |
|--------|------|-----------|---------|
| id | UUID | PK | `aaaabbbb-cccc-dddd-eeee-ffff00001111` |
| batch_id | UUID | FK(hrmq_payment_batch) | `b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0` |
| approver_user_id | UUID | FK(user) | `uuuu0001-uuuu-uuuu-uuuu-uuuuuuuuuuuu` |
| role | VARCHAR(32) | payroll_admin, finance_controller, cfo | `cfo` |
| decision | ENUM | approved, rejected, pending | `approved` |
| timestamp | TIMESTAMP | NOT NULL | `2026-05-23 09:30:00 UTC` |
| comment | TEXT | NULLABLE | `Approved: verified against payroll summary` |
| sca_token_reference | VARCHAR(256) | NULLABLE | `sca_20260523_001_token_hash` |
| created_at | TIMESTAMP | NOT NULL | `2026-05-23 09:30:00 UTC` |

**Seed Data Example (2 approvals):**
```sql
INSERT INTO hrmq_payment_approval (
  id, batch_id, approver_user_id, role, decision, timestamp, comment, sca_token_reference
) VALUES
(
  'aaaabbbb-cccc-dddd-eeee-ffff00001111',
  'b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0',
  'uuuu0002-uuuu-uuuu-uuuu-uuuuuuuuuuuu',
  'finance_controller',
  'approved',
  '2026-05-22 15:45:00 UTC',
  'Verified batch total EUR 1,250,000 matches payroll summary',
  NULL
),
(
  'aaaabbbb-cccc-dddd-eeee-ffff00001112',
  'b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0',
  'uuuu0003-uuuu-uuuu-uuuu-uuuuuuuuuuuu',
  'cfo',
  'approved',
  '2026-05-23 09:30:00 UTC',
  'CFO approval granted. Batch cleared for submission to bank.',
  'sca_20260523_001_token_hash_abc123'
);
```

### Entity: hrmq_payment_bank_endpoint

| Column | Type | Constraint | Example |
|--------|------|-----------|---------|
| id | UUID | PK | `77777777-8888-9999-aaaa-bbbbbbbbbbbb` |
| tenant_id | UUID | FK(tenant) | `11c1b15f-5e57-4c6e-8f2e-9e9c8f8f8f8f` |
| bank_name | VARCHAR(64) | NOT NULL | `Rabobank` |
| connection_type | ENUM | psd2_api, sftp, manual_download | `psd2_api` |
| connection_credentials_ref | VARCHAR(256) | NOT NULL | `vault://rabo-direct-connect-prod` |
| pain_version | VARCHAR(16) | DEFAULT 'pain.001.001.09' | `pain.001.001.09` |
| iban_debtor | VARCHAR(34) | NOT NULL (payroll account) | `NL91ABNA0417164300` |
| created_at | TIMESTAMP | NOT NULL | `2026-01-15 10:00:00 UTC` |
| updated_at | TIMESTAMP | NOT NULL | `2026-01-15 10:00:00 UTC` |

**Seed Data Example:**
```sql
INSERT INTO hrmq_payment_bank_endpoint (
  id, tenant_id, bank_name, connection_type, connection_credentials_ref,
  pain_version, iban_debtor
) VALUES
(
  '77777777-8888-9999-aaaa-bbbbbbbbbbbb',
  '11c1b15f-5e57-4c6e-8f2e-9e9c8f8f8f8f',
  'Rabobank',
  'psd2_api',
  'vault://rabo-direct-connect-prod',
  'pain.001.001.09',
  'NL91ABNA0417164300'
);
```

### Entity: hrmq_payment_batch_status_update

| Column | Type | Constraint | Example |
|--------|------|-----------|---------|
| id | UUID | PK | `99998888-7777-6666-5555-444433332222` |
| batch_id | UUID | FK(hrmq_payment_batch) | `b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0` |
| batch_item_id | UUID | FK(hrmq_payment_batch_item) | `550e8401-e29b-41d4-a716-446655440001` |
| pain002_message_id | VARCHAR(64) | NOT NULL | `20260523-bank-status-001` |
| received_at | TIMESTAMP | NOT NULL | `2026-05-23 16:15:22 UTC` |
| status_code | VARCHAR(4) | NOT NULL | `ACPT` |
| status_description | TEXT | NOT NULL | `Accepted` |
| created_at | TIMESTAMP | NOT NULL | `2026-05-23 16:15:22 UTC` |

**Seed Data Example:**
```sql
INSERT INTO hrmq_payment_batch_status_update (
  id, batch_id, batch_item_id, pain002_message_id, received_at, status_code, status_description
) VALUES
(
  '99998888-7777-6666-5555-444433332222',
  'b0d0b0d0-b0d0-b0d0-b0d0-b0d0b0d0b0d0',
  '550e8401-e29b-41d4-a716-446655440001',
  '20260523-bank-status-001',
  '2026-05-23 16:15:22 UTC',
  'ACPT',
  'Accepted'
);
```

## UI Wireframe: SEPA-Batches Page

```
┌─ Salarissen › SEPA-batches ─────────────────────────────────────────┐
│                                                                      │
│ [Nieuwe batch genereren]  [Filters] [Export] [Settings]             │
│                                                                      │
│ Maand:  [May 2026 ▼]                                                │
│                                                                      │
│ ┌──────────────────────────────────────────────────────────────────┐
│ │ Batch-ref         │ Status  │ Bedrag    │ Betalingen │ Datum     │
│ ├──────────────────────────────────────────────────────────────────┤
│ │ HRMQ-11c1-202605- │ ✅ Exec │ EUR 1.25M │ 240        │ 2026-05-28│
│ │ 001               │ uted    │           │            │            │
│ ├──────────────────────────────────────────────────────────────────┤
│ │ HRMQ-11c1-202604- │ ⏳ Pend │ EUR 950k  │ 235        │ 2026-04-28│
│ │ 001               │ ing     │           │            │            │
│ ├──────────────────────────────────────────────────────────────────┤
│ │ HRMQ-11c1-202603- │ 🚨 Part │ EUR 1.1M  │ 240        │ 2026-03-28│
│ │ 001               │ Exec    │           │ (239✅,1❌) │            │
│ └──────────────────────────────────────────────────────────────────┘
│
│ [Detail:] Click row → batch detail panel with:
│   - Approval history (who approved when)
│   - Payment item list (per-employee breakdown)
│   - Rejection work-queue (if any)
│   - Download pain.001.xml + audit-report
│
└──────────────────────────────────────────────────────────────────────┘
```

## API Endpoints (Summary)

- `POST /api/hrmq/payment-batch/generate` — Generate batch from payroll-run
- `POST /api/hrmq/payment-batch/{batchId}/approve` — Approve batch (with optional SCA challenge)
- `POST /api/hrmq/payment-batch/{batchId}/reject` — Reject batch (back to concept)
- `POST /api/hrmq/payment-batch/{batchId}/submit` — Submit to bank
- `GET /api/hrmq/payment-batch` — List batches with filters
- `GET /api/hrmq/payment-batch/{batchId}` — Batch detail
- `GET /api/hrmq/payment-batch/{batchId}/items` — Batch items (paginated)
- `GET /api/hrmq/payment-batch/{batchId}/approvals` — Approval history
- `GET /api/hrmq/payment-batch/{batchId}/audit-report` — Generate audit-report PDF

## Integration Points

**Inbound:**
- payroll-engine-nl: Fetch netto-loon, bonussen per employee per run
- expense-reimbursement: Fetch declaraties per employee status (approved, pending-reimbursement)
- shillinq SEPA-module: pain.001/pain.002 XML parsing & generation

**Outbound:**
- openconnector: Adapter-based bank-API submission (PSD2, SFTP)
- document-template-engine: Pre-notification emails (template: `salaris-betaling-vooraf.hbs`), audit-reports (template: `salaris-audit-report.hbs`)
- docudesk: Archive pain.001.xml blobs, audit-reports
- Worker queue (async): pain.002 polling job, pre-notification email scheduler

**Notifications:**
- Email: Pre-notification (to employee, D-2 before execution)
- Email: Approval request (to finance-controller, CFO if threshold crossed)
- Email: Rejection work-queue alert (to HR, if rejections in pain.002)
- In-app: Batch status change notifications (to payroll-admin)
