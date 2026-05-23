# loonbeslag-admin: Tasks

**Status:** draft  
**Created:** 2026-05-23  

## Task Breakdown

### Phase 1: Data Layer & Core API (Backend Foundation)

- [ ] **T-101: Create database schema**
  - Define tables: `Beslag`, `BeslagvrijeVoet`, `BeslagSamenloop`, `BeslagAfdracht`, `BeslagCorrespondentie`, `BeslagVertrouwelijkheidsLog`.
  - Add indexes on `(administratie_id, medewerker_id, status)`, `(administratie_id, exploot_datum)`, etc.
  - Set up soft-delete triggers (deleted_at).
  - Link to existing `Loonrun`, `Medewerker`, `Gebruiker`, `Administratie` tables.
  - **Estimate:** 5 points | **Owner:** Backend lead

- [ ] **T-102: Implement Wvbvv formula engine**
  - Parse 2026 Wvbvv normbedragen tables (indexed by peilmaand).
  - Implement formula: base(leefvorm) + child_supplement(children) + rent_component(woonkosten) + care_component(premie).
  - Write unit tests for 5+ scenarios (single, single-parent, couple, couple-with-kids, various rent/care combinations).
  - Handle 2021 vs. future-year table transitions.
  - **Estimate:** 8 points | **Owner:** Backend engineer

- [ ] **T-103: Build Beslag registration API endpoint**
  - `POST /api/v1/administraties/{id}/beslagen` — accepts file upload (exploot PDF), extracts metadata.
  - Validates required fields: `beslaglegger_type`, `beslaglegger_naam`, `beslaglegger_iban`, `exploot_datum`, `vorderingsbedrag_oorspronkelijk`.
  - Generates unique `beslag_id`, sets `status = concept`, `volgnummer_intern = next_sequence`.
  - Stores scan in document-vault; returns Beslag detail with countdown to derdenverklaring deadline.
  - **Estimate:** 5 points | **Owner:** Backend engineer

- [ ] **T-104: Implement BVV calculation service**
  - `POST /api/v1/beslagen/{id}/bvv-berekening` — triggers recalculation for given peilmaand.
  - Pulls household data from employee-master (leefvorm, kinderen, woonkosten, nominale_premie_zvw).
  - Runs Wvbvv formula; creates `BeslagvrijeVoet` record with `bvv_methode = wvbvv_standaard`.
  - Returns detailed calculation with transparent inputs/outputs.
  - Tests: verify formula correctness against official Wvbvv 2021 examples.
  - **Estimate:** 8 points | **Owner:** Backend engineer

- [ ] **T-105: Build BVV override endpoint with justification**
  - `POST /api/v1/beslagen/{id}/bvv-override` — requires `hr_manager` role + free-text `handmatig_motivering`.
  - Validates motivering is non-empty and ≥ 20 characters.
  - Creates new `BeslagvrijeVoet` with `bvv_methode = handmatig_overruled_met_motivering`.
  - Logs immutable audit event (high-volume) in `audit-trail-payroll` with full override text.
  - Sends notification to `compliance_officer` for review.
  - **Estimate:** 6 points | **Owner:** Backend engineer

- [ ] **T-106: Samenloop allocation engine (preference + pro-rata/chrono)**
  - For each payroll month, given employee + list of active Beslagen:
    - Rank by preferentie (preferent first).
    - Within class, sort by volgnummer_intern (registration order).
    - Compute `totaal_beschikbaar_voor_beslag = netto_loon - bvv_bedrag`.
    - Allocate according to methodiek (preferent_eerst_dan_concurrent_naar_rato OR strikt_chronologisch_oudst_eerst).
    - Store verdeling in `BeslagSamenloop` record.
  - Unit tests: 5+ multi-beslag scenarios (preferent-only, concurrent-only, mixed, pro-rata, chronological).
  - **Estimate:** 10 points | **Owner:** Backend engineer (senior)

- [ ] **T-107: Create Afdracht recording endpoints**
  - `GET /api/v1/loonrun/{id}/beslag-afdrachten` — fetch afdracht records for a payroll run.
  - `PATCH /api/v1/afdracht/{id}/status` — update status (gepland → uitgevoerd / mislukt / teruggeboekt).
  - Support status transitions with validation (can only move forward in workflow).
  - Log each status change in audit-trail.
  - **Estimate:** 5 points | **Owner:** Backend engineer

- [ ] **T-108: SEPA pain.001 file generation**
  - `POST /api/v1/loonrun/{id}/beslag-sepa-batch` — generates SEPA pain.001.003.03 XML file.
  - For each afdracht (status = gepland):
    - Validate IBAN (checksum algorithm).
    - Build CdtTrfTxInf element with Amt, Cdtr, CdtrAcct, RmtInf (beslaglegger_kenmerk + reference).
    - Fail gracefully if IBAN invalid; return error list + guidance to correct.
  - Save XML file to document-vault; link from Loonrun record.
  - Unit tests: valid/invalid IBANs, multiple afdracht scenarios, XML schema validation.
  - **Estimate:** 8 points | **Owner:** Backend engineer

- [ ] **T-109: Payment status reconciliation (pain.002 / camt.054)**
  - Listener for bank return files (openconnector delivers pain.002 or camt.054).
  - Parse transaction statuses (ACCC, RJCT, PDNG, etc.).
  - Match to `BeslagAfdracht` records via reference number.
  - Update afdracht status + `afdrachtdatum`, `betalingsreferentie`, `bedrag_afgedragen`.
  - Create GL posting for successful remittances; temporary transit account for failed.
  - Log reconciliation events.
  - **Estimate:** 8 points | **Owner:** Backend engineer

- [ ] **T-110: Confidentiality access logging (BeslagVertrouwelijkheidsLog)**
  - Intercept all Beslag reads, writes, exports.
  - On each access:
    - Log user + roles (snapshot at time of access).
    - Log toegangstype (raadpleging, wijziging, export, etc.).
    - Log IP + user-agent.
    - Validate authorization (payroll_admin, hr_manager, self-medewerker only).
    - Return 404 if unauthorized; log the denial attempt.
  - Tests: authorized vs. unauthorized access; repeated denial detection.
  - **Estimate:** 6 points | **Owner:** Backend engineer

- [ ] **T-111: Wsnp conflict detection & auto-suspension**
  - Listener on employee-master Wsnp events (`wsnp_toelating_datum`).
  - Identify all concurrent Beslagen for the affected medewerker.
  - Auto-transition to `status = opgeschort`.
  - Queue aansprakelijkheidsbetwisting letter for each suspended Beslag.
  - Notify payroll_admin + hr_manager.
  - Tests: incoming Beslag on Wsnp-protected employee; retroactive Wsnp after Beslag active.
  - **Estimate:** 6 points | **Owner:** Backend engineer

---

### Phase 2: Correspondence & Document Generation (Templates)

- [ ] **T-201: Derdenverklaring template design & rendering**
  - Design Dutch template (derdenverklaring_20260101) per ADR-006 and REQ-006-001.
  - Implement template engine (e.g., Jinja2, Handlebars, or in-app logic) to pre-fill:
    - Employer name + address.
    - Employee name, DOB, BSN, employment start.
    - Bruto monthly salary.
    - Existing Beslagen (list).
    - Computed BVV (with input parameters shown).
    - Allocated remittance amount.
    - Date field.
  - Generate PDF from template; store in document-vault.
  - Allow HR-manager to edit before finalization.
  - **Estimate:** 8 points | **Owner:** Frontend engineer + Template designer

- [ ] **T-202: Monthly statement template (maandelijkse_specificatie)**
  - Design template showing:
    - Employee, SSN, period.
    - Gross salary, BVV applied, available for garnishment.
    - Per-beslag allocation + remittance status.
    - Total remitted.
    - Remaining claim (vorderingsbedrag_resterend).
  - Implement rendering + PDF generation.
  - **Estimate:** 5 points | **Owner:** Frontend engineer

- [ ] **T-203: Release letter template (eindverklaring)**
  - Design template for full debt satisfaction.
  - Implement auto-generation when `vorderingsbedrag_resterend = 0`.
  - **Estimate:** 3 points | **Owner:** Frontend engineer

- [ ] **T-204: Aansprakelijkheidsbetwisting template (Wsnp conflict)**
  - Design template explaining Wsnp suspension.
  - Implement auto-generation on Wsnp conflict detection.
  - **Estimate:** 3 points | **Owner:** Frontend engineer

- [ ] **T-205: Document dispatch integration (post, email, Digipoort)**
  - Integrate with openconnector for multi-channel dispatch.
  - Support registered post (post_aangetekend with tracking), email, Digipoort (where creditor supports).
  - Log dispatch in `BeslagCorrespondentie` (verzenddatum, verzendmethode, bevestiging_uri).
  - **Estimate:** 6 points | **Owner:** Backend engineer (integration)

---

### Phase 3: Frontend - Payroll Admin Views

- [ ] **T-301: Garnishment list & filtering UI**
  - Table: Beslag ID, Medewerker, Beslaglegger, Bedrag, Status, Exploot-datum, Deadline.
  - Filters: Status (concept / actief / opgeschort / afgelost), Beslaglegger-type, Deadline (overdue / T-7 / T-14 / upcoming).
  - Sorting: by exploot_datum, bedrag, deadline urgency.
  - Row actions: View detail, Transition status, Generate correspondence.
  - **Estimate:** 5 points | **Owner:** Frontend engineer

- [ ] **T-302: Garnishment detail + registration form**
  - Form for new Beslag:
    - File upload (exploot PDF).
    - Beslaglegger-type (dropdown).
    - Beslaglegger name, address (structured fields).
    - IBAN (validated).
    - Kenmerk, vorderingsbedrag_oorspronkelijk, exploot_datum.
  - Detail view showing:
    - All fields + metadata.
    - Current status + status-change buttons.
    - Countdown to derdenverklaring deadline.
    - List of related BeslagvrijVoet + Afdracht records.
  - **Estimate:** 8 points | **Owner:** Frontend engineer

- [ ] **T-303: BVV calculator display & override UI**
  - Display computed BVV (standard formula) with transparent inputs.
  - Show formula breakdown: base + child + rent + care components.
  - "Override" button (hr_manager only) → modal with:
    - New BVV amount (text input).
    - Motivering (required, ≥ 20 chars).
    - Submit → creates override record, logs audit event, notifies compliance_officer.
  - **Estimate:** 6 points | **Owner:** Frontend engineer

- [ ] **T-304: Correspondence builder & dispatch UI**
  - UI to select correspondence type (derdenverklaring, monthly-statement, eindverklaring, aansprakelijkheidsbetwisting).
  - Pre-filled template rendered in doc-editor or preview pane.
  - Edit fields as needed (HR-manager editing).
  - "Send" button → select dispatch method (post_aangetekend, email, Digipoort).
  - Confirmation + tracking number entry.
  - Log dispatch in `BeslagCorrespondentie`.
  - **Estimate:** 10 points | **Owner:** Frontend engineer

- [ ] **T-305: SEPA batch generation & status monitor UI**
  - On payroll-run completion, show "Generate SEPA batch" button.
  - Click → generates pain.001 file, displays summary (3 afdracht records, €750 total).
  - Download link + validation summary (IBAN errors, if any).
  - Status monitor: list of afdracht records for the payroll run, each showing status (gepland / uitgevoerd / mislukt).
  - "Retry failed" button for mislukt afdrachten.
  - **Estimate:** 8 points | **Owner:** Frontend engineer

- [ ] **T-306: Compliance deadline widget & alerts**
  - Dashboard widget (or top banner) showing:
    - Overdue derdenverklaringen (red).
    - T-3 / T-2 upcoming deadlines (yellow).
    - No action needed (green).
  - Click on row → navigate to Beslag detail.
  - **Estimate:** 4 points | **Owner:** Frontend engineer

- [ ] **T-307: Liability risk alerts dashboard**
  - Widget showing:
    - Incomplete derdenverklaring warnings.
    - Underremittance alerts (withheld ≠ remitted).
    - Samenloop conflict warnings.
  - Drill-down to affected Beslagen.
  - **Estimate:** 5 points | **Owner:** Frontend engineer

---

### Phase 4: Frontend - HR Manager Views

- [ ] **T-401: HR override approval queue**
  - List of pending BVV overrides (awaiting hr_manager confirmation).
  - Row: Employee, Beslag, Standard BVV, Proposed BVV, Motivering preview.
  - Actions: Approve (moves to active), Reject (reverts to standard), Request clarification.
  - **Estimate:** 4 points | **Owner:** Frontend engineer

- [ ] **T-402: Escalation & support dashboard**
  - List of escalations: missed deadlines, failed payments, Wsnp conflicts.
  - HR-manager triage queue.
  - Actions: acknowledge, reassign to payroll_admin, field hardship-waiver request.
  - **Estimate:** 6 points | **Owner:** Frontend engineer

- [ ] **T-403: Employee support interface**
  - Search / filter medewerkers by name or ID.
  - View their active Beslagen + BVV calculations.
  - "Request review" button → queues hardship-waiver or BVV-recalc request.
  - Manage employee-submitted proof (housing, care) → trigger BVV recalculation.
  - **Estimate:** 5 points | **Owner:** Frontend engineer

---

### Phase 5: Frontend - Employee Self-Service

- [ ] **T-501: Mijn Beslagen summary view**
  - Employee logs into Mijn HR; navigates to "Mijn Beslagen".
  - List of active Beslagen:
    - Beslaglegger name + type.
    - Monthly deduction amount.
    - Remaining claim.
    - [Details] button.
  - **Estimate:** 4 points | **Owner:** Frontend engineer

- [ ] **T-502: Beslag detail + BVV transparency**
  - Show:
    - Full Beslag metadata (creditor, dates, status).
    - Computed BVV with formula breakdown (for employee understanding).
    - Monthly afdracht history (what was withheld, what was remitted, dates).
    - [Request HR review] button.
  - **Estimate:** 5 points | **Owner:** Frontend engineer

- [ ] **T-503: Document upload for BVV recalculation**
  - Employee can upload proof of:
    - Housing (lease, mortgage statement).
    - Care costs (insurance document, private-arrange ment proof).
  - Submit → notification to HR-manager.
  - Once approved, BVV is recalculated for next payroll period.
  - **Estimate:** 4 points | **Owner:** Frontend engineer

- [ ] **T-504: Payslip detail conditioning**
  - Payslip detail page shows garnishment line:
    - Full detail to employee (Inhouding loonbeslag — €350 — afdracht LBIO alimentatie 12345).
    - Generic line to non-authorized manager (Overige loonheffing — €350).
  - Implement in payslip-rendering logic (conditional role-based text).
  - **Estimate:** 4 points | **Owner:** Frontend engineer

---

### Phase 6: Frontend - Accountant/Auditor Views

- [ ] **T-601: Garnishment export interface**
  - Accountant clicks "Export for audit" → date-range picker (start date, end date).
  - Options: ZIP, encrypted (single-use passphrase).
  - Submit → system generates ZIP with:
    - All Beslagen (active/finalized in period).
    - PDFs: exploot scans, derdenverklaringen, monthly statements.
    - Excel: Compliance checklist, summary, Samenloop allocations.
  - Download link + passphrase display (single-use).
  - Logs export in `BeslagVertrouwelijkheidsLog`.
  - **Estimate:** 8 points | **Owner:** Frontend engineer

---

### Phase 7: Backend - Jobs & Integrations

- [ ] **T-701: Daily compliance-check job**
  - Scheduled daily (08:00 UTC).
  - Iterate Beslagen with `status = concept`.
  - For each, calculate days until derdenverklaring deadline (exploot_datum + 28 days).
  - If T-2: queue high-priority alert (payroll_admin + hr_manager).
  - If T-0 (overdue): queue critical alert (escalate to HR leadership + legal).
  - **Estimate:** 4 points | **Owner:** Backend engineer

- [ ] **T-702: Monthly BVV recalculation triggers**
  - Listen for employee-master changes (leefvorm, kinderen, woonkosten, nominale_premie_zvw).
  - On change, mark affected `BeslagvrijeVoet` for the next peilmaand as "needs recalc."
  - Queue notification to payroll_admin: "BVV recalculation needed for [Employee] in July 2026."
  - **Estimate:** 5 points | **Owner:** Backend engineer

- [ ] **T-703: Retention & destruction job**
  - Scheduled monthly.
  - For each Beslag, calculate destruction eligibility:
    - Beslag end date + 7 years >= today? (or medewerker termination + 7 years).
  - If eligible:
    - Pseudo-anonymize scans (hash, de-index).
    - Remove PII from searchable records.
    - Retain numeric summaries for stats.
    - Schedule for shredding after 30-day hold.
  - Track destruction in audit-log.
  - **Estimate:** 6 points | **Owner:** Backend engineer

- [ ] **T-704: Integration with payroll-engine-nl**
  - Export `BeslagSamenloop` data to payroll engine via internal event/API.
  - Payroll engine reads allocation per medewerker per period, applies deductions.
  - Feedback: payroll engine confirms deduction applied correctly.
  - **Estimate:** 8 points | **Owner:** Backend engineer (integration)

- [ ] **T-705: Integration with journaalpost-export**
  - On successful afdracht (status = uitgevoerd), trigger GL posting:
    - Debit: Salary expense account (employee payslip).
    - Credit: "Garnishers payable" account (beslaglegger liability).
  - Record `journaalpost_id` in afdracht.
  - **Estimate:** 4 points | **Owner:** Backend engineer (integration)

- [ ] **T-706: Integration with notification-engine**
  - Queue notifications for:
    - Deadline reminders (T-2, T-0).
    - Escalations (failed payments, underremittance).
    - Correspondence dispatch confirmations.
    - Employee support requests.
  - Implement priority levels (high, critical).
  - **Estimate:** 4 points | **Owner:** Backend engineer (integration)

---

### Phase 8: Testing & QA

- [ ] **T-801: Unit tests for Wvbvv formula**
  - Test 10+ scenarios: single, single-parent, couple, various rent/care/child combos.
  - Verify against official Wvbvv 2021 normbedragen tables.
  - Edge cases: rent = 0, care = 0, children = 0, large care premiums.
  - **Estimate:** 5 points | **Owner:** QA engineer / Backend engineer

- [ ] **T-802: Integration tests for samenloop allocation**
  - Test 8 scenarios:
    - Preferent only (LBIO).
    - Concurrent only (multiple deurwaarders).
    - Mixed (preferent + concurrent).
    - Pro-rata allocation (concurrent).
    - Chronological allocation (concurrent).
    - Single beslag (no samenloop needed).
    - Beslag with insufficient available funds.
    - Multiple beslagen with varying preferences.
  - Verify allocation matches legal requirements.
  - **Estimate:** 8 points | **Owner:** QA engineer

- [ ] **T-803: End-to-end scenario tests**
  - **Scenario 1:** Register → calculate BVV → allocate → remit → monthly statement → full debt satisfaction.
  - **Scenario 2:** BVV override with HR justification → audit trail verification.
  - **Scenario 3:** Employee submits rent proof → BVV recalculation → next period reflects new amount.
  - **Scenario 4:** Wsnp conflict (new beslag on protected employee) → auto-suspend → send aansprakelijkheidsbetwisting.
  - **Scenario 5:** Failed SEPA payment → error handling → retry → success.
  - **Estimate:** 12 points | **Owner:** QA engineer

- [ ] **T-804: Confidentiality access control tests**
  - Verify payroll_admin sees full detail; hr_manager sees appropriate subset; medewerker sees own detail; manager sees generic line; unauthorized users get 404.
  - Test access logging (every read/write captured in BeslagVertrouwelijkheidsLog).
  - Test repeated unauthorized access → security alert triggered.
  - **Estimate:** 6 points | **Owner:** QA engineer

- [ ] **T-805: Deadline & alert tests**
  - Trigger daily job; verify alerts generated at T-2 and T-0.
  - Verify escalation notification routing (payroll_admin → hr_manager → HR leadership).
  - **Estimate:** 4 points | **Owner:** QA engineer

- [ ] **T-806: SEPA file validation tests**
  - Generate pain.001 with valid + invalid IBANs.
  - Verify XML schema compliance.
  - Reconciliation: simulate bank return file (pain.002) → parse → update afdracht status.
  - **Estimate:** 6 points | **Owner:** QA engineer

- [ ] **T-807: Accountant export & audit trail verification**
  - Request export for Q2 2026.
  - Verify ZIP contents (Beslagen, afdrachten, correspondence, Samenloop).
  - Verify Excel summary (compliance checklist).
  - Verify logging in BeslagVertrouwelijkheidsLog.
  - **Estimate:** 5 points | **Owner:** QA engineer

- [ ] **T-808: Performance & load tests**
  - Wvbvv formula: 1000 calls → all complete in < 100 ms total.
  - Samenloop: 1000 medewerkers × 10 beslagen → < 500 ms per allocation.
  - SEPA batch: 1000 afdrachten → < 2 seconds.
  - **Estimate:** 6 points | **Owner:** Performance engineer

---

### Phase 9: Documentation & Compliance Review

- [ ] **T-901: Legal compliance audit**
  - Review implementation against Wvbvv 2021, Rv art. 475–479g, GDPR art. 9, AVG rules.
  - Verify BVV formula matches official BKWI normbedragen.
  - Verify confidentiality controls match NEN 7510 / ISO 27001 A.9.
  - Obtain sign-off from legal counsel.
  - **Estimate:** 5 points | **Owner:** Compliance officer + Legal

- [ ] **T-902: User documentation (HR / Payroll)**
  - Write guides for:
    - Registering a new Beslag.
    - Reviewing/overriding BVV.
    - Sending derdenverklaring & monthly statements.
    - Handling failed payments.
    - Responding to Wsnp conflicts.
  - Include screenshots, step-by-step walkthroughs.
  - **Estimate:** 8 points | **Owner:** Technical writer

- [ ] **T-903: Employee self-service guide**
  - Write plain-language guide for employee view of Beslagen.
  - Explain BVV calculation.
  - Instructions for submitting housing/care proof.
  - FAQ: "How is the amount calculated?", "Can I dispute?", "How long is this retained?"
  - **Estimate:** 4 points | **Owner:** Technical writer

- [ ] **T-904: Accountant export guide**
  - Document the export format, contents, and interpretation.
  - Compliance checklist explanation.
  - Sample audit procedure for reviewing garnishment data.
  - **Estimate:** 3 points | **Owner:** Technical writer

---

### Phase 10: Deployment & Rollout

- [ ] **T-1001: Staging environment setup**
  - Set up loonbeslag-admin in staging with test data (5 Beslagen, 10 Afdrachten).
  - Configure SEPA mock bank (test file exchange).
  - **Estimate:** 3 points | **Owner:** DevOps / Backend

- [ ] **T-1002: Migration & data seeding (if retrofitting)**
  - If migrating from legacy garnishment system: extract Beslag data, map to new schema.
  - Seed test administratie with 50 historical Beslagen (mix of active, completed, suspended).
  - Verify data integrity post-migration.
  - **Estimate:** 5 points | **Owner:** Data engineer (if applicable)

- [ ] **T-1003: Rollout plan & communication**
  - Schedule launch (June 2026).
  - Prepare rollout comms: payroll admins, HR managers, employees.
  - Training sessions (2 hours for payroll team, 1 hour for HR, 30 min for employees).
  - **Estimate:** 4 points | **Owner:** Project manager + Communications

- [ ] **T-1004: Post-launch monitoring & support**
  - Monitor error rates, performance metrics.
  - Support desk ready for user questions.
  - Weekly "lessons learned" reviews for first month.
  - **Estimate:** ongoing | **Owner:** Support engineer + Backend lead

---

## Summary

**Total Effort Estimate:**
- Phase 1 (Data & API): 11 tasks, ~70 points
- Phase 2 (Correspondence): 5 tasks, ~25 points
- Phase 3 (Payroll Admin UI): 7 tasks, ~46 points
- Phase 4 (HR Manager UI): 3 tasks, ~15 points
- Phase 5 (Employee UI): 4 tasks, ~17 points
- Phase 6 (Accountant UI): 1 task, ~8 points
- Phase 7 (Jobs & Integration): 6 tasks, ~31 points
- Phase 8 (Testing): 8 tasks, ~52 points
- Phase 9 (Documentation & Compliance): 4 tasks, ~20 points
- Phase 10 (Deployment): 4 tasks, ~12 points

**Grand Total: 53 tasks, ~296 points (~8–10 weeks, 4 FTE).**

---

## Notes

- **Prioritization:** Phases 1–3 are critical path (data + core UI for payroll admins). Phases 4–5 (HR + employee views) can run in parallel after Phase 3 unblocks.
- **Testing integration:** T-801 through T-807 should begin mid-way through implementation (not end-loaded).
- **Rollout:** June 2026 launch presumes April–May development completion.
- **Handoff:** Legal/compliance review (T-901) must complete before production deployment.
