---
title: WVP Module Implementation Tasks
change-id: sick-leave-wvp-full
status: draft
created: 2026-05-23
---

# Implementation Task Checklist

---

## Phase 1: Core Data Model & API Foundation

### Task 1.1: Implement wvp-case & wvp-milestone Entities

- [ ] Create `wvp_cases` table with schema per design.md Entity: wvp-case
  - [ ] PK: case-id (UUID)
  - [ ] FK: employee-id (→ employee-master.id)
  - [ ] FK: tenant-id (→ administratie.id)
  - [ ] Enum columns: case-status, cao-id
  - [ ] Indexes: (tenant-id, employee-id, case-status), (tenant-id, case-opening-date)
  - [ ] Audit columns: created-by, created-at, updated-at
- [ ] Create `wvp_milestones` table
  - [ ] PK: milestone-id (UUID)
  - [ ] FK: case-id (→ wvp_cases.id)
  - [ ] Enum: milestone-type (11 values: week-1-arbo, week-6-probleemanalyse, etc.)
  - [ ] Dates: due-date, completed-date, escalation-sent-at
  - [ ] Enums: status (pending, escalated, at-risk, completed)
  - [ ] Text: gevolgen-bij-niet-naleven
  - [ ] Audit columns
- [ ] Write migration script to create tables with constraints & indexes
- [ ] Seed data: 3-5 example wvp-case & milestone records (per design.md)

### Task 1.2: Implement re-integratie-dossier Table (AVG-Segregated Schema)

- [ ] Create separate schema: `medical_dossier` (different from HR schema)
- [ ] Create `medical_dossier.re_integratie_dossier` table
  - [ ] PK: dossier-entry-id (UUID)
  - [ ] FK: case-id (→ public.wvp_cases.id) — cross-schema reference
  - [ ] FK: tenant-id
  - [ ] Enum: entry-type (probleemanalyse, spreekuur-verslag, fml, izp, etc.)
  - [ ] UUID: bedrijfsarts-author-id (→ employee-master.id)
  - [ ] Date: recorded-date
  - [ ] Bytea: encrypted-payload (AES-256)
  - [ ] Boolean: share-with-uwv-bij-riva
  - [ ] Timestamp: employee-viewed-at
  - [ ] Soft-delete: deleted-at
- [ ] Create row-level security (RLS) policy:
  - [ ] Bedrijfsarts: can SELECT/INSERT own records
  - [ ] HR-medewerker: SELECT only returns NULL for encrypted-payload (metadata only)
  - [ ] Employee (self-service): can SELECT own case's entries, view decrypted
  - [ ] UWV-verzekeringsarts: SELECT allowed only if share-with-uwv-bij-riva = true
- [ ] Create HSM key-store integration:
  - [ ] Function: `encrypt_medical_payload(plaintext, tenant-id)` → uses HSM key
  - [ ] Function: `decrypt_medical_payload(bytea, tenant-id, requester-role)` → logs access
- [ ] Create `medical_access_audit` table (same schema as medical_dossier):
  - [ ] reader-id, record-id, timestamp, ip-address, action-code, purpose-code
  - [ ] PK: audit-id (UUID)
  - [ ] FK: record-id (→ dossier-entry-id)

### Task 1.3: REST API Endpoints — Case CRUD

- [ ] POST `/api/v1/wvp-cases` — Create case on ziekmelding
  - [ ] Input: employee-id, eerste-ziektedag, bedrijfsarts-id (auto-fetch from employee-master if not provided)
  - [ ] Output: case-id, all 11 milestone-due-dates, success message
  - [ ] Side effects: Create 11 wvp-milestone rows, send notification to bedrijfsarts
  - [ ] Tests: Valid input, invalid employee-id, duplicate case within 28 days
- [ ] GET `/api/v1/wvp-cases/{case-id}` — Retrieve case + milestones
  - [ ] Output: case object, 11 milestones (with due-dates, status, evidence-doc-ids)
  - [ ] Role filtering: HR sees no medical-dossier details; bedrijfsarts sees full
  - [ ] Tests: Role-based filtering, non-existent case-id (404)
- [ ] PATCH `/api/v1/wvp-cases/{case-id}` — Update case (status, percentage-arbeidsongeschikt)
  - [ ] Input: case-status, percentage-arbeidsongeschikt, notes
  - [ ] Validation: case-status transition rules (open → herstel only if medical-confirmation)
  - [ ] Side effects: Update updated-at, may trigger notifications
  - [ ] Tests: Invalid transitions, missing medical confirmation
- [ ] GET `/api/v1/wvp-cases` — List cases (casemanager dashboard)
  - [ ] Filters: case-status, employee-id, date-range, loonsanctie-risk-flag
  - [ ] Pagination: limit, offset
  - [ ] Output: Lightweight list (case-id, employee-name, case-opening-date, status, next-due-milestone)

### Task 1.4: Milestone Completion & Evidence Tracking

- [ ] PATCH `/api/v1/wvp-cases/{case-id}/milestones/{milestone-type}` — Mark milestone complete
  - [ ] Input: completed-date (or default to today), evidence-document-id
  - [ ] Validation: Document must exist in document-store; user must have role to mark this milestone
  - [ ] Side effects: Update milestone.completed-date, .evidence-document-id, .status → completed
  - [ ] Trigger notifications (casemanager, next-step alerts)
  - [ ] Tests: Invalid document-id, user without permission, milestone already completed
- [ ] GET `/api/v1/wvp-cases/{case-id}/milestone-timeline` — Timeline view
  - [ ] Output: All 11 milestones with: due-date, completed-date, status, days-until-due, evidence-link
  - [ ] For HR: Medical milestones show only count & date-range, not content

---

## Phase 2: Medical Data Segregation & Access Control

### Task 2.1: Implement Medical Portal (Bedrijfsarts-Only)

- [ ] Create `/medical-portal` route (separate from HR-portal)
  - [ ] Authentication: Require bedrijfsarts-role + HTTPS + HSTS
  - [ ] No cross-domain iframe/embed from HR-portal
- [ ] Create UI: Bedrijfsarts dashboard
  - [ ] List: Assigned cases (case-id, employee-name, case-opening-date, next-deadline)
  - [ ] Actions: Upload probleemanalyse, spreekuur-verslag, FML, medisch-advies, etc.
- [ ] POST `/medical-portal/api/v1/dossier-entries` — Upload medical document
  - [ ] Input: case-id, entry-type, plaintext-content, share-with-uwv-bij-riva checkbox
  - [ ] Validation: User-role must be bedrijfsarts; document-type must match entry-type
  - [ ] Processing:
    - [ ] Encrypt plaintext via `encrypt_medical_payload(content, tenant-id)`
    - [ ] Store encrypted-payload in medical_dossier.re_integratie_dossier
    - [ ] Log access to medical_access_audit
    - [ ] Update wvp-milestone.evidence-document-id (if probleemanalyse)
    - [ ] Trigger notification to casemanager: "Probleemanalyse received [date]"
  - [ ] Tests: Non-bedrijfsarts user (403), invalid entry-type, encryption/decryption round-trip
- [ ] GET `/medical-portal/api/v1/dossier-entries/{entry-id}` — Retrieve with audit
  - [ ] Validation: User role, case assignment
  - [ ] Processing:
    - [ ] Decrypt via `decrypt_medical_payload(encrypted-payload, tenant-id, requester-role)`
    - [ ] Log to medical_access_audit (reader-id, record-id, timestamp, IP, purpose: "READ")
    - [ ] Return decrypted content to bedrijfsarts only
  - [ ] Tests: HR tries to access (403 + security event log), audit-log is accurate

### Task 2.2: Implement Medical-Data Filtering for HR-Portal

- [ ] Modify GET `/api/v1/wvp-cases/{case-id}` for HR-role:
  - [ ] Instead of returning re_integratie_dossier records, return metadata only:
    ```json
    {
      "medical_dossier_metadata": {
        "entry_count": 3,
        "date_range": { "first": "2026-03-20", "last": "2026-03-28" },
        "entry_types": ["probleemanalyse", "spreekuur-verslag"]
      }
    }
    ```
  - [ ] Implement via Postgres RLS policy (query rewrite)
  - [ ] Tests: HR role returns metadata only; bedrijfsarts returns full content

### Task 2.3: Implement Medical-Data Encryption & HSM Integration

- [ ] Integrate with HSM (Hardware Security Module) for key management
  - [ ] Configure tenant-scoped keys: Each tenant has one master key in HSM
  - [ ] Create functions:
    - [ ] `encrypt_medical_payload(plaintext, tenant_id)` → encrypted-bytea
    - [ ] `decrypt_medical_payload(encrypted, tenant_id, requester_role)` → plaintext (or error if unauthorized)
  - [ ] Handle key rotation: HSM rotation schedule (annually) with key versioning
  - [ ] Tests: Encryption/decryption correctness, key versioning, HSM unavailable (graceful failure)
- [ ] Create audit-log for all decryptions:
  - [ ] Automatic log on successful decrypt (in `decrypt_medical_payload`)
  - [ ] Log includes: dossier-entry-id, reader-id, timestamp, IP, purpose-code

### Task 2.4: Implement Retention & Deletion (24-Month Rule)

- [ ] Create `medical_deletion_cron` scheduled job:
  - [ ] Runs: Daily at 02:00 (off-peak)
  - [ ] Query: Find all re-integratie-dossier entries where:
    - [ ] Associated wvp-case.actual-end-date IS NOT NULL
    - [ ] `NOW() - case.actual-end-date > 24 months`
  - [ ] Action:
    - [ ] Hard-delete: DELETE FROM medical_dossier.re_integratie_dossier WHERE [conditions]
    - [ ] Audit-log: INSERT INTO medical_deletion_audit (dossier-entry-id, deletion-date, deletion-reason)
    - [ ] Notification: Email HR "Medical records deleted for [N] cases per AVG retention policy"
  - [ ] Rollback: If deletion fails, email alerting administrator; do not proceed further
  - [ ] Tests: Mock case with 24+ month old end-date, verify deletion and audit-log

---

## Phase 3: Milestone Escalation & Automation

### Task 3.1: Implement Milestone Escalation Reminders (Week 6 Probleemanalyse)

- [ ] Create `probleemanalyse_escalation_cron` scheduled job:
  - [ ] Runs: Daily at 08:00
  - [ ] Query: Find all wvp-cases where:
    - [ ] case-status = open
    - [ ] milestone (week-6-probleemanalyse).due-date exists
    - [ ] milestone.completed-date IS NULL
    - [ ] `DATE_TRUNC('day', CURRENT_DATE - (eerste-ziektedag + 28 days)) = 0` (i.e., day 28)
  - [ ] Action (Day 28):
    - [ ] Fetch bedrijfsarts-organisation contact from employee-master.bedrijfsarts-id
    - [ ] Send email: Subject "Probleemanalyse benodigd: [employee-name], vervaldatum [due-date]"
    - [ ] Update milestone.escalation-sent-at = NOW()
    - [ ] Create case-event log: "Escalation reminder sent to bedrijfsarts"
  - [ ] Action (Day 35):
    - [ ] Same as day 28, but email subject: "URGENT: Probleemanalyse vervaldatum nakend"
    - [ ] Also send notification to casemanager dashboard
  - [ ] Tests: Cron runs on day 28 (verify email sent), day 35 (verify second email), non-applicable cases (skip)

### Task 3.2: Implement Loonsanctie-Risk Flagging (Week 6 Deadline)

- [ ] Create `week_6_deadline_check_cron` scheduled job:
  - [ ] Runs: Daily at 09:00
  - [ ] Query: Find all wvp-cases where:
    - [ ] case-status = open
    - [ ] `DATE_TRUNC('day', CURRENT_DATE - eerste-ziektedag) = 42` (i.e., day 42, week 6)
    - [ ] milestone (week-6-probleemanalyse).completed-date IS NULL
  - [ ] Action:
    - [ ] Update milestone.status → `at-risk`
    - [ ] Generate HR-notification (persistent banner on casemanager dashboard):
      - [ ] Title: "⚠️ Loonsanctie Risk: Probleemanalyse Not Received"
      - [ ] Body: "[Employee-name], case opened [case-opening-date]. Probleemanalyse deadline [due-date] passed. Missing = UWV loonsanctie risk (EUR 35k+). Contact bedrijfsarts: [name], [phone]."
      - [ ] Action buttons: "Mark Complete" (with evidence upload), "Contact Bedrijfsarts", "Dismiss" (HR acknowledges)
    - [ ] Create case-event log: "Milestone week-6-probleemanalyse entered at-risk status per UWV poortwachtsregel"
    - [ ] If HR does not acknowledge within 48 hours, escalate to manager (another notification)
  - [ ] Tests: Cron runs on day 42 (verify flag & notification), edge cases (almost day 42, past day 42)

### Task 3.3: Implement PvA Signature Deadline Enforcement

- [ ] Create `pva_deadline_check_cron` scheduled job:
  - [ ] Runs: Daily at 09:00
  - [ ] Query: Find all wvp-cases where:
    - [ ] case-status = open
    - [ ] `DATE_TRUNC('day', CURRENT_DATE - eerste-ziektedag) = 44` (i.e., day 44, week 8 deadline)
    - [ ] pva-status ≠ `vastgesteld` (not signed by both parties)
  - [ ] Action:
    - [ ] Update milestone (week-8-pva).status → `at-risk`
    - [ ] Generate notification to casemanager (red banner):
      - [ ] Title: "⚠️ PvA Signature Deadline Approaching"
      - [ ] Body: "PvA must be signed by both parties by [due-date]. Current status: [pva-status]. [Link to PvA]"
    - [ ] Create case-event log
  - [ ] At day 56: Escalate to HR-manager (second notification)
  - [ ] At day 57: Update case status to `loonsanctie-risico` (final alert)
  - [ ] Tests: Notifications at days 44, 56, 57; skip if PvA already signed

---

## Phase 4: Plan-van-Aanpak (PvA) & Templates

### Task 4.1: Implement PvA CRUD

- [ ] Create `plan_van_aanpak` table:
  - [ ] PK: pva-id (UUID)
  - [ ] FK: case-id
  - [ ] version (INT), pva-status (ENUM), doelstelling-re-integratie (ENUM)
  - [ ] acties (JSON array), evaluatie-frequentie-weken, volgende-evaluatie-datum
  - [ ] Signature fields: werkgever-signed-on, werknemer-signed-on, signature-document-ids
  - [ ] Audit columns
- [ ] POST `/api/v1/wvp-cases/{case-id}/pvas` — Create PvA
  - [ ] Input: doelstelling, acties array, evaluatie-frequentie-weken, cao-id (from case)
  - [ ] Fetch PvA template from template-engine based on cao-id
  - [ ] Pre-fill template fields (re-integratiebudget-eur, mandatory-acties per CAO, etc.)
  - [ ] Output: pva object with status = `concept`
  - [ ] Side effects: Create case-event log
  - [ ] Tests: Valid cao-id, invalid cao-id (template not found), pre-fill accuracy
- [ ] PATCH `/api/v1/wvp-cases/{case-id}/pvas/{pva-id}` — Update PvA
  - [ ] Input: acties array, evaluatie-frequentie-weken, doelstelling (changes allowed only in concept)
  - [ ] Validation: If pva-status ≠ `concept`, reject with "PvA locked for editing"
  - [ ] Output: Updated pva object
- [ ] POST `/api/v1/wvp-cases/{case-id}/pvas/{pva-id}/sign` — Sign PvA (werkgever)
  - [ ] Input: signature (base64 or e-sign token), signatoryRole = "werkgever"
  - [ ] Validation: User must be HR-role with permission on this case
  - [ ] Processing:
    - [ ] Create/update signature-document in document-store
    - [ ] Update: werkgever-signed-by-user-id, werkgever-signed-on, werkgever-signature-document-id
    - [ ] If both werkgever & werknemer signed: pva-status → `vastgesteld`
    - [ ] Notify employee: "PvA ready for your review. Please sign by [date]"
  - [ ] Tests: Valid signature, double-signing (idempotent), invalid role

### Task 4.2: Implement Employee Self-Service PvA Portal

- [ ] Create `/self-service/my-pva` route:
  - [ ] Authentication: Employee login
  - [ ] Display: PvA in formatted, read-friendly layout (not JSON)
  - [ ] Sections:
    - [ ] Case summary (employee, dates, status)
    - [ ] Doelstelling (goal)
    - [ ] Acties (actions, responsible parties, deadlines)
    - [ ] Evaluation schedule
    - [ ] Werkgever signature + date
    - [ ] Two action buttons:
      - [ ] "✅ Akkoord en ondertekenen" (agree & sign)
      - [ ] "❌ Niet akkoord, toelichting" (disagree & provide reason)
- [ ] POST `/self-service/my-pva/{pva-id}/sign` — Employee agrees
  - [ ] Input: e-sign token or uploaded signature
  - [ ] Processing:
    - [ ] Create signature-document
    - [ ] Update: werknemer-signed-on, werknemer-signature-document-id
    - [ ] pva-status → `vastgesteld`
    - [ ] milestone (week-8-pva) → completed
    - [ ] Notify casemanager: "PvA signed by employee"
  - [ ] Output: Confirmation message, link to signed PvA PDF
- [ ] POST `/self-service/my-pva/{pva-id}/object` — Employee objects
  - [ ] Input: free-text toelichting (reason for objection)
  - [ ] Processing:
    - [ ] pva-status → `werknemers-bezwaar`
    - [ ] Generate deskundigenoordeel-aanvraag template (per UWV Artikel 32)
    - [ ] Email employee: "Objection received. Here is the template to request UWV mediation."
    - [ ] Notify casemanager: "Employee objects to PvA; deskundigenoordeel process initiated"
  - [ ] Tests: Valid objection text, invalid e-signature, double-submission (idempotent)

### Task 4.3: Implement CAO-Specific PvA Templates

- [ ] Create `cao_pva_templates` configuration table:
  - [ ] PK: template-id
  - [ ] FK: cao-id
  - [ ] template-version (INT), template-json (JSONB)
  - [ ] Fields: doelstelling-enum, mandatory-acties, re-integratiebudget-eur, evaluatie-frequentie-weken, cao-specific-clauses
- [ ] Seed templates for 7 CAOs:
  - [ ] cao-gemeenten: EUR 4.500 budget, 6-week evaluation
  - [ ] cao-rijk: EUR 4.200 budget, 6-week evaluation
  - [ ] cao-onderwijs-po: Vervangingsfonds conditions, 4-week evaluation
  - [ ] cao-onderwijs-vo: Vervangingsfonds conditions, 4-week evaluation
  - [ ] cao-ziekenhuizen: Sick-leave fund conditions, 6-week evaluation
  - [ ] cao-zorg-vvt: Specific reintegration budget per facility type, 6-week evaluation
  - [ ] cao-ambtenaren (aor): Statutory reintegration framework, 6-week evaluation
- [ ] POST `/api/v1/admin/cao-pva-templates` — Upload/update custom template
  - [ ] Input: cao-id, template-json, template-version
  - [ ] Validation:
    - [ ] Validate against UWV-required-schema (check design.md REQ-004-002 for required fields)
    - [ ] If any required field missing: Return 422 with per-field error list
    - [ ] If valid: Store template-version, mark as active
  - [ ] Side effects:
    - [ ] Create case-event for audit: "CAO template updated: [cao-name], version [version]"
    - [ ] Notify all casemanagers: "Updated PvA template deployed for [CAO-name]"
  - [ ] Tests: Valid template (all required fields), missing fields, version increment, activation

---

## Phase 5: Evaluatie Milestones (Year 1 & Final)

### Task 5.1: Implement Eerstejaarsevaluatie (Year 1 Evaluation)

- [ ] Create `eerstejaars_evaluatie` table:
  - [ ] PK: evaluatie-id
  - [ ] FK: case-id
  - [ ] scheduled-date, completed-date, completed-by-user-id
  - [ ] Enum: besluit (voortzetting-1e-spoor, start-2e-spoor, wia-aanvraag-recommended)
  - [ ] Document IDs: bedrijfsarts-opinion-document-id, minutes-document-id
- [ ] Create `week_46_evaluatie_reminder_cron` scheduled job:
  - [ ] Runs: Daily at 08:00
  - [ ] Query: Find all wvp-cases where:
    - [ ] case-status = open
    - [ ] `DATE_TRUNC('day', CURRENT_DATE - eerste-ziektedag) >= 322` (i.e., week 46+)
    - [ ] No eerstejaars-evaluatie exists (or completed-date IS NULL)
  - [ ] Action:
    - [ ] Send daily reminder to casemanager: "Schedule eerstejaarsevaluatie for [employee-name] by [week-52-deadline]. [Link to schedule form]"
    - [ ] Persist banner on dashboard until scheduled or completed
    - [ ] At week 50 (if no action): Escalate reminder to casemanager's manager
  - [ ] Tests: Runs only during weeks 46-52, stops after evaluatie scheduled

### Task 5.2: Implement 2e-Spoor Decision & Trajectory Creation

- [ ] Create `tweede_spoor_traject` table:
  - [ ] PK: traject-id
  - [ ] FK: case-id
  - [ ] traject-status (ENUM), re-integratiebureau-id, contract-start-date, contract-end-date
  - [ ] contracted-amount-eur, progress-rapportage-frequentie-days, last-voortgangsrapportage-date
- [ ] POST `/api/v1/wvp-cases/{case-id}/eerstejaarsevaluatie` — Create & complete evaluation
  - [ ] Input: scheduled-date, completed-date, completed-by-user-id, besluit (enum), bedrijfsarts-opinion-doc-id, minutes-doc-id
  - [ ] If besluit = `start-2e-spoor`:
    - [ ] Create tweede-spoor-traject with status = `concept`
    - [ ] Navigate to "Select Reintegration Bureau" form
    - [ ] Notify casemanager: "2e-spoor trajectory initiated. Select bureau and contract details."
  - [ ] If besluit = `voortzetting-1e-spoor`:
    - [ ] No 2e-spoor created
    - [ ] Flag for heroverweging at week 52 (if 1e-spoor progress stalls)
  - [ ] Output: evaluatie object
  - [ ] Tests: Both besluit options, invalid bedrijfsarts-opinion (missing document)

### Task 5.3: Implement 2e-Spoor Bureau Selection & Contract Management

- [ ] GET `/api/v1/partner-registry/re-integratiebureau` — List certified bureaus
  - [ ] Filters: blik-op-werk-status = "certified", region (optional), specialization (optional)
  - [ ] Output: Bureau list with: name, address, contact, Blik op Werk certification date, specializations
- [ ] POST `/api/v1/wvp-cases/{case-id}/tweede-spoor-traject/{traject-id}/activate` — Activate with selected bureau
  - [ ] Input: re-integratiebureau-id, contract-start-date, contract-end-date, contracted-amount-eur
  - [ ] Validation: Bureau must have blik-op-werk-status = "certified"
  - [ ] Processing:
    - [ ] Update traject: traject-status → `actief`, populate all contract fields
    - [ ] Schedule first voortgangsrapportage reminder 90 days out
    - [ ] Notify casemanager & bureau: "Contract activated"
  - [ ] Output: Updated traject object
  - [ ] Tests: Valid bureau, uncertified bureau (rejected), contract dates (start < end)

### Task 5.4: Implement Voortgangsrapportage Tracking & Overdue Alerts

- [ ] Create `voortgangsrapportage_check_cron` scheduled job:
  - [ ] Runs: Daily at 09:00
  - [ ] Query: Find all tweede-spoor-traject records where:
    - [ ] traject-status = `actief`
    - [ ] `NOW() - last-voortgangsrapportage-date > (progress-rapportage-frequentie-days + 14)`
      - [ ] E.g., 90 + 14 = 104 days since last report
  - [ ] Action:
    - [ ] Update traject-status → `voortgangsrapportage-overdue`
    - [ ] Update parent case: Add flag `2e-spoor-niet-bijgehouden-risico`
    - [ ] Generate critical alert for casemanager (red banner):
      - [ ] Title: "🚨 2e-Spoor Progress Report OVERDUE"
      - [ ] Body: "[Bureau] has not submitted quarterly report for [case-id] / [employee-name]. Last report: [date]. Contact bureau immediately."
    - [ ] Send escalation email to bureau: "URGENT: Progress report overdue for [case-id]. Submit immediately."
    - [ ] Create case-event log: "2e-spoor rapportage overdue; loonsanctie risk"
  - [ ] Tests: Simulate 91+ days without report, verify traject-status & case-flags

---

## Phase 6: Final Evaluation & RIV Assembly

### Task 6.1: Implement Eindevaluatie & RIV Trigger

- [ ] Create `eindevaluatie_riva` table:
  - [ ] PK: riva-id
  - [ ] FK: case-id
  - [ ] requested-date, completed-date, requested-by-user-id
  - [ ] riva-pdf-document-id, riva-checksum (SHA256)
  - [ ] werknemer-signed-on, werknemer-signature-document-id
  - [ ] uwv-submitted-on, uwv-submission-reference
- [ ] Create `week_87_riva_alert_cron` scheduled job:
  - [ ] Runs: Daily at 09:00
  - [ ] Query: Find all wvp-cases where:
    - [ ] case-status = open
    - [ ] `DATE_TRUNC('day', CURRENT_DATE - eerste-ziektedag) >= 609` (i.e., week 87+)
    - [ ] No eindevaluatie-riva exists (or completed-date IS NULL)
  - [ ] Action:
    - [ ] Generate CRITICAL persistent alert on casemanager dashboard (red banner):
      - [ ] Title: "🚨 CRITICAL: RIV Assembly Required by Week 91"
      - [ ] Body: "Eindevaluatie and RIV must be completed by [week-91-deadline]. Time remaining: [days]. Start now: [Link]"
    - [ ] At week 88: Escalate to HR-manager
    - [ ] At week 89: Send pre-notification to UWV: "RIV will be late due to [reason]"
  - [ ] Tests: Alert fires at week 87, escalates, pre-notifies UWV

### Task 6.2: Implement RIV PDF-A Generation & Assembly

- [ ] POST `/api/v1/wvp-cases/{case-id}/riva/generate` — Trigger RIV assembly
  - [ ] Input: case-id, requested-by-user-id
  - [ ] Processing:
    - [ ] Query wvp-case, all 11 wvp-milestones
    - [ ] Query re-integratie-dossier entries where share-with-uwv-bij-riva = true & employee-consent = true
    - [ ] Query all plan-van-aanpak versions, eerstejaars-evaluatie, tweede-spoor-rapportages
    - [ ] Fetch all documents (probleemanalyse PDF, FML, etc.) from document-store
    - [ ] Call document-template-engine to render RIV master template (UWV 2024-01 format):
      - [ ] Bundle all documents into single PDF-A (ISO/IEC 19005-1)
      - [ ] Generate cover page with case summary, checksum, UWV instructions
    - [ ] Generate SHA256 checksum of final PDF
    - [ ] Store PDF in document-store
    - [ ] Create eindevaluatie-riva record with riva-pdf-document-id, riva-checksum, completed-date = NOW()
    - [ ] Email employee: "Your RIV is ready. Review by [week-91-deadline] and sign: [Portal Link]"
  - [ ] Output: riva-id, download-link, checksum
  - [ ] Error handling: Missing documents (list them with severity), template rendering failure (retry or alert)
  - [ ] Tests: Valid case with all artifacts, case missing probleemanalyse (skip with note), encryption/decryption during assembly

### Task 6.3: Implement Employee RIV Review & Signature

- [ ] Create `/self-service/my-riva` route:
  - [ ] Authentication: Employee login
  - [ ] Display: RIV PDF in embedded viewer
  - [ ] Button: "Review & Sign RIV"
  - [ ] Show deadline: "Sign by [week-91-deadline]"
- [ ] POST `/self-service/my-riva/{riva-id}/sign` — Employee signs
  - [ ] Input: e-sign token or uploaded signature
  - [ ] Processing:
    - [ ] Create signature-document in document-store
    - [ ] Update: eindevaluatie-riva.werknemer-signed-on = NOW(), werknemer-signature-document-id = [doc-id]
    - [ ] Email casemanager: "RIV signed by employee"
  - [ ] Output: Confirmation, link to signed-copy
- [ ] Create `week_91_riva_unsigned_cron` scheduled job:
  - [ ] Runs: Daily at 09:00 on week 91 deadline
  - [ ] Query: Find all eindevaluatie-riva records where:
    - [ ] werknemer-signed-on IS NULL
    - [ ] requested-date + 7 days <= TODAY (i.e., week 91 deadline passed)
  - [ ] Action:
    - [ ] Alert HR: "Employee has not signed RIV by deadline. You may submit without signature per UWV guidelines: [Link]."
    - [ ] Provide button: "Submit RIV Without Employee Signature (Werkgever-version only)"

### Task 6.4: Implement RIV Submission to UWV

- [ ] POST `/api/v1/wvp-cases/{case-id}/riva/{riva-id}/submit-to-uwv` — Transmit signed RIV to UWV
  - [ ] Input: case-id, riva-id, additional-notes (optional)
  - [ ] Validation: riva-pdf-document-id exists, checksum is valid
  - [ ] Processing:
    - [ ] Fetch signed RIV PDF from document-store
    - [ ] Call UWV REST API (Werkgevers-Portal) to upload RIV with metadata:
      - [ ] Case ID, employee name/BSN, submission-date, checksum, submission-type (with or without employee-signature)
    - [ ] Receive uwv-submission-reference from UWV API
    - [ ] Update eindevaluatie-riva: uwv-submitted-on = NOW(), uwv-submission-reference = [UWV-ref]
    - [ ] Email casemanager: "RIV submitted to UWV. Reference: [UWV-ref]. WIA-claim processing will now begin."
  - [ ] Output: uwv-submission-reference, success message
  - [ ] Error handling: API failure (retry logic, queue for retry), invalid PDF (alert HR)
  - [ ] Tests: Valid RIV, API timeout (retry), invalid checksum (rejected by UWV)

---

## Phase 7: Loondoorbetaling & Payroll Integration

### Task 7.1: Implement Loondoorbetaling Calculation Engine

- [ ] Create `loondoorbetaling_line` table:
  - [ ] PK: loondoorbetaling-id
  - [ ] FK: case-id, payroll-run-id
  - [ ] pay-period-start, pay-period-end, year-of-sickness (1 or 2)
  - [ ] refundable-loon-amount-eur, percentage-applicable, loondoorbetaling-gross-eur
  - [ ] cao-suppletie-eur, total-gross-eur, days-paid, sanction-extension-applied
- [ ] Create loondoorbetaling calculation function:
  - [ ] Input: wvp-case-id, pay-period-start, pay-period-end, year-of-sickness
  - [ ] Logic:
    - [ ] Fetch employee-master: refundable-loon-amount (last-known-salary)
    - [ ] Calculate: `base-70 = refundable-loon * 0.70`
    - [ ] If year-of-sickness = 1: `loondoorbetaling-gross = MAX(base-70, wettelijk-minimum-loon)`
    - [ ] If year-of-sickness = 2: `loondoorbetaling-gross = base-70` (no floor)
    - [ ] Fetch cao-engine: suppletie-percentage per cao-id & year-of-sickness
    - [ ] Calculate: `cao-suppletie = refundable-loon * (suppletie-percentage - 0.70)`
    - [ ] Total: `total-gross = loondoorbetaling-gross + cao-suppletie`
    - [ ] Calculate: `days-paid` from pay-period (count sick-leave days)
  - [ ] Output: loondoorbetaling_line object
  - [ ] Tests: Year 1 with/without minimum-loon floor, year 2 calculation, CAO-suppletie rules (Gemeenten 100%/90%)

### Task 7.2: Integrate with Payroll-Engine-NL

- [ ] Create webhook/callback: When payroll-run is created, trigger loondoorbetaling fetch
  - [ ] POST `/api/v1/payroll-engine-nl/wvp-loondoorbetaling` (or webhook from payroll)
  - [ ] Input: payroll-run-id, pay-period-start, pay-period-end, employee-id-list
  - [ ] Processing:
    - [ ] For each employee in list:
      - [ ] Find open wvp-cases (where pay-period overlaps case-opening-date to actual-end-date or today)
      - [ ] Call loondoorbetaling-calculation function
      - [ ] Create loondoorbetaling_line row
    - [ ] Return: array of loondoorbetaling_line objects to payroll-engine
  - [ ] Payroll-engine-nl integrates lines into payroll (combines with other deductions, taxes, etc.)
  - [ ] Tests: Multiple active cases, payroll-period spanning year-boundary (year 1 → year 2)

### Task 7.3: Implement Sanction Extension (Loonsanctie)

- [ ] When loonsanctie is imposed (REQ-010):
  - [ ] Query all loondoorbetaling-lines for this case with due-date >= loonsanctie-start-date
  - [ ] Extend all lines: Update expected-end-date of case by loonsanctie-weeks
  - [ ] For each line: Set sanction-extension-applied = true
  - [ ] Recalculate payroll-export with extended end-date
- [ ] Tests: Loonsanctie 52 weeks, verify all lines extended, payroll-export matches extension

---

## Phase 8: UWV Integration & Loonsanctie Response

### Task 8.1: Implement UWV Poortwachterstoets Webhook

- [ ] Set up webhook endpoint: POST `/api/v1/webhooks/uwv/poortwachterstoets-outcome`
  - [ ] Input: UWV-signed payload with case-id, outcome (approved, denied, denied-loonsanctie), decision-date, sanction-weeks
  - [ ] Validation:
    - [ ] Verify UWV signature (TLS certificate pinning + HMAC validation)
    - [ ] Find case by case-id
    - [ ] Verify webhook is from authorized UWV IP/domain
  - [ ] Processing (if outcome = denied-loonsanctie):
    - [ ] Update case: case-status → `loonsanctie`, loonsanctie-start-date, loonsanctie-weeks
    - [ ] Call loondoorbetaling-extension function (Task 7.3)
    - [ ] Create case-event log: "UWV poortwachterstoets: DENIED. Loonsanctie [weeks] weeks"
    - [ ] Alert casemanager: "UWV loonsanctie imposed. Loondoorbetaling extended to [new-end-date]. Consider bezwaarschrift?"
    - [ ] Alert finance: "Loondoorbetaling obligation extended for case [case-id]. Budget impact: ~EUR [amount]."
  - [ ] Output: 200 OK (webhook acknowledgment)
  - [ ] Tests: Valid UWV signature, invalid signature (rejected), case not found (error log)

### Task 8.2: Implement Bezwaarschrift (Appeal) Workflow

- [ ] Create `loonsanctie_appeal` table:
  - [ ] PK: appeal-id
  - [ ] FK: case-id
  - [ ] filed-date, filed-by-user-id, expected-outcome-date (6 weeks out)
  - [ ] reason-text, supporting-document-ids (array), appeal-status (filed, acknowledged, approved, denied)
- [ ] POST `/api/v1/wvp-cases/{case-id}/loonsanctie-appeal` — File bezwaarschrift
  - [ ] Input: reason-text, supporting-document-ids
  - [ ] Processing:
    - [ ] Validate case-status = `loonsanctie`
    - [ ] Validate appeal-deadline (typically 6 weeks from loonsanctie-decision)
    - [ ] Create loonsanctie-appeal record
    - [ ] Update case-status → `loonsanctie-bezwaar-lopend`
    - [ ] Generate bezwaarschrift form (pre-filled with case details, reason, documents)
    - [ ] Notify case-reviewer/manager: "Loonsanctie appeal filed for [case-id]"
  - [ ] Output: appeal-id, expected-outcome-date
  - [ ] Tests: Valid appeal (case in loonsanctie), invalid (case not in loonsanctie), deadline-passed (rejected)

### Task 8.3: Implement Early Herstel with Sanction Termination

- [ ] When employee recovers (before sanction-end-date):
  - [ ] HR updates case-status from `loonsanctie` → `herstel` (with confirmation dialog)
  - [ ] Processing:
    - [ ] Calculate actual-sanction-weeks = (herstel-date - loonsanctie-start-date) / 7
    - [ ] Update case: loonsanctie-weeks = actual-sanction-weeks
    - [ ] Delete all loondoorbetaling-lines with due-date > herstel-date
    - [ ] Generate bekorting-loonsanctie-aanvraag form (per UWV procedure)
    - [ ] Send form to UWV via webhook/API: "Employee recovered early. Sanction terminated. [Case-id], [actual-weeks], [herstel-date]."
    - [ ] Notify finance: "Loondoorbetaling terminated early. Final payment: [date]."
  - [ ] Output: Confirmation, bekorting-aanvraag submission-status
  - [ ] Tests: Herstel before mid-sanction (verify recalculation), mid-sanction, late sanction (verify correct actual-weeks)

---

## Phase 9: Testing & Validation

### Task 9.1: Unit Tests

- [ ] Test wvp-case creation (REQ-001-001):
  - [ ] Case created with correct due-dates, status = open, all 11 milestones created
  - [ ] Edge case: Employee has prior closed case within 28 days (should error or reopen)
- [ ] Test probleemanalyse escalation (REQ-002):
  - [ ] Day 28 reminder sent, day 35 reminder sent, day 42 flagged at-risk
  - [ ] Cron runs only on those specific days
- [ ] Test PvA signing (REQ-003):
  - [ ] Werkgever signs, pva-status = `werkgever-signed`
  - [ ] Werknemer signs, pva-status = `vastgesteld`, milestone completed
  - [ ] Werknemer objects, pva-status = `werknemers-bezwaar`, deskundigenoordeel generated
- [ ] Test medical-data encryption/decryption (REQ-008):
  - [ ] Bedrijfsarts reads encrypted entry, content decrypted correctly, audit-log created
  - [ ] HR reads same entry, gets metadata only (null content), no audit-log entry
  - [ ] Non-authorized user reads (403 error, security-event logged)
- [ ] Test loondoorbetaling calculation (REQ-009):
  - [ ] Year 1 with min-loon floor, year 1 without (if salary > 1.5× floor), year 2 (no floor)
  - [ ] CAO-suppletie applied correctly (Gemeenten 100%/90%)
  - [ ] Sanction extension recalculates end-date correctly
- [ ] Test RIV assembly (REQ-007):
  - [ ] All artifacts bundled into PDF-A, checksum generated, employee notified
  - [ ] PDF generation fails gracefully, error logged
- [ ] Tests for each milestone & cron job

### Task 9.2: Integration Tests

- [ ] End-to-end scenario: Ziekmelding → Case created → Probleemanalyse submitted → PvA signed → Year 1 eval → RIV → UWV submission
- [ ] Role-based access: HR portal (no medical), bedrijfsarts portal (full medical), employee self-service (own case)
- [ ] Multi-administratie: Two cases in different tenants (tenant-isolation verified)
- [ ] Payroll integration: Case + loondoorbetaling-lines created, payroll-engine receives lines, payroll includes them

### Task 9.3: Regression Tests

- [ ] Ziekmelding system (sick-leave-mvp) not affected by new WVP module
- [ ] Employee-master fields (bedrijfsarts-assignment, cao-id) still populated correctly
- [ ] Payroll-engine-nl still functions with non-WVP employees

### Task 9.4: Compliance & Security Tests

- [ ] GDPR/AVG Artikel 9 audit:
  - [ ] Medical dossier never visible to HR-role (even via API bypass attempts)
  - [ ] Access-audit-log complete (all reads logged)
  - [ ] 24-month retention: Old entries deleted, audit-log retained
- [ ] Encryption audit:
  - [ ] HSM key never exposed (keys stay in HSM)
  - [ ] Medical payloads encrypted at rest (database dump is unreadable)
  - [ ] Decryption logs role & purpose
- [ ] UWV integration audit:
  - [ ] RIV checksum matches UWV-received checksum (integrity)
  - [ ] Case-data accurate on RIV export (no misalignment with source data)
  - [ ] Loonsanctie webhook signature verified (prevents spoofing)

---

## Phase 10: Deployment & Go-Live

### Task 10.1: Database Migration Plan

- [ ] Create migration script: 01_create_wvp_tables.sql
- [ ] Create migration script: 02_create_medical_dossier_schema.sql
- [ ] Create migration script: 03_create_rls_policies.sql
- [ ] Dry-run on staging environment
- [ ] Test rollback procedure (if migration fails, revert cleanly)
- [ ] Schedule for maintenance window (zero-downtime if possible)

### Task 10.2: Configuration & Deployment

- [ ] Document CAO configurations (7 CAOs, template pre-fills, suppletie-rules)
- [ ] Configure HSM credentials (tenant-scoped keys)
- [ ] Configure UWV webhook endpoint (signature verification, IP whitelist)
- [ ] Configure notification-engine templates (emails for reminders, alerts, escalations)
- [ ] Configure cron jobs (schedule times, retry logic, error alerting)
- [ ] Feature-flag WVP module (enable for pilot group, then global rollout)

### Task 10.3: User Training & Rollout

- [ ] Casemanager training: Dashboard alerts, milestone tracking, RIV assembly
- [ ] Bedrijfsarts training: Medical portal, probleemanalyse upload, audit-log
- [ ] Employee communication: Self-service portal, PvA review, RIV signing
- [ ] Finance training: Loondoorbetaling-line audit, sanction impact on payroll
- [ ] HR-admin training: CAO templates, case lifecycle, escalation procedures
- [ ] Rollout plan: Pilot (50 cases), then full deployment (monitor for bugs)

### Task 10.4: Go-Live Checklist

- [ ] [ ] All unit tests pass
- [ ] [ ] All integration tests pass
- [ ] [ ] Regression tests confirm no impact on other modules
- [ ] [ ] GDPR/security audit passed
- [ ] [ ] UWV integration endpoint tested & verified
- [ ] [ ] Database migration dry-run successful
- [ ] [ ] Configuration deployed to production
- [ ] [ ] Cron jobs tested & scheduled
- [ ] [ ] Monitoring & alerting configured (for cron failures, webhook errors, etc.)
- [ ] [ ] Documentation deployed (for users & developers)
- [ ] [ ] User training completed
- [ ] [ ] Pilot rollout: 50 cases selected, monitored
- [ ] [ ] Feedback loop: Monitor pilot for 2 weeks, fix bugs
- [ ] [ ] Full rollout: Enable for all tenants
- [ ] [ ] Post-launch support: On-call team ready for 2 weeks

---

## Success Metrics (Post-Launch)

- [ ] 100% of new ziekmeldings create wvp-case automatically
- [ ] 95%+ of milestone deadlines met (via escalation reminders)
- [ ] 0 loonsanctie penalties due to missed WVP deadlines
- [ ] 90%+ of RIVs successfully submitted to UWV on first attempt
- [ ] 0 GDPR/AVG audit findings on medical-data segregation
- [ ] Casemanager dashboard alerts reduce manual workload by 70%+
- [ ] Employee portal signatures collected for 80%+ of PvAs & RIVs

