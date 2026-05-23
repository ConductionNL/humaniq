---
title: AVG / GDPR Data-Subject Rights Engine — Specifications
status: draft
---

# Specifications: AVG / GDPR Data-Subject Rights Engine

## REQ-001: Article 30 verwerkingenregister (ROPA)

### REQ-001-001: Pre-fill Standard Processing Activities on Setup

**GIVEN** a hrmq tenant onboarding  
**WHEN** the privacy-setup wizard is launched  
**THEN** the system pre-populates the ProcessingActivity register with ~15 standard Dutch employment-law defaults:

1. Salarisadministratie (legal_basis: legal_obligation, retention: 84 months)
2. Verzuimregistratie (legal_basis: legal_obligation, retention: 24 months, requires NEN 7510)
3. Personeelsgegevens (legal_basis: contract, retention: 36 months)
4. Reiskosten (legal_basis: contract, retention: 60 months)
5. Erfgoedverlof (legal_basis: legal_obligation, retention: 12 months)
6. Opleiding & Training (legal_basis: legitimate_interest, retention: 36 months)
7. Performance Reviews (legal_basis: legitimate_interest, retention: 36 months)
8. Pensioenadministratie (legal_basis: legal_obligation, retention: 120 months)
9. Recruitment (legal_basis: consent, retention: 24 months)
10. Background Checks (legal_basis: consent, retention: 36 months)
11. Arbeidsovereenkomst & wijzigingen (legal_basis: contract, retention: 60 months)
12. Verklaringen (legal_basis: contract, retention: 84 months)
13. Declaraties & onkosten (legal_basis: contract, retention: 60 months)
14. Gratuïteitsfonds (legal_basis: legal_obligation, retention: 120 months)
15. Rooster & Tijdregistratie (legal_basis: legal_obligation, retention: 24 months)

**AND** each pre-filled activity includes: purpose (Dutch), data_categories, recipients, security_measures, and dpia_required flag

**AND** the privacy officer can edit each activity before sign-off

### REQ-001-002: Privacy Officer Customises & Signs Off ROPA

**GIVEN** a privacy officer reviewing the pre-filled ROPA  
**WHEN** they customise name, purpose, legal_basis, data_categories, retention_period_months, recipients, security_measures, dpia_required  
**THEN** each modification is timestamped with editor identity

**AND** the privacy officer can add custom ProcessingActivity records (not in the default list)

**AND** the privacy officer can mark a DPIA as completed (dpia_completed_at timestamp)

**AND** the privacy officer signs off the ROPA via a "Approve & Export" action

**AND** upon approval, the ROPA is locked (editable only with new approval) and exportable as PDF

### REQ-001-003: ROPA Export for AP Inspection

**GIVEN** an approved ROPA  
**WHEN** the privacy officer exports for supervisory inspection  
**THEN** the system generates a PDF document containing:

- Header: organisation name, tenant name, export date, privacy officer signature
- Table of all ProcessingActivity records with: name, purpose, legal_basis, data_categories, retention_period, recipients, security measures, DPIA status
- Footer: audit trail references (date approved, who approved, change history link)
- Digital signature / tamper-evident seal

**AND** the PDF is downloadable + archivable for AP inspection

---

## REQ-002: Processor Registry + Verwerkersovereenkomsten

### REQ-002-001: Processor Record Creation on Integration

**GIVEN** a hrmq tenant enabling an external integration (payroll-provider, ATS, learning-platform, etc.)  
**WHEN** the integration is enabled  
**THEN** a Processor record is created with:
- name (from integration metadata)
- contact (email from integration)
- data_categories_processed (inferred from integration's declared data scope)
- country (from integration metadata or manual input)

**AND** the privacy officer is prompted with a banner: "Complete processor agreement: upload a verwerkersovereenkomst or use a Conduction template"

### REQ-002-002: Verwerkersovereenkomst Upload & Template

**GIVEN** a Processor record with no verwerkersovereenkomst_signed_at  
**WHEN** the privacy officer clicks "Add agreement"  
**THEN** they can either:
1. Upload a signed PDF verwerkersovereenkomst (stored via FileService, document_id recorded)
2. Use a Conduction-provided template (pre-filled with processor contact + hrmq organisation details, prints for signature)

**AND** upon upload, the privacy officer sets:
- verwerkersovereenkomst_signed_at (date signed)
- verwerkersovereenkomst_expiry_at (expiry date, e.g. 2 years from signing)

**AND** the system validates: document is PDF, has minimum 500 bytes (basic PDFK validation)

### REQ-002-003: Integration Blocked Until Agreement Signed

**GIVEN** a Processor record with verwerkersovereenkomst_signed_at = null  
**WHEN** the integration attempts to send data to the processor  
**THEN** the system blocks the data transfer and logs: "Integration [name] blocked: verwerkersovereenkomst unsigned"

**AND** the privacy officer is alerted: "Processor agreement required for [processor name]"

**AND** once verwerkersovereenkomst_signed_at is set, the integration is unblocked

### REQ-002-004: Processor Agreement Renewal Reminder

**GIVEN** a Processor record with verwerkersovereenkomst_expiry_at < 60 days from today  
**WHEN** the background job `processor-renewal-check` runs daily  
**THEN** the privacy officer receives a Nextcloud notification: "Processor [name] agreement expires on [date]. Action required."

**AND** the Processor record displays a yellow warning: "Agreement expiring [date]"

---

## REQ-003: DSR Request Intake — Article 15 Inzage

### REQ-003-001: Public Form Submission

**GIVEN** an employee or ex-employee visiting `/privacy/request` (no login required)  
**WHEN** they fill out the intake form with: name, email, request_type (radio: access / rectification / erasure / portability / objection), optional reason  
**AND** they submit  
**THEN** a DsrRequest record is created with:
- requester_email (from form)
- requester_user_id (null for ex-employees; looked up if current employee)
- request_type (from radio selection)
- submitted_at (now)
- status = pending_verification
- verified_at = null

**AND** a confirmation email is sent to requester_email: "Request received. Case number: [ID]. Next: identity verification. Deadline: 30 days from verification."

**AND** the privacy officer is notified: "New DSR request from [name]. Action: verify identity."

### REQ-003-002: In-App Submission (Logged-In)

**GIVEN** a current employee viewing `Mijn HR › AVG-verzoeken › Inzage aanvragen`  
**WHEN** they fill out the intake form (pre-filled with: name from employee record, email from employee record)  
**AND** they submit  
**THEN** the same flow as REQ-003-001 occurs

**BUT** requester_user_id is automatically populated (current employee)

### REQ-003-003: Identity Verification — DigiD

**GIVEN** a DsrRequest with status = pending_verification  
**WHEN** the privacy officer reviews the request and initiates verification  
**AND** the requester is flagged as a Dutch citizen (heuristic: .nl email domain OR BSN present)  
**THEN** the system generates a DigiD authentication link and sends it to requester_email

**AND** upon successful DigiD authentication, the system:
- Sets verified_at = now, verification_method = digid
- Records the requester's authenticated identity (name, BSN if provided)
- Computes deadline_at = verified_at + 30 days
- Updates status → in_progress
- Notifies privacy officer: "Request [ID] identity verified via DigiD."

### REQ-003-004: Identity Verification — Manual Passport Copy

**GIVEN** a DsrRequest with status = pending_verification  
**WHEN** the privacy officer initiates verification for a non-Dutch citizen  
**THEN** the system sends an email to requester: "Please upload a scanned passport copy (first 2 pages) via this secure link."

**AND** the requester uploads the scanned PDF (via FileService)

**AND** the privacy officer reviews the uploaded copy, checks: name matches, passport is valid (not expired), face is clear

**AND** upon approval, the privacy officer marks the request verified:
- Sets verified_at = now, verification_method = passport_copy
- Computes deadline_at = verified_at + 30 days
- Updates status → in_progress
- Deletes the passport copy file (no longer needed, identity stored)
- Notifies requester: "Identity verified. Your request is in progress. Deadline: [deadline_at]."

**AND** if rejected, the privacy officer records: reason, sends rejection to requester, DsrRequest remains pending_verification

---

## REQ-004: Evidence Collection — Cross-App Data Walk

### REQ-004-001: Schema Walk & Collection

**GIVEN** a verified DsrRequest (verified_at is set)  
**WHEN** the privacy officer clicks "Collect evidence"  
**THEN** the system iterates over all schemas in the hrmq register:

1. For each schema declaring a `data_subject_field` annotation (e.g. Employee.user_id, LeaveRequest.requester_id, PerformanceReview.subject_employee_id):
   - Query all records matching requester.user_id OR requester.email (exact + case-insensitive)
   - For each matching record, collect the full record as a DsrEvidence snapshot
   - Store: source_app = hrmq, source_schema = [schema name], record_ids = [collected IDs], data_snapshot_json = [full record], collected_at = now, collector_user_id = [privacy officer ID]

2. Update DsrRequest.status → awaiting_external_responses (if external apps are integrated)

**AND** the privacy officer sees a progress report: "Collected X records from Y schemas. Awaiting external responses..."

### REQ-004-002: Cross-App Evidence via Webhook

**GIVEN** a verified DsrRequest with evidence collection in progress  
**WHEN** the system fires the `dsr.collect` webhook via hris-api-public  
**THEN** all registered external apps (shillinq, openconnector, opencatalogi audit-log) receive the event:

```
POST /webhook
{
  "event": "dsr.collect",
  "subject": {
    "email": "jan.jansen@example.nl",
    "user_id": "emp-001-janjansen" (nullable)
  },
  "request_id": "req-20260515-emp-001",
  "deadline_for_response": "2026-05-23T00:00:00Z" (5 days)
}
```

**AND** each app responds within 5 days with:

```
POST /hrmq/dsr-evidence
{
  "request_id": "req-20260515-emp-001",
  "source_app": "shillinq",
  "source_schema": "PayslipRecord",
  "record_ids": ["slip-2024-001", "slip-2024-002"],
  "data_snapshot_json": [{...}, {...}],
  "collected_at": "2026-05-20T14:30:00Z"
}
```

**AND** hrmq stores each response as a DsrEvidence record

**AND** if an app fails to respond within 5 days:
- The privacy officer is alerted: "Pending response from [app]. Deadline was [date]."
- DsrRequest.status can be marked: awaiting_input (manual decision to proceed without external data or extend deadline)

### REQ-004-003: Evidence Completeness Check

**GIVEN** evidence collection complete (all schemas + external apps responded or deadline passed)  
**WHEN** the privacy officer reviews the collected evidence  
**THEN** they can see:
- Count of records per source_app/source_schema
- Data volume (total bytes of collected JSON)
- Any missing external responses (flagged as yellow warning)

**AND** the privacy officer can:
- Mark collection complete → status = in_progress (move to decision phase)
- Extend deadline (if awaiting external responses) → extend deadline, status stays awaiting_input
- Send a reminder email to non-responding apps

---

## REQ-005: Article 16 Rectification

### REQ-005-001: Requester Submits Correction Request

**GIVEN** a DsrRequest with request_type = rectification, status = in_progress  
**WHEN** the requester submits a correction: field name, old value, corrected value  
**THEN** the system validates:
- The specified field exists in collected evidence
- The old value matches the collected evidence (or is plausible)
- The corrected value is reasonable (no injection, max 500 chars)

**AND** the system creates a correction-proposal record with: request_id, field_name, old_value, corrected_value, requester_comment (optional)

**AND** the privacy officer is notified: "Correction proposed for [field]. Action: review."

### REQ-005-002: Privacy Officer Reviews & Accepts

**GIVEN** a correction-proposal pending review  
**WHEN** the privacy officer clicks "Accept"  
**THEN** the system:

1. Updates the specified field in the original record (e.g. Employee.first_name)
2. Writes an immutable audit-log entry with:
   - action: field_update
   - field: [field name]
   - old_value: [old]
   - new_value: [new]
   - request_id: [DSR request ID]
   - correction_reason: avg_rectification
   - timestamp: now
   - updated_by: [privacy officer ID]
3. Fires a `employee.updated` webhook (or record-type-specific update event) with: `correction_reason: avg_rectification`, `request_id: [DSR request ID]`
4. Downstream systems (payroll, leave, etc.) receive the webhook and can respond accordingly
5. Updates DsrRequest.status → completed (if this was the only action)
6. Sends confirmation to requester: "Field [field] corrected on [date]. Downstream systems updated."

**AND** if rejected, the privacy officer enters a rejection_reason + legal_basis_for_rejection (enum: frivolous_request, excessive_requests, no_legal_basis)

**AND** the requester is notified: "Your correction request was rejected. Reason: [reason]. Legal basis: [citation]."

---

## REQ-006: Article 17 Erasure

### REQ-006-001: Evaluate Erasure Against Retention Obligations

**GIVEN** a DsrRequest with request_type = erasure, status = in_progress  
**WHEN** the privacy officer clicks "Evaluate erasure"  
**THEN** the system computes, for each collected data field:

1. Check ProcessingActivity.retention_period_months for the activity that collected this field
2. If retention_period is 0 (no legal retention), mark as: erasable
3. If retention_period > 0:
   - Calculate earliest_erasure_date = last_collection_date + (retention_period_months / 12 years)
   - Determine: not_yet_erasable (until earliest_erasure_date)
4. For each field, generate a decision: { field, erasable_now, not_erasable_until: [date], reason }

**AND** the system generates a summary:
```
Erasable now (X fields):
- Field A: [reason]
- Field B: [reason]

Not erasable until [date] (Y fields):
- Field C: [retention reason]
- Field D: [retention reason]
```

### REQ-006-002: Execute Erasure or Anonymisation

**GIVEN** erasure evaluation complete  
**WHEN** the privacy officer confirms erasure  
**THEN** for each erasable_now field:
1. Delete the field value OR anonymise it (e.g. replace first_name with "REDACTED", email with "redacted@example.com")
2. Log a deletion audit-entry with: action: field_deleted, field, reason: gdpr_article_17, request_id, timestamp, deleted_by

**AND** for each not_erasable field:
1. Append metadata: { retention_reason, earliest_erasure_date, legal_basis_for_retention }
2. Log: action: field_retention_flagged, field, reason, earliest_erasure_date, timestamp, reviewed_by

**AND** the system generates a response document to the requester:

```
Fields erased:
- [Field A]: Deleted from our records on [date].
- [Field B]: Anonymised on [date].

Fields retained (with legal basis):
- [Field C]: Retained until [earliest_erasure_date] per [legal basis citation] (e.g. "7-year tax retention requirement").
- [Field D]: Retained until [earliest_erasure_date] per [reason].

Any retained fields will be automatically erased on [earliest_erasure_date].
```

**AND** the DsrRequest.status → completed, erasure_summary_json = [summary]

---

## REQ-007: Article 20 Portability Export

### REQ-007-001: Generate Machine-Readable Export

**GIVEN** a DsrRequest with request_type = portability, status = in_progress  
**WHEN** the privacy officer clicks "Generate export"  
**THEN** the system filters collected evidence:

1. Include only records where legal_basis in [consent, contract] (exclude derived data, internal notes, performance-reviews-from-others)
2. For each included record, extract fields that were directly provided by the subject (heuristic: fields like name, email, phone, address, emergency-contact)
3. Exclude fields like: notes, performance_scores_from_others, calculated_fields, internal_flags

**AND** generate two export files:

**Export 1: JSON (machine-readable)**
```json
{
  "export_metadata": {
    "generated_at": "2026-05-20T15:00:00Z",
    "requester_email": "jan.jansen@example.nl",
    "data_categories": ["identifying", "contact", "employment"],
    "retention_period_months": 36,
    "legal_basis": ["consent", "contract"]
  },
  "data": [
    {
      "schema": "Employee",
      "record_id": "emp-001",
      "fields": {
        "first_name": "Jan",
        "last_name": "Jansen",
        "email": "jan.jansen@example.nl",
        "phone": "+31 6 12345678",
        "address": "Straat 1, 1234 AB Amsterdam, NL",
        "emergency_contact_name": "Marie Jansen"
      }
    },
    {
      "schema": "LeaveRequest",
      "record_id": "leave-2024-005",
      "fields": {
        "leave_type": "annual",
        "start_date": "2024-07-01",
        "end_date": "2024-07-14",
        "approved": true
      }
    }
  ]
}
```

**Export 2: CSV (human-readable)**
```
Schema,Record ID,Field Name,Value
Employee,emp-001,first_name,Jan
Employee,emp-001,last_name,Jansen
Employee,emp-001,email,jan.jansen@example.nl
LeaveRequest,leave-2024-005,leave_type,annual
...
```

### REQ-007-002: Deliver Export with Signed Link

**GIVEN** export files generated  
**WHEN** the privacy officer clicks "Deliver to requester"  
**THEN** the system:

1. Creates a ZIP archive containing both JSON + CSV files
2. Uploads the ZIP to FileService with: metadata = { request_id, requester_email, generated_at, expiry_at = now + 30 days }
3. Generates a signed, time-limited download link (valid for 30 days, single-use OR multi-use configurable)
4. Sends an email to requester: "Your data export is ready. Download link: [link]. Valid until: [expiry_at]. File: data-export-20260520.zip (2.5 MB)."

**AND** the download link logs access: { requester_email, download_timestamp, ip_address } for audit trail

**AND** upon expiry, the ZIP file is deleted (FileService lifecycle)

**AND** DsrRequest.status → completed, portability_export_link = [link metadata]

---

## REQ-008: Deadline Tracking + Extension

### REQ-008-001: Alert at T-7 Days

**GIVEN** a DsrRequest with deadline_at in 7 days  
**WHEN** the daily job `dsr-deadline-check` runs  
**THEN** the privacy officer receives a Nextcloud notification:

"DSR Request [Case#] due in 7 days.  
Requester: [name] ([email])  
Request type: [access/rectification/erasure/portability]  
Current status: [status]  
Action needed: [next step based on status]"

**AND** the DsrRequest detail page displays a yellow warning banner: "Deadline: [deadline_at]. 7 days remaining."

### REQ-008-002: Extension Request

**GIVEN** a DsrRequest approaching deadline with complex evidence (multi-app, >1000 records)  
**WHEN** the privacy officer clicks "Request extension"  
**THEN** they can:
- Select: Reason = "Complex multi-source request" OR "Awaiting external app response" OR "Large data volume" (enum)
- Optional: Add custom note
- Click "Extend by 60 days"

**AND** the system validates: extension = true, extension_deadline_at = deadline_at + 60 days (max per AVG Art. 12.3)

**AND** the system sends an email to requester: "Your request is complex. We are extending our deadline to [extension_deadline_at]. We will respond by that date."

**AND** all extension events are logged in audit-trail: action: deadline_extended, reason, extension_deadline_at, approved_by, timestamp

### REQ-008-003: Escalation on Deadline Breach

**GIVEN** a DsrRequest with deadline_at or extension_deadline_at in the past  
**WHEN** the daily job `dsr-deadline-check` runs  
**THEN** the system:

1. Updates DsrRequest.status → deadline_breached
2. Sends alert to privacy officer + DPO + tenant admin: "DEADLINE BREACH: Request [ID] was due [date]. Action required immediately."
3. Generates an escalation-incident record (link to DsrRequest) for manual follow-up
4. Writes an audit-log entry: action: deadline_breached, overdue_days: [N], escalated_to: [DPO, admin], timestamp
5. Prepares AP-inspection-ready report: "Request [ID] exceeded statutory deadline by [N] days. Timeline: [submitted_at → verified_at → evidence collection → deadline passed]. Reason for delay: [status history]."

**AND** the tenant admin can manually extend further (only once, with documented severe reason + AP notification flag)

---

## REQ-009: Audit Trail & AP Inspection Ready

### REQ-009-001: Immutable Audit Log for All DSR Actions

**GIVEN** any DSR action (intake, verification, evidence collection, decision, delivery)  
**WHEN** the action occurs  
**THEN** an immutable audit-log entry is created with:
- action: [intake / verified / evidence_collected / rectification_approved / erasure_executed / export_generated / deadline_extended / deadline_breached]
- dsr_request_id: [reference]
- actor: [user_id of privacy officer / system job]
- timestamp: [now, server time]
- details: {action-specific details}
- signature: [HMAC or cryptographic signature for tamper-detection]

**AND** entries are stored in a separate, append-only log table (no deletes, updates only with new entries marked as corrections)

**AND** the audit-log is queryable and exportable for AP inspection

### REQ-009-002: AP Inspection Report

**GIVEN** a tenant under AP supervisory inspection  
**WHEN** the privacy officer clicks "Generate AP inspection report"  
**THEN** the system exports:

1. A comprehensive timeline of all DSR requests from the past 12 months
2. For each request: case number, submission date, requester, request type, verification method, deadline, status, completion date, all audit-log entries
3. Summary statistics: total requests, average time-to-completion, deadline breaches (count + dates)
4. ROPA snapshot (as of inspection date) with last-approved date + reviewer
5. Processor registry snapshot with all verwerkersovereenkomsten status
6. Digital signature + export timestamp

**AND** the report is exportable as PDF + raw audit CSV for AP analysis

---

## Quality Gates & Constraints

### Data Subject Matching

- Email matching is case-insensitive
- For ex-employees, accept BSN as alternative identifier (must pass 11-proef validation)
- For current employees, prefer user_id over email (more reliable)

### Field Redaction Policy (Portability Export)

- Never include: password hashes, 2FA tokens, internal notes, performance reviews from other employees, system logs, internal flags
- Always include: name, email, phone, address, emergency contact, employment contract, leave records, salary (if subject provided it), benefits declarations

### Retention Period Calculations

- Retention is measured from either: last_collection_date OR employment_end_date, whichever is later
- Earliest_erasure_date must be at least 90 days in the future (allow for legal appeals)

### Webhook Response Timeout

- External apps have 5 business days to respond to `dsr.collect` webhook
- If no response by deadline, mark as "not_responded" in evidence summary; privacy officer can proceed without it or extend deadline

### Immutability of Audit Logs

- Audit entries cannot be deleted, only marked as "superseded" if a correction is needed
- All corrections to audit entries must themselves be logged as new entries
