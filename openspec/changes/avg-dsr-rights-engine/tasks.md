---
title: AVG / GDPR Data-Subject Rights Engine — Implementation Tasks
status: draft
---

# Implementation Tasks: AVG / GDPR Data-Subject Rights Engine

## Deduplication Check

- Searched `openspec/specs/` and related directories for overlapping DSR/GDPR/audit functionality
- **Finding:** No existing DSR implementation found. AuditTrailService (platform) exists; this spec extends it with DSR-specific audit events.
- **Reuse:** All core services (ObjectService, FileService, WebhookService, NotificationService) are platform-provided; no reimplementation needed.

---

## Phase 1: Data Model & Schema Registration

- [ ] **T1.1 — Create ProcessingActivity schema + register**
  - Define ProcessingActivity schema in `lib/Settings/hrmq_register.json` with all properties: name, purpose, legal_basis, data_categories, retention_period_months, recipients, cross_border_transfers, security_measures, dpia_required, dpia_completed_at, last_reviewed_at, last_reviewed_by
  - Mark schema as x-openregister.type: "application"
  - Add 3-5 seed objects (salarisadministratie, verzuim, performance, recruitment, etc.) with realistic Dutch values
  - Validate schema against OpenRegister schema standards (PascalCase, schema.org vocabulary)
  - Integration test: import ProcessingActivity schema, verify 15 default activities load on tenant creation

- [ ] **T1.2 — Create Processor schema + register**
  - Define Processor schema with: id, name, contact, data_categories_processed, country, verwerkersovereenkomst_signed_at, verwerkersovereenkomst_document_id, verwerkersovereenkomst_expiry_at, created_at
  - Add 1-2 seed Processor objects (e.g. Exact Online payroll processor)
  - Integration test: create Processor via API, verify file upload field works

- [ ] **T1.3 — Create DsrRequest schema + register**
  - Define DsrRequest schema with: id, requester_email, requester_user_id, request_type, submitted_at, verified_at, verification_method, deadline_at, extended, extension_reason, extension_deadline_at, status, rejection_reason, legal_basis_for_rejection, assigned_to_user_id, erasure_summary_json, created_at, updated_at
  - Add 2-3 seed DsrRequest objects (access, rectification, erasure request examples)
  - Integration test: CRUD operations on DsrRequest, status transitions

- [ ] **T1.4 — Create DsrEvidence schema + register**
  - Define DsrEvidence schema with: id, request_id, source_app, source_schema, record_ids, data_snapshot_json, collected_at, collector_user_id, record_count
  - Integration test: store evidence snapshot with 100+ field JSON, verify retrieval

- [ ] **T1.5 — Register `data_subject_field` annotation on existing schemas**
  - Review all hrmq schemas (Employee, LeaveRequest, PerformanceReview, PayslipRecord, etc.) for fields containing personal data
  - Add `x-data-subject-field: true` annotation to: Employee.user_id, LeaveRequest.requester_id, PerformanceReview.subject_employee_id, PayslipRecord.employee_id, etc.
  - Document which schemas have data_subject_field (for evidence collector walk)
  - Integration test: run schema introspection, verify all data_subject_field annotations are present

---

## Phase 2: ROPA (Article 30 Register) Setup & Export

- [ ] **T2.1 — Create privacy-setup wizard route**
  - Add route: GET/POST `/admin/privacy-setup` (protected, privacy-officer role)
  - Wizard steps:
    1. "Verify tenant name and organisation details"
    2. "Review default ProcessingActivity list (15 items)"
    3. "Customise activities (edit name, purpose, legal_basis, data_categories, retention, security_measures)"
    4. "Mark DPIA completion status per activity"
    5. "Review & approve ROPA"
  - Integration test: walk full wizard, verify ProcessingActivity records created

- [ ] **T2.2 — Pre-fill default ProcessingActivity records**
  - Create a fixture/seed file: `lib/Settings/hrmq_register_defaults.json` with 15 standard Dutch employment-law activities
  - On tenant creation, trigger: `ConfigurationService::importFromApp('hrmq', defaults, version=1, force=false)`
  - Implement idempotency: re-import with force=false skips existing activities (match by slug: salarisadministratie, verzuim, etc.)
  - Integration test: create two tenants, verify both have 15 pre-filled activities without duplicates

- [ ] **T2.3 — Privacy officer customisation form**
  - Create Vue component: `src/views/PrivacySetup/ProcessingActivityForm.vue`
    - Fields: name, purpose, legal_basis (select), data_categories (multi-select), retention_period_months (number), recipients (textarea), security_measures (textarea), dpia_required (checkbox), dpia_completed_at (date picker if checked)
    - Save button triggers: `ObjectService::saveObject(ProcessingActivity)` with last_reviewed_at = now, last_reviewed_by = current user
  - Integration test: edit one default activity, verify changes persisted

- [ ] **T2.4 — ROPA PDF export**
  - Create controller: `src/Controller/PrivacyController::exportRopa()` 
  - Implement PDF generation (using existing PDF library in platform, e.g. TCPDF or mPDF):
    - Header: organisation name, tenant name, export date, privacy officer name
    - Table: all ProcessingActivity records (name, purpose, legal_basis, data_categories, retention, recipients, security_measures, DPIA status)
    - Footer: audit trail reference (date approved, last review date)
    - Digital signature (HMAC-SHA256 of PDF content)
  - Integration test: export ROPA, verify PDF is valid, contains all activities, signature is correct

- [ ] **T2.5 — Lock ROPA after approval**
  - Add field to ProcessingActivity: approved_at (timestamp), approved_by (user_id)
  - Once approved_at is set, ProcessingActivity records are read-only (only admin can re-approve + edit)
  - Add UI: "Approve ROPA" button → sets approved_at, locks form, shows "ROPA approved on [date]"
  - Integration test: approve ROPA, verify edit button is disabled

---

## Phase 3: Processor Registry & DPA Management

- [ ] **T3.1 — Processor registration on integration enable**
  - Hook into integration-enable workflow (part of `openintegrations` or similar)
  - When integration enabled, create Processor record:
    - Call `ObjectService::saveObject(Processor)` with: name (from integration), contact (from integration metadata), data_categories_processed (inferred or manual), country (from metadata)
  - Integration test: enable a mock integration, verify Processor record created

- [ ] **T3.2 — Verwerkersovereenkomst upload UI**
  - Create Vue dialog: `src/dialogs/ProcessorAgreementDialog.vue`
    - Options: "Upload existing PDF" OR "Use Conduction template"
    - Upload: file input → FileService::upload() → store file, set document_id
    - Template: pre-filled form → download as DOC/DOCX → user prints + signs manually → user uploads signed PDF
    - Fields: verwerkersovereenkomst_signed_at (date), verwerkersovereenkomst_expiry_at (date picker, default +2 years)
  - Validation: PDF mime-type check, minimum file size (500 bytes)
  - Integration test: upload valid PDF, verify stored in FileService; reject invalid file

- [ ] **T3.3 — Integration blocking until agreement signed**
  - Add guard in integration-send workflow: before sending data to processor, check Processor.verwerkersovereenkomst_signed_at != null
  - If null, log warning: "Integration [name] blocked: verwerkersovereenkomst unsigned" and don't send
  - Notify privacy officer: Nextcloud notification + audit log
  - Integration test: attempt to send data without agreement, verify blocked; add agreement, verify data sends

- [ ] **T3.4 — Processor renewal reminder job**
  - Create scheduled job: `src/Jobs/ProcessorRenewalCheckJob.php`
    - Runs daily at 08:00
    - Query all Processor records: where verwerkersovereenkomst_expiry_at < today + 60 days
    - For each, create Nextcloud notification to privacy officer: "Processor [name] agreement expires on [date]. Action required."
    - Log: action: processor_renewal_reminder, processor_id, expiry_date, timestamp
  - Integration test: create Processor with expiry_at = today + 45 days, run job, verify notification created

---

## Phase 4: DSR Request Intake & Identity Verification

- [ ] **T4.1 — Public form route (no login required)**
  - Create route: GET/POST `/privacy/request`
  - Form fields: name, email, request_type (radio: access / rectification / erasure / portability / objection), reason (textarea, optional)
  - No CSRF protection required (public form); use honeypot field to deter bots
  - On submit:
    - Validate email is not suspicious (basic spam check)
    - Create DsrRequest: requester_email, requester_user_id (null for now), request_type, submitted_at = now, status = pending_verification, verified_at = null
    - Save via `ObjectService::saveObject(DsrRequest)`
    - Send confirmation email to requester_email
    - Notify privacy officer
  - Integration test: submit form with valid data, verify DsrRequest created + emails sent

- [ ] **T4.2 — In-app submission for logged-in employees**
  - Create route: GET/POST `/account/privacy/request` (requires auth, employee role)
  - Form pre-fills: name (from employee record), email (from employee record)
  - On submit: same flow as public form, BUT also set requester_user_id = current user.id
  - Integration test: logged-in employee submits, verify user_id is set

- [ ] **T4.3 — DigiD integration for Dutch citizens**
  - Integrate OpenID Connect flow to Dutch DigiD provider (or use existing platform integration if available)
  - When identity verification is initiated for requester flagged as Dutch:
    - Generate DigiD auth link: `oidc/authorize?client_id=...&request_id=[DSR request ID]`
    - Send link to requester_email
    - Upon successful DigiD callback:
      - Extract authenticated identity (name, BSN, email)
      - Set DsrRequest.verified_at = now, verification_method = digid
      - Compute deadline_at = verified_at + 30 days
      - Update status = in_progress
      - Notify privacy officer + requester
      - Log: action: identity_verified, verification_method: digid, timestamp
  - Integration test: complete DigiD flow, verify DsrRequest marked as verified

- [ ] **T4.4 — Manual passport verification flow**
  - When identity verification initiated for non-Dutch citizen:
    - Send email to requester: "Please upload scanned passport (first 2 pages) via secure link"
    - Generate time-limited upload link (valid 7 days, single-use): `/privacy/upload-passport?token=[JWT token]`
    - Requester uploads PDF → stored in FileService with metadata
    - Privacy officer reviews:
      - Check: name matches, passport valid (not expired), face visible, no obvious fake
      - Option: APPROVE (proceed with verification) OR REJECT (request different ID)
    - On APPROVE:
      - Set DsrRequest.verified_at, verification_method = passport_copy
      - Delete uploaded passport file (no longer needed)
      - Notify requester + privacy officer
      - Log: action: identity_verified, verification_method: passport_copy
    - On REJECT:
      - Keep DsrRequest.status = pending_verification
      - Send rejection reason to requester
      - Log: action: identity_verification_rejected, reason, timestamp
  - Integration test: upload passport, verify + approve, check status updates; reject, verify requester notified

---

## Phase 5: Evidence Collection (Cross-App Data Walk)

- [ ] **T5.1 — Introspect hrmq schemas for data_subject_field**
  - Create service: `src/Service/DsrEvidenceCollectorService.php`
  - Method: `discoverDataSubjectFields(): array` 
    - Call `SchemaService::getSchemas()` for hrmq register
    - For each schema, check for `x-data-subject-field: true` annotation
    - Return: array of (schema_name, field_names) that reference data subjects
  - Integration test: call method, verify returns expected list (Employee.user_id, LeaveRequest.requester_id, etc.)

- [ ] **T5.2 — Query hrmq records matching subject**
  - Method: `collectEvidenceFromHrmq(DsrRequest): array`
    - For each schema with data_subject_field annotation:
      - Query via ObjectService: `findAll()` with filter: `user_id = requester.user_id` OR `email = requester.email` (case-insensitive)
      - For each matching record, collect full record as JSON snapshot
      - Create DsrEvidence: source_app = hrmq, source_schema = [schema], record_ids = [...], data_snapshot_json = record, collected_at = now, collector_user_id = [privacy officer]
      - Save via `ObjectService::saveObject(DsrEvidence)`
    - Log: action: evidence_collected_from_hrmq, schema_count: N, record_count: M, timestamp
  - Integration test: create Employee + LeaveRequest for user, trigger evidence collection, verify DsrEvidence records created

- [ ] **T5.3 — Fire dsr.collect webhook to external apps**
  - Method: `dispatchExternalEvidenceRequest(DsrRequest): void`
    - Call `WebhookService::publish()` with event = dsr.collect
    - Payload: { event, subject: {email, user_id}, request_id, deadline_for_response: now + 5 days }
    - Update DsrRequest.status = awaiting_external_responses
    - Log: action: external_evidence_request_fired, apps_targeted: N, deadline_date, timestamp
  - Integration test: fire webhook, verify event logged; mock external app responds with data

- [ ] **T5.4 — Receive external app evidence via API**
  - Create endpoint: POST `/api/dsr-evidence` (requires token from integration/webhook context)
  - Input: { request_id, source_app, source_schema, record_ids, data_snapshot_json, collected_at }
  - Validate: request_id exists, source_app is known, data_snapshot_json is valid JSON
  - Create DsrEvidence record via ObjectService
  - Return: 200 OK with evidence_id
  - Log: action: external_evidence_received, source_app, record_count, timestamp
  - Integration test: POST valid evidence, verify stored + retrievable

- [ ] **T5.5 — Evidence collection completion & summary**
  - Method: `markEvidenceCollectionComplete(DsrRequest): void`
    - Query all DsrEvidence for request_id
    - Generate summary: { total_records, records_per_source, total_bytes, missing_responses: [apps] }
    - Update DsrRequest.status = in_progress (ready for decision)
    - Create timeline event: action: evidence_collection_complete, summary, timestamp
  - UI: privacy officer sees progress: "Collected 847 records from 8 sources. Pending: shillinq (no response yet)."
  - Option: send reminder to non-responding apps
  - Integration test: collect evidence from multiple sources, mark complete, verify summary accurate

---

## Phase 6: Decision Actions (Rectification, Erasure, Portability)

- [ ] **T6.1 — Article 16 rectification approval**
  - Create controller: `src/Controller/DsrController::acceptRectification(request_id, field_name)`
    - Retrieve DsrRequest + corrected value
    - Validate: field exists in collected evidence, correction is reasonable
    - Update target record: `ObjectService::saveObject()` with new field value
    - Write audit-log entry: action: field_update, field, old_value, new_value, correction_reason: avg_rectification, request_id, timestamp, updated_by
    - Fire webhook: event = record.updated, correction_reason: avg_rectification, request_id (payload depends on record type: employee.updated, leave.updated, etc.)
    - Update DsrRequest: status = completed (if no other actions pending)
    - Send confirmation email: "Field [field] corrected on [date]. Downstream systems notified."
    - Log: action: rectification_approved, request_id, field, timestamp
  - Integration test: approve rectification, verify field updated + audit logged + webhook fired

- [ ] **T6.2 — Rejection of rectification with documented reason**
  - Controller method: `rejectRectification(request_id, reason, legal_basis)`
    - Validate: reason provided, legal_basis selected (enum: frivolous_request, excessive_requests, no_legal_basis)
    - Update DsrRequest: rejection_reason, legal_basis_for_rejection, status = completed
    - Send email: "Your correction request was rejected. Reason: [reason]. Legal basis: [legal_basis citation]. You may appeal."
    - Log: action: rectification_rejected, request_id, reason, timestamp
  - Integration test: reject rectification, verify requester email sent

- [ ] **T6.3 — Article 17 erasure evaluation**
  - Create service: `src/Service/ErasureEvaluatorService.php`
  - Method: `evaluateErasure(DsrRequest): array`
    - For each collected data field:
      - Query ProcessingActivity for the field's origin schema
      - Get ProcessingActivity.retention_period_months
      - If retention = 0: mark erasable
      - If retention > 0: calculate earliest_erasure_date = collection_date + retention_period; mark not_erasable_until
    - Return: { erasable_fields: [...], not_erasable_fields: [{field, not_until, reason}] }
  - Integration test: evaluate erasure for employee with salary + leave records, verify retention dates calculated correctly

- [ ] **T6.4 — Execute erasure or anonymisation**
  - Method: `executeErasure(DsrRequest, decision: {erasable_fields, not_erasable_fields})`
    - For each erasable_field:
      - Delete or anonymise field value (configurable per field type)
      - Write audit-log: action: field_deleted, field, reason: gdpr_article_17, request_id, timestamp
    - For each not_erasable_field:
      - Append metadata: { retention_reason, earliest_erasure_date, legal_basis }
      - Write audit-log: action: field_retention_flagged, field, earliest_erasure_date, timestamp
    - Generate response document (HTML + PDF):
      - "Fields erased: [list with deletion confirmation]"
      - "Fields retained: [list with retention_reason + legal_basis + earliest_erasure_date]"
      - "Retained fields will be automatically erased on [date]."
    - Update DsrRequest: status = completed, erasure_summary_json = decision
    - Send response document to requester
    - Log: action: erasure_executed, erasable_count, retained_count, timestamp
  - Integration test: execute erasure on mixed salary + leave data, verify erasable deleted, retained flagged, email sent

- [ ] **T6.5 — Article 20 portability export**
  - Service: `src/Service/PortabilityExportService.php`
  - Method: `generateExport(DsrRequest): string (ZIP file ID)`
    - Filter collected evidence: include only records where legal_basis in [consent, contract]
    - For each record, extract user-provided fields (heuristic: name, email, phone, address, emergency_contact; exclude notes, performance_from_others)
    - Generate JSON export: { export_metadata: {...}, data: [{schema, record_id, fields}] }
    - Generate CSV export: Schema, Record ID, Field Name, Value (one row per field)
    - Create ZIP: data-export-[date].zip containing both files
    - Upload to FileService with: metadata = { request_id, requester_email, generated_at, expiry_at: now + 30 days }
    - Generate signed download link (JWT token, 30-day TTL)
    - Return: { zip_id, download_link, expiry_at }
  - Integration test: generate export, verify JSON + CSV valid, ZIP downloadable, expiry set

- [ ] **T6.6 — Deliver portability export**
  - Method: `deliverPortabilityExport(request_id)`
    - Retrieve export_id + download_link
    - Send email to requester: "Your data export is ready. Download: [link]. Valid until: [expiry_at]."
    - Create FileService access log: { requester_email, timestamp: when downloaded, ip_address }
    - Update DsrRequest: status = completed, portability_export_link = link metadata
    - Log: action: portability_export_delivered, request_id, timestamp
  - Integration test: deliver export, verify email sent, download link works, access logged

---

## Phase 7: Deadline Tracking & Escalation

- [ ] **T7.1 — Calculate and store deadline**
  - When DsrRequest.verified_at is set:
    - Compute deadline_at = verified_at + 30 days
    - Store in DsrRequest
    - Log: action: deadline_calculated, deadline_at, timestamp
  - Integration test: verify DsrRequest, check deadline_at = verified_at + 30 days

- [ ] **T7.2 — Daily deadline check job**
  - Create scheduled job: `src/Jobs/DsrDeadlineCheckJob.php`
    - Runs daily at 08:00
    - Query DsrRequest where status != [completed, rejected] AND deadline_at is set
    - For each request:
      - If deadline_at - 7 days <= today: send T-7 alert to privacy officer (Nextcloud notification)
      - If deadline_at < today AND status != extended: escalate to DPO + admin, mark status = deadline_breached
    - Log each check: action: deadline_check_run, alerts_sent, escalations_triggered, timestamp
  - Integration test: create requests with various deadline dates, run job, verify alerts/escalations triggered

- [ ] **T7.3 — Extension request & approval**
  - Method: `requestDeadlineExtension(request_id, reason: enum, custom_note: string)`
    - Validate: request not yet extended, status allows extension
    - Set: extended = true, extension_reason, extension_deadline_at = deadline_at + 60 days
    - Send email to requester: "Your request is complex. Deadline extended to [extension_deadline_at]."
    - Log: action: deadline_extended, from_date: deadline_at, to_date: extension_deadline_at, reason, timestamp
    - Update DsrRequest
  - Integration test: extend deadline, verify email sent, new deadline_at = old + 60 days

- [ ] **T7.4 — Deadline breach escalation**
  - Method: `escalateDeadlineBreach(request_id)`
    - Update DsrRequest: status = deadline_breached
    - Create escalation incident linked to request
    - Send urgent notifications to: privacy officer, DPO, tenant admin
    - Generate AP-inspection-ready report: timeline, reason for delay (status history), actions to remediate
    - Log: action: deadline_breach_escalated, request_id, overdue_days, escalated_to: [users], timestamp
  - Integration test: trigger breach on overdue request, verify escalation incident created, notifications sent

---

## Phase 8: Audit Trail & AP Inspection

- [ ] **T8.1 — DSR-specific audit log events**
  - Extend AuditTrailService with DSR action types:
    - `dsr.intake`, `dsr.verified`, `dsr.evidence_collected`, `dsr.rectification_approved`, `dsr.erasure_executed`, `dsr.export_generated`, `dsr.deadline_extended`, `dsr.deadline_breached`
  - Each entry: action, dsr_request_id, actor, timestamp, details (action-specific), signature (HMAC for tamper-detection)
  - Implement immutability: audit entries cannot be deleted, only marked as "superseded" with a correction entry
  - Integration test: perform DSR action, verify audit entry created, retrievable, tamper-proof

- [ ] **T8.2 — AP inspection report export**
  - Create controller: `src/Controller/PrivacyController::generateApInspectionReport()`
    - Query all DsrRequest, DsrEvidence, audit-log entries from past 12 months
    - Generate comprehensive report: Excel or PDF format with:
      - Sheet 1: Summary stats (total requests, request types, average time-to-completion, breaches)
      - Sheet 2: All requests with timeline (submission → verification → completion, each timestamped)
      - Sheet 3: ROPA snapshot (all activities, last-reviewed dates, approvals)
      - Sheet 4: Processor registry snapshot (all processors, DPA status, expiry dates)
      - Sheet 5: Audit trail (all DSR actions, immutable log)
    - Digital signature (HMAC-SHA256) for tamper-detection
    - Return: downloadable file
  - Integration test: generate report, verify all data present, file valid

- [ ] **T8.3 — Audit trail UI for privacy officer**
  - Create Vue component: `src/views/DsrRequest/AuditTrailTab.vue`
    - Timeline view of all actions for a DsrRequest
    - For each entry: action type, actor, timestamp, details (collapsible)
    - Color-coded: success (green), warning (yellow), error (red)
  - Integrate into DsrRequest detail page as a tab
  - Integration test: view DSR request, switch to audit tab, verify timeline displayed

---

## Phase 9: Frontend UI & UX

- [ ] **T9.1 — Create privacy-setup wizard page**
  - Route: `/admin/privacy-setup`
  - Multi-step form (Vuetify Stepper or similar):
    - Step 1: Tenant verification
    - Step 2: Review default activities
    - Step 3: Customise (add/edit/delete activities)
    - Step 4: DPIA status
    - Step 5: Review & approve (read-only summary)
  - Completion: ROPA is locked, exportable
  - Integration test: walk wizard, verify data persisted

- [ ] **T9.2 — Create public DSR request form**
  - Route: `/privacy/request`
  - Form: name, email, request_type (radio), reason (textarea)
  - Confirmation page: "Request received. Case number: [ID]. Check email for next steps."
  - No login required, honeypot field
  - Integration test: submit valid form, check DsrRequest created

- [ ] **T9.3 — Create in-app DSR request form (for employees)**
  - Route: `/account/privacy/request`
  - Form: pre-filled name/email (from employee record), request_type, reason
  - Integration test: logged-in employee submits, user_id set

- [ ] **T9.4 — Create DSR request management page**
  - Route: `/admin/dsr-requests`
  - List view: table of all requests (case #, requester, type, submitted, status, deadline)
  - Filters: status, request_type, date range
  - Detail view: full request data, timeline, action buttons (verify, collect evidence, decide, extend deadline)
  - Role-based: privacy-officer see all, HR-admin sees assigned requests
  - Integration test: list requests, filter, open detail, perform action

- [ ] **T9.5 — Create processor registry UI**
  - Route: `/admin/processors`
  - List: all Processor records with DPA status (green: signed, yellow: expiring, red: expired/unsigned)
  - Detail: view processor, upload/renew DPA, view historical agreements
  - Integration test: list processors, click to detail, upload DPA

- [ ] **T9.6 — Create ROPA export page**
  - Route: `/admin/ropa-export`
  - Button: "Export ROPA as PDF"
  - PDF includes: all activities, last-reviewed dates, approvals, digital signature
  - Integration test: export, verify PDF valid

---

## Phase 10: Integration Tests

- [ ] **T10.1 — End-to-end DSR access request test**
  - Scenario: Employee submits access request → identity verified → evidence collected → delivery
  - Steps:
    1. Employee submits form at `/privacy/request`
    2. Privacy officer verifies identity (mock DigiD)
    3. System collects evidence from hrmq + external apps (mock response)
    4. Privacy officer marks complete
    5. Requester receives delivery notification
    6. Audit trail shows full timeline
  - Assertions: DsrRequest.status = completed, audit log has 6+ entries, email sent to requester

- [ ] **T10.2 — Rectification workflow test**
  - Scenario: Employee requests correction → privacy officer reviews → field updated → downstream notified
  - Steps:
    1. DsrRequest with rectification submitted
    2. Privacy officer reviews, accepts
    3. Field is updated + audit logged
    4. Webhook fired to downstream
    5. Requester receives confirmation
  - Assertions: field value updated, audit entry created, webhook called, status = completed

- [ ] **T10.3 — Erasure workflow test**
  - Scenario: Employee requests erasure → system evaluates retention → erases/retains fields → response sent
  - Steps:
    1. DsrRequest with erasure submitted
    2. System evaluates each field (erasable vs. retained)
    3. Privacy officer confirms erasure decision
    4. Erasable fields deleted, retained fields flagged with retention_date
    5. Response document sent to requester
  - Assertions: erasable fields deleted, retained fields flagged, audit logged, response sent

- [ ] **T10.4 — Deadline tracking test**
  - Scenario: Request submitted → deadline calculated → T-7 alert → deadline extension → completion
  - Steps:
    1. DsrRequest created, verified
    2. deadline_at = verified_at + 30 days
    3. Run daily job when T-7
    4. Privacy officer extends deadline
    5. Run job when original deadline passed (no escalation due to extension)
    6. Run job when extended deadline passed (escalation)
  - Assertions: alerts sent, extension logged, escalation triggered on breach

- [ ] **T10.5 — Multi-app evidence collection test**
  - Scenario: Evidence collected from hrmq + external apps (shillinq, openconnector)
  - Steps:
    1. DsrRequest created, verified
    2. Trigger evidence collection
    3. hrmq schemas queried
    4. dsr.collect webhook fired
    5. External apps respond with data
    6. Evidence stored as DsrEvidence records
  - Assertions: DsrEvidence created for hrmq + external sources, record_count accurate, collected_at timestamps present

---

## Phase 11: Security & Compliance Gates

- [ ] **T11.1 — Field-level RBAC for DSR data**
  - Only privacy-officer role can view requester_email, rectification_fields, erasure_summary_json
  - HR-admin can act on rectifications/erasures but cannot view request details
  - Employee can view only their own DsrRequest + associated communications
  - Implement via PropertyRbacHandler on DsrRequest schema
  - Integration test: logged-in as HR-admin, attempt to view requester_email (should be redacted)

- [ ] **T11.2 — Data export filtering (no PII in logs)**
  - When exporting evidence to requester, sanitise:
    - Never include: password hashes, 2FA secrets, internal notes, performance reviews from others, system flags
    - Always include: name, email, phone, address, employment contract, leave records, salary (if subject provided)
  - Implement in PortabilityExportService via field-type classification
  - Integration test: export for requester, verify sensitive fields excluded

- [ ] **T11.3 — Audit trail immutability**
  - Audit entries stored in append-only log table
  - No deletes allowed; corrections only via new superseding entries
  - HMAC signature on each entry for tamper-detection
  - Integration test: attempt to modify audit entry (should fail), verify signature validation

- [ ] **T11.4 — Webhook signature validation**
  - Incoming evidence via dsr.collect webhook must include signature (HMAC-SHA256)
  - Validate signature against known integration secret before storing evidence
  - Log failed signature attempts as security event
  - Integration test: POST evidence with invalid signature (should reject)

---

## Phase 12: Documentation & Onboarding

- [ ] **T12.1 — Privacy officer manual**
  - Write guide: "Managing DSR Requests in hrmq"
  - Sections:
    - ROPA setup (Article 30 register)
    - Processor registry management
    - Requesting DSR identity verification (DigiD / passport)
    - Evidence collection process
    - Decision workflows (rectification, erasure, portability)
    - Deadline tracking & extension
    - AP inspection reports
  - Format: Markdown, embedded in in-app help or separate PDF

- [ ] **T12.2 — Employee/requester information**
  - Write public-facing guide: "How to Request Your Data"
  - Sections:
    - Types of requests (access, rectification, erasure, portability)
    - How to submit request (online form or in-app)
    - Identity verification process (DigiD explanation)
    - Timeline (30-day response period + possible extension)
    - How to appeal rejection
  - Format: Markdown, linked from `/privacy/request` form

- [ ] **T12.3 — Integration guide for external apps**
  - Document dsr.collect webhook contract
  - Payload format, response format, timeout rules (5 business days)
  - Example response (JSON with data fields)
  - Error handling (retry logic, logging)
  - Format: OpenAPI / AsyncAPI spec

- [ ] **T12.4 — In-app tooltips & help**
  - Add contextual help text to all DSR forms (name, purpose, legal_basis, etc.)
  - Hover tooltips on complex fields (retention_period_months, data_categories)
  - Link to manual + AVG articles from help text
  - Integration test: check tooltips render, links valid

---

## Summary

**Total tasks: ~70 checkboxes across 12 phases**

**Estimated effort breakdown:**
- Phase 1 (Data models): 1-2 days
- Phase 2 (ROPA): 2-3 days
- Phase 3 (Processor registry): 1-2 days
- Phase 4 (DSR intake + verification): 2-3 days
- Phase 5 (Evidence collection): 2-3 days
- Phase 6 (Decisions): 3-4 days
- Phase 7 (Deadline tracking): 1-2 days
- Phase 8 (Audit + AP inspection): 2-3 days
- Phase 9 (Frontend UI): 3-4 days
- Phase 10 (Integration tests): 2-3 days
- Phase 11 (Security gates): 1-2 days
- Phase 12 (Documentation): 1-2 days

**Total estimated effort: 20-35 days (L-size spec)**

**Critical path:**
1. Phase 1: Data models (foundational)
2. Phase 2: ROPA setup (Article 30 requirement)
3. Phase 4: DSR intake + verification (primary workflow)
4. Phase 5: Evidence collection (core feature)
5. Phase 6: Decisions (domain logic, longest phase)
6. Phase 7-8: Deadline + audit (compliance requirements)
7. Phase 9-10: Frontend + tests (validation)

**Blocking dependencies:**
- Phase 1 must complete before any other phase
- Phase 4 must complete before Phase 5 (evidence collection depends on verified requests)
- Phase 5-6 can run in parallel (independent decision workflows)
- Phase 10-11 can run in parallel with Phase 9
