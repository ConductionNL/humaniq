---
status: draft
app: hrmq
spec: stagiair-bbl-admin
version: 0.1.0
owners: [hrmq-team]
---

# Stagiair & BBL-leerling Administratie — Tasks

## Entities & Core Data Model

- [ ] **Entity: Stagiair** — Schema definition, database migration, ORM model
  - Fields: bsn, voornaam, achternaam, geboortedatum, onderwijsinstelling_id, opleiding, niveau, studierichting, stagetype
  - Fields: startdatum, einddatum, aantal_dagen_per_week
  - Fields: stagebegeleider_intern_id, stagebegeleider_extern
  - Fields: stagevergoeding_per_maand, reiskosten_vergoeding
  - Fields: pok_id, pok_ondertekening_status
  - Fields: verzekering_status, verzekering_via
  - Fields: evaluatie_punten (array), beoordeling_eindcijfer, diploma_behaald, diploma_behaald_datum
  - Fields: createdAt, updatedAt, archivedAt
  - Indexes: (onderwijsinstelling_id, startdatum), (pok_id), (stagebegeleider_intern_id)

- [ ] **Entity: BBLLeerling** — Schema definition, database migration, ORM model
  - Fields: bsn, voornaam, achternaam, geboortedatum
  - Fields: roc_instelling_id, crebo_code, niveau
  - Fields: werknemer_id (FK to Employee, required, unique)
  - Fields: leerbedrijf_erkenning_id (FK to SBBErkenning)
  - Fields: pok_id, pok_ondertekening_status
  - Fields: bbl_staffel_jaar (1|2|3), bbl_staffel_jaar_gewijzigd_datum
  - Fields: vouchers_toegekend, subsidie_praktijkleren_aangevraagd, subsidie_aanvraag_id, subsidie_bedrag_uitgekeerd
  - Fields: praktijkbeoordelaar_id
  - Fields: evaluatie_punten (array), startdatum, einddatum, beoordeling_eindcijfer, diploma_behaald, diploma_behaald_datum
  - Fields: createdAt, updatedAt, archivedAt
  - Constraint: unique(werknemer_id) — one BBL per Employee, no double-linking
  - Indexes: (roc_instelling_id, crebo_code), (leerbedrijf_erkenning_id), (werknemer_id), (pok_id)

- [ ] **Entity: PraktijkLeerOvereenkomst (POK)** — Schema definition, database migration, ORM model
  - Fields: stagiair_id (nullable FK), bbl_leerling_id (nullable FK)
  - Fields: partij_leerbedrijf (JSON: naam, kvk, contactperson, ondertekend, ondertekend_datum)
  - Fields: partij_onderwijsinstelling (JSON: naam, contactperson, ondertekend, ondertekend_datum)
  - Fields: partij_deelnemer (JSON: bsn, naam, ondertekend, ondertekend_datum)
  - Fields: ingangsdatum, einddatum, onderwerp, leerdoelen (JSON array)
  - Fields: praktijkbeoordelaar (JSON: naam, contact)
  - Fields: aantal_begeleidingsuren_per_week
  - Fields: tussentijdse_evaluaties (JSON array: moment_percentage, geplande_datum, voltooide_datum, opmerkingen, score)
  - Fields: pok_document_url, pok_ondertekend_volledig_url, archief_url
  - Fields: ondertekend_compleet_datum, archivering_startdatum, archivering_verwijderplandatum
  - Fields: createdAt, updatedAt
  - Constraint: at least one of (stagiair_id, bbl_leerling_id) must be set
  - Indexes: (stagiair_id), (bbl_leerling_id), (ondertekend_compleet_datum)

- [ ] **Entity: SBBErkenning** — Schema definition, database migration, ORM model
  - Fields: kvk (unique), sbb_erkenningsnummer (unique), erkende_crebos (array of strings)
  - Fields: erkenningsdatum, vervaldatum, status (enum: geldig|verlopen|ingetrokken)
  - Fields: praktijkopleider_named (JSON: id, naam)
  - Fields: sbb_synced_at, sbb_sync_status (enum: ok|error|not_found), sbb_sync_error (text)
  - Fields: createdAt, updatedAt
  - Indexes: (kvk), (sbb_erkenningsnummer)

- [ ] **Entity: SubsidieAanvraagPraktijkleren** — Schema definition, database migration, ORM model
  - Fields: bbl_leerling_id (FK), studiejaar (string, e.g., "2025-2026")
  - Fields: aangevraagd_datum, rvo_referentienr, bedrag_aangevraagd, bedrag_toegekend, uitkeringsdatum
  - Fields: bewijsstukken (JSON: pok_url, urenregistratie_url, evaluatieverslag_url, diploma_url)
  - Fields: status (enum: concept|ingediend|goedgekeurd|afgewezen|uitgekeerd)
  - Fields: createdAt, updatedAt
  - Indexes: (bbl_leerling_id), (rvo_referentienr), (aangevraagd_datum)

---

## UI & Forms

### Registration & Management

- [ ] **Stagiair Registration Form** — React component
  - Fieldgroups: Identiteit (bsn, voornaam, achternaam, geboortedatum)
  - Fieldgroups: Onderwijs (onderwijsinstelling dropdown, opleiding, niveau picker, studierichting, stagetype)
  - Fieldgroups: Periode (startdatum, einddatum, aantal_dagen_per_week slider 1–5)
  - Fieldgroups: Begeleiding (stagebegeleider_intern dropdown from Employee, stagebegeleider_extern text)
  - Fieldgroups: Financieel (stagevergoeding_per_maand input EUR, reiskosten_vergoeding input EUR)
  - Validation: BSN format (9 digits), dates (startdatum < einddatum), aantal_dagen_per_week in [1,5]
  - On save: creates Stagiair record + auto-creates POK (concept)
  - Redirects to POK detail page
  - Audit log: "Stagiair registered [name]"

- [ ] **BBLLeerling Registration Form** — React component
  - Fieldgroups: Identiteit (bsn, voornaam, achternaam, geboortedatum)
  - Fieldgroups: ROC & Opleiding (roc_instelling dropdown, crebo_code picker)
  - On crebo_code selection: validates against SBBErkenning (GIVEN org KVK)
    - If no valid erkenning: show error, disable submit
    - If valid: shows "Erkend tot [vervaldatum]" badge
  - Fieldgroups: Werknemerslink (radio: "Link to existing Employee" / "Create new Employee")
    - If existing: dropdown of eligible Employees (contractvorm empty or non-BBL)
    - If new: prefill voornaam/achternaam from form, set contractvorm = "bbl-arbeidsovereenkomst"
  - Fieldgroups: Plaatsinggegevens (niveau picker 2|3|4, startdatum, einddatum)
  - Validation: crebo_code must pass SBB check (REQ-SBA-003)
  - On save: creates BBLLeerling record + creates/links Employee record + auto-creates POK
  - Redirects to POK detail page
  - Audit log: "BBL registered [name], linked Employee [id]"

- [ ] **Stagiair/BBL List View** — React component
  - Table columns: Name, Type (Stagiair|BBL), Status (active|completed|archived), Opleiding, Startdatum, Einddatum, Stagebegeleider, Actions
  - Filters: Status (active/completed/archived), Onderwijsinstelling (multi-select), Period (date range), BBL-staffel (if BBL)
  - Sorting: startdatum (default, desc), einddatum, name
  - Pagination: 50 rows/page
  - Bulk actions: Export (CSV), Archive completed
  - Row click: opens detail page
  - Search: fuzzy match on name + institution

- [ ] **Stagiair/BBL Detail Page** — React component
  - Tabs: Gegevens | POK & Ondertekening | Evaluaties | Financieel (BBL) | Versicherung | Archief
  - **Gegevens tab:**
    - Read-only display of registration data (name, bsn masked, institution, periode, etc.)
    - Edit button: opens modal with Stagiair/BBL form (same as registration)
    - Status badge: active|completed|archived
  - **POK & Ondertekening tab:** (see POK detail below)
  - **Evaluaties tab:**
    - List: evaluatie_punten with status (geplande_datum, voltooide_datum if done)
    - For each point: "Evaluatie afgerond" button (if not yet completed)
    - Button click → modal "Voortgangsevaluatie registreren" (see REQ-SBA-009)
    - History table: past evaluations with resultaat, opmerkingen, voltooide_datum
  - **Financieel tab (BBL only):**
    - Display: werknemer_id (link to Employee detail), contractvorm, current salary, BBL-staffel-jaar
    - Button: "Registreer leerjaar-overgang" (active if T + 12 months > now)
    - Modal on click: "Bevordering naar jaar 2" (see REQ-SBA-006)
    - Salary mutation history: table of past salary changes
  - **Verzekering tab:**
    - verzekering_status dropdown (read/edit)
    - verzekering_via field
    - Edit button to verify/update status
  - **Archief tab:**
    - Shown only if archived
    - archivedAt, archivering-verwijderplandatum, read-only copy of final data
  - Bottom: "Registreer uitstroom" button (active if einddatum <= today and not already archived)
    - Click → modal "Uitstroom registreren" (see REQ-SBA-010)

- [ ] **POK Detail Page** — React component (shared for both Stagiair & BBL)
  - Tabs: Samenvatting | Ondertekening | Leerdoelen & Evaluaties | Documenten
  - **Samenvatting tab:**
    - Display: linked Stagiair/BBL name, ingangsdatum, einddatum
    - Edit button: modal to edit POK data (onderwerp, leerdoelen, praktijkbeoordelaar, aantal_begeleidingsuren_per_week)
    - Status badge: concept|wacht_[party]|compleet|archived
  - **Ondertekening tab:**
    - Three-party signature grid:
      - Leerbedrijf: [naam], [contactperson], status (pending|signed|complete), button "Send signing link" / "Pending leerbedrijf signature"
      - Onderwijs: [naam], status (pending|signed|complete), button "Send signing link" / "Pending onderwijs signature"
      - Deelnemer: [naam (BSN)], status (pending|signed|complete), button "Send signing link" / "Pending deelnemer signature"
    - Once all three signed: ondertekend_compleet_datum displayed, pok_ondertekening_status = "compleet"
    - Download button: "Download signed POK" (if available pok_ondertekend_volledig_url)
  - **Leerdoelen & Evaluaties tab:**
    - Leerdoelen list (read/edit): for each goal, beschrijving, niveau
    - Evaluatiemoment schedule: auto-populated 25%/50%/75%, dates calculated
    - Buttons to open evaluation modals (same as Detail page Evaluaties tab)
  - **Documenten tab:**
    - Links to uploaded/generated documents:
      - pok_document_url (original PDF)
      - pok_ondertekend_volledig_url (signed multi-signature PDF)
      - archief_url (if archived)
  - Bottom (if not archived): "Archive POK" button (for manual archive, rare case)

---

## Integrations & APIs

- [ ] **SBB Register Integration** — Backend service
  - Scheduled job: nightly sync of SBBErkenning for all org KVKs
  - For each org:
    - Query SBB public API (endpoint TBD)
    - Update erkende_crebos array
    - Update status (geldig|verlopen|ingetrokken)
    - Update erkenningsdatum, vervaldatum
    - Set sbb_synced_at = now, sbb_sync_status = "ok"
  - On error: sbb_sync_status = "error", sbb_sync_error = [message], alert ops
  - Timeout: 30s per org, retry up to 3×
  - Manual sync option: button in UI "Sync SBB Register Now" → triggers on-demand

- [ ] **CREBO Validation on Registration Form** — Backend API endpoint
  - Input: org_kvk, crebo_code
  - Output: {valid: bool, erkening: SBBErkenning object if valid, error: string if invalid}
  - Logic:
    - Query SBBErkenning by kvk
    - Check status = "geldig"
    - Check crebo_code in erkende_crebos array
    - Return result
  - Caching: result cached for 24h (SBB updates nightly)
  - On cache miss: query SBB API live (fallback to real-time if needed)

- [ ] **RVO Subsidieregeling API Integration** — Backend service
  - On 31 juli (configurable): generate SubsidieAanvraagPraktijkleren concepts (see REQ-SBA-007)
  - On Finance approval: submit batch to RVO API
    - Endpoint: rvo.nl/api/subsidieaanvraag (TBD)
    - Payload: array of {bbl_leerling_id, bedrag_aangevraagd, bewijsstukken URLs, ...}
    - Response: {rvo_referentienr, status, message}
    - Update SubsidieAanvraagPraktijkleren.rvo_referentienr, status = "ingediend"
  - Polling job: daily check RVO status for all "ingediend" aanvragen
    - Query RVO API by referentienr
    - Update bedrag_toegekend, uitkeringsdatum if goedgekeurd
    - Update status (goedgekeurd|afgewezen)
  - Error handling: status = "submission_error", notify Finance

- [ ] **Payroll Engine Integration** — API queries + mutations
  - On BBL registration:
    - Query BBL-staffel-jaar-1 amount from payroll-engine-nl (by CAO + region + year)
    - Prefill Employee.salaris on creation
  - On BBL staffel-progression (REQ-SBA-006):
    - Query BBL-staffel-jaar-2 amount
    - Create payroll_mutation record
    - Finance approval → mutation applies in next loonrun
  - Implementation: via payroll-engine-nl API or direct config table (TBD)

- [ ] **Contract Management Integration** — POK as contract type
  - POK stored in contract-management module (if contract-management exists, else hrmq-native)
  - Signing flow: reuse contract-management digital-signing workflow
  - Archivering: contract-management enforces Archiefwet retention (7 years)
  - Document storage: POK PDFs stored in contract-management document vault

- [ ] **Document Storage Integration** — PDF upload & archival
  - On POK creation: create document folder in document-storage for this POK
  - On POK signing: upload multi-signature PDF to folder
  - On POK archive (REQ-SBA-010): move folder to archive vault, set retention until archivering_verwijderplandatum
  - Manual upload: stagebegeleider can upload urenregistratie, evaluatierapporten, diploma PDFs
  - Integration: via document-storage API (endpoints TBD)

- [ ] **Task Management Integration** — Create/close tasks
  - Instroom blockers (REQ-SBA-004, REQ-SBA-008): create tasks with priority HIGH
  - Evaluation reminders (REQ-SBA-009): create tasks T-7 days before evaluatie_punten.geplande_datum
  - Subsidie deadline reminders (REQ-SBA-007): create task T-30 days before 16 september
  - On Stagiair/BBL exit (REQ-SBA-010): optional task for HR to offer vaste aanstelling (if configurable)
  - Task closure: automatic when user marks evaluation complete, or when HR resolves blocker
  - Integration: via task-management API (create, close, query endpoints)

- [ ] **Employee Master Integration** — Foreign key references & creation
  - On Stagiair registration: no Employee record created; only refs for stagebegeleider_intern_id
  - On BBL registration: create Employee record with contractvorm = "bbl-arbeidsovereenkomst"
    - Use BBL voornaam, achternaam, bsn, geboortedatum
    - Employee.organisatie_id = BBLLeerling.roc_instelling_id (or org's own ID, TBD)
    - Employee.startdatum = BBLLeerling.startdatum
    - Employee.contracttype = "bbl-arbeidsovereenkomst"
    - Employee.salaris = BBL-staffel-jaar-1 amount
  - On BBL exit (REQ-SBA-010): set Employee.status = "uit-dienst", Employee.exit_date = BBLLeerling.einddatum
  - Integration: via employee-master API or direct SQL (TBD)

---

## Scheduled Jobs & Automation

- [ ] **Evaluation Reminder Job** — Runs daily at 08:00 CET
  - Query all active Stagiair & BBLLeerling records
  - For each, check evaluatie_punten where geplande_datum = today
  - Create task in task-management: "Voortgangsevaluatie [moment%] [name]"
  - Assign to stagebegeleider_intern_id or praktijkbeoordelaar_id (depending on entity type)
  - Log: created [N] tasks

- [ ] **POK Signature Blocker Job** — Runs daily at 09:00 CET
  - Query all Stagiair & BBLLeerling with startdatum = today + 7 days
  - For each, check pok_ondertekening_status ≠ "compleet"
  - If not complete: create HIGH-priority task: "POK niet compleet — [name]. Instroom kan niet plaatsvinden. Handtekeningen ontbreken: [list missing parties]"
  - Assign to stagebegeleider_intern_id
  - Log: created [N] blockers

- [ ] **Verzekering Validation Job** — Runs daily at 10:00 CET
  - Query all Stagiair & BBLLeerling with startdatum = today + 3 days
  - For each, check verzekering_status in ["niet_geverifieerd", "afgewezen"]
  - If not verified: create HIGH-priority task: "Verzekering niet geverifieerd — [name]. Instroom [startdatum]."
  - Assign to HR-admin role (or config TBD)
  - Log: created [N] reminders

- [ ] **Subsidie Aanvraag Generation Job** — Runs on 31 juli at 00:01 CET
  - Query all BBLLeerling with:
    - startdatum <= 31 juli - 40 weeks (i.e., at least 40 weeks in progress)
    - status ≠ archived | exited (check archivedAt = null)
  - For each: create SubsidieAanvraagPraktijkleren (concept)
    - bbl_leerling_id, studiejaar = "2025-2026" (current academic year), bedrag_aangevraagd = 2700
    - Fetch bewijsstukken from document-storage & POK
    - Status = "concept"
  - Create task: "Subsidie Praktijkleren aanvraag gereed voor [count] leerlingen. Deadline: 16 september. Review & submit bij RVO."
  - Assign to Finance-admin role
  - Log: created [count] aanvragen, [count] tasks

- [ ] **SBB Register Sync Job** — Runs nightly at 03:00 CET
  - Query all unique org KVKs from SBBErkenning + Stagiair + BBLLeerling tables
  - For each KVK: call SBB API (nightly bulk endpoint TBD)
    - Fetch current erkenningen + crebos
    - Update SBBErkenning records
    - Update status if changed
  - Log: synced [N] orgs, [N] errors, alert ops if any API failures

- [ ] **RVO Subsidie Status Poll Job** — Runs daily at 11:00 CET
  - Query all SubsidieAanvraagPraktijkleren with status = "ingediend"
  - For each, call RVO API to check status by rvo_referentienr
  - Update bedrag_toegekend, uitkeringsdatum if status changed to "goedgekeurd"
  - Update status if changed to "afgewezen"
  - Send email to Finance if status = "goedgekeurd" (reminder to process payment)
  - Log: polled [N] aanvragen, [N] status updates

---

## Reporting & Dashboards

- [ ] **Stagiair & BBL Dashboard** — React component, accessible from "Medewerkers › Stagiairs & BBL"
  - **KPI cards:**
    - Active Stagiairs (count by status = active)
    - Active BBLs (count by status = active)
    - Completions this month (count by diploma_behaald = true, exit_date in current month)
    - Subsidie revenue (count × €2.700 for completed BBLs this FY)
    - Evaluations overdue (count of voltooide_datum = null, geplande_datum < today)
    - POK incomplete (count of pok_ondertekening_status ≠ "compleet", startdatum <= T+7)
  - **Charts:**
    - Stagiairs by level (HBO / WO / MBO-BOL) — pie/bar
    - BBLs by niveau (2 / 3 / 4) — pie/bar
    - Placements by month (line chart, monthly counts)
    - Staffel progression (BBLs in year 1 / 2 / 3) — stacked bar
  - **Tables:**
    - "Evaluaties achterstand" — list of active stagiairs/BBLs with overdue evaluations
    - "POK incompleet" — list with signatures pending, contact action items
    - "Subsidie aanvragen" — list by status (concept / ingediend / goedgekeurd / afgewezen)
  - **Export:** CSV/Excel for all tables

- [ ] **Finance Dashboard — Subsidie Praktijkleren Module**
  - Table: SubsidieAanvraagPraktijkleren by status
  - Filters: studiejaar, status (concept / ingediend / goedgekeurd / afgewezen / uitgekeerd)
  - Columns: leerling name, studiejaar, bedrag_aangevraagd, bedrag_toegekend, status, rvo_referentienr, actions
  - Actions: "View details" (link to POK), "Submit to RVO" (if concept), "Mark as received" (if goedgekeurd)
  - KPI: Total aangevraagd, Total goedgekeurd, Total ingediend

- [ ] **Opleidingscoordinator Dashboard — Capacity Planning**
  - Heatmap: leerbedrijven × months, colored by active BBL count
  - Table: SBBErkenning by status (geldig / verlopen / ingetrokken), erkende CREBO count, praktijkopleider
  - Action: "Request renewal" (if status = verlopen)

---

## Compliance & Audit

- [ ] **Audit Trail Implementation** — Backend data structure + API
  - Create audit_logs table: entity_type, entity_id, action (create|update|delete), old_value, new_value, field, user_id, timestamp, context
  - On all CRUD operations: append row to audit_logs
  - Immutable after 5 minutes (prevent deletion via trigger / application logic)
  - API endpoint: GET /audit-logs?entity_type=Stagiair&entity_id=[id] → returns ordered list
  - UI component: "Audit Trail" tab in Stagiair/BBL detail, shows versioned timeline

- [ ] **Archiefwet Compliance — 7 Year Retention**
  - On POK archive (REQ-SBA-010): set archivering_startdatum = now, archivering_verwijderplandatum = now + 7 years
  - Scheduled job (T-3 months before verwijderplandatum): send compliance notification to Directie
  - Job (on verwijderplandatum): archive soft-deletes POK + associated documents (or move to cold storage, TBD)
  - Manual override: Compliance officer can extend retention if litigation/audit holds

- [ ] **AVG/GDPR Compliance**
  - BSN encryption at rest (use django/framework crypto, TBD)
  - BSN only stored if (Stagiair.stagevergoeding > 0) OR (BBL)
  - Deletion: on Stagiair record delete, BSN is purged; on soft-archive (archivedAt set), BSN retained only for 7y then purged
  - Right to be forgotten: on AVG DSR, anonymize Stagiair with stagevergoeding = 0 (mask BSN)
  - Audit logging: all BSN access logged with user + timestamp

---

## Testing

- [ ] **Unit Tests — Entities**
  - Stagiair validation: dates (startdatum < einddatum), BSN format, stagevergoeding <= fiscal limit
  - BBLLeerling validation: werknemer_id unique, crebo_code → SBBErkenning check
  - POK validation: at least one of (stagiair_id, bbl_leerling_id) set, three-party timestamps consistent
  - SBBErkenning validation: status in [geldig|verlopen|ingetrokken], crebos is array of strings

- [ ] **Integration Tests — Workflows**
  - Stagiair registration → POK creation → evaluation scheduling (REQ-SBA-001, REQ-SBA-009)
  - BBL registration → Employee creation → payroll integration (REQ-SBA-002)
  - CREBO validation on BBL registration (REQ-SBA-003)
  - POK three-party signing flow (REQ-SBA-004)
  - Stagevergoeding validation & warning (REQ-SBA-005)
  - BBL staffel progression & payroll mutation (REQ-SBA-006)
  - Subsidie aanvraag generation & RVO submission (REQ-SBA-007)
  - Verzekering status blocking (REQ-SBA-008)
  - Evaluation completion & audit trail (REQ-SBA-009)
  - Exitstroom & archival (REQ-SBA-010)

- [ ] **Acceptance Tests — User Journeys**
  - HR-admin: registreer Stagiair, upload evaluaties, mark complete
  - Stagebegeleider: view actieve plaatsingen, register evaluaties on schedule, sign POK
  - Finance: view subsidie-aanvragen, submit batch to RVO, track uitkeringsstatus
  - Opleidingscoordinator: monitor SBB-erkenningen, capacity planning heatmap

---

## Documentation

- [ ] **User Guide — HR-admin**
  - How to register Stagiair vs. BBL (decision tree)
  - POK setup & signing workflow
  - Common errors (CREBO validation, verzekering missing)
  - Archival & retention

- [ ] **User Guide — Stagebegeleider**
  - How to view assigned plaatsingen
  - Evaluation reminder workflow
  - Signposting when evaluations overdue
  - Submitting eindbeoordeling

- [ ] **User Guide — Finance-admin**
  - Subsidie dashboard navigation
  - RVO submission batch workflow
  - Stagevergoeding processing (no tax, no premies)
  - BBL salary step-up approval

- [ ] **API Documentation**
  - OpenAPI / Swagger spec for all endpoints
  - SBB integration contract (CREBO lookup)
  - RVO integration contract (batch submission, polling)
  - payroll-engine-nl BBL-staffel queries

- [ ] **Data Model Documentation**
  - Entity relationship diagram (Stagiair ← POK → BBL, SBBErkenning refs)
  - Field definitions, constraints, indexes

