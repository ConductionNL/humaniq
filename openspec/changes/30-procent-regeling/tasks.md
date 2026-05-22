---
status: draft
---

# 30%-regeling Administratie — Implementation Tasks

## 1. Data Model & Schema Registration

### 1.1 OpenRegister schema definitions

- [ ] Create `Beschikking30` schema in `lib/Settings/hrmq_register.json` with properties:
  - `id` (string, required, identifier)
  - `medewerker_id` (string, required, relation to medewerker)
  - `administratie_id` (string, required, scoped)
  - `beschikkingsnummer` (string, required, unique per administratie)
  - `beschikkingsdatum` (date, required)
  - `vanaf` (date, required, "first workday in NL where 30% applies")
  - `tot` (date, required, "final day of 30%-eligibility")
  - `oorspronkelijke_looptijd_jaren` (integer, required, 5 or 8)
  - `categorie` (enum: regulier/jonge_onderzoeker/wetenschappelijk_onderzoeker, required)
  - `partial_non_resident_gekozen` (boolean, default false)
  - `salarisdrempel_van_toepassing` (decimal, required, e.g. 46660)
  - `bron_document_uri` (string, nullable, reference to gescande beschikking in document-vault)
  - `aanvrager_intern` (string, required, audit trail — which HR-medewerker registered it)
  - `status` (enum: aangevraagd/toegekend/afgewezen/actief/verlopen/ingetrokken, required)
  - Add `x-openregister-lifecycle` with states: aangevraagd → actief → verlopen/ingetrokken

- [ ] Create `Beschikking30Periode` schema with properties:
  - `id`, `beschikking_id` (relation), `jaar` (integer), `percentage` (30/20/10), `salarisdrempel_jaar`, `wnt_norm_jaar`, `actief` (boolean)
  - Relation: beschikking → periodes (1:N)

- [ ] Create `Beschikking30Toetsing` schema with properties:
  - `id`, `beschikking_id`, `toetsingsdatum` (date), `toetsingsperiode` (year, e.g. "2025"), `bruto_loon_excl_30` (decimal), `bruto_loon_geannualiseerd` (decimal), `drempel_gehaald` (boolean), `wnt_grens_overschreden` (boolean), `aftopping_bedrag` (decimal), `conclusie` (enum: continueren/intrekken/aanpassen), `door_gebruiker_id`, `automatisch` (boolean)
  - Relation: beschikking → toetsingen (1:N)

- [ ] Create `Beschikking30Intrekking` schema with properties:
  - `id`, `beschikking_id`, `intrekkingsdatum` (date), `effectieve_datum` (date, may be retroactive), `reden` (enum: drempel_niet_gehaald/dienstverband_eindigt/expat_verhuist_terug/wijziging_functie/wettelijke_wijziging/handmatig), `correctieaangifte_vereist` (boolean), `terugbetaling_door_werknemer_bedrag` (decimal), `boeking_journaalpost_id` (string, nullable, links to accounting system)
  - Relation: beschikking → intrekkingen (1:N)

- [ ] Create `Beschikking30LoonImpact` schema with properties:
  - `id`, `loonrun_id` (string), `beschikking_id`, `medewerker_id`, `bruto_loon_periode` (decimal), `percentage_toegepast` (30/20/10), `vergoeding_30_bedrag` (decimal), `vergoeding_30_grondslag_excl_wnt_aftopping` (decimal), `wnt_aftopping_bedrag` (decimal), `effectief_belastingvoordeel_werknemer` (decimal, informational), `bron` (enum: gewone_loonrun/13e_maand/vakantiegeld/bonus)
  - Relation: beschikking → loon-impacts (1:N)

- [ ] Create `Beschikking30AlertConfig` schema with properties:
  - `id`, `administratie_id`, `looptijd_einde_waarschuwing_dagen_vooraf` (integer, default 180), `drempel_marge_percentage` (decimal, default 5), `wnt_marge_percentage` (decimal, default 10), `actief` (boolean)
  - Relation: administratie → alert-configs (1:1)

- [ ] Add seed data (3-5 example objects per schema) to register file with realistic Dutch names, postcodes, KVK-codes (see design.md Seed Data section)

### 1.2 Entity mappers & lifecycle handlers

- [ ] Create `Beschikking30Mapper` (CRUD operations via OpenRegister ObjectService)
- [ ] Create `Beschikking30PeriodeMapper`, `Beschikking30ToetsingMapper`, `Beschikking30IntrekkingMapper`, `Beschikking30LoonImpactMapper`, `Beschikking30AlertConfigMapper`
- [ ] Register lifecycle guards for `x-openregister-lifecycle` states if custom preconditions required (e.g. guard that blocks transition to "actief" if looptijd validation fails)

### 1.3 Deduplication check

- [ ] Verify no overlap with `employee-master` (no 30%-regeling data stored in employee-master)
- [ ] Verify no overlap with `payroll-engine-nl` (payroll-engine consumes 30%-impact rules but does not store beschikkingen)
- [ ] Document findings: "No deduplication issues; 30%-regeling is a new cohesive module"

---

## 2. Backend Service Layer

### 2.1 Beschikking30Service (registration & validation)

- [ ] Implement `Beschikking30Service` with methods:
  - `registerBeschikking(medewerker_id, administratie_id, beschikking_data): Beschikking30Entity` — validates looptijd (≤5 jaar for nieuwe gevallen, ≤8 jaar for pre-2019 overgangsrecht), beschikkingsdatum (≤4 months before vanaf), creates entity + auto-generates `Beschikking30Periode` records per afbouwregel (30/30/20/20/10 for 2024+)
  - `validateLooptijd(vanaf_date, tot_date): bool` — returns true if looptijd ≤5 (or ≤8 if overgangsrecht applies)
  - `validateBeschikkingsdatum(beschikkingsdatum, vanaf_date): bool` — returns true if gap ≤4 months
  - `createPeriodes(beschikking_id, vanaf, tot): array` — generates annual Beschikking30Periode records with correct percentages per Belastingplan 2024 rules
  - `getBeschikkingsByMedewerker(medewerker_id): array` — returns active/pending beschikkingen for a medewerker
  - `getActiveBeschikking(medewerker_id, date): Beschikking30Entity|null` — returns the valid beschikking on a given date, or null if expired

- [ ] Implement validation rules (REQ-030-001 scenarios) with detailed error messages in Dutch

### 2.2 Beschikking30ToetsingService (annual re-validation)

- [ ] Implement `Beschikking30ToetsingService` with methods:
  - `runAnnualToetsing(beschikking_id, toetsingsjaar): Beschikking30Toetsing` — calculates YTD-loon, applies parttime-correctie, compares to drempel, creates toetsing-record
  - `calculateAnnualizedLoon(medewerker_id, toetsingsjaar): decimal` — sums fiscal loon from `Beschikking30LoonImpact` records, handles verlof-exceptions (ouderschapsverlof, geboorteverlof, langdurig-ziekteverzuim)
  - `evaluateDrempel(annualized_loon, drempel, categorie): bool` — returns true if loon ≥ drempel
  - `autoIntrekIfFailed(beschikking_id, toetsing_result): void` — if drempel failed, creates Beschikking30Intrekking and triggers correctieaangifte workflow
  - Scheduled job `Beschikking30ToetsingJob` (runs January 1st + on dienstverband-end) calling `runAnnualToetsing` for all active beschikkingen

- [ ] Implement parttime-correctie per REQ-030-004: no pro-rata reduction except for specific verlof-types

### 2.3 Beschikking30LoonImpactService (monthly 30%-calculation)

- [ ] Implement `Beschikking30LoonImpactService` with method:
  - `evaluateForMedewerker(medewerker_id, loonrun_id, bruto_loon_periode, bron): Beschikking30LoonImpactDTO` — calculates 30%-vergoeding for a single loonperiode
    1. Fetch active beschikking + current periode (year-dependent percentage)
    2. Calculate `vergoeding_30_bedrag = bruto_loon_periode × percentage_toegepast`
    3. Apply WNT-aftopping: cap grondslag to WNT-norm for the year (e.g. €246k for 2026)
    4. If bruto > WNT-norm: adjust vergoeding down and track `wnt_aftopping_bedrag`
    5. Return DTO: {vergoeding_30_bedrag, wnt_aftopping_bedrag, percentage_toegepast, grondslag_used}
  - `saveLoonImpact(medewerker_id, loonrun_id, beschikking_id, dto): Beschikking30LoonImpact` — persists the record for audit trail

- [ ] Integrate with payroll-engine-nl: expose HTTP endpoint `/hrmq/loon-impact/evaluate` (POST) that payroll-engine calls per medewerker per loonperiode

- [ ] Implement WNT-norm per REQ-030-006: fetch current WNT-norm from `Beschikking30Periode.wnt_norm_jaar` (indexed annually per ministry decision)

### 2.4 Beschikking30IntrekkingService (revocation & correction filing)

- [ ] Implement `Beschikking30IntrekkingService` with method:
  - `revokeBeschikking(beschikking_id, effectieve_datum, reden, hr_medewerker_id): Beschikking30Intrekking` — creates intrekking-record, recalculates all affected loon-impacts retroactively, triggers correctieaangifte
  - `recalculateLoonImpacts(beschikking_id, from_date, to_date): void` — updates all `Beschikking30LoonImpact` records in range: 30%-vergoeding → belastingplichtig loon, recompute taxes
  - `calculateTerugbetaling(beschikking_id, from_date, to_date): decimal` — sums total 30%-vergoeding over-received that must be corrected

- [ ] Queue workflow to `journaalpost-export` for boekhoudpakket correction-posting

- [ ] Implement terugwerkende kracht logic per REQ-030-007: allow retroactive intrekking to any date >= beschikking start

### 2.5 Beschikking30AlertService (expiry + drempel-risk alerts)

- [ ] Implement `Beschikking30AlertService` with methods:
  - `checkLooptijdEindeAlerts(): void` — runs daily; for each beschikking ending within `alert_config.looptijd_einde_waarschuwing_dagen_vooraf`, creates action-item for hr_manager
  - `checkLooptijdEindeEscalation(): void` — runs daily; if 30-day warning exists but no action taken, escalates to administratie_owner
  - `checkYTDDrempelRisk(medewerker_id): void` — runs daily; calculates YTD-loon, annualizes, compares to drempel; if within `alert_config.drempel_marge_percentage`, generates alert for HR
  - Scheduled jobs (cron @ daily for looptijd, daily for YTD) that call these methods

- [ ] Integrate with NotificationService: alerts sent via in-app + email notification

### 2.6 Beschikking30ExportService (bewijspakket PDF)

- [ ] Implement `Beschikking30ExportService` with method:
  - `generateBewijspakketPDF(administratie_id, medewerker_id_list, year_from, year_to): PDF` — generates single PDF containing per-medewerker sections with:
    1. Cover page: administratie, medewerkers, date-range, export-date
    2. Per-medewerker: gescande beschikking (from document-vault), all Periode records, all Toetsing records, all LoonImpact records, all Intrekking records
    3. Summary table: medewerker, beschikking-nr, jahre, status, next-toetsing-date
  - PDF is digitally signed (verifiable audit-trail)
  - If gescande beschikking missing: red-flag note in PDF

- [ ] Expose HTTP endpoint `GET /hrmq/beschikking30/export/bewijspakket?administratie_id=...&medewerker_ids=...&year_from=...&year_to=...` → downloads PDF

---

## 3. API & Integration Endpoints

### 3.1 REST routes for beschikking CRUD

- [ ] `POST /api/hrmq/beschikkingen30` — create new beschikking
- [ ] `GET /api/hrmq/beschikkingen30/{id}` — fetch beschikking details
- [ ] `PUT /api/hrmq/beschikkingen30/{id}` — update beschikking (with re-validation if dates change)
- [ ] `DELETE /api/hrmq/beschikkingen30/{id}` — (soft-delete only; audit trail preserved)
- [ ] `GET /api/hrmq/beschikkingen30?medewerker_id=...&administratie_id=...` — filter/search beschikkingen

### 3.2 REST routes for toetsing & intrekking

- [ ] `GET /api/hrmq/beschikkingen30/{id}/toetsingen` — list toetsing-records for a beschikking
- [ ] `POST /api/hrmq/beschikkingen30/{id}/intrek` — manually revoke beschikking (creates Intrekking + triggers correctieaangifte)

### 3.3 Loon-impact webhook integration with payroll-engine-nl

- [ ] `POST /api/hrmq/loon-impact/evaluate` — expects payload: `{medewerker_id, loonrun_id, bruto_loon_periode, bron}`, returns `{vergoeding_30_bedrag, wnt_aftopping_bedrag, percentage_toegepast}`
- [ ] Payroll-engine-nl calls this endpoint for each medewerker per loonperiode

### 3.4 Document-vault integration

- [ ] `POST /api/hrmq/beschikkingen30/{id}/upload-beschikking` — upload gescande beschikking (PDF), store in document-vault with 7-year retention flag
- [ ] Endpoint validates file type (PDF), size (<10MB), stores with metadata (medewerker_id, beschikking_id, upload_date, uploader_id)

---

## 4. Frontend Components & Views

### 4.1 Beschikking-registration form

- [ ] Create `CnBeschikkingRegistrationForm` (or use auto-generated `CnFormDialog` from schema)
- [ ] Form fields:
  - Medewerker (searchable dropdown, from employee-master)
  - Administratie (dropdown, multi-tenant scoped)
  - Beschikkingsnummer (text input, unique validation)
  - Beschikkingsdatum (date picker, with tooltip "issued by Belastingdienst")
  - Vanaf (date picker, with tooltip "first NL workday for 30%")
  - Tot (date picker, auto-calculated from "looptijd_jaren" or manual entry)
  - Looptijd Jahren (select: 1-8, updates "Tot" field)
  - Categorie (radio: regulier / jonge_onderzoeker / wetenschappelijk_onderzoeker)
  - Partial-Non-Resident (checkbox, with deprecation warning if future-dated)
  - Upload Gescande Beschikking (file-upload button)
  - Submit button with inline validation feedback

- [ ] Form validation (client-side + server-side):
  - Looptijd ≤5 (warn if >5, allow only if pre-2019 overgangsrecht)
  - Beschikkingsdatum ≤4 months before vanaf
  - Vanaf < Tot
  - Required fields highlighted

### 4.2 Medewerker-detail sidebar panel

- [ ] Create component `CnBeschikkingDetailPanel` shown in medewerker-detail view
- [ ] Displays:
  - Active beschikking summary (beschikkingsnummer, vanaf, tot, categorie, status)
  - Countdown to expiry (e.g. "Expires in 127 days")
  - YTD-loon progress toward drempel (visual bar chart, current vs threshold)
  - Last toetsing result (pass/fail, date)
  - Any active alerts (expiry warning, drempel-risk, PNR-deprecation)
  - Action buttons: view-full-beschikking, upload-gescande-beschikking, manual-intrek (HR-only)

### 4.3 Administratie-dashboard widget

- [ ] Create `CnBeschikking30DashboardWidget` for administratie overview
- [ ] Displays:
  - Total count: medewerkers with active beschikkingen
  - Upcoming expiries (next 180 days) — sortable list
  - At-risk medewerkers (drempel-risk YTD or WNT-overage) — sortable list
  - Failed toetsingen (intrekkingen triggered in last 30 days) — with correction-status links
  - Export button: "Generate Bewijspakket for audit"

### 4.4 Self-service portal views

- [ ] Expose in medewerker self-service:
  - My 30%-Beschikking status (if applicable): beschikkingsnummer, expiry-date, PNR-choice, last-toetsing-outcome
  - YTD-loon progress chart (if at-risk, show warning)
  - Action required: if PNR=true and 2027+ date, show deprecation warning

---

## 5. Background Jobs & Scheduling

### 5.1 Annual toetsing job

- [ ] Implement `Beschikking30ToetsingJob` (cron: January 1st, 00:30 UTC)
- [ ] For each administratie, for each medewerker with active beschikking:
  1. Calculate annualized loon (via Beschikking30ToetsingService)
  2. Evaluate drempel-pass/fail
  3. If fail: auto-intrek, trigger correctieaangifte
  4. Create Beschikking30Toetsing record
  5. Notify HR via NotificationService

### 5.2 Daily alert jobs

- [ ] Implement `Beschikking30LooptijdAlertJob` (cron: daily @ 06:00 UTC)
  - Checks all beschikkingen for expiry-date ± alert-threshold
  - Creates action-items + escalations

- [ ] Implement `Beschikking30DrempelRiskJob` (cron: daily @ 06:00 UTC)
  - For each medewerker with active beschikking: calculates YTD-loon, annualizes, checks drempel-risk
  - Creates alerts if within risk-margin

---

## 6. Testing & Validation

### 6.1 Unit tests for service methods

- [ ] Test `Beschikking30Service.validateLooptijd()` — all scenarios (5-year ok, 6-year fail, 8-year overgangsrecht ok)
- [ ] Test `Beschikking30Service.createPeriodes()` — verifies correct percentage allocation (30/30/20/20/10)
- [ ] Test `Beschikking30ToetsingService.calculateAnnualizedLoon()` — with/without verlof-exemptions
- [ ] Test `Beschikking30LoonImpactService.evaluateForMedewerker()` — WNT-aftopping scenarios, parttime-factor
- [ ] Test `Beschikking30IntrekkingService.revokeBeschikking()` — retroactive recalculation of loon-impacts
- [ ] Test `Beschikking30AlertService` — alert-timing, escalation logic
- [ ] Test `Beschikking30ExportService.generateBewijspakketPDF()` — PDF structure, completeness

### 6.2 Integration tests

- [ ] Test payroll-engine-nl integration: call loon-impact webhook, verify correct return values
- [ ] Test intrekking workflow: revoke beschikking → auto-generate correctieaangifte → queue for Digipoort
- [ ] Test document-vault integration: upload gescande beschikking, verify 7-year retention flag

### 6.3 Compliance & audit tests

- [ ] Verify all Beschikking30-mutations create immutable audit-trail (via AuditTrailService)
- [ ] Verify bewijspakket PDF includes all required records for Belastingdienst-audit
- [ ] Verify correctieaangifte generation + Digipoort-filing workflow

### 6.4 Browser/UI tests

- [ ] Test beschikking-registration form: validation feedback, looptijd-calculation, upload
- [ ] Test medewerker-detail sidebar: displays active beschikking, expiry-countdown, YTD-progress
- [ ] Test administratie-dashboard: widget loads, lists expiries + at-risk + failed

---

## 7. Documentation & Compliance

### 7.1 Schema documentation

- [ ] Add PHPDoc comments to all `Beschikking30*Service` classes and public methods
  - Tag each class/method with `@spec openspec/changes/30-procent-regeling/tasks.md#{task-id}`
  - Document parameter types, return values, business-rule constraints

### 7.2 Regulatory compliance notes

- [ ] Document in design.md or a separate `COMPLIANCE.md`:
  - References to Wet LB 1964 art. 31a, Uitvoeringsbesluit LB art. 10ea-10ej
  - Afbouw rules per Belastingplan 2024 (30/20/10)
  - WNT-norm capping per Wet normering topinkomens
  - Partial-non-resident deprecation per Belastingplan 2026 (pending)

### 7.3 API documentation

- [ ] Generate OpenAPI spec for all REST endpoints (loon-impact, beschikking CRUD, export)
- [ ] Document webhook contract for payroll-engine-nl integration

---

## 8. Deployment & Seed Data

### 8.1 Schema import & seed data load

- [ ] Register schemas + seed data in `lib/Settings/hrmq_register.json` (per ADR-001)
- [ ] On first install: `ConfigurationService::importFromApp('hrmq', register_data, version, force: false)` loads schemas + seed objects

### 8.2 Migration path for existing systems

- [ ] If migrating from legacy spreadsheet/manual tracking:
  - Provide data-import template (CSV → Beschikking30 entity)
  - Or manual one-time import script to backfill historical beschikkingen/toetsingen/loon-impacts from audit logs

---

## Completion Checklist

- [ ] All 6 entity schemas registered in OpenRegister
- [ ] All 6 mappers implemented (CRUD via ObjectService)
- [ ] Beschikking30Service: registration, validation, periode-generation
- [ ] Beschikking30ToetsingService: annual re-validation, parttime-correctie, intrekking-trigger
- [ ] Beschikking30LoonImpactService: monthly 30%-calculation, WNT-aftopping
- [ ] Beschikking30IntrekkingService: revocation + correctieaangifte workflow
- [ ] Beschikking30AlertService: expiry + drempel-risk alerts + escalations
- [ ] Beschikking30ExportService: bewijspakket PDF generation
- [ ] All REST endpoints implemented and documented
- [ ] Payroll-engine-nl webhook integration (both sides)
- [ ] Document-vault integration (upload gescande beschikking)
- [ ] All 4 frontend components (registration-form, medewerker-sidebar, administratie-dashboard, self-service)
- [ ] All 2 background jobs (toetsing, alerts)
- [ ] Unit + integration tests passing
- [ ] API documentation (OpenAPI spec)
- [ ] Compliance documentation (regulatory references)
- [ ] Seed data loaded on install
- [ ] All code tagged with `@spec` PHPDoc comments
