---
status: draft
---

# 30%-regeling Administratie — Specs

## ADDED Requirements

### REQ-030-001: Beschikking-registratie en validatie

HR registers a Belastingdienst-issued 30%-beschikking; system validates statutory bounds (looptijd ≤5 jaar voor nieuwe gevallen, beschikkingsdatum ≤ 4 maanden vóór vanaf-datum).

#### Scenario 1a: Valid beschikking registration

- **GIVEN** HR fills in a beschikking form with `vanaf = 2026-01-01`, `tot = 2031-01-01` (5 years), `beschikkingsdatum = 2025-10-15` (4.5 months before vanaf), categorie = regulier
- **WHEN** the form is submitted
- **THEN** the system accepts the registration, status = "aangevraagd", and creates 5 `Beschikking30Periode` records (2026-2030) with percentage 30/30/20/20/10

#### Scenario 1b: Looptijd exceeds 5 years for nieuwe gevallen

- **GIVEN** HR fills in `vanaf = 2026-01-01`, `tot = 2032-01-01` (6 years), `beschikkingsdatum = 2025-10-15`
- **WHEN** the form is submitted
- **THEN** the system rejects with `validation_error: looptijd_exceeds_max` and message "looptijd overschrijdt wettelijk maximum van 5 jaar (overgangsrecht 8 jaar alleen bij vanaf-datum vóór 2019-01-01)"

#### Scenario 1c: Valid 8-year looptijd for overgangsrecht (pre-2019 start)

- **GIVEN** HR fills in `vanaf = 2018-06-01`, `tot = 2026-06-01` (8 years), `beschikkingsdatum = 2018-03-01`
- **WHEN** the form is submitted
- **THEN** the system accepts it, creates 8 `Beschikking30Periode` records, and sets `oorspronkelijke_looptijd_jaren = 8`

#### Scenario 1d: Beschikkingsdatum > 4 months before vanaf

- **GIVEN** HR fills in `vanaf = 2026-01-01`, `beschikkingsdatum = 2024-01-01` (25 months before vanaf)
- **WHEN** the form is submitted
- **THEN** the system shows warning "beschikkingsdatum is > 4 maanden voor vanaf — valideer bij Belastingdienst dat dit geldige aanvraag-timing is" and allows override with HR confirmation

### REQ-030-002: Maandelijkse loon-impact-berekening tijdens loonrun

During each payroll run, the system calculates 30%-vergoeding per medewerker with active beschikking, including WNT-aftopping and parttime-correctie.

#### Scenario 2a: Standard 30% on regular monthly salary

- **GIVEN** medewerker has active beschikking (30%), bruto-maandloon €5.000 (no parttime, no overages)
- **WHEN** the loonrun executes
- **THEN** system calculates `vergoeding_30_bedrag = €1.500`, books this as belastingvrij, remaining €3.500 as belastingplichtig loon
- **AND** persists `Beschikking30LoonImpact` with `percentage_toegepast = 30`, `vergoeding_30_grondslag_excl_wnt_aftopping = €5.000`

#### Scenario 2b: WNT-aftopping for high earner

- **GIVEN** medewerker has bruto-jaarloon €300.000 (€25.000/month), WNT-norm = €246.000, beschikking in first year (30%)
- **WHEN** the loonrun executes for month 1
- **THEN** system calculates: max-grondslag-for-30% = €246.000; 30% of €246.000 = €73.800/year = €6.150/month
- **AND** `vergoeding_30_bedrag = €6.150` (capped), `wnt_aftopping_bedrag = €54.000` (portion above WNT-norm, ineligible for 30%)
- **AND** remaining €18.850 (surplus above WNT-norm after 30%-reserve) is fully belastingplichtig

#### Scenario 2c: Afbouw-jaar (30→20→10 progression)

- **GIVEN** medewerker in year 3 of beschikking (afbouwjaar 3 = 20%), bruto-maandloon €5.000
- **WHEN** the loonrun executes
- **THEN** system calculates `vergoeding_30_bedrag = €1.000` (20% instead of 30%)
- **AND** `percentage_toegepast = 20`, status-reason = "afbouwregel 2024: jaar 3 = 20%"

#### Scenario 2d: Parttime employee (no pro-rata threshold reduction)

- **GIVEN** medewerker works 24 uur/week (60% FTE), bruto-jaarloon €30.000 (pro-rata), beschikking active in first year (30%)
- **WHEN** the loonrun executes
- **THEN** system applies 30% to monthly bruto (€30.000 / 12 = €2.500) = €750/month vergoeding
- **AND** audit-trail notes: "parttime factor 0.6 applied to loon-calculation; drempel-check will use full FTE equivalent when toetsing occurs"

### REQ-030-003: Jaarlijkse her-toetsing op salarisdrempel

Each January (or on dienstverband-end), system checks if FTE-corrected fiscal loon met €46.660+ threshold; auto-revokes if failed.

#### Scenario 3a: Drempel gehaald - beschikking continues

- **GIVEN** medewerker closed 2025 with fiscal loon excl. 30% = €65.000 (above drempel €46.660)
- **WHEN** the annual toetsing job runs on 2026-01-15
- **THEN** system creates `Beschikking30Toetsing` with `drempel_gehaald = true`, `conclusie = continueren`, `bruto_loon_excl_30 = €65.000`
- **AND** beschikking lifecycle remains "actief"
- **AND** HR receives notification: "Drempel-toetsing 2025 passed — beschikking continues"

#### Scenario 3b: Drempel niet gehaald - auto-intrekking

- **GIVEN** medewerker closed 2025 with fiscal loon excl. 30% = €44.000 (below drempel €46.660)
- **WHEN** the annual toetsing job runs on 2026-01-15
- **THEN** system creates `Beschikking30Toetsing` with `drempel_gehaald = false`, `conclusie = intrekken`
- **AND** auto-creates `Beschikking30Intrekking` with `effectieve_datum = 2026-01-01`, `reden = drempel_niet_gehaald`
- **AND** recalculates all 2025 `Beschikking30LoonImpact` records: 30%-vergoeding is reclassified as belastingplichtig loon
- **AND** computes `terugbetaling_door_werknemer_bedrag` = total 2025 30%-vergoeding over-received
- **AND** HR + medewerker + accountant receive notification with financial impact

#### Scenario 3c: Parttime with ouderschapsverlof (12-month annualization exemption)

- **GIVEN** medewerker worked 10 months (2 months ouderschapsverlof), earned €37.000, FTE 0.8
- **WHEN** the annual toetsing job calculates drempel-check
- **THEN** system annualizes: (€37.000 / 10 maanden) × 12 maanden = €44.400 × 1/0.8 FTE-factor = €55.500 (above drempel)
- **AND** `drempel_gehaald = true`, beschikking continues

#### Scenario 3d: Jonge-onderzoeker lower drempel (€35.468)

- **GIVEN** medewerker is jonge_onderzoeker (age <30, master-diploma), closed 2025 with loon = €38.000
- **WHEN** the annual toetsing job runs
- **THEN** system applies drempel = €35.468 (not €46.660)
- **AND** `drempel_gehaald = true` (€38.000 > €35.468), beschikking continues

### REQ-030-004: Parttime-correctie bij drempeltoetsing

Drempel is NOT pro-rata reduced for parttime; exception only for leave (parental, birth, long sick-leave).

#### Scenario 4a: Parttime 60% FTE - drempel not met, regeling fails

- **GIVEN** medewerker 60% FTE, entire year worked, earned €30.000 (pro-rata)
- **WHEN** annual toetsing runs
- **THEN** system checks: actual loon €30.000 < drempel €46.660
- **AND** `drempel_gehaald = false`, beschikking is ingetrokken (no 30%-vergoeding applies retroactively)

#### Scenario 4b: Parttime 60% + 4 months geboorteverlof - annualized against full-time drempel

- **GIVEN** medewerker 40 uur/week, 4 months geboorteverlof, 8 months worked, earned €28.000
- **WHEN** annual toetsing runs
- **THEN** system annualizes: €28.000 ÷ (8/12) = €42.000 (still < €46.660)
- **AND** `drempel_gehaald = false`, intrekking triggered

#### Scenario 4c: Full-time employee all year - annualization not applied

- **GIVEN** medewerker full-time, 0 verlof, earned €60.000
- **WHEN** annual toetsing runs
- **THEN** system does NOT apply any annualization factor; compares €60.000 directly to drempel €46.660
- **AND** `drempel_gehaald = true`

### REQ-030-005: Alert 6 maanden voor looptijd-einde

HR is alerted 180 days before beschikking expires for contract renegotiation planning.

#### Scenario 5a: First alert at 180 days

- **GIVEN** beschikking eindigt op 2027-06-30
- **WHEN** the date becomes 2027-01-01 (exactly 180 days before expiry)
- **AND** the daily alert-check job runs
- **THEN** system creates action-item for `hr_manager` of the administratie
- **AND** message: "30%-beschikking van {medewerker} verloopt op 2027-06-30 (180 dagen) — plan CAO-conversie of salariscontinuering"
- **AND** sends via NotificationService (in-app + email)

#### Scenario 5b: Escalation alert at 30 days if no action taken

- **GIVEN** the 180-day alert was created on 2027-01-01, but HR did not action it
- **WHEN** the date becomes 2027-06-01 (29 days before expiry) and the alert-check job runs
- **THEN** system creates escalation action-item for `administratie_owner` (not just hr_manager)
- **AND** message: "URGENT: 30%-beschikking {medewerker} expires in 29 days — contract status unresolved"

#### Scenario 5c: Custom alert threshold per administratie

- **GIVEN** administratie-1 has `looptijd_einde_waarschuwing_dagen_vooraf = 90` (not default 180)
- **WHEN** beschikking expires on 2027-06-30 and date becomes 2027-03-31 (90 days before)
- **THEN** system triggers the first alert at 90 days (not 180)
- **AND** respects administratie-specific configuration

### REQ-030-006: WNT-aftopping conform Wet op de Loonbelasting art. 31a lid 8

30%-vergoeding grondslag is capped at WNT-norm; surplus earns no onbelaste vergoeding.

#### Scenario 6a: Exact WNT-norm (no aftopping)

- **GIVEN** medewerker bruto-jaarloon = €246.000 (exact WNT-norm for 2026), year 1 (30%)
- **WHEN** the loonrun executes monthly
- **THEN** system calculates 30% × €246.000 = €73.800/year = €6.150/month vergoeding
- **AND** `wnt_aftopping_bedrag = €0`

#### Scenario 6b: Double WNT-norm (significant aftopping)

- **GIVEN** medewerker bruto-jaarloon = €492.000 (2× WNT-norm), year 1 (30%), monthly = €41.000
- **WHEN** the loonrun executes
- **THEN** system calculates max-30%-grondslag = €246.000, vergoeding = €73.800/year = €6.150/month
- **AND** `wnt_aftopping_bedrag_maand = €41.000 - €6.150 - belastable-portion = €13.575` (the €246k+ portion is fully belast)
- **AND** audit logs: "WNT-aftopping applied: €246.000 cap, surplus €246.000 fully belast"

#### Scenario 6c: WNT-norm increases year-to-year (2026 €246k → 2027 €248k hypothetically)

- **GIVEN** medewerker on 2-year beschikking, year 1 (30%) = €246k threshold, year 2 (30%) = €248k threshold, earner at €250k/year
- **WHEN** the loonrun executes in year 2
- **THEN** system uses `Beschikking30Periode.wnt_norm_jaar = €248.000` for year 2
- **AND** calculates 30% × €248.000 (not €246k), adjusting vergoeding to reflect new WNT-norm

### REQ-030-007: Intrekking met correctieaangifte

Revocation with retroactive effect triggers automatic correction filing.

#### Scenario 7a: Automatic intrekking (drempel-fail) with full-year correctie

- **GIVEN** medewerker was in beschikking full 2025, received 30%-vergoeding every month (€1.500 × 12 = €18.000), toetsing shows drempel failed
- **WHEN** auto-intrekking triggers with `effectieve_datum = 2025-01-01`
- **THEN** system generates `Beschikking30Intrekking` with `correctieaangifte_vereist = true`
- **AND** recalculates all 12 × `Beschikking30LoonImpact` records for 2025: 30%-vergoeding reclassified as belastingplichtig loon
- **AND** computes `terugbetaling_door_werknemer_bedrag = €18.000` (total over-received 30%)
- **AND** generates correctieaangifte loonheffingen (via journaalpost-export) ready for Digipoort filing
- **AND** notifies HR: "Intrekking 2025 — correctieaangifte for {medewerker} prepared, review attachment before filing"

#### Scenario 7b: Manual intrekking (medewerker moved abroad) with partial-year terugwerkende kracht

- **GIVEN** HR manually intrekks beschikking effective 2026-07-01 (terugwerkende kracht from half-year)
- **WHEN** HR confirms intrekking in system
- **THEN** system creates `Beschikking30Intrekking` with `effectieve_datum = 2026-07-01`, `reden = expat_verhuist_terug`
- **AND** recalculates `Beschikking30LoonImpact` for Jan-Jun 2026 only (July-Dec remain as-is)
- **AND** computes terugbetaling for first 6 months only
- **AND** generates correctieaangifte covering Jan-Jun retroactively
- **AND** HR + medewerker notified with corrected amount due

#### Scenario 7c: Intrekking record is immutable audit-trail

- **GIVEN** a `Beschikking30Intrekking` record is created
- **WHEN** HR views the record
- **THEN** system displays it as read-only, with full audit-trail: created-by, created-at, approved-by, approved-at
- **AND** all downstream artifacts (correctieaangifte, journaalpost) are immutable once the intrekking is finalized

### REQ-030-008: Partial-non-resident keuze (2024-2026, vervallen 2027)

System tracks PNR-selection for box 2/3 tax-filing; flags deprecation in 2027+.

#### Scenario 8a: PNR-selection in 2026 (valid)

- **GIVEN** HR registers beschikking with `partial_non_resident_gekozen = true`, beschikkingsjaar = 2026
- **WHEN** the form is submitted
- **THEN** system accepts it, stores the flag, and notes "PNR-keuze registered for 2026"
- **AND** exports "PNR applicable" in jaaropgave-export alongside the beschikking details

#### Scenario 8b: PNR-selection attempt in 2027+ (rejected with warning)

- **GIVEN** HR tries to register beschikking with `partial_non_resident_gekozen = true`, beschikkingsjaar = 2027
- **WHEN** the form is submitted
- **THEN** system rejects with warning: "Partial-non-resident keuze is per 2027 vervallen op basis van wetswijziging — vergewis u van de actuele belastingadvies"
- **AND** forces `partial_non_resident_gekozen = false`

#### Scenario 8c: Existing 2026 PNR-selections remain valid

- **GIVEN** a valid PNR=true beschikking from 2026 is stored in system
- **WHEN** the date advances to 2027
- **THEN** system does NOT retroactively delete or alter the 2026 record
- **AND** displays a yellow flag: "PNR-selection on this 2026 beschikking is no longer applicable per 2027 — review with tax-adviser"

### REQ-030-009: Salarisdrempel-marge alert YTD

During the year, system warns if YTD-loon is within 5% of drempel threshold; HR can intervene.

#### Scenario 9a: YTD-loon within 5% below drempel (alert triggered)

- **GIVEN** medewerker on beschikking, drempel = €46.660, YTD-date is Sept 30, YTD-loon (excl. 30%) = €33.000
- **WHEN** the daily drempel-marge-check runs
- **THEN** system annualizes: €33.000 × (12/9) = €44.000
- **AND** margin = (€46.660 - €44.000) / €46.660 = 5.7% (within 5% alert-threshold)
- **AND** generates alert for HR: "Drempel-risico {medewerker}: €2.660 tekort op basis van huidige loonprojectie (geannualiseerd €44k vs drempel €46.66k)"
- **AND** suggests action: "Bonus, raise, or shift expected for Q4 to preserve 30%-eligibility"

#### Scenario 9b: YTD-loon below 5% margin (no alert)

- **GIVEN** same medewerker, YTD Sept = €35.000, annualized = €46.667 (just above drempel)
- **WHEN** the drempel-marge-check runs
- **THEN** system calculates margin = (€46.660 - €46.667) / €46.660 = -0.015% (above safety margin)
- **AND** generates NO alert (loon is trending safely above drempel)

#### Scenario 9c: HR can dismiss YTD-alert if corrective action planned

- **GIVEN** a drempel-risk alert was generated, HR knows a bonus is planned in Dec
- **WHEN** HR marks the alert as "acknowledged — corrective action planned"
- **THEN** system suppresses further duplicate alerts for this medewerker until year-end toetsing
- **AND** includes HR's note in audit-trail: "Bonus €5.000 planned Dec — drempel will be met"

### REQ-030-010: Documentatie en bewijslast voor Belastingdienst-controle

Per-medewerker bewijspakket (PDF) exportable for tax-audit response.

#### Scenario 10a: Generate bewijspakket for single medewerker, single year

- **GIVEN** HR requests export for medewerker "John Smith", administratie "Scale-up A", year-range 2024-2025
- **WHEN** HR clicks "Export Bewijspakket"
- **THEN** system generates a PDF containing:
  1. Cover: administratie-name, medewerker-name, date-range, export-date
  2. Gescande beschikking (from document-vault)
  3. All `Beschikking30Periode` records for 2024-2025
  4. All `Beschikking30Toetsing` records for 2024-2025
  5. All `Beschikking30LoonImpact` records (monthly breakdown) for 2024-2025
  6. Any `Beschikking30Intrekking` records if applicable, with correctieaangifte-status
  7. Summary table with beschikking-nr, jaren, status, next-toetsing-date

#### Scenario 10b: Belastingdienst audit - complete administratie export

- **GIVEN** Belastingdienst announces boekenonderzoek for administratie "Scale-up A" covering 2023-2025
- **WHEN** HR runs "Export Bewijspakket — entire administratie, 2023-2025"
- **THEN** system generates one master PDF with subsections per medewerker (John, Jane, Jan, Jaqueline)
- **AND** each subsection contains complete documentation (beschikking, periodes, toetsingen, loon-impacts, intrekkingen)
- **AND** PDF is digitally signed (verifiable provenance) and includes export-timestamp
- **AND** HR can email directly to auditor or upload to Belastingdienst's investigation platform

#### Scenario 10c: Missing gescande beschikking - PDF notes the gap

- **GIVEN** medewerker "Alex" has a registered beschikking but `bron_document_uri` is null (gescande beschikking never uploaded)
- **WHEN** bewijspakket is generated
- **THEN** system includes a red-flag note in Alex's section: "MISSING: Gescande beschikking — upload required for audit-readiness"
- **AND** still includes all other documentation (periodes, loon-impacts, toetsingen)
- **AND** alerts HR to remedy this gap before Belastingdienst contact

---

**Specs complete. Ready to author tasks.**
