---
status: approved
---

# Tasks: Offboarding workflow (Offboarding case entity)

## Backend Implementation

### Data Model & Schemas

- [ ] **Task 1.1** — Create OpenRegister schema `Offboarding` in `lib/Settings/hrmq_register.json` with all fields per design.md (employee_id, status enum, reden enum, eindafrekening fields, etc.)
  - Subtask 1.1.1: Define status enum with all 16 step values
  - Subtask 1.1.2: Define reden enum with all 10 termination-reason values
  - Subtask 1.1.3: Add field-level RBAC rules (hr_admin only on transitievergoeding_bedrag, etc.)

- [ ] **Task 1.2** — Create OpenRegister schema `OffboardingStep` with fields per design.md (offboarding_id, step_key enum, status, completed_by_user_id, blocked reasons, timestamps)
  - Subtask 1.2.1: Define step_key enum with all 16 step values
  - Subtask 1.2.2: Ensure immutability post-completion (no direct field edit, only "reopen" via audit)

- [ ] **Task 1.3** — Create OpenRegister schema `Eindafrekening` with nested `componenten` JSON object per design.md
  - Subtask 1.3.1: Define componenten structure (verlofuren_wettelijk, vakantiegeld, etc.)
  - Subtask 1.3.2: Add computed-field definitions (totaal_bruto, netto_beverage)
  - Subtask 1.3.3: Add freeze-flag + immutability rules per REQ-OFF-004

- [ ] **Task 1.4** — Create OpenRegister schema `EquipmentReturn` with categorie enum, asset-tracking fields, condition + damage-assessment per design.md

- [ ] **Task 1.5** — Create OpenRegister schema `ExitInterview` with nested `antwoorden` JSON (satisfaction scores, reason categories, open feedback)
  - Subtask 1.5.1: Add anonimiteit enum (geanonimiseerd_na_90_dagen, etc.)
  - Subtask 1.5.2: Define anonymization-rules per company policy (90-day pseudo-replacement)

- [ ] **Task 1.6** — Create OpenRegister schema `Getuigschrift` with template_id, doc-reference, signature-tracking per design.md

- [ ] **Task 1.7** — Create OpenRegister schema `RetentionTimer` with artefact_type enum, grondslag enum (fiscal/labour/recruitment timers), destruction-tracking per design.md

- [ ] **Task 1.8** — Load 3-5 seed objects per schema via `lib/Settings/hrmq_register.json` with `@self` envelopes and Dutch realistic values (see design.md Seed Data sections)

### Reden & Legal Determinism (REQ-OFF-001)

- [ ] **Task 2.1** — Implement `ReasondeterminismService::computeToepasselijkheid(reden: string)` method that returns structured object with:
  - `transitievergoeding_van_toepassing: bool`
  - `transitievergoeding_grondslag: string`
  - `ww_melding_uwv_vereist: bool`
  - Mapping per REQ-OFF-001 with audit-comment per decision
  - Add @spec tag linking to offboarding-wizard/specs.md#REQ-OFF-001

- [ ] **Task 2.2** — Wire `ReasondeterminismService` into `OffboardingController::store()` (on creation) and add validation that fields computed from reden cannot be manually overridden (block user-edits with error message)

- [ ] **Task 2.3** — Add test cases for all 10 reden values covering toepasselijkheid mapping (unit tests)

### Eindafrekening Computation (REQ-OFF-002)

- [ ] **Task 3.1** — Implement `EindafekeningComputationService::compute(offboarding_id: string)` method:
  - Fetch employee's dienstjaren from employee-master
  - Fetch 12-month salary average (or 36-month for bonuses) per Besluit loonbegrip
  - Fetch current leave-balance (wettelijk/bovenwettelijk) from employee-master
  - Compute transitievergoeding: `dienstjaren × 1/3 × maandsalaris × (dagen_dit_jaar / 365)`
  - Cap at 2026 statutory max + log cap-applied
  - Build audit-table (55-month grondslag per month visible)
  - Return nested `componenten` structure with all 8 components
  - Add @spec tag linking to offboarding-wizard/specs.md#REQ-OFF-002

- [ ] **Task 3.2** — Implement `EindafekeningComputationService::calculateLeaveUTF(uurloon, wettelijk_uren, bovenwettelijk_uren)` for leave payout per REQ-OFF-003
  - Verify statutory-leave expiry (6 months post accrual)
  - Verify extra-statutory-leave expiry (5 years post accrual)
  - Return bedrag per component

- [ ] **Task 3.3** — Implement `EindafekeningComputationService::calculateVakantiegeld(grondslag, percentage, periode_start, periode_end, reeds_uitbetaald)` for vacation-money pro-rata per REQ-OFF-003
  - Return pro-rata bedrag for 1 juni – einddatum period
  - Subtract already-paid amounts in that period

- [ ] **Task 3.4** — Implement `EindafekeningComputationService::calculateThirteenthMonth(vol_bedrag, teller, noemer)` for 13th-month pro-rata per REQ-OFF-002
  - Formula: `vol_bedrag × (teller / noemer)`
  - Return pro-rata bedrag

- [ ] **Task 3.5** — Create data-dump export for Eindafrekening with audit-table visible as CSV/PDF (55-month detail)

- [ ] **Task 3.6** — Add test cases covering:
  - 4-year 7-month example from REQ-OFF-002 AC-002.1 (expect €6033.33)
  - Leave-expiry scenarios (6m statutory, 5y extra-statutory)
  - Vacation-money pro-rata calculation
  - Transitievergoeding max-cap for 2026

### Eindafrekening Freeze & Payroll Handoff (REQ-OFF-004)

- [ ] **Task 4.1** — Implement `EindafekeningController::approve(id)` method (accessible to hr_admin role only):
  - Validate all components are complete
  - Set bevroren = true, goedgekeurd_op = now, goedgekeurd_door_user_id = current_user_id
  - Trigger webhook to payroll-engine-nl with frozen Eindafrekening snapshot
  - Add @spec tag linking to offboarding-wizard/specs.md#REQ-OFF-004

- [ ] **Task 4.2** — Implement immutability guard in `EindafekeningService::update()`:
  - Check if bevroren == true
  - If true, reject with "Bevroren eindafrekening kan niet worden gewijzigd. Intrekken en opnieuw aanmaken."
  - Log rejection attempt in audit-trail

- [ ] **Task 4.3** — Implement `EindafekeningController::revoke(id, reason, correctie_naheffing)` method (hr_admin only):
  - Validate reason is provided
  - If payroll_run_id is set (already paid) AND correctie_naheffing != true, reject
  - Mark old Eindafrekening.ingetrokken_op = now, .ingetrokken_reden = reason
  - Auto-create new Eindafrekening with same components
  - Send correction-message to payroll-engine-nl
  - Log entire action in audit-trail

- [ ] **Task 4.4** — Add test cases for freeze-guard, post-payment revocation with/without correctie-flag

### IT Account Deactivation & Data-Export (REQ-OFF-005)

- [ ] **Task 5.1** — Implement `ItDeactivationService::exportDataForEmployee(offboarding_id, export_channel)` method:
  - Fetch all employee personal data from Nextcloud (Files, Calendar, Contacts, Talk history)
  - Create ZIP/TAR archive (or USB image if postal selected)
  - If download-link: generate secure link with 14-day expiry via FileService
  - If USB: create postal form + route to admin queue
  - Update `Offboarding.data_export_aan_werknemer_status = link_verstuurd`
  - Add @spec tag linking to offboarding-wizard/specs.md#REQ-OFF-005

- [ ] **Task 5.2** — Implement `ItDeactivationService::disableUserAccount(employee_id)` method:
  - Call Nextcloud OCS Users API to set user `enabled = false`
  - Configure mail-forwarding to manager via OCS Mail API (90-day window)
  - Set mail-forwarding text (Dutch + English) with auto-responder pointing to new contact
  - Log all OCS API calls in audit-trail

- [ ] **Task 5.3** — Implement background job `DeleteDisabledUserAccountJob` (runs daily):
  - Find all disabled users with last_workday + 30 days <= today
  - Call Nextcloud OCS Users API to delete user
  - Update `Offboarding.data_export_aan_werknemer_status = voltooid`
  - Log deletion in audit-trail

- [ ] **Task 5.4** — Add test cases for download-link expiry, USB-postal routing, OCS API error handling, 30-day delete window

### UWV WW-Melding (REQ-OFF-006)

- [ ] **Task 6.1** — Implement `UwvMeldingService::draftAndSubmit(offboarding_id)` method (called when entering uwv_ww_melding step):
  - Check `Offboarding.ww_melding_uwv_vereist == true`
  - Fetch Offboarding, employee, and termination-agreement PDF (if exists)
  - Draft XML/JSON per UWV API spec with: reden enum, einddatum, lastgenoten loon, agreement PDF
  - Submit via openconnector
  - Update `Offboarding.ww_melding_uwv_status = verzonden`
  - Log submission timestamp + openconnector reference
  - Add @spec tag linking to offboarding-wizard/specs.md#REQ-OFF-006

- [ ] **Task 6.2** — Implement webhook handler for UWV confirmation-response:
  - Receive CloudEvent from openconnector on UWV acceptance
  - Update `Offboarding.ww_melding_uwv_status = bevestigd`
  - Log confirmation details

- [ ] **Task 6.3** — Implement background job `UwvMeldingRetryJob`:
  - Daily check for submissions with status = `verzonden` older than 24 hours
  - Attempt re-submission (up to 3 retries)
  - On final failure, create escalation task for HR-owner with error details

### Pensioenfonds + ZVW-Afmelding (REQ-OFF-007)

- [ ] **Task 7.1** — Implement `PensionMeldingService::submitTermination(offboarding_id)` method:
  - Fetch employee's pension fund(s) from employee-master
  - Look up fund-specific API endpoint from settings
  - Draft per-fund termination message with: employee BSN, end-date, fund-ref
  - Submit via openconnector
  - Update OffboardingStep status = `in_progress`
  - Log submission reference

- [ ] **Task 7.2** — Implement webhook handler for pension-fund confirmation:
  - Receive CloudEvent from openconnector
  - Update OffboardingStep status = `completed`
  - Log confirmation details

- [ ] **Task 7.3** — Implement `ZvwMeldingService::submitTermination(offboarding_id)` method:
  - Draft ZVW-afmelding (health insurance termination) per ZVW API spec
  - Submit via openconnector
  - Similar confirmation-tracking as pension

- [ ] **Task 7.4** — Implement background job `PensionZvwEscalationJob` (daily check):
  - Find submissions with status = `in_progress` older than 14 days
  - Create escalation task for HR-owner

- [ ] **Task 7.5** — Add test cases for multi-fund scenarios, OpenConnector error-handling, escalation triggering

### Getuigschrift Generation (REQ-OFF-008)

- [ ] **Task 8.1** — Implement `GetuigschriftService::generateDraft(offboarding_id, type, kwalitatief_assessment)` method:
  - Fetch employee, offboarding, and manager data
  - Select template from docudesk (feitelijk or kwalitatief variant)
  - Pre-fill: name, position, hire-date, end-date, job-description from employee-master
  - Render template via docudesk API
  - Store draft in FileService
  - Update `Getuigschrift.status = draft`
  - Add @spec tag linking to offboarding-wizard/specs.md#REQ-OFF-008

- [ ] **Task 8.2** — Implement `GetuigschriftService::prepareForSignature(getuigschrift_id)` method:
  - Fetch rendered draft from FileService
  - Create eIDAS signature-envelope via docudesk
  - Send signature-request to manager
  - Update `Getuigschrift.status = awaiting_signature`

- [ ] **Task 8.3** — Implement webhook handler for signature-completion:
  - Receive signed PDF from docudesk
  - Store final signed PDF in FileService (immutable)
  - Update `Getuigschrift.verstrekt_op = today`, status = `completed`
  - Trigger delivery notification to employee

- [ ] **Task 8.4** — Implement delivery mechanism (email link or print-postal routing) per employee preference

- [ ] **Task 8.5** — Add test cases for feitelijk-only vs. kwalitatief variants, manager-signature flow, docudesk integration error-handling

### Retention Timers & Cryptographic Destruction (REQ-OFF-009)

- [ ] **Task 9.1** — Implement `RetentionTimerService::createTimersOnCompletion(offboarding_id)` method (called when Offboarding.afgerond_op is set):
  - Identify all artefacts in the dossier (WID-Kopie, Salarisstroken, Jaaropgaven, contract, etc.)
  - For each artefact, determine grondslag (fiscal 7y, labour 5y, recruitment 2y, other per statute)
  - Create RetentionTimer per artefact with: gestart_op = today, vervalt_op = today + retention-period
  - Store in OpenRegister
  - Set `Offboarding.retentie_timers_gestart = true`
  - Add @spec tag linking to offboarding-wizard/specs.md#REQ-OFF-009

- [ ] **Task 9.2** — Implement background job `DestructionExecutionJob` (daily at 01:00 UTC):
  - Query all RetentionTimers with vervalt_op <= today AND vernietigd_op = null
  - For each expired timer:
    - Fetch artefact from FileService via artefact_referentie
    - If encrypted: cryptographic key-destruction (NIST SP 800-88 compliant)
    - If plaintext: overwrite-7pass (Gutmann algorithm or equivalent)
    - Update RetentionTimer.vernietigd_op = today, .vernietigingsmethode = method-used
    - Log destruction in audit-trail (immutable, includes key-fingerprint if applicable)
  - Handle errors gracefully (escalate to AVG-functionaris if destroy fails)

- [ ] **Task 9.3** — Implement `RetentionTimerController::list()` for auditor/AVG-functionaris (read-only, filtered by RBAC):
  - Return all RetentionTimers (active + destroyed) with filter by offboarding, artefact_type, grondslag
  - Include full audit-chain (before/after, timestamps)

- [ ] **Task 9.4** — Add test cases for 5y/7y/2y timer calculations, cryptographic-destruction logging, auditor-query filtering

### Audit Trail Completeness (REQ-OFF-015)

- [ ] **Task 10.1** — Ensure all 7 entity schemas (Offboarding, OffboardingStep, Eindafrekening, etc.) have automatic audit-trail tracking enabled in OpenRegister:
  - Before/after snapshots on every field change
  - Actor (user_id), timestamp, change-reason (if provided)
  - Immutable audit-log (no deletion, no editing)

- [ ] **Task 10.2** — Implement `AuditTrailController::export(offboarding_id, format)` method:
  - Format options: CSV, PDF, JSON
  - Include all audit entries sorted by timestamp
  - Sign PDF/JSON with organization's key (for regulatory compliance)

- [ ] **Task 10.3** — Add test cases for field-change tracking, immutability of audit-log, export formatting

### Step Enforcement & Workflow Guards (REQ-OFF-014)

- [ ] **Task 11.1** — Implement `WorkflowStepService::validateStepCompletion(offboarding_id, target_step)` method:
  - Check if target_step's dependencies are all completed
  - If reden = pensionering: auto-skip uwv_ww_melding
  - If equipment_geretourneerd not complete: block it_accounts_deactiveren
  - Throw exception with dependency-list if validation fails

- [ ] **Task 11.2** — Wire validation into `OffboardingStepController::markComplete(step_id)`:
  - Call WorkflowStepService::validateStepCompletion()
  - On validation failure: return 422 with dependency error message
  - On success: update OffboardingStep.status = completed, completed_at = now

- [ ] **Task 11.3** — Implement skipping-logic for inapplicable steps:
  - On Offboarding creation, compute which steps apply based on reden
  - Auto-create OffboardingStep records with status = `skipped` for non-applicable steps

- [ ] **Task 11.4** — Add test cases for dependency validation, skipping-logic, error messages

### GDPR Data Subject Access Export (REQ-OFF-010)

- [ ] **Task 12.1** — Implement `GdprDataSubjectAccessService::exportDossier(offboarding_id, data_subject_user_id)` method:
  - Validate requesting user is the employee (or HR with explicit consent)
  - Fetch all Offboarding + related entity records
  - Pseudonymize third-party references: manager, IT-owner, colleague names → "Manager [M01]", etc.
  - Build searchable PDF with sections: Personal data, Offboarding steps, Eindafrekening detail, Exit interview (anonymized), Equipment, Audit trail
  - Encrypt PDF with password
  - Store delivery-link (7-day expiry) in secure channel
  - Log request + delivery in GDPR-verwerkingsregister
  - Add @spec tag linking to offboarding-wizard/specs.md#REQ-OFF-010

- [ ] **Task 12.2** — Implement deadline-tracking:
  - On GDPR request received: store `request_received_at`
  - Background job checks for requests older than 28 days: escalate to legal/AVG-functionaris if not yet delivered

- [ ] **Task 12.3** — Add test cases for pseudonymization, PDF encryption, deadline-tracking, sensitive-data masking (bank account, etc.)

### Manager Handover Checklist (REQ-OFF-011)

- [ ] **Task 13.1** — Create `ManagerHandoverChecklist` schema in OpenRegister with sections:
  - `active_projects` (array: {project_name, status, assigned_to, reasoning})
  - `client_contacts` (array: {contact_name, relationship_status, handover_plan})
  - `external_system_access` (array: {system_name, access_type, new_assignee})
  - `key_meetings` (array: {meeting_name, date, successor_intro_scheduled})
  - `tacit_knowledge_memos` (array: {memo_title, content, recorded_format})

- [ ] **Task 13.2** — Implement `HandoverChecklistService::validateCompletion(offboarding_id)` method:
  - Iterate through all active_projects: ensure each has assigned_to OR "no-transfer" reasoning
  - If any project is open (no assignment + no reasoning), throw exception with project-list
  - Called before step completion per REQ-OFF-011 AC-011.2

- [ ] **Task 13.3** — Implement `HandoverChecklistService::exportForSuccessor(checklist_id)` method:
  - Generate PDF with operational runbook (projects, contacts, access, meetings, memos)
  - Exclude personal offboarding data (salary, reason, feedback)
  - Return PDF suitable for successor to read without learning leaver's circumstances

- [ ] **Task 13.4** — Add test cases for validation-blocking (open projects), successor-export pseudonymization

### Goodbye Communication (REQ-OFF-012)

- [ ] **Task 14.1** — Implement `GoodbyeMessageService::draftMessage(offboarding_id, lang)` method:
  - Load template from settings (Dutch + English)
  - Pre-fill: departure date, role, successor (if assigned)
  - Expose free-text fields for customization
  - Support distribution-channel selection (Nextcloud Talk / Email)
  - Return draft for HR-officer review

- [ ] **Task 14.2** — Implement `GoodbyeMessageService::sendMessage(goodbye_message_id)` method:
  - Send via Nextcloud Talk (thread in #general or selected channel)
  - OR send via email to all recipients + distribution-list
  - If external-contacts selected: generate personalized emails with new point-of-contact + clear call-to-action
  - Log send timestamp + delivery status

- [ ] **Task 14.3** — Implement multi-language support (Dutch + English side-by-side in Talk, both versions in email)

- [ ] **Task 14.4** — Add test cases for template-rendering, Talk vs. email delivery, external-contact notification

### Cross-App Integration (REQ-OFF-013)

- [ ] **Task 15.1** — Implement `EmployeeMasterSyncService::writeBackOnCompletion(offboarding_id)` method:
  - Fetch Offboarding record
  - Call employee-master API: POST /employees/{employee_id}/termination with:
    - `uit_dienst_per` (einddatum)
    - `reden_uit_dienst` (reden enum)
    - `laatste_werkdag`
    - `status = inactive`
  - Log API call + response in audit-trail
  - If success: set flag to prevent re-write (idempotency)
  - Add @spec tag linking to offboarding-wizard/specs.md#REQ-OFF-013

- [ ] **Task 15.2** — Implement error-handling + retry-logic:
  - On network error: retry up to 3 times over 24 hours
  - On validation error (e.g., employee_id mismatch): create manual escalation task for HR-owner
  - Block case archival until write-back succeeds

- [ ] **Task 15.3** — Add test cases for success, transient errors, validation errors, idempotency

### Background Jobs & Scheduling

- [ ] **Task 16.1** — Register all background jobs in `config/services.yaml` (Symfony Jobs):
  - `DeleteDisabledUserAccountJob` (daily, 06:00 UTC)
  - `UwvMeldingRetryJob` (daily, 08:00 UTC)
  - `PensionZvwEscalationJob` (daily, 08:00 UTC)
  - `DestructionExecutionJob` (daily, 01:00 UTC)
  - `GdprRequestDeadlineCheckJob` (daily, 08:00 UTC)

- [ ] **Task 16.2** — Implement job-orchestration logic in `AbstractOffboardingJob` base class (error-logging, escalation-notification templates)

---

## Frontend Implementation

### UI Pages & Components

- [ ] **Task 20.1** — Create Offboarding list page (`OffboardingIndexPage`):
  - Use `CnIndexPage` + `CnDataTable`
  - Columns: employee name, reden, einddatum, current status, hr_owner, manager
  - Filters: status, reden, date-range
  - Actions: create new case, view detail
  - Link to design.md entity schema

- [ ] **Task 20.2** — Create Offboarding detail page (`OffboardingDetailPage`):
  - Use `CnDetailPage` + step-progress visualization (`CnTimelineStages`)
  - Sections:
    - Overview (employee, dates, reden, toepasselijkheid summary)
    - Eindafrekening (display frozen values, approval-gate)
    - Equipment return (checklist)
    - Exit interview (capture form)
    - Handover checklist (structured sections)
    - Retention timers (read-only for auditor)
  - Actions: mark step complete, revoke/retry, export dossier

- [ ] **Task 20.3** — Create Eindafrekening form (`EindafekeningFormDialog`):
  - Read-only display of computed values (leave, vacation, 13th-month, transitievergoeding, withholdings)
  - Show audit-table (55-month detail, exportable)
  - Approval-gate (hr_admin only): "Goedkeuren" button → freeze + payroll-submission
  - On frozen: revocation-option with reason (hr_admin only)

- [ ] **Task 20.4** — Create Equipment return form (`EquipmentReturnFormDialog`):
  - Checklist per equipment item
  - Condition dropdown (good, wear-marks, damaged, unusable)
  - Comments field
  - Manager/IT-owner sign-off (checkbox)

- [ ] **Task 20.5** — Create Exit interview form (`ExitInterviewFormDialog`):
  - Satisfaction sliders (1-10)
  - Reason multi-select (career, salary, culture, work-life-balance, etc.)
  - Recommendation question (yes/no)
  - Open feedback textarea
  - Anonymity option (checkbox)

- [ ] **Task 20.6** — Create Manager handover checklist form (`HandoverChecklistFormDialog`):
  - Dynamic sections: projects, contacts, system access, key meetings, knowledge memos
  - Add/remove rows per section
  - For projects: project name + assigned receiver OR "no transfer needed" reasoning
  - Validation: block step-completion if any project unassigned + no reasoning
  - Export button → PDF for successor

- [ ] **Task 20.7** — Create Goodbye message composer (`GoodbyeMessageComposer`):
  - Language tabs (Dutch / English)
  - Template sections (departure date, role, successor, etc.) + free-text custom message
  - Distribution-channel selector (Talk / Email)
  - External-contacts list (optional)
  - Preview + send

- [ ] **Task 20.8** — Create Retention timer read-only view (auditor/AVG-functionaris only):
  - Table: artefact_type, grondslag, created_on, expires_on, destroyed_on, destruction_method
  - Filters: status (active/destroyed), grondslag, date-range
  - Audit-trail link per timer

---

## Testing

### Unit Tests (Backend)

- [ ] **Task 30.1** — Unit tests for `ReasondeterminismService` (all 10 reden mappings)

- [ ] **Task 30.2** — Unit tests for `EindafekeningComputationService`:
  - 4-year-7-month scenario (expect €6033.33 transitievergoeding)
  - Leave-expiry validation (6m statutory, 5y extra-statutory)
  - Vacation-money pro-rata
  - 13th-month pro-rata
  - 2026 max-cap

- [ ] **Task 30.3** — Unit tests for `RetentionTimerService` (timer-creation per grondslag, destruction-logging)

- [ ] **Task 30.4** — Unit tests for `WorkflowStepService` (dependency validation, skipping-logic)

- [ ] **Task 30.5** — Unit tests for `ManagerHandoverChecklist` (validation-blocking, successor-export pseudonymization)

### Integration Tests (Backend)

- [ ] **Task 31.1** — Integration test for OpenConnector UWV WW-melding (mock openconnector, verify request format + retry-logic)

- [ ] **Task 31.2** — Integration test for employee-master write-back (mock employee-master, verify ut_dienst_per write, idempotency)

- [ ] **Task 31.3** — Integration test for Nextcloud OCS API (mock OCS, verify user disable + mail-forward)

- [ ] **Task 31.4** — Integration test for docudesk getuigschrift (mock docudesk template-render + eIDAS signature)

### Browser / Functional Tests

- [ ] **Task 32.1** — End-to-end test: Create offboarding case → auto-compute eindafrekening → HR-admin approval → freeze → payroll-submission
  - Verify: reden auto-maps to toepasselijkheid, eindafrekening computed correctly, freeze-guard prevents edits, payroll-webhook triggered

- [ ] **Task 32.2** — End-to-end test: Manager handover checklist → block step-completion if project unassigned → export for successor
  - Verify: projects show in form, validation-error blocks completion, successor-export excludes personal data

- [ ] **Task 32.3** — End-to-end test: IT account deactivation → data-export provisioned (download-link + 14-day expiry) → account disabled → 30-day delete
  - Verify: download-link works, expiry-guard prevents access after 14d, account disabled, deleted on day 31

- [ ] **Task 32.4** — End-to-end test: GDPR data-subject-access request → dossier-export PDF generated → pseudonymization applied → deadline tracked
  - Verify: PDF contains all sections, third-party names replaced with pseudonyms, PDF encrypted, delivery-link has 7d expiry

- [ ] **Task 32.5** — End-to-end test: Retention timer creation on case completion → auditor can query timers → destruction-job runs at expiry
  - Verify: timers created with correct grondslag + expiry-dates, auditor-query filters by artefact_type, destruction-job logs crypto-erase

### Performance Tests

- [ ] **Task 33.1** — Load test: 500 concurrent offboarding cases → list-view pagination (50/page) loads in <500ms with sorting/filtering responsive <200ms

- [ ] **Task 33.2** — Load test: Eindafrekening computation with 55-month detail → completes in <2 seconds, audit-table fully rendered

- [ ] **Task 33.3** — Load test: Dossier-export PDF generation (200+ audit-entries) → completes in <10 seconds, <10MB uncompressed

---

## Documentation

- [ ] **Task 40.1** — Add @spec PHPDoc tags to all public classes/methods linking to offboarding-wizard/specs.md
  - Controllers, Services, Mappers
  - Format: `@spec openspec/changes/offboarding-wizard/specs.md#REQ-OFF-NNN`

- [ ] **Task 40.2** — Create user-facing documentation (Dutch + English):
  - HR-officer guide (case lifecycle, approvals, overrides)
  - Manager guide (handover-checklist, equipment sign-off, goodbye message)
  - IT-admin guide (data-export provisioning, account-deletion timing)
  - Auditor guide (retention-timer queries, audit-trail export, GDPR-request handling)

- [ ] **Task 40.3** — Create API documentation (OpenAPI 3.0 / Swagger):
  - Offboarding CRUD endpoints
  - Eindafrekening computation + freeze + revocation
  - Retention timer queries
  - GDPR export endpoint

---

## Deployment & Cutover

- [ ] **Task 50.1** — Data-migration (if applicable): Identify legacy off-boarding records in payroll/HR systems; import as Offboarding objects with status = `afgerond` + archive-flag. Seed retention-timers retroactively where data exists.

- [ ] **Task 50.2** — Staging validation:
  - Test full workflow in staging environment (all integrations mocked or pre-agreed sandbox endpoints)
  - Verify export/import round-trip (dossier-export PDF, then reimport as evidence)
  - Validate retention-timer calculation for current + backdated cases

- [ ] **Task 50.3** — Production rollout:
  - Deploy with feature-flag off (dark-mode)
  - Enable for test-user (HR-officer) to run pilot case
  - Gradual roll-out: enable for 1 organization → 10 organizations → all (over 1-2 weeks)

- [ ] **Task 50.4** — Cutover training:
  - HR-officer: case-lifecycle walkthrough, approval-gates, override-scenarios
  - Manager: handover-checklist expectations, equipment sign-off process
  - IT-admin: data-export delivery, account-disable timing, 30-day delete window
  - Auditor: retention-timer queries, audit-trail export, GDPR-request handling

---

## Deduplication Check

- [ ] **Task 60.1** — Verify no overlap with existing OpenRegister services:
  - ObjectService (CRUD) — **reused**, no custom DAO needed
  - CnIndexPage, CnDetailPage (list/detail views) — **reused**, no custom UI-orchestration
  - AuditTrailService (change tracking) — **reused**, no custom audit-log layer
  - FileService (document storage) — **reused** for PDFs, memos, exports
  - ImportService/ExportService (bulk import/export) — **reused** for dossier-export
  - TasksController (workflow tasks) — **reused** for step-tracking (or custom OffboardingStep, TBD)
  - NotificationService (notifications) — **reused** for step-completion alerts

- [ ] **Task 60.2** — Document findings:
  - No duplication detected
  - All CRUD, search, audit, file, task, notification infrastructure **reused from OpenRegister**
  - Custom code is **domain-logic only**: eindafrekening computation, reden-determinism, retention-timer scheduling, integration glue

---

## Deferred Decisions

- **Successor-task assignment for handover-checklist projects**: Should completion of a project-handover task create an automated task for the successor? Defer to design phase when handover-checklist integration is clearer.

- **Corrected nacheffing iterations**: How many corrected Eindafrekening iterations (post-payment) are allowed before escalation to legal? Defer to HR policy review.

- **Tacit-knowledge memo encryption**: Should knowledge-transfer memos be encrypted per conversation-participant roles, or logged in plaintext audit-trail per transparency principle? Defer to data-governance review.
