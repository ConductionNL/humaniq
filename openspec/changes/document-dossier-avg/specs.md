---
status: proposed
---

# Document Dossier & AVG Retention — Specifications

## REQ-DD-001: Personeelsdossier in Nextcloud Files with strict ACL per role

**Requirement:**
The system SHALL store each dossier document as a Nextcloud Files binary inside a per-employee folder, owned by a system user, with explicit ACL grants injected per `acl-grant` row. The default role matrix SHALL be: employee (view+download own), direct manager (view), HR (view+download+edit), HR admin (view+download+edit+share+destroy), OR-inzage (anonymised aggregate only).

### Scenario: Employee uploads document, ACL grants default to role matrix

- **GIVEN** an employee uploads a document of category SALARISBRIEF to their own dossier
- **WHEN** the upload commits
- **THEN** an `acl-grant` SHALL exist for principal_type=employee_self with permission=view+download
- **AND** an `acl-grant` SHALL exist for HR-role with permission=view+download+edit
- **AND** the direct manager SHALL not appear in the ACL (salarisbrieven are not visible to line managers per AVG least-privilege)
- **AND** the Nextcloud Files binary SHALL be readable by the employee and HR-role, readable by no others

### Scenario: Manager attempts download without ACL, receives 403

- **GIVEN** a manager attempts to download a SALARISBRIEF with no `acl-grant` for them
- **WHEN** the request hits the Files API
- **THEN** the system SHALL return 403 with error code DOSSIER_NO_ACCESS
- **AND** SHALL log a denied-access audit-log entry with actor, target, timestamp, reason

### Scenario: HR admin grants temporary auditor access with expiry

- **GIVEN** an HR admin grants a temporary view permission to a named external auditor for 30 days
- **WHEN** the grant commits
- **THEN** the `acl-grant` SHALL persist with expires_at set to 30 days from now and grant_reason="external audit"
- **AND** the system SHALL automatically revoke the grant at expiry with an audit-log entry
- **AND** subsequent download attempts by the auditor after expiry SHALL fail with 403

---

## REQ-DD-002: Version history per document

**Requirement:**
The system SHALL retain every prior version of a dossier document with full audit attribution, allowing rollback, side-by-side compare, and retention-aware version pruning that respects the active retention policy on the document family.

### Scenario: HR uploads new version, old version moves to history

- **GIVEN** an HR user uploads version 2 of an existing CONTRACT_ARBEID document
- **WHEN** the upload commits
- **THEN** the new version SHALL become current (version_number=2)
- **AND** version 1 SHALL move to history with a `superseded_at` timestamp
- **AND** both versions SHALL share the same retention_end_at and category

### Scenario: Document with multiple versions destroyed together

- **GIVEN** a document with three historical versions reaches retention_end_at
- **WHEN** the retention engine runs
- **THEN** all three versions SHALL be destroyed together via one `destruction-certificate`
- **AND** partial-version destruction SHALL never occur (a half-destroyed dossier breaks evidence chain)
- **AND** the destruction-certificate SHALL list all three version IDs

### Scenario: HR admin rolls back to version 1 with audit trail

- **GIVEN** an HR admin requests rollback to version 1 of a beoordeling
- **WHEN** the rollback commits
- **THEN** a new version SHALL be written with content copied from version 1 (never silent pointer-move)
- **AND** an audit entry SHALL be written with action=rollback, old_version=1, new_version=3, reason="[user-provided]"
- **AND** the display SHALL show "Rolled back to version 1" with timestamp and actor

---

## REQ-DD-003: E-sign workflow eIDAS-compliant

**Requirement:**
The system SHALL support eIDAS-compliant signing at three assurance levels (simple, advanced, qualified) via the docudesk e-sign service, with category-driven default level (e.g. CONTRACT_ARBEID = advanced, AANSPRAKELIJKHEIDSVERKLARING = qualified, AKKOORD_HUISREGELS = simple).

### Scenario: HR dispatches CONTRACT_ARBEID for advanced signing

- **GIVEN** an HR user opens a new CONTRACT_ARBEID and clicks "Versturen ter ondertekening"
- **WHEN** the envelope form displays
- **THEN** the system SHALL pre-select signature_type=advanced (the category default)
- **AND** the signatories list SHALL show employee + werkgever-vertegenwoordiger
- **WHEN** the form submits
- **THEN** a `signature-request` SHALL persist with type=advanced, status=sent, docudesk_envelope_id set
- **AND** docudesk SHALL send the eIDAS-compliant signing link to each signatory in order (sign_order)

### Scenario: All signatories sign, document transitions to signed

- **GIVEN** all signatories have signed an advanced envelope
- **WHEN** docudesk reports completion (POST callback to hrmq)
- **THEN** the system SHALL update signature-request.status=completed
- **AND** the signed PDF from docudesk SHALL be stored as a new version of the document
- **AND** the document status SHALL transition to=signed
- **AND** the eIDAS audit trail (evidence_url, eidas_audit_trail_id) SHALL be captured
- **AND** the document SHALL freeze from further edits (status=signed cannot edit)

### Scenario: Signatory declines or envelope expires

- **GIVEN** a signatory declines or the envelope expires
- **WHEN** the docudesk callback fires
- **THEN** the signature-request SHALL transition to declined or expired
- **AND** the original document status SHALL revert to draft
- **AND** the initiator SHALL receive a notification with the docudesk reason (e.g. "Declined by John Doe at 2026-05-20 14:30")

---

## REQ-DD-004: AVG retention engine with category-driven policies

**Requirement:**
The system SHALL compute retention_end_at on every document save using the category's default policy plus any explicit override, and SHALL execute destruction automatically when retention_end_at passes. Default policies SHALL include OVERHEID_50Y, FISCAAL_7Y_POST_FY, ARBEID_5Y_POST_DIENST, ID_KOPIE_5Y_POST_DIENST, ZIEKTEVERZUIM_2Y_POST_DIENST, SOLLICITATIE_4W_POST_DECISION (with explicit consent for longer).

### Scenario: Private-sector employer, SALARISBRIEF FISCAAL_7Y_POST_FY anchor

- **GIVEN** a private-sector employer uploads a SALARISBRIEF dated 2026-03-01
- **WHEN** the retention engine computes
- **THEN** retention_end_at SHALL be 2033-12-31 (FISCAAL_7Y_POST_FY anchored on fiscal_year_end(2026) = 2026-12-31, plus 7 years)

### Scenario: Public-sector employer, SALARISBRIEF OVERHEID_50Y anchor

- **GIVEN** an overheid-tenant uploads the same SALARISBRIEF dated 2026-03-01
- **WHEN** the retention engine computes
- **THEN** retention_end_at SHALL be 2076-03-01 (OVERHEID_50Y per Archiefwet selectielijst, anchored on document_effective_from)

### Scenario: Employment ends, ZIEKTEVERZUIM recomputed to post-dienst anchor

- **GIVEN** an employment ends on 2026-09-30 and a ZIEKTEVERZUIM document is linked to that employee
- **WHEN** the engine receives the employment-end event and recomputes
- **THEN** retention_end_at SHALL move to 2028-09-30 (ZIEKTEVERZUIM_2Y_POST_DIENST anchored on employment_end, plus 2 years)

---

## REQ-DD-005: Categorisering with controlled vocabulary

**Requirement:**
The system SHALL enforce category selection from `document-category` at upload time, with a default suggestion derived from filename/MIME heuristics and a per-category form that captures required metadata (e.g. CONTRACT_ARBEID requires effective_from + contract_type; VOG requires issuing_authority + issue_date).

### Scenario: HR uploads VOG, form prompts for required metadata

- **GIVEN** an HR user uploads `vog-jansen-2026.pdf`
- **WHEN** the upload form opens
- **THEN** the system SHALL pre-select category=VOG (heuristic from filename)
- **AND** the form SHALL prompt for issuing_authority and issue_date as required fields
- **AND** the upload shall refuse save until both are provided (with message FIELD_REQUIRED)

### Scenario: Upload without category, rejected

- **GIVEN** an upload with category=null
- **WHEN** the user attempts save
- **THEN** the system SHALL block save with error CATEGORY_REQUIRED
- **AND** no document SHALL be created without a category

### Scenario: ZIEKTEVERZUIM upload, special-category warning and ACL default

- **GIVEN** the user picks category=ZIEKTEVERZUIM
- **WHEN** the form renders
- **THEN** the system SHALL display an `is_special_category` warning: "This category contains special personal data (health). Only HR role can access by default."
- **AND** legal_basis SHALL default to legal_obligation (loondoorbetalingsplicht)
- **AND** the ACL template SHALL pre-populate with HR-only (no manager view)

---

## REQ-DD-006: Search with access-controls and filter facets

**Requirement:**
The system SHALL provide a faceted search across the dossier corpus visible to the requesting user, with facets for category, employee (where ACL allows), date range, expiring-soon, missing-required, and full-text on display_title and description.

### Scenario: HR user searches for VOG, sees filtered results

- **GIVEN** an HR user searches for "VOG"
- **WHEN** the result list renders
- **THEN** every VOG visible to the user (per role + ACL) SHALL appear with category, employee name (where allowed), and expires_at
- **AND** results SHALL be sorted by soonest expiry (expires_at ASC)

### Scenario: Employee searches own dossier, sees only own documents

- **GIVEN** an employee searches their own dossier
- **WHEN** the result list renders
- **THEN** only documents with an `acl-grant` for principal_type=employee_self matching the requesting employee SHALL appear
- **AND** no documents from other employees SHALL appear, regardless of full-text match elsewhere in the tenant

### Scenario: HR admin filters "expiring in 30 days"

- **GIVEN** an HR admin runs the "expiring in 30 days" report
- **WHEN** the report renders
- **THEN** every document with expires_at within 30 days (NOW to NOW+30d) SHALL appear
- **AND** results SHALL be grouped by category
- **AND** each row SHALL include one-click "renew/replace" action

---

## REQ-DD-007: Audit log per view, download, edit, delete, sign

**Requirement:**
The system SHALL log every access action on a dossier document to a tamper-evident audit log retained for the document's full lifetime plus the retention period of the underlying document.

### Scenario: User views document detail, logged

- **GIVEN** any user views a dossier document detail page
- **WHEN** the page loads
- **THEN** an audit-log row SHALL be written with actor_id, target_dossier_document_id, action=view, timestamp, IP, user-agent
- **AND** last_accessed_at on the document SHALL update to NOW
- **AND** last_accessed_by on the document SHALL update to the user

### Scenario: User downloads binary, logged with byte count

- **GIVEN** any user downloads the binary
- **WHEN** the Files API serves the bytes
- **THEN** an audit-log row SHALL be written with actor_id, target_dossier_document_id, action=download, timestamp, byte_size_served, download_token (for chain-of-custody tracing)
- **AND** IP and user-agent SHALL be captured

### Scenario: Destruction job logs with certificate link

- **GIVEN** the destruction job destroys a document
- **WHEN** the destruction commits
- **THEN** an audit-log entry SHALL be written with action=destroy, executor=system, policy_code, destruction_certificate_id, timestamp
- **AND** this entry is NOT itself destroyed at the document's retention end (audit of destruction must outlast the destroyed object)

---

## REQ-DD-008: Bewaartermijn-engine with geautomatiseerde vernietiging

**Requirement:**
The system SHALL run a destruction job nightly that selects documents past retention_end_at, executes the configured destruction_action (hard_delete from Nextcloud Files + register, or anonymise, or archive_offsite), and emits a signed `destruction-certificate`.

### Scenario: Ten ARBEID documents reach retention end, destroyed together

- **GIVEN** ten ARBEID_5Y_POST_DIENST documents reach retention_end_at on 2026-09-30
- **WHEN** the destruction job runs that night
- **THEN** all ten binaries SHALL be hard-deleted from Nextcloud Files
- **AND** the `dossier-document` rows SHALL transition to status=destroyed (metadata stays for the audit)
- **AND** one `destruction-certificate` SHALL be issued listing all ten document IDs, policy_codes=[ARBEID_5Y_POST_DIENST], executed_at, signed_pdf_path

### Scenario: Document under legal hold, skipped

- **GIVEN** a document marked `retention_hold=true` (e.g. ongoing CAO-arbitrage)
- **WHEN** the destruction job runs
- **THEN** the document SHALL be skipped
- **AND** a hold-skip entry SHALL log with reason "legal_hold=true", actor=system

### Scenario: Destruction-certificate rendered and archived

- **GIVEN** a destruction-certificate is emitted
- **WHEN** the certificate renders
- **THEN** it SHALL be a signed PDF containing document IDs, categories, retention policies cited (e.g. "AWR art. 115"), executed_at, audit_chain_hash
- **AND** the PDF SHALL be archived (e.g. in a separate destruction-certificate vault) and retained for 10 years

---

## REQ-DD-009: System-generated document deposit

**Requirement:**
The system SHALL accept system-generated documents (payroll PDFs from `payroll-engine-nl`, approved timesheets from `time-attendance`, leave decisions from `leave-absence`, published rosters from `rostering-planning`) via a service-account API, with the source system populating category, employee, effective dates, and source_system_ref.

### Scenario: Payroll deposits SALARISBRIEF, deduped on source_system_ref

- **GIVEN** payroll-engine-nl POSTs a SALARISBRIEF for employee X period 2026-03
- **WHEN** the deposit endpoint accepts
- **THEN** a `dossier-document` SHALL persist with category=SALARISBRIEF, source=system_generated, source_system_ref containing the payroll batch ID
- **AND** the standard ACL template (HR view+download+edit, employee view+download) SHALL apply
- **WHEN** payroll resubmits the same batch
- **THEN** the existing document SHALL be returned (idempotent 200), no second copy SHALL be created

### Scenario: Deposit missing required metadata, rejected

- **GIVEN** a system deposit lacks a required category metadata field (e.g. SALARISBRIEF without period)
- **WHEN** the endpoint validates
- **THEN** the deposit SHALL reject with error code METADATA_INCOMPLETE and HTTP 400
- **AND** the source system SHALL be expected to retry with the missing field

---

## REQ-DD-010: Special-category data (AVG art. 9) handling

**Requirement:**
The system SHALL apply elevated handling to documents in categories marked `is_special_category=true` (medical, biometric, religious, union membership, criminal records): no manager ACL grant by default, encryption-at-rest required on the Files store, AP-aligned data-breach notification path, and explicit consent or legal-obligation grounding recorded.

### Scenario: ZIEKTEVERZUIM upload, manager ACL NOT created

- **GIVEN** a ZIEKTEVERZUIM document is uploaded
- **WHEN** the upload commits
- **THEN** the manager ACL grant SHALL NOT be created (only HR-role with legal_basis=legal_obligation)
- **AND** an audit-log entry SHALL record "special-category handling applied, manager ACL omitted"

### Scenario: HR admin attempts manager grant on special-category, requires override approval

- **GIVEN** an HR admin attempts to grant manager view on a special-category document
- **WHEN** the grant submits
- **THEN** the system SHALL present a two-person-rule dialog: "This is a special-category document. Confirm your reason and request approval from another HR-admin."
- **AND** the grant SHALL NOT commit until a DIFFERENT HR-admin approves (acl-grant.override_approval_by set)
- **AND** the override SHALL be logged in the special-category-override log (visible to Data Protection Officer)

### Scenario: Special-category document destroyed, certificate annotates it

- **GIVEN** a special-category document is destroyed at retention end
- **WHEN** the destruction-certificate renders
- **THEN** the certificate SHALL annotate the special-category nature: "Special-category documents per GDPR art. 9: [list categories]"
- **AND** this annotation allows the AP to verify proportional handling in a hypothetical audit

---

## REQ-DD-011: Least-privilege default ACL per role

**Requirement:**
The default role-based ACL matrix SHALL be applied at upload time, with explicit manager exclusion for salary, payroll, and special-category documents. Managers SHALL see only documents for their direct reports and only those not marked as payroll-confidential.

### Scenario: HR uploads contract (non-sensitive), manager sees it

- **GIVEN** an HR user uploads a CONTRACT_ARBEID for employee Jansen
- **WHEN** the document commits
- **THEN** Jansen's direct manager SHALL have an implicit acl-grant with permission=view (not download+edit)
- **AND** the manager SHALL see "Contract" in their view of Jansen's dossier

### Scenario: HR uploads payslip, manager does NOT see it

- **GIVEN** an HR user uploads a SALARISBRIEF for employee Jansen
- **WHEN** the document commits
- **THEN** no acl-grant SHALL be created for manager (even if manager is Jansen's direct manager)
- **AND** the manager SHALL not see SALARISBRIEF in their view of Jansen's dossier
