---
status: draft
---
# Document Dossier & AVG Retention — Personeelsdossier, E-Sign, Bewaartermijnen

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Medewerkers › Documenten

**Rationale:** Personeelsdossier-tab + AVG-retentie.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `document-dossier-avg` capability gives hrmq a compliant, register-backed personeelsdossier built on Nextcloud Files with first-class AVG retention, role-based access control, e-sign workflow and a tamper-evident audit log. Every employer with one or more medewerkers is legally obliged to keep a personeelsdossier; in practice this lives today in a tangle of Dropbox folders, Outlook attachments, an HR-suite vendor's vault, or — for a non-trivial share of SMBs — a literal kast met ordners. hrmq replaces that with a structured dossier where each document is classified, retained for the statutory minimum, automatically destroyed at retention end, and signed where the law requires it.

The capability covers the full document lifecycle: capture (upload, scan, e-sign, system-generated PDF from payroll/timesheet/leave), classify (contract, beoordeling, ziekteverzuim, opleidingscertificaat, salarisbrief, identiteitsbewijs, VOG, verklaring goed gedrag), permit (per-role and per-employee ACLs honouring the AVG least-privilege baseline), retain (per category, with overheids- and reguliere-werkgever variants), search (filtered by ACL so an employee sees only their own dossier), audit (every view, download, edit, delete tracked), and destroy (engineering-enforced automatic vernietiging at retention end with auditable certificate).

The architectural cornerstone is that the dossier is not a folder tree: it is a register-backed catalogue of `dossier-document` openregister objects, each pointing to an underlying Nextcloud Files binary via the existing OpenRegister "files attached to object" pattern. This means the document carries its metadata (category, retention class, signatories, audit log, ACL grants) as first-class fields, queryable across the whole employer estate. A "all expiring VOGs across the team" report becomes a single openregister query; a "what does manager X have access to and why" report is an ACL-grants join; a "destroy all expired loonstroken from 2018" run is an idempotent job that emits a vernietigingscertificaat.

The capability does not store the binary natively — that's Nextcloud Files. It does not own the e-sign cryptography — that flows through docudesk's eIDAS-compliant signing service. It does not own the employee — that's `employee-master`. What it owns is the personeelsdossier-shaped layer on top: categorisation, ACL, retention engine, audit log, search filter, and the contract surface so other hrmq capabilities (payroll, time, leave, rostering) can deposit and retrieve dossier documents safely.

## Data Model

Six openregister schemas in the `hrmq` register: `dossier-document`, `document-category`, `retention-policy`, `acl-grant`, `signature-request`, `destruction-certificate`. All schemas share the tenant + employee permission backbone from `employee-master`. Binaries live in Nextcloud Files under a per-employee folder, owned by the system user with explicit ACL injection per `acl-grant`.

**`dossier-document`** is the catalogue entry. Fields: `employee_id` (ref employee-master), `category_id` (ref document-category), `nextcloud_file_id` (int, the underlying Files node), `nextcloud_file_path` (string, denormalised for migration safety), `mime_type`, `byte_size`, `sha256_hash` (integrity baseline), `original_filename`, `display_title`, `description`, `effective_from` (date, when document took effect — e.g. contract start), `expires_at` (date, optional — VOG, opleidingscertificaat), `retention_policy_id`, `retention_end_at` (computed), `status` (enum: draft, active, signed, archived, expired, destroyed), `source` (enum: upload, system_generated, e_sign_completed, scan, imported), `source_system_ref` (jsonb — e.g. {"system":"payroll-engine-nl","batch_id":"…"}), `signature_request_id` (nullable), `created_by`, `created_at`, `last_accessed_at`, `last_accessed_by`.

**`document-category`** is the controlled vocabulary. Fields: `code` (e.g. CONTRACT_ARBEID, BEOORDELING, ZIEKTEMELDING, OPLEIDING_CERT, SALARISBRIEF, ID_KOPIE, VOG, VERKLARING_GEDRAG), `display_name_nl`, `display_name_en`, `description_nl`, `default_retention_policy_id`, `requires_signature` (boolean), `signature_type` (enum: none, simple, advanced, qualified per eIDAS), `default_acl_template_id`, `is_special_category` (boolean — AVG art. 9), `legal_basis_default` (enum: contract, legitimate_interest, legal_obligation, consent).

**`retention-policy`** is the bewaartermijn rule. Fields: `code` (e.g. FISCAAL_7Y, ARBEID_POST_DIENST_5Y, OVERHEID_50Y, ID_KOPIE_5Y_POST_DIENST, ZIEKTEVERZUIM_2Y_POST_DIENST), `display_name_nl`, `years` (int), `months` (int), `anchor` (enum: document_effective_from, document_expires_at, employment_end, fiscal_year_end), `legal_source` (string citation), `destruction_action` (enum: hard_delete, anonymise, archive_offsite), `override_allowed` (boolean — usually false).

**`acl-grant`** is the per-document permission. Fields: `dossier_document_id`, `principal_type` (enum: employee_self, role, named_user, named_group), `principal_id`, `permission` (enum: view, download, edit, share, sign), `granted_by`, `granted_at`, `expires_at` (optional), `grant_reason` (string), `legal_basis` (enum AVG-art. 6/9).

**`signature-request`** drives the e-sign flow. Fields: `dossier_document_id`, `signature_type` (enum: simple, advanced, qualified), `signatories` (jsonb array of {employee_id|external_email, role, sign_order, status, signed_at, evidence_hash}), `status` (enum: draft, sent, partially_signed, completed, declined, expired, cancelled), `docudesk_envelope_id`, `evidence_url`, `eidas_audit_trail_id`.

**`destruction-certificate`** is the proof of vernietiging. Fields: `dossier_document_ids` (array), `executed_at`, `executed_by` (system user, never a human), `policy_codes` (array), `destruction_method` (enum: hard_delete, anonymise, archive_offsite), `audit_chain_hash`, `signed_pdf_path` (the printable certificate).

Indexes: `dossier_document (employee_id, category_id)`, `dossier_document (retention_end_at)` partial on status=active, `acl_grant (dossier_document_id, principal_id)`, `acl_grant (principal_id, principal_type)` for the inverse "what can X see" query, `signature_request (status, expires_at)`.

## Requirements

### REQ-DD-001: Personeelsdossier in Nextcloud Files with strict ACL per role
The system SHALL store each dossier document as a Nextcloud Files binary inside a per-employee folder, owned by a system user, with explicit ACL grants injected per `acl-grant` row. The default role matrix SHALL be: employee (view+download own), direct manager (view), HR (view+download+edit), HR admin (view+download+edit+share+destroy), OR-inzage (anonymised aggregate only).

- **GIVEN** an employee uploads a document of category SALARISBRIEF to their own dossier **WHEN** the upload commits **THEN** an `acl-grant` SHALL exist for principal_type=employee_self with view+download, an `acl-grant` SHALL exist for HR-role with view+download+edit, and the direct manager SHALL not appear (salarisbrieven are not visible to line managers per AVG least-privilege).
- **GIVEN** a manager attempts to download a document with no `acl-grant` for them **WHEN** the request hits the Files API **THEN** the system SHALL return 403 with code DOSSIER_NO_ACCESS and SHALL log the denied access attempt in the audit log.
- **GIVEN** an HR admin grants a temporary view permission to a named external auditor for 30 days **WHEN** the grant commits **THEN** the `acl-grant` SHALL persist with expires_at and grant_reason, and the system SHALL automatically revoke at expiry with an audit-log entry.

### REQ-DD-002: Version history per document
The system SHALL retain every prior version of a dossier document with full audit attribution, allowing rollback, side-by-side compare, and retention-aware version pruning that respects the active retention policy on the document family.

- **GIVEN** an HR user uploads version 2 of an existing CONTRACT_ARBEID document **WHEN** the upload commits **THEN** the new version SHALL become current, version 1 SHALL move to history with a `superseded_at` timestamp, and both versions SHALL share the same retention class.
- **GIVEN** a document with three historical versions **WHEN** the retention engine runs at retention_end_at **THEN** all versions SHALL be destroyed together via one `destruction-certificate`; partial-version destruction SHALL never occur (a half-destroyed dossier breaks evidence chain).
- **GIVEN** an HR admin requests rollback to version 1 of a beoordeling **WHEN** the rollback commits **THEN** a new version SHALL be written with content copied from version 1 (never silent pointer-move), with audit entry "rollback to v1" and the user's reason.

### REQ-DD-003: E-sign workflow eIDAS-compliant
The system SHALL support eIDAS-compliant signing at three assurance levels (simple, advanced, qualified) via the docudesk e-sign service, with category-driven default level (e.g. CONTRACT_ARBEID = advanced, AANSPRAKELIJKHEIDSVERKLARING = qualified, AKKOORD_HUISREGELS = simple).

- **GIVEN** an HR user opens a new CONTRACT_ARBEID and clicks "Versturen ter ondertekening" **WHEN** the envelope dispatches **THEN** a `signature-request` SHALL persist with type=advanced (the category default), signatories listing employee + werkgever-vertegenwoordiger, and docudesk SHALL send the eIDAS-compliant signing link to each in order.
- **GIVEN** all signatories have signed an advanced envelope **WHEN** docudesk reports completion **THEN** the system SHALL persist the signed PDF as a new version of the document, set status=signed, copy the eIDAS audit trail into eidas_audit_trail_id, and freeze the document from further edits.
- **GIVEN** a signatory declines or the envelope expires **WHEN** the docudesk callback fires **THEN** the signature-request SHALL transition to declined/expired, the original document SHALL revert to status=draft, and the initiator SHALL receive a notification with the docudesk reason captured.

### REQ-DD-004: AVG retention engine with category-driven policies
The system SHALL compute retention_end_at on every document save using the category's default policy plus any explicit override, and SHALL execute destruction automatically when retention_end_at passes. Default policies SHALL include OVERHEID_50Y, FISCAAL_7Y_POST_FY, ARBEID_5Y_POST_DIENST, ID_KOPIE_5Y_POST_DIENST, ZIEKTEVERZUIM_2Y_POST_DIENST, SOLLICITATIE_4W_POST_DECISION (with explicit consent for longer).

- **GIVEN** a private-sector employer uploads a SALARISBRIEF dated 2026-03-01 **WHEN** the retention engine computes **THEN** retention_end_at SHALL be 2033-12-31 (FISCAAL_7Y_POST_FY anchored on fiscal year end).
- **GIVEN** an overheid-tenant uploads the same SALARISBRIEF **WHEN** the retention engine computes **THEN** retention_end_at SHALL be 2076-03-01 (OVERHEID_50Y per Archiefwet selectielijst), reflecting the public-record retention obligation.
- **GIVEN** an employment ends on 2026-09-30 and a ZIEKTEVERZUIM document is linked to that employee **WHEN** the engine recomputes **THEN** retention_end_at SHALL move to 2028-09-30 (ZIEKTEVERZUIM_2Y_POST_DIENST anchored on employment_end), capturing the post-dienst countdown.

### REQ-DD-005: Categorisering with controlled vocabulary
The system SHALL enforce category selection from `document-category` at upload time, with a default suggestion derived from filename/MIME heuristics and a per-category form that captures required metadata (e.g. CONTRACT_ARBEID requires effective_from + contract_type; VOG requires issuing_authority + issue_date).

- **GIVEN** an HR user uploads `vog-jansen-2026.pdf` **WHEN** the upload form opens **THEN** the system SHALL pre-select category VOG, prompt for issuing_authority and issue_date, and refuse save until both are provided.
- **GIVEN** an upload with category=null **WHEN** the user attempts save **THEN** the system SHALL block save with error CATEGORY_REQUIRED; no document SHALL ever live in the dossier without a category (otherwise the retention engine cannot compute retention_end_at).
- **GIVEN** the user picks ZIEKTEVERZUIM **WHEN** the form renders **THEN** the `is_special_category` warning SHALL display (AVG art. 9), legal_basis SHALL default to legal_obligation (loondoorbetalingsplicht), and the ACL template SHALL pre-populate with HR-only (no manager view).

### REQ-DD-006: Search with access-controls and filter facets
The system SHALL provide a faceted search across the dossier corpus visible to the requesting user, with facets for category, employee (where ACL allows), date range, expiring-soon, missing-required, and full-text on display_title and description.

- **GIVEN** an HR user searches for "VOG" **WHEN** the result list renders **THEN** every VOG visible to the user SHALL appear with category, employee name (where allowed), and expires_at, sorted by soonest expiry.
- **GIVEN** an employee searches their own dossier **WHEN** the result list renders **THEN** only documents with employee_self acl-grant for the requesting employee SHALL appear, regardless of full-text match elsewhere in the tenant.
- **GIVEN** an HR admin runs the "expiring in 30 days" report **WHEN** the report renders **THEN** every document with expires_at within 30 days SHALL appear grouped by category, with one-click navigation to the document and a "renew/replace" action.

### REQ-DD-007: Audit log per view, download, edit, delete, sign
The system SHALL log every access action on a dossier document to a tamper-evident audit log retained for the document's full lifetime plus the retention period of the underlying document.

- **GIVEN** any user views a dossier document detail page **WHEN** the page loads **THEN** an audit-log row SHALL be written with actor_id, target_dossier_document_id, action=view, timestamp, IP, user-agent, and last_accessed_at on the document SHALL update.
- **GIVEN** any user downloads the binary **WHEN** the Files API serves the bytes **THEN** an audit-log row SHALL be written with action=download, byte_size_served, and a download_token recorded for chain-of-custody tracing.
- **GIVEN** the destruction job destroys a document **WHEN** the destruction commits **THEN** an audit-log entry SHALL be written with action=destroy, executor=system, policy_code, and a link to the `destruction-certificate` row; this entry is NOT itself destroyed at the document's retention end (audit of destruction must outlast the destroyed object).

### REQ-DD-008: Bewaartermijn-engine with geautomatiseerde vernietiging
The system SHALL run a destruction job nightly that selects documents past retention_end_at, executes the configured destruction_action (hard_delete from Nextcloud Files + register, or anonymise, or archive_offsite), and emits a signed `destruction-certificate`.

- **GIVEN** ten ARBEID_5Y_POST_DIENST documents reach retention end on 2026-09-30 **WHEN** the destruction job runs that night **THEN** all ten binaries SHALL be hard-deleted from Nextcloud Files, the `dossier-document` rows SHALL transition to status=destroyed (metadata stays for the audit), and one `destruction-certificate` SHALL be issued listing all ten document IDs.
- **GIVEN** a document under legal hold (e.g. ongoing CAO-arbitrage) marked `retention_hold=true` **WHEN** the destruction job runs **THEN** the document SHALL be skipped and a hold-skip entry SHALL log; legal hold OVERRIDES retention expiry.
- **GIVEN** a destruction-certificate is emitted **WHEN** the certificate renders **THEN** it SHALL be a signed PDF containing document IDs, categories, retention policies cited, executed_at, audit_chain_hash, and SHALL itself be archived in a separate destruction-certificate vault retained 10 years.

### REQ-DD-009: System-generated document deposit
The system SHALL accept system-generated documents (payroll PDFs from `payroll-engine-nl`, approved timesheets from `time-attendance`, leave decisions from `leave-absence`, published rosters from `rostering-planning`) via a service-account API, with the source system populating category, employee, effective dates, and source_system_ref.

- **GIVEN** payroll-engine-nl posts a SALARISBRIEF for employee X period 2026-03 **WHEN** the deposit endpoint accepts **THEN** a `dossier-document` SHALL persist with category=SALARISBRIEF, source=system_generated, source_system_ref containing the payroll batch ID, and the standard ACL template SHALL apply.
- **GIVEN** a duplicate deposit attempt with the same source_system_ref **WHEN** the endpoint dedupes **THEN** the existing document SHALL be returned, no second copy SHALL be created, and the source system SHALL receive an idempotent 200 with the original document ID.
- **GIVEN** a system deposit lacks a required category metadata field (e.g. SALARISBRIEF without period) **WHEN** the endpoint validates **THEN** the deposit SHALL reject with error METADATA_INCOMPLETE; the source system SHALL be expected to retry with the missing field rather than the dossier accepting a half-classified document.

### REQ-DD-010: Special-category data (AVG art. 9) handling
The system SHALL apply elevated handling to documents in categories marked `is_special_category=true` (medical, biometric, religious, union membership, criminal records): no manager ACL grant by default, encryption-at-rest required on the Files store, AP-aligned data-breach notification path, and explicit consent or legal-obligation grounding recorded.

- **GIVEN** a ZIEKTEVERZUIM document is uploaded **WHEN** the upload commits **THEN** the manager ACL grant SHALL NOT be created (only HR-role with legal_obligation legal-basis), and an audit-log entry SHALL record the special-category handling.
- **GIVEN** an HR admin attempts to grant manager view on a special-category document **WHEN** the grant submits **THEN** the system SHALL require an explicit override reason + secondary HR-admin approval before the grant commits, and the override SHALL flag in the AP-notifiable special-category override log.
- **GIVEN** a special-category document is destroyed at retention end **WHEN** the destruction-certificate renders **THEN** the certificate SHALL annotate the special-category nature so the AP can verify proportional handling in a hypothetical audit.

## Standards & Sources

- **AVG / GDPR** (verordening 2016/679) — entire capability is built around art. 5 (beginselen), art. 6 (rechtmatigheid), art. 9 (bijzondere categorieën), art. 17 (recht op vergetelheid), art. 30 (verwerkingsregister), art. 32 (beveiliging), art. 33–34 (datalek-meldplicht).
- **UAVG** (Uitvoeringswet AVG) — Dutch implementing law clarifying e.g. ID-kopie copying rules (BSN-streep, max retention).
- **Archiefwet 1995 + selectielijst rijksoverheid** — overheidsretentie tot 50 jaar voor specifieke personeelsstukken; basis for OVERHEID_50Y policy.
- **Wet bewaarplicht administratie (AWR)** — 7-year fiscal retention basis for FISCAAL_7Y_POST_FY.
- **Burgerlijk Wetboek 7 / arbeidsrecht** — post-dienst retentie van arbeidscontract-gerelateerde stukken (5 jaar gangbaar).
- **AP-richtsnoer Werkgever en personeelsgegevens** (autoriteitpersoonsgegevens.nl) — bewaartermijnen per categorie; basis for the default retention matrix.
- **Wet poortwachter** — ZIEKTEVERZUIM dossier-verplichtingen tot 2 jaar post-dienst (basis ZIEKTEVERZUIM_2Y_POST_DIENST).
- **eIDAS verordening 910/2014** — drei assurance-niveaus voor elektronische handtekeningen; CONTRACT_ARBEID en aansprakelijkheidsverklaringen vereisen minimaal "geavanceerd".
- **NTA 9120 / NEN 7510** — informatiebeveiliging in de zorg; baseline voor versleuteling-at-rest van bijzondere categoriegegevens.
- **BIO** (Baseline Informatiebeveiliging Overheid) — for overheids-tenants the BIO maatregelen-set is applied to the Files store and the audit log.
- **Forum Standaardisatie pas-toe-of-leg-uit** — PDF/A-2b voor archivering van vernietigingscertificaten, eIDAS-handtekeningen voor signed bestanden, OAuth 2.0 voor de deposit-API.

Competitor calibration: **AFAS Personeel** (closed vault, weak AVG retention automation), **NMBRS** (loonsoftware met dossier-bijlage, geen ACL per document), **Visma RAET** (enterprise, sterke compliance, kostbaar en gesloten), **Personio** (DE-leider, retention-engine bestaat maar Nederlandse selectielijst ontbreekt), **HRM Force / Loket** (boekhoudkantoor-dossiers, geen e-sign), **Dossier-online vendors** (Document-Cloud-stijl, geen integratie met payroll/time/leave). hrmq onderscheidt zich met open data-model, register-native AVG-engine, eIDAS-koppeling via docudesk, en integrale deposit-flows vanuit de andere hrmq-capabilities.

## Cross-app integration

- **employee-master** — bron van de employee-entity en het dienstverband; dienst-einde-event triggert herberekening van retention_end_at op alle post-dienst-geanchorde documenten (REQ-DD-004).
- **payroll-engine-nl** — deposit van salarisbrieven, jaaropgaves, loonbeslag-bescheiden via REQ-DD-009; payroll trekt nooit zelf binaries, alleen via getekende download-URL met audit-trail.
- **time-attendance** — deposit van approved timesheet-PDFs als bewijsstuk; correctiebatches deponeren een nieuwe versie van het oorspronkelijke document.
- **rostering-planning** — deposit van published rosters per filiaal/periode als locatie-dossier-bijlage.
- **leave-absence** (sibling brief) — deposit van vakantie-, ziekteverlof- en compensatie-besluiten; ZIEKTEVERZUIM-stukken triggeren REQ-DD-010 special-category handling.
- **docudesk** — host van de eIDAS-handtekeningendienst (REQ-DD-003); docudesk is leveranciersagnostisch (DigiTraced, Signhost, Connective) en levert de audit-trail terug.
- **openregister AVG retention engine** — sectoraal-overstijgende rule-engine die `retention-policy`-rijen levert; hrmq registreert eigen policies bovenop de open-register baseline (selectielijst-overheid, fiscaal, arbeidsrechtelijk).
- **Nextcloud Files** — onderliggende binary-store; ACL injection via Files-API met system-owned mappen per medewerker; encryption-at-rest verplicht voor `is_special_category` documenten.
- **nldesign** — overheidsthema voor gemeenten/provincies, met selectielijst-rijksoverheid als default-policy preset.
- **n8n** — `dossier.document.created`, `dossier.document.expiring_soon`, `dossier.document.destroyed`, `signature.completed` events voor klant-automatiseringen (Whatsapp-reminder bij verlopen VOG, mail aan accountant bij nieuwe loonstrook, sync naar branchespecifieke archiefdienst).
- **Forum Standaardisatie / Common Ground** — dossier-document exposeert ZGW-achtige document-endpoints zodat ConductionNL apps elders (procest, decidesk) een hrmq-dossierstuk kunnen citeren zonder de binary te dupliceren.

## Target users

Primary: HR-administrateur of bedrijfsleider in een SMB met 5–250 medewerkers, die vandaag personeelsdossiers half in een afgesloten ladekast en half in een Dropbox-map beheert en wakker ligt van AVG-handhaving en CAO-controles. Secondary: de medewerker, die het recht heeft op inzage in en kopie van het eigen dossier (AVG art. 15) en dat in hrmq met één klik krijgt in plaats van een formeel verzoek per e-mail. Tertiary: de boekhouder / loonadviseur, die maandelijks salarisbrieven en jaaropgaves moet kunnen ophalen voor klanten. Quaternary: de OR / personeelsvertegenwoordiging, die onder WOR-art. 28 toezicht houdt op personeelsregistraties en geanonimiseerde aggregaten nodig heeft. Quintary: de externe accountant of arbeidsinspectie-medewerker die in een audit-context een tijdelijke, gelogde toegang krijgt via REQ-DD-001 expires_at.

Non-users: enkele-medewerker-eenmanszaken zonder personeel (geen dossierplicht), en grote corporates met SAP/Workday/Personio-implementaties in lopende contracten — hun migratiekost overstijgt de hrmq-businesscase, hoewel de open data-model wel een toekomstige migratie faciliteert. De fit zit in de lange Nederlandse SMB-staart en in MKB-gemeenten/zorginstellingen die op SMB-schaal opereren maar wel onder overheidsretentie vallen.
