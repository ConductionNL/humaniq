---
status: proposed
change: cao-onderwijs-vo
version: 1.0
---

# Specifications: CAO Voortgezet Onderwijs

## REQ-001: Schaal-tabel versioning

**GIVEN** the CAO VO is renegotiated and a new schaal-tabel takes effect on 1 augustus 2026  
**WHEN** an HR-admin imports the new tabel via the CAO-import UI (XLSX or JSON)  
**THEN** the system stores the new tabel with `geldig_vanaf = 2026-08-01` and `geldig_tot = NULL`, automatically sets the previous tabel's `geldig_tot = 2026-07-31`, and ensures every payroll-run after that date uses the new tabel without manual employee-level updates. Employees retain their schaal+trede; the new bruto-bedragen flow automatically.

**Acceptance criteria:**
- Import dialog accepts both XLSX (columns: schaal, trede, bruto_maandloon, bruto_jaarloon) and JSON.
- System validates that new table's `geldig_vanaf` is after previous table's `geldig_tot`.
- Previous active table (where `geldig_tot = NULL`) is automatically closed before new table is activated.
- Payroll-run queries tables by effective date (run-date) and uses the correct version; no manual employee-level overwrites.
- Audit log records HR-admin name, import timestamp, number of rows imported, and which previous table was closed.

**Test scenario:**
- Pre-state: Active schaal-table for 2024-2026, with LB-trede-1 = EUR 3245/month.
- Import: New table with `geldig_vanaf = 2026-08-01`, LB-trede-1 = EUR 3342/month (3% increase).
- Post-state: 2024-2026 table has `geldig_tot = 2026-07-31`; new table is marked active.
- Payroll run on 2026-07-31 uses 2024-2026 table; run on 2026-08-01 uses new table.
- A teacher with schaal=LB, trede=1 receives EUR 3245 in July, EUR 3342 in August (no contract change).

---

## REQ-002: Periodieke verhoging

**GIVEN** a docent at schaal LB trede 8 with `laatste_periodiek_datum = 2025-08-01`  
**WHEN** the payroll-run executes on 2026-08-01  
**THEN** the system automatically advances the trede to 9 (max trede LB = 12, after which the periodiek stops), updates `laatste_periodiek_datum`, recalculates the bruto-loon from the current schaal-tabel, and emits a `PeriodiekToegekend` event. If the docent is on a "uitloopschaal" or trede-stop is administratively imposed, the periodiek is skipped with a recorded reden.

**Acceptance criteria:**
- Payroll-run triggers trede-increment check if current date >= `laatste_periodiek_datum + 1 year`.
- Trede advances by 1 unless: (a) teacher already at max trede for schaal, (b) `uitloopschaal = true`, (c) `trede_blokkering_reden` is set.
- If trede cannot advance, reason is logged (e.g., "max_trede_reached", "uitloopschaal", "admin_block").
- New bruto-loon is retrieved from active schaal-table row for (schaal, new-trede).
- `PeriodiekToegekend` event is emitted with: employee_id, old_trede, new_trede, effective_date.
- Event is visible in teacher self-service audit trail.

**Test scenario:**
- Pre-state: Teacher LB-trede-8, `laatste_periodiek_datum = 2025-08-01`, salary EUR 3700/month.
- Payroll run: 2026-08-01.
- Expected: Trede advances to 9 (LB max = 12), salary updates to EUR 3850/month (from schaal-table).
- `PeriodiekToegekend` event emitted; teacher sees "Periodieke verhoging: trede 8→9" in audit trail.
- Teacher at LB-trede-12 (max): Trede does not advance; log "max_trede_reached".

---

## REQ-003: Bevoegdheid-gate voor schaal LC/LD

**GIVEN** a docent currently in schaal LB  
**WHEN** HR attempts to promote to schaal LC  
**THEN** the system validates that the docent has `bevoegdheid = eerstegraads` OR a recorded `lc_lc_traject_voltooid = true`, and validates against the Lerarenregister that the bevoegdheid is current. Promotion without bevoegdheid is blocked with a clear error; with bevoegdheid the promotion is logged with effective date and triggers a new contract-addendum via `document-template-engine`.

**Acceptance criteria:**
- Promotion form displays teacher's current schaal, bevoegdheid, and Lerarenregister expiry.
- When "Promote to LC" is selected, system checks: `bevoegdheid IN (eerstegraads, tweedegraads)`.
  - If `bevoegdheid = onbevoegd` AND `lc_lc_traject_voltooid = false`: block with error "Docent niet bevoegd voor LC. Voltooi eerst de LC-traject."
  - If LC-traject-voltooid flag is true: allow promotion (recorded completion is equivalent to certification).
- System calls Lerarenregister API: `GET /teacher/{lerarenregister_id}` to validate `bevoegdheid` is current and `lerarenregister_geldig_tot > today`.
  - If API is unavailable: log "Lerarenregister API unavailable; retry in 24h" and block promotion with "Validatie uitgesteld. Probeer morgen."
  - If bevoegdheid expired or not found: block with "Bevoegdheid in Lerarenregister is verlopen. Contacteer DUO."
- Upon success, system: (a) updates `employee_extension.schaal = LC`, (b) triggers document-template-engine to generate contract addendum, (c) emits `PromotionApproved` event with promotion_date = today.
- Audit log records: HR-admin name, old schaal, new schaal, Lerarenregister validation timestamp, contract-addendum document ID.

**Test scenario:**
- Teacher: LB-trede-10, bevoegdheid=eerstegraads, lerarenregister_geldig_tot=2028-06-30.
- HR action: Promote to LC.
- Lerarenregister API returns valid response: promotion succeeds.
  - Employee_extension.schaal updated to LC.
  - Contract addendum generated via document-template-engine.
  - `PromotionApproved` event emitted.
- Teacher: LB-trede-3, bevoegdheid=onbevoegd, lc_lc_traject_voltooid=false.
- HR action: Attempt promote to LC.
- System blocks: "Docent niet bevoegd voor LC."
- Teacher: LC-trede-6, bevoegdheid=eerstegraads, lerarenregister_geldig_tot=2023-12-31 (expired).
- HR action: Any change affecting bevoegdheid validates against Lerarenregister.
- System calls API; API returns expired; promotion blocked: "Bevoegdheid in Lerarenregister is verlopen."

---

## REQ-004: Lesuren-cap 750 per fte

**GIVEN** a docent with `aanstelling = 1.0 fte` and a taaktoedeling for schooljaar 2026-2027  
**WHEN** the schooladministratie enters the lesuren-toedeling  
**THEN** the system flags any toedeling >750 lesuren as overschrijding (CAO art. 7.1), requires explicit goedkeuring by the docent (formeel akkoord opgeslagen), and triggers an overschrijdingstoeslag berekend pro-rato per uur boven 750. For deeltijders the cap scales proportioneel (0.6 fte → max 450 lesuren).

**Acceptance criteria:**
- Taakverdeling form displays total lesuren from `lesuren_per_vak` fields (summed).
- System calculates cap as: `taakomvang_lesuren_per_jaar * (fte / 1.0)` (e.g., 750 for 1.0 FTE, 450 for 0.6 FTE).
- If total lesuren > cap: flag as "Overschrijding: XX uur boven limiet".
- Overschrijding prevents form submission unless teacher explicitly checks "Ik ga akkoord met overschrijding van XX uur" and submits.
- `goedkeuring_docent_at` is recorded with timestamp and teacher name.
- `overschrijdingstoeslag_uren` is set to (total lesuren - cap).
- Surtax calculation: surtax-rate × overschrijdingstoeslag_uren per month (rate TBD per CAO, typically 15–20% of monthly bruto per overschrijding-hour).
- Surtax appears as separate line on loonstrook: "Overschrijdingstoeslag: EUR XXX".

**Test scenario:**
- Teacher: 1.0 FTE, contract 750 lesuren/year.
- Taakverdeling entry: wiskunde 300h, nask1 450h, totaal 750h.
- Result: No flag; `overschrijdingstoeslag_uren = 0`.
- Taakverdeling entry: wiskunde 400h, nask1 400h, totaal 800h.
- Result: Flag "Overschrijding: 50 uur"; form blocked until teacher signs off.
- Upon sign-off: `overschrijdingstoeslag_uren = 50`, `goedkeuring_docent_at = 2026-06-15T...`.
- Surtax: 50 uur × 15% × bruto-maandloon / 750 uren = EUR XXX/month (example).
- Teacher 0.6 FTE, cap = 450h.
- Taakverdeling: Duits 450h; no overschrijding.
- Taakverdeling: Duits 480h; flag "Overschrijding: 30 uur", surtax applied.

---

## REQ-005: Vakvolledigheids-toeslag voor schaarste-vakken

**GIVEN** the CAO-tabel defines vakvolledigheids-percentages voor schaarste-vakken (wiskunde, natuurkunde, scheikunde, Duits, Frans, informatica) and a docent teaches one or more of these  
**WHEN** the payroll-run computes the maand-bruto  
**THEN** an arbeidsmarkttoelage is added als percentage van het bruto-loon (per CAO bijlage), pro-rato het aandeel lesuren in het schaarste-vak. The toelage is shown as a separate regel op de loonstrook ("arbeidsmarkttoelage wiskunde") and is pensioengevend en SV-loon.

**Acceptance criteria:**
- For each vakgebied in employee's `vakgebieden` array, system looks up `arbeidsmarkttoelage_table` row (by run-date).
- Calculate pro-rata: `lesuren_in_vakgebied / totaal_lesuren_per_jaar`.
- Apply toelage as: `bruto-loon × toelage_pct × pro_rata`.
  - Example: teacher teaches 300 wiskunde + 450 nask = 750 total; wiskunde toelage 4.5%.
  - Toelage = EUR 4000 bruto × 4.5% × (300/750) = EUR 72/month.
- If teacher teaches multiple schaarste-vakken, sum all toelagen (one per vak).
- Toelage is treated as SV-loon and pensioengevend (included in ABP-premium calculation).
- Loonstrook line-item: "Arbeidsmarkttoelage wiskunde: EUR 72" (one per vak taught).

**Test scenario:**
- Teacher: LB-trede-5, bruto EUR 3700/month, teaches wiskunde 300h + nask1 450h (750 total).
- Arbeidsmarkttoelage-table: wiskunde 4.5%, nask1 4.5%.
- Toelagen:
  - Wiskunde: EUR 3700 × 4.5% × (300/750) = EUR 66.60
  - Nask1: EUR 3700 × 4.5% × (450/750) = EUR 99.90
  - Total: EUR 166.50/month
- Loonstrook shows:
  - Bruto-loon: EUR 3700.00
  - Arbeidsmarkttoelage wiskunde: EUR 66.60
  - Arbeidsmarkttoelage nask1: EUR 99.90
  - Totaal bruto (pensioengevend): EUR 3866.50

---

## REQ-006: Vervangingsfonds-claim bij ziekteverzuim

**GIVEN** a docent meldt zich ziek voor >2 dagen  
**WHEN** HR registreert de ziekmelding in HRMQ  
**THEN** the system generates a Vervangingsfonds-claim met de relevante gegevens (BSN, schaal, trede, periode, fte), verstuurt deze via de Vervangingsfonds-API (openconnector), en volgt de claim-status (`ingediend`, `goedgekeurd`, `uitbetaald`, `afgewezen`). Bij uitbetaling wordt de ontvangst geboekt op de bekostigings-grootboekrekening van de school.

**Acceptance criteria:**
- Sick-leave record created in hrmq (employee.absence_type = "ziek", start_date, end_date).
- System triggers on save if duration > 2 days.
- System assembles claim payload: employee BSN, schaal, trede (from employee_extension), absence dates, fte (from contract), school BRIN.
- Calls Vervangingsfonds API endpoint (via openconnector) to register claim.
- On success: creates `hrmq_cao_vo_vervanging` record with claim_status="ingediend", vfpf_referentie = API response ID.
- On API failure: logs error and creates record with claim_status="ingediend_pending" (retry flag).
- Daily batch job (or monthly) polls Vervangingsfonds API for claim status updates; updates claim_status and claim_bedrag.
- When claim_status changes to "uitbetaald", system posts GL entry to school's bekostigings account (cost-center).
- HR-admin can view claim status dashboard: list of open, approved, paid claims per school per year.

**Test scenario:**
- Teacher: BSN 123456789, schaal=LC, trede=8, fte=1.0, at school with BRIN=0200.
- Absence record: 2026-03-15 (Mon) to 2026-03-19 (Fri), reason=ziek (5 days).
- System triggers claim generation.
- API call succeeds; vfpf_referentie = "VF-2026-001234".
- `hrmq_cao_vo_vervanging` record created: claim_status="ingediend", claim_bedrag=NULL (pending).
- Batch job (2 weeks later): polls API; returns claim_status="goedgekeurd", claim_bedrag=EUR 1240.50.
- Record updated.
- Batch job (1 week later): returns claim_status="uitbetaald".
- Record updated; GL entry posted to school's cost-center.

---

## REQ-007: ABP-OW pensioen-aansluiting

**GIVEN** a docent met een vast contract aan een VO-school  
**WHEN** een nieuwe in-diensttreding wordt afgerond  
**THEN** the system meldt de werknemer aan bij ABP-sector OW via de standaard UPA-levering (openconnector → ABP), berekent de pensioenpremie met de OW-specifieke franchise (2026: EUR 18.275) en premie (27,9% werkgever + werknemer-aandeel), en houdt het werknemer-deel in op de loonstrook. Bij uitdiensttreding wordt een afmelding gestuurd binnen 5 werkdagen.

**Acceptance criteria:**
- On contract start (employee.start_date), system triggers ABP-enrollment workflow.
- UPA (Uniforme Pensioen Aangifte) XML is generated per employee with: BSN, name, birth date, contract type (vast), start date, salary level (bruto from schaal-table).
- Franchise (pensioengevend minimum) is set to 2026 value: EUR 18.275/year (EUR 1523/month).
- Premium rate (sector OW): 27.9% (shared employer+employee; employer typically ~18%, employee ~10%).
- Payroll-run calculates: pensionable_salary = MAX(bruto_salary, franchise); premium_per_month = pensionable_salary × 10% (employee share, example).
- Loonstrook line-item: "ABP-OW pensioenaftrek: EUR XXX".
- Monthly UPA file aggregates all active employees; submitted to ABP via openconnector.
- On contract end (employee.end_date), system generates ABP-afmelding (UPA termination record) and submits within 5 working days.
- HR-admin can view ABP-saldo dashboard per employee (accrued pension credit, last reconciliation).

**Test scenario:**
- Teacher: hired 2026-06-01, salary LC-trede-6 = EUR 4500/month.
- Contract type: vast (permanent).
- UPA enrollment: generated with franchise EUR 18.275.
- Payroll run (June): pensionable_salary = EUR 4500, premium = EUR 4500 × 10% = EUR 450/month (employee share).
- Loonstrook: "ABP-OW pensioenaftrek: EUR 450" (in addition to other withholdings).
- Teacher resignation effective 2026-12-31.
- System generates ABP-afmelding on 2026-12-31, submitted to ABP by 2027-01-07.

---

## REQ-008: Bekostiging-leverancier (DUO)

**GIVEN** een school met een geldig BRIN-nummer en een vastgestelde leerlingen-teldatum  
**WHEN** de kwartaalleverancier-cycle draait (1 januari, 1 april, 1 juli, 1 oktober)  
**THEN** the system stelt een DUO-bekostigingsverzoek samen met leerling-aantallen per onderwijssoort (vmbo-b/k/g/t, havo, vwo), verstuurt dit via de DUO-zakelijk-API, en boekt het ontvangen bedrag op de bekostigings-grootboekrekening. Discrepanties tussen verwacht en ontvangen bedrag worden in een werklijst voor de schooladministratie geplaatst.

**Acceptance criteria:**
- Scheduled job runs on 1 Jan, 1 Apr, 1 Jul, 1 Oct.
- For each school with `organisation.cao = "vo"` and valid BRIN:
  - Reads `hrmq_cao_vo_school.leerlingen_aantal_per_onderwijssoort` (JSONB: vmbo_b, vmbo_k, havo, vwo).
  - Calls DUO-zakelijk API endpoint with: BRIN, vestigingsnummer, kwartaal-ID, pupil counts per type.
  - API returns: verwacht_bedrag (expected funding for quarter).
  - Creates `hrmq_cao_vo_duo_bekostiging` record: kwartaal, verwacht_bedrag, ontvangen_bedrag=NULL (pending).
- Bank-reconciliation job (separate process) matches incoming payments to expected amounts; updates `ontvangen_bedrag`.
- Daily job checks variance: if |ontvangen - verwacht| > 5% of verwacht, creates worklist entry for HR-admin: "DUO-discrepantie kwartaal 2, EUR -3250 verschil; review gewijzigde leerlingentelling?"
- HR-admin can update `leerlingen_aantal_per_onderwijssoort` in school config; next quarter's request uses new values.

**Test scenario:**
- School (GYM-AMSTERDAM): BRIN=0200, leerlingen_aantal_per_onderwijssoort = { havo: 150, vwo: 200 }.
- Scheduled job on 2026-01-01:
  - Calls DUO API; returns verwacht_bedrag = EUR 487.500 (kwartaal 1).
  - Creates DUO-bekostiging record.
- Bank receives EUR 487.500 on 2026-02-20 (reconciliation matches).
- `ontvangen_bedrag` updated to EUR 487.500; verschil = 0 (no worklist entry).
- Scheduled job on 2026-04-01 (Q2):
  - Expected: EUR 487.500 (same pupil count).
  - API call succeeds.
- Bank receives EUR 484.250 on 2026-05-10.
- Variance = -EUR 3250 (-0.67%).
- Since variance > 5% threshold (EUR 24.375), worklist entry created: "DUO discrepantie Q2: EUR -3250; mogelijk gewijzigde leerlingentelling."
- HR-admin checks; discovers 5 VWO-students transferred mid-year; updates leerlingen_aantal_per_onderwijssoort.
- Note added to DUO-bekostiging record: "VWO-transfer mid-quarter; DUO adjusted."

---

## REQ-009: BAPO / Seniorenregeling

**GIVEN** een docent van 57+ met een vast contract  
**WHEN** de docent kiest voor de seniorenregeling (170 uur per jaar minder werken tegen gedeeltelijke salaris-inlevering)  
**THEN** the system reduces the lesuren-cap proportioneel, applies the salaris-korting per CAO-tabel (afhankelijk van leeftijd en omvang regeling), en past de pensioen-opbouw aan (volledige opbouw blijft, conform OBP/BAPO-regeling). De keuze is jaarlijks herzienbaar met de juli-cyclus.

**Acceptance criteria:**
- BAPO election form: teacher enters birth date; if age >= 57, BAPO option is displayed.
- Options: "Geen BAPO" (default), "BAPO 57+" (170-hour reduction per CAO).
- On selection of BAPO-57+: system updates `employee_extension.bapo_regeling = "57plus"`, `bapo_omvang_uren = 170`.
- Lesuren-cap reduced: max lesuren = 750 - 170 = 580 per FTE (pro-rata for part-time).
- Salary deduction: per CAO-table BAPO-bijlage (varies by age; 2026 typical: 5–8% salary reduction for 57+).
  - Example: teacher earns EUR 4500 bruto; BAPO deduction 6% = EUR 270/month less.
  - Loonstrook line-item: "BAPO-korting 57+: -EUR 270".
- Pension accrual: teacher continues full pension contributions (franchise still applies, premium still withheld); BAPO-reduction does not lower pension basis.
- Annual review: each July, BAPO election can be changed for next schooljaar.
- Audit log records: election date, from/to BAPO status, teacher name.

**Test scenario:**
- Teacher: born 1966-03-15 (age 60 in 2026), salary EUR 4500, lessons 750/year.
- Selects BAPO-57+ on 2026-06-01.
- System updates: bapo_regeling="57plus", bapo_omvang_uren=170, lesuren_cap=580.
- Salary: EUR 4500 × (1 - 0.06) = EUR 4230/month.
- Loonstrook: "Gross salary EUR 4500, BAPO-korting 57+ EUR -270, Net EUR 4230".
- Pension: ABP premium still withheld on EUR 4500 (full amount, not reduced).
- July 2027: teacher can opt back to "Geen BAPO"; system reverts bapo_regeling="geen", lesuren_cap=750, salary back to EUR 4500.

---

## REQ-010: Jaaropgaaf en IB47

**GIVEN** een kalenderjaar is afgesloten met alle 12 loonstroken finaal  
**WHEN** de jaarrun draait op 5 januari  
**THEN** the system genereert per docent een jaaropgaaf (zelfde format als REQ-005 in zzp-dga-mode maar met OW-pensioen-specifieke velden), publiceert deze in de docent-self-service, en stelt een IB47-levering samen voor de Belastingdienst (gastdocenten, vakantiekrachten, examinatoren) met de aggregated bedragen per BSN.

**Acceptance criteria:**
- Annual batch job runs on 5 Jan (for prior calendar year).
- For each teacher employed any time in prior year: generate jaaropgaaf document.
- Jaaropgaaf contents (per CAO VO):
  - Employee name, BSN, schaal, start/end trede, number of step increments.
  - Total bruto salary (12-month aggregate).
  - Arbeidsmarkttoelage (per vakgebied, total).
  - Overschrijdingstoeslag (total, if applicable).
  - Salary deductions (loonheffingen, SV-contributions, ABP-pension, BAPO-korting).
  - Net salary (paid).
  - ABP-pensioen details: franchise, premium rate, total employee-contribution, employer-contribution, accrued pension credit.
  - Leerlingen-benefit (if applicable; school-specific benefit).
- Jaaropgaaf published in teacher self-service: Dashboard › Loonstroken › Jaaropgaven, downloadable as PDF.
- IB47 file generated for Belastingdienst:
  - One row per teacher per inkomstenbron (e.g., 2 rows if teacher was at 2 schools).
  - Fields: BSN, name, income-type (docent_onderwijs_vast_contract vs. docent_onderwijs_gastwerk), year, total gross, withholding (loonheffingen).
  - File submitted to Belastingdienst via openconnector (bulk upload, usually via XML or EDI).
- HR-admin can view jaaropgaaf/IB47 status dashboard: list generated, submitted, acknowledged.

**Test scenario:**
- Teacher: LB-trede-8 (Jan), promoted to LC-trede-1 (Aug); teaches wiskunde+nask.
- Calendar year 2026: 7 months LB, 5 months LC.
- Jaaropgaaf:
  - Name: John Doe, BSN: 123456789, schaal progression: LB (trede 8) → LC (trede 1).
  - 1 step increment (Aug): trede LB-8 → LC-1 (not a traditional step, but a promotion).
  - Bruto (aggregate): EUR 3700 × 7 + EUR 4000 × 5 = EUR 45,900.
  - Arbeidsmarkttoelage (aggregate): EUR 250 (7 months) + EUR 280 (5 months) = EUR 530.
  - Overschrijdingstoeslag: EUR 0 (no overschrijding).
  - Deductions: loonheffingen EUR 12,000, SV-contributions EUR 2,800, ABP-pension EUR 3,600.
  - Net salary: EUR 27,500.
  - ABP: franchise EUR 18.275, premium 27.9%, employee-contribution EUR 3,600, employer-contribution EUR 5,400, accrued credit EUR 12,340.
- Jaaropgaaf published in self-service on 2026-01-05.
- Teacher can download and print for tax-filing.
- IB47 file generated: 1 row (single school), income-type=docent_onderwijs_vast_contract, gross=EUR 46,430, withholding=EUR 12,000.
- IB47 submitted to Belastingdienst by 2026-02-01.

---

## Non-Functional Requirements

### Performance
- Schaal-table lookups (110k employees/month) must complete in <100ms per payroll-run iteration (cached in memory).
- Lerarenregister API calls batch where possible (daily batch of pending promotions).
- DUO/ABP/Vervangingsfonds API calls are asynchronous; no block on payroll completion.

### Availability
- Lerarenregister downtime: cache results 24h; block new promotions with retry flag.
- DUO API downtime: scheduled job retries; known failure modes are monitored and escalated.
- Vervangingsfonds: claim registration is non-blocking (submitted asynchronously); status polling is resilient to API outages.

### Audit & Compliance
- Every change to employee_extension (schaal, trede, bevoegdheid, BAPO, taakverdeling) is logged with: timestamp, changed-by (HR-admin name), old value, new value, reason (if provided).
- Payroll calculations are reproducible: for any loonstrook, system can show which schaal-table row and toelage-table row were used.
- DUO-discrepancies are documented and retained (never auto-corrected without HR-admin review).

### Data Privacy
- Jaaropgaaf and IB47 are encrypted at rest and in transit.
- Teacher self-service only shows own data (role-based access control).
- Anonymized aggregates (e.g., average salary per schaal) can be exported to PO-Raad / VO-raad with opt-in consent per school.

