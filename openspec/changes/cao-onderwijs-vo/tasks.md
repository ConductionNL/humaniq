---
status: proposed
change: cao-onderwijs-vo
version: 1.0
---

# Tasks: CAO Voortgezet Onderwijs

## Phase 1: Core Data Model & Table Management

- [ ] **T1.1** Create `hrmq_cao_vo_schaal_table` migration script.
  - Columns: id, cao_version, geldig_vanaf, geldig_tot, schaal (enum), trede (int), bruto_maandloon_fulltime, bruto_jaarloon_fulltime, created_at, created_by.
  - Indexes: (cao_version, geldig_vanaf, schaal, trede), (geldig_tot).
  - Seed: 2024-2026 CAO tables (LA/LB/LC/LD/LE, 1–20 tredes).

- [ ] **T1.2** Create `hrmq_cao_vo_arbeidsmarkttoelage_table` migration script.
  - Columns: id, cao_version, geldig_vanaf, geldig_tot, vakgebied (varchar), toelage_pct (decimal).
  - Indexes: (cao_version, geldig_vanaf, vakgebied).
  - Seed: 2024-2026 toelage rates (wiskunde, nask, Duits, Frans, informatica).

- [ ] **T1.3** Create `hrmq_cao_vo_employee_extension` migration script.
  - Columns: id, employee_id (fk), organisation_id (fk), schaal (enum), trede (int), laatste_periodiek_datum, vakgebieden (jsonb), bevoegdheid (enum), lerarenregister_id, lerarenregister_geldig_tot, lerarenregister_checked_at, taakomvang_lesuren_per_jaar, taakomvang_overige_uren, vakvolledigheid_pct, bapo_regeling (enum), bapo_omvang_uren, uitloopschaal (bool), trede_blokkering_reden, created_at, updated_at.
  - Indexes: (employee_id), (organisation_id), (lerarenregister_id).
  - Foreign key: employee(id).

- [ ] **T1.4** Create `hrmq_cao_vo_school` migration script.
  - Columns: id, organisation_id (fk), brin_nummer, vestigingsnummer, bestuursnummer, leerlingen_teldatum, leerlingen_aantal_per_onderwijssoort (jsonb), bekostigingsbedrag_per_kwartaal (decimal), created_at, updated_at.
  - Indexes: (organisation_id), (brin_nummer).
  - Foreign key: organisation(id).

- [ ] **T1.5** Create `hrmq_cao_vo_taakverdeling` migration script.
  - Columns: id, employee_id (fk), schooljaar (varchar), lesuren_per_vak (jsonb), taakuren_per_taak (jsonb), overschrijdingstoeslag_uren (int), goedkeuring_docent_at, created_at, updated_at.
  - Indexes: (employee_id, schooljaar).
  - Foreign key: employee(id).

- [ ] **T1.6** Create `hrmq_cao_vo_vervanging` migration script.
  - Columns: id, employee_id (fk), absence_start_date, absence_end_date, absence_reason (enum), claim_status (enum), claim_bedrag (decimal), vfpf_referentie (varchar), created_at, updated_at.
  - Indexes: (employee_id), (claim_status), (vfpf_referentie).
  - Foreign key: employee(id).

- [ ] **T1.7** Create `hrmq_cao_vo_duo_bekostiging` migration script.
  - Columns: id, organisation_id (fk), kwartaal (int 1–4), schooljaar (varchar), verwacht_bedrag, ontvangen_bedrag, verschil, verschil_reden (varchar), created_at, updated_at.
  - Indexes: (organisation_id, kwartaal, schooljaar).
  - Foreign key: organisation(id).

- [ ] **T1.8** Implement admin UI: "Configuratie › CAO's & regelingen › Schaal-tabellen"
  - Display active & historical tables (geldig_vanaf / geldig_tot).
  - Import button: XLSX/JSON upload; parse rows; validate (no duplicate trede per schaal, geldig_vanaf > prev_geldig_tot).
  - On import: auto-close previous active table (set geldig_tot = day before new geldig_vanaf).
  - Audit log: who, when, how many rows.

- [ ] **T1.9** Implement admin UI: "Configuratie › CAO's & regelingen › Arbeidsmarkttoelage"
  - Display toelage rates per vakgebied (current & historical).
  - Import similar to schaal-table.

- [ ] **T1.10** Implement employee_extension admin view (HR-admin).
  - Search/filter by employee, schaal, bevoegdheid status.
  - Edit form: schaal, trede, vakgebieden, bevoegdheid, taakomvang.
  - Bevoegdheid edit: dropdown + "Check Lerarenregister" button (manual verification).

---

## Phase 2: Payroll Integration & Periodieke Verhoging

- [ ] **T2.1** Implement payroll-hook: Periodieke Verhoging
  - On payroll-run: iterate employees; check `laatste_periodiek_datum + 1 year <= run_date`.
  - If true && trede < max_trede && !uitloopschaal && !trede_blokkering_reden: increment trede, update `laatste_periodiek_datum`.
  - Emit `PeriodiekToegekend` event (employee_id, old_trede, new_trede, effective_date).
  - Log to audit table (employee_id, action, old_value, new_value, timestamp).

- [ ] **T2.2** Implement payroll-hook: Schaal-table lookup
  - On payroll-run: for each employee, query schaal_table WHERE schaal = employee.schaal AND trede = employee.trede AND geldig_vanaf <= run_date AND (geldig_tot IS NULL OR geldig_tot >= run_date).
  - Return bruto_maandloon_fulltime; scale by FTE (employee.fte).
  - Cache in memory for run duration.

- [ ] **T2.3** Implement payroll-hook: Arbeidsmarkttoelage
  - On payroll-run: for each employee.vakgebieden entry, query arbeidsmarkttoelage_table WHERE vakgebied = X AND geldig_vanaf <= run_date.
  - For each vakgebied, retrieve lesuren from taakverdeling (lesuren_per_vak[vakgebied]).
  - Calculate pro-rata: lesuren / sum(all lesuren).
  - Apply toelage: bruto × toelage_pct × pro_rata.
  - Emit loonstrook line-items (one per vakgebied, e.g., "Arbeidsmarkttoelage wiskunde: EUR 72").

- [ ] **T2.4** Implement payroll-hook: Lesuren-cap overschrijding surtax
  - On payroll-run: retrieve taakverdeling (overschrijdingstoeslag_uren).
  - If > 0: calculate surtax = bruto_monthly × surtax_rate × (overschrijdingstoeslag_uren / taakomvang_lesuren).
  - Emit loonstrook line-item: "Overschrijdingstoeslag: EUR XXX".
  - Surtax is SV-loon and pensioengevend.

- [ ] **T2.5** Implement payroll-hook: ABP-OW pension enrolment
  - On payroll-run: check if employee is in cao_vo mode AND contract_type = "vast".
  - Calculate pensionable_salary = MAX(bruto, franchise_2026_EUR18275).
  - Premium = pensionable_salary × 27.9%; employee_share ~10%, employer_share ~18%.
  - Withhold employee_share from net salary.
  - Accumulate employer_share in GL (pension expense).
  - Emit loonstrook line-item: "ABP-OW pensioenaftrek: EUR XXX".

- [ ] **T2.6** Implement payroll-hook: BAPO-korting
  - On payroll-run: check if employee.bapo_regeling = "57plus" or "60plus".
  - Retrieve CAO-BAPO-korting percentage (age-dependent, e.g., 6% for 57+).
  - Apply korting: bruto × korting_pct.
  - Emit loonstrook line-item: "BAPO-korting 57+: -EUR XXX".
  - Note: pension is still withheld on unreduced bruto.

- [ ] **T2.7** Test payroll integration
  - Create test employees with various schaal/trede/vakgebieden combinations.
  - Run payroll; verify loonstrook contains all VO-CAO line-items (toelage, overschrijding, BAPO, pension).
  - Verify schaal-table versioning: switch active table mid-year; confirm payroll uses correct table per date.

---

## Phase 3: Bevoegdheid & Lerarenregister Integration

- [ ] **T3.1** Implement Lerarenregister API client
  - Endpoint: `GET /teacher/{lerarenregister_id}` (to be configured per region/provider).
  - Retry logic: exponential backoff on timeout; fail open with 24h cache TTL.
  - Cache: in memory with TTL; fallback to cached value if API down.
  - Log all calls: timestamp, lerarenregister_id, result (valid/expired/notfound), cached=true/false.

- [ ] **T3.2** Implement bevoegdheid-gate for promotion to LC/LD
  - UI: employee detail page, "Edit Schaal" button.
  - Form: current schaal, target schaal (LC/LD only gated), bevoegdheid display.
  - On form submit with target=LC or LD:
    - Check employee.bevoegdheid IN (eerstegraads, tweedegraads) OR lc_lc_traject_voltooid=true.
    - If onbevoegd && !traject: show error, block submit.
    - Call Lerarenregister API (with caching/retry).
    - If API error: block with "Validatie uitgesteld; probeer morgen".
    - If bevoegdheid expired: show error "Bevoegdheid verlopen".
    - If valid: proceed (T3.3).

- [ ] **T3.3** Implement promotion workflow
  - On successful bevoegdheid validation: update employee_extension.schaal, emit `PromotionApproved` event.
  - Trigger document-template-engine to generate contract addendum.
  - Log audit: old_schaal, new_schaal, promotion_date, lerarenregister_validation_timestamp.

- [ ] **T3.4** Implement daily Lerarenregister validation batch
  - Query: employees with bevoegdheid = eerstegraads/tweedegraads AND lerarenregister_checked_at < 30 days ago.
  - For each: call API (batch if possible).
  - Update lerarenregister_geldig_tot and lerarenregister_checked_at.
  - If expired: alert HR-admin (e.g., "Teacher X bevoegdheid expires 2027-06-30; plan retraining/update").

- [ ] **T3.5** Test Lerarenregister integration
  - Mock API responses: valid, expired, notfound.
  - Verify caching & retry logic.
  - Verify audit logs.

---

## Phase 4: Taakverdeling & Lesuren-Cap

- [ ] **T4.1** Implement taakverdeling form (HR-admin / schooladministratie)
  - Fields: employee, schooljaar, lesuren_per_vak (grid: vak, hours), taakuren_per_taak (grid: task, hours).
  - On input: calculate total lesuren; compare to cap (750 × fte).
  - If total > cap: show warning "Overschrijding: XX uur boven limiet".
  - Button: "Requires teacher sign-off for overschrijding".
  - On submit: if overschrijding, show modal "Ik ga akkoord met XX uur overschrijding".
  - Teacher must acknowledge (signature or checkbox); timestamp recorded as goedkeuring_docent_at.
  - Save: taakverdeling record + overschrijdingstoeslag_uren = total - cap.

- [ ] **T4.2** Implement taakverdeling admin view
  - List: all taakverdeling per schooljaar.
  - Filter: schooljaar, compliance-status (cap OK / overschrijding / awaiting-teacher-sign-off).
  - Bulk action: "Require teacher acknowledgment" (e.g., for taakverdeling created without proper sign-off).

- [ ] **T4.3** Test taakverdeling workflow
  - Create taakverdeling for 1.0 FTE: 750h, 850h, 450h part-time.
  - Verify cap enforcement and surtax calculation.
  - Verify teacher sign-off requirement.

---

## Phase 5: DUO Bekostiging & Vervangingsfonds Integration

- [ ] **T5.1** Implement DUO API client
  - Endpoint: quarterly funding request (to be configured per region).
  - Request payload: BRIN, vestigingsnummer, kwartaal, leerlingen-aantallen per onderwijssoort.
  - Response: verwacht_bedrag.
  - Retry logic: scheduled job with exponential backoff.
  - Error handling: log and alert on persistent failure.

- [ ] **T5.2** Implement quarterly DUO-bekostiging batch job
  - Trigger: 1st of each quarter (1 Jan, Apr, Jul, Oct).
  - For each school (organisation.cao = "vo") with valid BRIN:
    - Query hrmq_cao_vo_school.leerlingen_aantal_per_onderwijssoort.
    - Call DUO API.
    - Create hrmq_cao_vo_duo_bekostiging record: kwartaal, schooljaar, verwacht_bedrag.
  - Log: timestamp, school, amounts, API status.

- [ ] **T5.3** Implement DUO-discrepancy tracking
  - Bank-reconciliation job (external, handled by payroll-engine-nl):
    - Matches incoming payment to hrmq_cao_vo_duo_bekostiging records.
    - Updates ontvangen_bedrag and verschil.
  - Daily job: check |verschil| / verwacht > 5%; create worklist entry for HR-admin.
  - Worklist item: school, kwartaal, discrepancy amount, suggested action (review leerlingen-teldatum).

- [ ] **T5.4** Implement Vervangingsfonds API client
  - Endpoint: claim registration (openconnector).
  - Request payload: BSN, schaal, trede, absence dates, fte, school BRIN.
  - Response: claim ID (vfpf_referentie).
  - Status polling: GET /claim/{vfpf_referentie}; returns status (ingediend/goedgekeurd/uitbetaald/afgewezen), bedrag.

- [ ] **T5.5** Implement sick-leave → Vervangingsfonds-claim trigger
  - On absence record save (employee.absence_type = "ziek", duration > 2 days):
    - Retrieve employee_extension: schaal, trede, fte.
    - Assemble claim payload.
    - Call Vervangingsfonds API (async, non-blocking).
    - Create hrmq_cao_vo_vervanging record: claim_status="ingediend", vfpf_referentie (if API success) or "ingediend_pending" (if API failure).

- [ ] **T5.6** Implement Vervangingsfonds claim-status polling batch
  - Daily/weekly job: query hrmq_cao_vo_vervanging WHERE claim_status IN (ingediend, ingediend_pending, goedgekeurd).
  - For each: poll Vervangingsfonds API.
  - Update claim_status and claim_bedrag.
  - When claim_status = "uitbetaald": create GL entry (school's cost-center, claim_bedrag).
  - Log: timestamp, claim_id, old_status, new_status, bedrag.

- [ ] **T5.7** Implement HR-admin dashboard: Vervangingsfonds claims
  - List: open, approved, paid, rejected claims per school per year.
  - Filter: status, date-range, employee.
  - Columns: employee, absence dates, claim bedrag, status, vfpf-referentie, last-updated.

- [ ] **T5.8** Test DUO & Vervangingsfonds integration
  - Mock API responses.
  - Verify quarterly request assembly & error handling.
  - Verify claim registration & status polling.
  - Verify GL posting on claim payment.

---

## Phase 6: Teacher Self-Service & Audit Trail

- [ ] **T6.1** Implement teacher self-service: Schaal & trede detail
  - Display: current schaal, trede, bruto-salary from schaal-table.
  - Show: last periodieke-verhoging date; countdown to next (in XXX days).
  - Show: bevoegdheid, Lerarenregister expiry, last validation date.
  - Display: CAO-compliance status (lesuren-cap, BAPO, etc.).

- [ ] **T6.2** Implement teacher self-service: Loonstrook detail
  - Show all VO-CAO line-items: bruto, arbeidsmarkttoelage (per vakgebied), overschrijdingstoeslag, BAPO-korting, ABP-pension, loonheffingen, net.
- [ ] **T6.3** Implement teacher self-service: Audit trail
  - Show: all changes to schaal, trede, bevoegdheid, taakverdeling, BAPO.
  - Columns: date, change description (e.g., "Schaal updated LB → LC"), changed_by (HR-admin name or "System"), reason (if provided).
  - Clickable: view full details (old value, new value, timestamp).

- [ ] **T6.4** Implement jaaropgaaf generation & publishing
  - Batch job on 5 Jan: for each employee active in prior calendar year, generate jaaropgaaf document.
  - Contents: name, BSN, schaal progression, total bruto, toelages, deductions, net, ABP-pension details.
  - Format: PDF, downloadable from self-service: Loonstroken › Jaaropgaven.
  - Publish: document marked with date_generated; accessible until replaced next year.

- [ ] **T6.5** Implement jaaropgaaf audit trail
  - Log: timestamp generated, document ID, employee name.
  - Prevent re-generation (idempotent per calendar year).

---

## Phase 7: External Reporting & Compliance

- [ ] **T7.1** Implement ABP-OW enrollment workflow
  - Trigger: on contract start (employee.start_date), contract type = "vast".
  - Generate UPA (Uniforme Pensioen Aangifte) XML: employee data, salary, franchise, premium rate.
  - Submit to ABP via openconnector (bulk monthly file).
  - Log: timestamp, employee_id, UPA submission ID.

- [ ] **T7.2** Implement ABP-OW termination workflow
  - Trigger: on contract end (employee.end_date).
  - Generate UPA termination record.
  - Submit within 5 working days; log submission.

- [ ] **T7.3** Implement IB47 generation (Tax filing)
  - Batch job on 5 Jan: aggregate all teachers' gross salary & withholding per calendar year.
  - Generate IB47 file (Belastingdienst XML format): BSN, name, income-type (docent_onderwijs_vast_contract), year, total gross, withholding.
  - Handle multi-school teachers: separate row per school per year.
  - Submit to Belastingdienst via openconnector by 28 Feb.
  - Log: submission date, file ID, row count.

- [ ] **T7.4** Implement compliance-report export (VO-raad, anonymized)
  - Admin UI: "Aangiftes & compliance › Exporteer CAO-VO benchmark".
  - Form: select schooljaar, choose output format (Excel / CSV).
  - Data: aggregate per school (anonymized BRIN only) + sector totals.
    - Count of teachers per schaal.
    - Average salary per schaal.
    - Lesuren-cap overschrijding count & frequency.
    - BAPO take-up rate.
    - Vervangingsfonds claim count & avg bedrag.
    - DUO-funding per pupil.
  - Require opt-in consent checkbox: "Share this data with VO-raad for sectorbenchmark".
  - Generate & allow download.

- [ ] **T7.5** Test external reporting
  - Verify UPA generation & ABP submission format.
  - Verify IB47 format & Belastingdienst API compatibility.
  - Verify anonymization in compliance export.

---

## Phase 8: Testing & Documentation

- [ ] **T8.1** Integration tests: Payroll run with VO-CAO employees
  - Test cases: periodieke-verhoging, schaal-table versioning, toelage, overschrijdingstoeslag, BAPO, ABP.
  - Verify loonstrook correctness.

- [ ] **T8.2** Integration tests: Lerarenregister, DUO, Vervangingsfonds APIs
  - Mock responses; verify retry/caching logic.
  - Verify error messages shown to user.

- [ ] **T8.3** User acceptance testing (UAT)
  - Recruit 3–5 pilot schools.
  - Test workflows: import CAO-table, promote teacher, create taakverdeling, run payroll, verify loonstrook, view self-service.
  - Gather feedback on UI clarity, CAO-rule correctness, integration robustness.

- [ ] **T8.4** Documentation
  - Admin guide: how to import new CAO-tables, configure schools (BRIN, pupil counts), manage employee_extension fields.
  - HR guide: how to promote teachers, create taakverdeling, handle overschrijdingen, check Vervangingsfonds claims.
  - Teacher guide: how to understand schaal/trede, view loonstrook CAO-items, understand self-service audit trail.
  - API documentation: Lerarenregister, DUO, ABP, Vervangingsfonds integration points.

- [ ] **T8.5** Training materials
  - Record: walkthrough videos (import CAO-table, run payroll, view self-service).
  - Slides: CAO-VO overview, schaal system, lesuren-cap rules, pension overview.

---

## Phase 9: Optimization & Analytics (Q1 2027)

- [ ] **T9.1** Implement BAPO rules versioning (age-dependent korting rates)
  - Store CAO-BAPO-korting-bijlage as versioned table (like schaal/toelage).
  - Allow import of new rates with geldig_vanaf / geldig_tot.

- [ ] **T9.2** Implement cost-per-teacher analytics
  - Dashboard: total payroll cost / FTE; cost-per-teacher by schaal.
  - Trends: YoY growth, cost variance (vs. budget, vs. sector benchmark).
  - Export: to PO-Raad / VO-raad for sectorbenchmark reporting.

- [ ] **T9.3** Implement lesuren-cap compliance reporting
  - Dashboard: % of teachers at/above cap, overschrijding hours distribution.
  - Alert: schools with >20% overschrijding (high cost risk).
  - Export: to Onderwijsinspectie (anonymized, opt-in).

- [ ] **T9.4** Optimize Lerarenregister caching
  - Implement distributed cache (if multi-region).
  - Batch validation: daily job pre-validates all bevoegdheden (cache warming).

- [ ] **T9.5** Performance testing & tuning
  - Load test: payroll-run with 110k employees.
  - Profile: schaal-table queries, toelage calculation, ABP-premium math.
  - Optimize: indexes, query plans, in-memory caching.

---

## Rollout Milestones

| Milestone | Phase | Date |
|-----------|-------|------|
| MVP (schaal-tables, periodieke-verhoging, payroll) | 1–2 | Q3 2026 (Aug–Sep) |
| Bevoegdheid-gate, Lerarenregister validation | 3 | Q3 2026 (Sep–Oct) |
| Taakverdeling, lesuren-cap | 4 | Q4 2026 (Oct–Nov) |
| DUO, Vervangingsfonds, ABP | 5 | Q4 2026 (Nov–Dec) |
| Teacher self-service, jaaropgaaf | 6 | Q4 2026 (Dec) |
| Compliance reporting, IB47 | 7 | Q1 2027 (Jan) |
| UAT, refinement, GA release | 8 | Q1 2027 (Feb–Mar) |
| Analytics & optimization | 9 | Q1 2027 (Mar–Apr) |

