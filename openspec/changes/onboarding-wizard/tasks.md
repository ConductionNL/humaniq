---
status: tasks
date: 2026-05-23
---

# Onboarding workflow — Implementation Tasks

## Phase 1: Data Model & Core Infrastructure

### Task 1: Create Onboarding entity + migration
- [ ] Add `Onboarding` entity class in `/lib/Entity/Onboarding.php` with all properties from design.md
  - Properties: id, employee_id, status, start_date, is_rehire, previous_offboarding_id, recruiter_user_id, hiring_manager_user_id, hr_owner_user_id, it_owner_user_id, mentor_user_id, payroll_ready, payroll_ready_at, checklist_completion_pct, vog_required, vog_status, proeftijd_einddatum, created_at, updated_at
  - Add getters/setters (POSITIONAL args only per ADR-003)
  - Add `@spec openspec/changes/onboarding-wizard/tasks.md#task-1` PHPDoc
- [ ] Create database migration: `Migration/Version20260523120000InitializeOnboarding.php`
  - Table: `hrmq_onboarding` with all columns + indexes on employee_id, status, created_at
  - Enforce unique constraint on `(employee_id)` unless `is_rehire = true` (or use partial unique index)
- [ ] Add mapper: `/lib/Mapper/OnboardingMapper.php` with CRUD operations (insert, update, find, findByEmployeeId, findByStatus)

### Task 2: Create OnboardingStep entity + migration
- [ ] Add `OnboardingStep` entity class in `/lib/Entity/OnboardingStep.php`
  - Properties: id, onboarding_id, step_key, status, voltooid_door_user_id, voltooid_op, blocking, payload (JSON), audit_evidence_id
  - Add validator for step_key enum (15 fixed keys)
  - Add `@spec openspec/changes/onboarding-wizard/tasks.md#task-2`
- [ ] Create database migration: `Migration/Version20260523120100InitializeOnboardingStep.php`
  - Table: `hrmq_onboarding_step` with indexes on onboarding_id, step_key, status
- [ ] Add mapper: `/lib/Mapper/OnboardingStepMapper.php`

### Task 3: Create WIDCheck entity + migration
- [ ] Add `WIDCheck` entity class with all properties from design.md
  - Add SHA-256 hashing utility for document_nummer_hash (with salt)
  - Validate geldig_tot date >= today
  - Add `@spec openspec/changes/onboarding-wizard/tasks.md#task-3`
- [ ] Create migration: `Migration/Version20260523120200InitializeWIDCheck.php`
  - Table: `hrmq_wid_check` with indexes on onboarding_id, gecontroleerd_on
- [ ] Add mapper

### Task 4: Create BSNValidatie entity + migration
- [ ] Add `BSNValidatie` entity class
  - Properties: id, onboarding_id, bsn_hash, elfproef_resultaat, format_resultaat, gevalideerd_op, bron
  - Add validator for elfproef_resultaat / format_resultaat enums
  - Add `@spec openspec/changes/onboarding-wizard/tasks.md#task-4`
- [ ] Create migration: `Migration/Version20260523120300InitializeBSNValidatie.php`
- [ ] Add mapper

### Task 5: Create ContractSigningEvent entity + migration
- [ ] Add `ContractSigningEvent` entity class
  - Properties: id, onboarding_id, contract_document_id, envelope_id, ondertekenmethode, ondertekend_door, ondertekend_op, ip_adres, certificaat_serienummer, lta_archive_id
  - Validate ondertekenmethode enum (eidas_qes, eidas_aes only)
  - Add `@spec openspec/changes/onboarding-wizard/tasks.md#task-5`
- [ ] Create migration + mapper

### Task 6: Create Reminder entity + migration
- [ ] Add `Reminder` entity class
  - Properties: id, onboarding_id, step_key, geadresseerde_user_id, kanaal, trigger_at, verzonden_op, escalatie_niveau, escalatie_naar_user_id
  - Validate kanaal enum (email, nextcloud_notification, email_plus_nextcloud_notification)
  - Add `@spec openspec/changes/onboarding-wizard/tasks.md#task-6`
- [ ] Create migration + mapper

### Task 7: Create OnboardingChecklistItem entity + migration
- [ ] Add `OnboardingChecklistItem` entity class
  - Properties: id, onboarding_id, categorie, titel, verantwoordelijke_user_id, due_op, status, opmerking
  - Validate categorie enum (eerste_werkdag, ausrusting, bedrijfskleding, administrative)
  - Add `@spec openspec/changes/onboarding-wizard/tasks.md#task-7`
- [ ] Create migration + mapper

### Task 8: Create Proeftijd entity + migration (optional, can embed in Onboarding.payload)
- [ ] Add `Proeftijd` entity class OR embed as a separate table
  - Properties: id, onboarding_id, einddatum, status, t7_notificatie_verzonden_op, t2_notificatie_verzonden_op, afgerond_op, afgerond_door_user_id
  - Add `@spec openspec/changes/onboarding-wizard/tasks.md#task-8`
- [ ] Create migration + mapper (or decide to embed in Onboarding JSON payload)

### Task 9: Create AuditLog entity + migration
- [ ] Add `AuditLog` entity class (append-only)
  - Properties: id, employee_id, onboarding_id, event_type, step_key, user_id, timestamp, details (JSON)
  - Do NOT allow updates/deletes on this table (DB-level triggers or ORM validation)
  - Add `@spec openspec/changes/onboarding-wizard/tasks.md#task-9`
- [ ] Create migration: `Migration/Version20260523120900InitializeAuditLog.php`
  - Table: `hrmq_audit_log` with indexes on employee_id, onboarding_id, timestamp, event_type
  - Cluster index on (onboarding_id, timestamp) for efficient queries
- [ ] Add mapper (insert-only)

---

## Phase 2: API Controllers & Services

### Task 10: Create OnboardingController + CRUD endpoints
- [ ] Create `/lib/Controller/OnboardingController.php`
  - POST `/api/onboarding` — create new case
    - Input validation: employee_id, recruiter_user_id, hiring_manager_user_id, hr_owner_user_id, it_owner_user_id, start_date, [is_rehire, previous_offboarding_id]
    - Enforce permission: create requires hr_admin or recruiter role
    - Return: 201 Created with case ID
  - GET `/api/onboarding/{onboarding_id}` — fetch case details
  - PATCH `/api/onboarding/{onboarding_id}` — update case (e.g. status, mentor_user_id, vog_status)
    - Validate state machine transition (REQ-OB-001)
    - Return 409 if invalid transition
  - GET `/api/onboarding?status={status}&employee_id={employee_id}` — list cases with filters
  - Add @spec tags linking to task-10
- [ ] Add exception classes: `InvalidTransitionException`, `OnboardingNotFoundException`

### Task 11: Create OnboardingStepController
- [ ] Create `/lib/Controller/OnboardingStepController.php`
  - GET `/api/onboarding/{onboarding_id}/steps` — list all steps for a case
  - GET `/api/onboarding/{onboarding_id}/steps/{step_key}` — fetch single step
  - PATCH `/api/onboarding/{onboarding_id}/steps/{step_key}` — update step status + payload
    - Mark step `voltooid` (validate preconditions per spec)
    - Return 422 if preconditions not met
  - File upload endpoint: POST `/api/onboarding/{onboarding_id}/steps/{step_key}/upload`
    - Accept file, store in docudesk (or internal storage), return document ID

### Task 12: Create OnboardingService (business logic)
- [ ] Create `/lib/Service/OnboardingService.php` with stateless methods:
  - `public function createOnboarding(EmployeeId, recruiterId, ...): Onboarding`
    - Initialize all 15 steps as `openstaand`
    - Check employee uniqueness (reject if already has active onboarding, unless rehire)
  - `public function transitionStatus(OnboardingId, newStatus): Onboarding` (REQ-OB-001)
    - Validate state machine, check preconditions
    - Log transition to AuditLog
  - `public function completeStep(OnboardingId, stepKey, payload): OnboardingStep` (REQ-OB-002 to REQ-OB-004)
    - Dispatch to step-specific validators (IBAN, BSN, WID)
    - Update step status, log to AuditLog
  - `public function computePayrollReady(OnboardingId): bool` (REQ-OB-005)
    - Check contract, BSN, IBAN, ZVW, pensioen preconditions
    - Update `payroll_ready` field if conditions met
  - `public function initiateDocudesk(OnboardingId, contractType): DocumentId`
    - Call docudesk API to create signing envelope (delegated to contract-management spec)
  - `public function handleDocusignWebhook(webhookPayload): void` (REQ-OB-006)
    - Create ContractSigningEvent, validate signature type (QES/AES), transition case to contract_getekend
  - All methods add @spec tags

### Task 13: Create WIDCheckService
- [ ] Create `/lib/Service/WIDCheckService.php`
  - `public function verifyAndStoreWIDCheck(OnboardingId, documentScan, documentType, issuer, validUntil, physicallyVerified): WIDCheck` (REQ-OB-002)
    - Hash document number (SHA-256 with salt)
    - Store scan in docudesk with hr_admin + auditor ACL
    - Calculate bewaartermijn_einde = start_date + 5 years
    - Log to AuditLog with "WID-check completed" event
    - Return WIDCheck entity
  - `public function validatePhysicalVerification(physicallyVerified, reason): void`
    - If false, require reason (min 50 chars)

### Task 14: Create BSNValidationService
- [ ] Create `/lib/Service/BSNValidationService.php`
  - `public function validateBSN(bsnString): ValidationResult` (REQ-OB-003)
    - Check format: 9 digits, no leading zeros, no all-same
    - Check elfproef (modulus-11 with weights 9,8,7,6,5,4,3,2,-1)
    - Return {format_resultaat: "geldig"|"ongeldig", elfproef_resultaat: "geldig"|"ongeldig"}
    - Never log raw BSN
  - `public function storeBSNValidation(OnboardingId, bsnString, source): BSNValidatie`
    - Hash BSN, store validation result + source
    - Update Employee.bsn_encrypted (delegated to employee-master integration)
    - Log to AuditLog

### Task 15: Create IBANValidationService
- [ ] Create `/lib/Service/IBANValidationService.php`
  - `public function validateIBAN(ibanString): ValidationResult` (REQ-OB-004)
    - Country check (NL 24, BE 16, DE 22 chars)
    - ISO-13616 modulus-97
    - Optional SEPA name-check (call openconnector if configured)
    - Return {validasi_resultaat, naam_op_rekening_match}
  - `public function storeIBANValidation(OnboardingId, iban, ...): void`
    - Store on OnboardingStep payload
    - If name-check is no_match, mark step geblokkerd
    - If HR-admin provides override, log with justification

### Task 16: Create PayrollReadinessService
- [ ] Create `/lib/Service/PayrollReadinessService.php`
  - `public function computePayrollReady(OnboardingId): bool` (REQ-OB-005)
    - Check: contract_ondertekenen voltooid
    - Check: BSNValidatie with elfproef_resultaat = geldig
    - Check: iban_verificatie voltooid (no pending overrides)
    - Check: zvw_melding step voltooid AND external ZVW service bevestigd
    - Check: pensioen_aanmelding voltooid AND pensioen bevestigd (or niet_van_toepassing with reason)
    - Set Onboarding.payroll_ready = true/false, update payroll_ready_at timestamp
    - Log to AuditLog
  - `public function notifyPayrollOfBlockage(OnboardingId): void`
    - Send email to payroll role with reason why payroll_ready = false

---

## Phase 3: Webhooks & External Integration

### Task 17: Implement docudesk contract_signed webhook
- [ ] Create `/lib/Controller/WebhookController.php` with endpoint:
  - POST `/api/webhook/docudesk/contract_signed/{onboarding_id}`
  - Verify webhook signature (docudesk HMAC-SHA256)
  - Extract: envelope_id, signature_type, signer_email, signed_at, ip_address, certificate_serial, lta_archive_id
  - Call OnboardingService.handleDocusignWebhook()
  - Create ContractSigningEvent (REQ-OB-006)
  - Validate signature_type: must be eidas_qes or eidas_aes; reject eidas_ses
  - Transition case to contract_getekend
  - Mark contract_ondertekenen step voltooid
  - Log to AuditLog: "Contract signed via {signature_type} by {signer_email}"
  - Return 200 OK

### Task 18: Implement ZVW confirmation webhook (from openconnector)
- [ ] Endpoint: POST `/api/webhook/zvw/melding_confirmed/{onboarding_id}`
  - Receive: zvw_status (bevestigd | afgewezen | in_behandeling), reference_id, message
  - Update OnboardingStep payload for zvw_melding step
  - If bevestigd, recompute payroll_ready (Task 16)
  - Log to AuditLog
  - Return 200 OK

### Task 19: Implement Pensioenfonds confirmation webhook
- [ ] Endpoint: POST `/api/webhook/pensioen/aanmelding_confirmed/{onboarding_id}`
  - Receive: pensioen_status (bevestigd | afgewezen | in_behandeling), fonds_name, reference_id
  - Update OnboardingStep payload for pensioen_aanmelding step
  - If bevestigd, recompute payroll_ready
  - If afgewezen, notify HR + payroll with reason
  - Log to AuditLog
  - Return 200 OK

### Task 20: Implement Nextcloud OCS user provisioning (IT-provisioning step)
- [ ] Create `/lib/Service/ITProvisioningService.php` (REQ-OB-008)
  - `public function provisionNextcloudUser(OnboardingId, EmployeeId): string` (returns userid)
  - Fetch Employee: voornaam, achternaam, afdeling
  - Generate userid: deterministic pattern (default: voornaam.achternaam in lowercase)
  - Handle collision: append numeric suffix (voornaam.achternaam2, ...3, ...)
  - Call OCS API: POST `/ocs/v1.php/apps/admin_provisioning_api/api/v1/users`
    - userid, temporary password (send via email), displayName
  - Add to groups: afdeling (if group exists), medewerker (global)
  - Set quota: 1GB (default, configurable)
  - Disable login (POST `.../disable`) until start_date
  - Store userid on Employee record
  - Mark it_provisioning step voltooid
  - Log to AuditLog: "Nextcloud user {userid} created, added to groups [...]"
  - Idempotent: if userid already exists, update groups + quota, do not error

### Task 21: Implement payroll-engine-nl integration gate
- [ ] Create integration point (likely in payroll-engine-nl spec, but onboarding must support the query)
  - Endpoint: GET `/api/onboarding/payroll-gate/{employee_id}?run_date={date}`
  - Return: {payroll_ready: true|false, payroll_ready_at: datetime}
  - Payroll-engine-nl checks this endpoint before including employee in salarisrun
  - If false, exclude employee + log reason to payroll run summary

---

## Phase 4: Reminders, Escalations & Scheduling

### Task 22: Implement Reminder + Escalation scheduler
- [ ] Create `/lib/BackgroundJob/ReminderSchedulerJob.php` (implements IJob)
  - Runs every 30 minutes (configurable)
  - Query all Onboarding cases with status NOT in (proeftijd_afgerond, geannuleerd)
  - For each case, find Reminders with `trigger_at <= now` AND `verzonden_op IS NULL`
  - Send via configured kanaal (email, Nextcloud, both)
  - Mark Reminder.verzonden_op = now
  - If this is the second unacknowledged reminder for a step, escalate to escalatie_naar_user_id
  - Log each send to AuditLog (REQ-OB-007)
- [ ] Create `/lib/Service/ReminderService.php`
  - `public function sendReminder(ReminderId, channel): void`
    - Email: plain text with Nextcloud magic link (token-based, no login required)
    - Nextcloud: notification with action button linking to step in wizard
  - `public function createReminderForStep(OnboardingId, stepKey, triggerTime, recipientUserId, escalateToUserId): Reminder`
- [ ] Register job in `info.xml` + enable via `IAppConfig`

### Task 23: Implement Proeftijd-watcher scheduler
- [ ] Create `/lib/BackgroundJob/ProeftijdWatcherJob.php` (REQ-OB-011)
  - Runs daily at 09:00
  - Query all Onboarding cases with `proeftijd_einddatum IS NOT NULL` AND `status = proeftijd_lopend`
  - Calculate working-day offset (T-7 and T-2 werkdagen before einddatum)
  - For T-7 and T-2 days, create Reminder rows if not already created
  - For T-0 (the einddatum day itself):
    - If no action recorded yet, auto-close: set `Proeftijd.status = proeftijd_afgerond_assumption`
    - Add warning to case: "⚠️ Proeftijd ended (assumption: geslaagd). Contract is now PERMANENT."
    - Log to AuditLog: "Proeftijd auto-closed on T-0, no action taken"
    - Notify HR + manager
- [ ] Create `/lib/Service/ProeftijdService.php`
  - `public function handleProeftijdAction(OnboardingId, action): void` (afronden, beëindigen, verlenging_rejected)
    - If beëindigen: auto-create matching Offboarding case with reason = "proeftijd_beëindigd"
    - Log action to AuditLog with user + timestamp
  - `public function calculateWorkingDays(date, daysOffset): date`
    - Account for Dutch weekends + holidays (configurable holiday list)

### Task 24: Implement bewaartermijn enforcement scheduler
- [ ] Create `/lib/BackgroundJob/RetentionEnforcementJob.php` (REQ-OB-009)
  - Runs daily at 02:00 (low-traffic time)
  - Query all artefacts with `retention_expires_at <= today`
  - For WID-copies: call docudesk API `DELETE /documents/{doc_id}?reason=bewaartermijn_expired`
  - For payroll-grondslagen: mark as flagged-for-deletion, notify payroll for final review
  - Log each deletion to AuditLog (append-only, cannot be modified):
    ```json
    {
      "event_type": "retention_deletion",
      "artefact_type": "wid_kopie",
      "artefact_id": "...",
      "deleted_at": "...",
      "bewaartermijn_einde": "...",
      "status": "irreversible"
    }
    ```
  - Deletion is irreversible; no undo mechanism

---

## Phase 5: UI & Frontend

### Task 25: Build Onboarding Wizard stepper UI
- [ ] Create Vue component `/src/components/OnboardingWizard.vue`
  - Left sidebar: 15 fixed steps in vertical stepper
  - Step indicators: openstaand (white), in_bewerking (blue), voltooid (green), geblokkerd (red)
  - Main area: step form (dynamic based on step_key)
  - Progress bar: X of 15 steps completed
  - Checklist progress: X% of non-blocking items done
  - Action buttons: Save Draft, Mark Complete (enabled only if all required fields filled)
  - Audit trail snippet: last 5 events for current step, expandable
  - Use Nextcloud design tokens (colors, fonts, spacing)

### Task 26: Build step-specific forms
- [ ] Create form components for each of the 15 steps:
  - `ContractAanmakenForm.vue` — template selection, preview, render
  - `ContractVersturenForm.vue` — PDF preview, send button, recipient email (auto-populated)
  - `ContractOndertekenForm.vue` — waiting state, refresh status, signature evidence display
  - `IDUploadForm.vue` — file upload (drag-drop), document type select, OCR preview
  - `WIDCheckForm.vue` — "fysiek gezien" checkbox, exception reason textarea (if unchecked), document display
  - `BSNValidatieForm.vue` — BSN input (masked), format+elfproef check on blur, error display
  - `IBANVerificatieForm.vue` — IBAN input, modulus-97 check, SEPA name-check result, override button (if hr_admin)
  - `PensionAanmeldingForm.vue` — pension fund selection, status display, contact info
  - `ZVWMeldingForm.vue` — ZVW status display, linked to external service confirmation
  - `ITProvisioningForm.vue` — userid generation preview, groups display, provisioning button
  - `BedrijfskledingForm.vue` — size/style selection, order button, order status display
  - `LaptopUitgifteForm.vue` — equipment list, assignment, pickup status
  - `MentorToewijzingForm.vue` — user picker, mentee name display
  - `VOGAanvraagForm.vue` — VOG requirement toggle, application status
  - `EersteWerkdagChecklistForm.vue` — task list display, completion tracking
- [ ] Each form:
  - Validates input on submit
  - Calls PATCH `/api/onboarding/{onboarding_id}/steps/{step_key}`
  - Shows loading state, error messages
  - Logs field changes for audit

### Task 27: Build Onboarding list view
- [ ] Create `/src/views/OnboardingList.vue`
  - Table: ID, Employee name, Status, Start date, HR-owner, Completion %, Last updated
  - Filters: Status, HR-owner, Start date range, Completion % range
  - Sort: by Status, Start date, Completion %
  - Actions: Open, Clone (for rehire), Archive, Cancel
  - Bulk actions: Export audit trail (for selected cases)

### Task 28: Build self-service portal (candidate-facing)
- [ ] Create self-service portal UI (may be separate SPA or Nextcloud iframe)
  - Route: `/self-service/portal/{token}`
  - Form sections:
    - Persoonsgegevens: full name, email, phone, address
    - ID-upload: file upload, document type, OCR preview
    - IBAN: entry field, validation feedback
    - Voorkeur-aanspreking: radio/select for formal/informal + pronouns
    - Noodcontactgegevens: name, phone, relation
    - Voedingsvoorkeur: checkboxes + free-text field
  - Save state on each section (progressive enhancement)
  - Log every field change with IP + user-agent
  - Token expiry check (redirect to "link expired" page if expired)

### Task 29: Build AVG-DSR export UI
- [ ] Add button to Onboarding case header: "AVG Gegevensuitvraag" (three-dot menu)
  - Click triggers async PDF generation job
  - Shows progress: "Generating PDF (30%)" + estimated time
  - On completion, shows download button
  - PDF includes: personal data snapshot, full audit trail, retention schedule, eIDAS timestamp
  - Access restricted to hr_admin + auditor roles

### Task 30: Build Proeftijd action UI
- [ ] In case header (when proeftijd is active), show three action-buttons:
  - `[Afronden - Geslaagd]` — marks proeftijd_afgerond_geslaagd
  - `[Beëindigen - Niet geschikt]` — triggers offboarding case creation + sends notification
  - `[Verlenging? - Niet mogelijk in NL]` — disabled, shows legal explanation on hover

---

## Phase 6: Integration Testing & Deployment

### Task 31: Write end-to-end test: Happy path (contract → IT provisioning)
- [ ] Test scenario: Create Onboarding → Complete contract steps → Send to docudesk → Receive webhook → Complete ID/BSN/IBAN/payroll steps → Verify payroll_ready = true
  - Setup: Mock Employee, Contract
  - Assertions: Status transitions, step completions logged, payroll_ready flag set, audit trail entries created

### Task 32: Write test: State machine integrity (REQ-OB-001)
- [ ] Test: Invalid state transitions are rejected with 409
  - Try to jump from aangenomen to it_provisioned → expect 409 with allowed_next_states
  - Try valid transition → expect 200

### Task 33: Write test: BSN validation (REQ-OB-003)
- [ ] Test: Valid BSN passes, invalid fails
  - Valid: 123456782 → elfproef passes
  - Invalid checksum: 123456781 → elfproef fails
  - Invalid format: "12345678" (8 digits) → format fails
  - Verify raw BSN never appears in logs or responses

### Task 34: Write test: IBAN validation + override (REQ-OB-004)
- [ ] Test: Valid NL IBAN passes modulus-97
- [ ] Test: SEPA name-check no_match blocks unless hr_admin override
  - Try to complete step with no_match → expect 422
  - HR-admin provides 20+ char justification → expect 200
  - Audit log includes full justification

### Task 35: Write test: Reminder + Escalation (REQ-OB-007)
- [ ] Test: Level-1 reminder sent on T+3 working days
- [ ] Test: Level-2 escalation sent if reminder unacknowledged
- [ ] Setup: Mock email service, advance scheduler time
- [ ] Assertions: Reminder row created, email/Nextcloud sent, timestamps recorded

### Task 36: Write test: IT provisioning idempotency (REQ-OB-008)
- [ ] Test: User provisioned once, no duplicate on re-run
- [ ] Test: Collision handling (userid already exists → append suffix)
- [ ] Mock OCS API, verify calls + group assignments

### Task 37: Write test: Proeftijd auto-close (REQ-OB-011)
- [ ] Test: T-7 reminder sent
- [ ] Test: T-2 reminder sent
- [ ] Test: T-0 auto-close if no action
  - Setup: Advance system time to T-0 + 1 day
  - Scheduler runs, case auto-closes as proeftijd_afgerond_assumption
  - Warning added to case, notification sent

### Task 38: Write test: Self-service portal (REQ-OB-012)
- [ ] Test: Portal link expires after 30 days
- [ ] Test: IBAN validation in portal
- [ ] Test: Field changes logged with IP + user-agent
- [ ] Test: After start_date, link disabled + Nextcloud account active

### Task 39: Write test: Audit trail completeness (REQ-OB-010)
- [ ] Test: Query audit log by onboarding_id → all events returned
- [ ] Test: Export audit log to PDF (async job)
  - Wait for job completion
  - Verify PDF contains: personal data, audit entries, retention schedule, eIDAS timestamp
  - PDF is encrypted to hr_admin + auditor roles

### Task 40: Database & migrations smoke test
- [ ] Run all migrations in a test env
- [ ] Verify schema is created correctly
- [ ] Test entity creation/queries in isolation
- [ ] Test unique constraints (employee_id on Onboarding)
- [ ] Verify indexes are created (performance test with 10k+ rows)

### Task 41: Accessibility testing (WCAG 2.1 AA)
- [ ] Wizard stepper: keyboard navigation (Tab, Arrow keys, Enter)
- [ ] Form labels: all inputs have associated labels
- [ ] Error messages: ARIA-live regions for dynamic updates
- [ ] Color contrast: all text passes WCAG AA (4.5:1 for normal text)
- [ ] Test with screen reader (NVDA, JAWS simulation)

### Task 42: Documentation & user guides
- [ ] Write HR-officer quickstart guide (PDF or in-app help)
  - Screenshots of each step
  - Explanation of blocking vs. non-blocking steps
  - Troubleshooting common errors
- [ ] Write recruiter guide (opening new cases, handing off to HR)
- [ ] Write IT-admin guide (configuring IT-provisioning, handling collisions)
- [ ] Write AVG-functionaris guide (exporting audit trail, serving DSRs)
- [ ] API documentation (OpenAPI / Swagger spec)

### Task 43: Translation & i18n setup (Dutch-first)
- [ ] Extract all user-facing strings to `lib/l10n/` (nl.json, en.json)
  - Step titles, form labels, error messages, email subjects/bodies
  - Use `ITranslationService` per ADR-007
- [ ] Test translations with actual Dutch users (MKB context)
- [ ] Implement date/time formatting per Dutch locale (dd-mm-yyyy, 24h clock)

### Task 44: Deployment & rollout
- [ ] Add feature flag: `onboarding_wizard_enabled` (IAppConfig)
  - Disabled by default in first release
  - Enable for pilot customers (HR-service-center + 2-3 MKB orgs)
- [ ] Create release notes (what's new, known issues, rollback plan)
- [ ] Monitor for errors: set up Sentry alerts for exceptions in onboarding namespace
- [ ] Post-deployment: HR-officer training (virtual session or video)
- [ ] Gather feedback: post-launch survey (1 week), iterate on pain points

---

## Checkpoints & Sign-Offs

| Phase | Checkpoint | Owner | Status |
|-------|-----------|-------|--------|
| 1-2 | Data model complete, all entities + mappers created | Backend lead | Pending |
| 2-3 | API controllers + services reviewed, unit tests passing | Backend lead | Pending |
| 3-4 | Webhooks tested (docudesk, ZVW, pensioen), scheduler jobs running | Backend + integration | Pending |
| 4-5 | UI components built, wizard form complete, self-service portal working | Frontend lead | Pending |
| 5-6 | E2E tests passing, accessibility audit complete, docs published | QA + UX | Pending |
| 6 | Pilot rollout begins (3 customers, 5 cases each) | Product + ops | Pending |
| Ongoing | Monitor & iterate (feedback loop, bug fixes, UX refinements) | Full team | Pending |

---

## Dependencies & Integration Checklist

- [ ] **procest**: Ensure Onboarding case inherits procest case model correctly
- [ ] **contract-management**: Contract rendering + docudesk integration (template selection, preview, send)
- [ ] **employee-master**: Fetch Employee data, store encrypted BSN, update payroll_ready flag
- [ ] **openconnector**: ZVW-melding, pensioen-aanmelding, SEPA name-check, VOG-aanvraag
- [ ] **docudesk**: Contract e-signing, document storage, LTA archive, webhook delivery
- [ ] **Nextcloud OCS API**: User provisioning, group management, quota setting
- [ ] **payroll-engine-nl**: payroll_ready gate check, salarisrun exclusion
- [ ] **Conduction app** (shillinq): Notification on onboarding completion
- [ ] **Talk** (optional): Integrated mentor messages in case dossier

---

## Notes

- All code must include `@spec openspec/changes/onboarding-wizard/tasks.md#{task-number}` PHPDoc tags (ADR-011)
- All entities follow Controller → Service → Mapper 3-layer pattern (ADR-003)
- Bewaartermijn enforcement is irreversible; implement with extreme care
- Proeftijd logic must account for Dutch Wet Arbeidsmarkt in Balans; consult legal review before release
- Self-service portal is unconnected to Nextcloud until start_date; use separate session/token mechanism
- All reminders/notifications must be configurable (schedule, template, recipients) via IAppConfig
