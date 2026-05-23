# Specifications: zzp-dga-single-person-mode

## Entities

Extends the HRMQ data model with:

- **hrmq_organisation**: adds `mode` (enum: `standard`, `dga_single_person`) and `dga_employee_id` (FK to hrmq_employee, nullable)
- **hrmq_dga_profile**: 1:1 with hrmq_employee (the DGA), contains fiscal metadata
- **hrmq_dga_ib_export**: annual IB-handover package record with delivery tracking
- **hrmq_accountant_delegation**: delegation model for external accountant access control

## Requirements

### REQ-001-001: Organisation mode toggle

**GIVEN** an HRMQ organisation with exactly one employee record marked as DGA  
**WHEN** an admin sets `organisation.mode = "dga_single_person"` via settings UI  
**THEN** the system validates that exactly one employee exists with `is_dga = true`, locks the organisation to that employee, hides all multi-employee navigation (teams, approvals, org chart, leave kalender voor anderen), and surfaces the DGA dashboard as the default landing page.

**Acceptance Criteria:**
- Validation: exactly one active employee with `is_dga = true` exists before mode switch
- Post-switch: multi-employee menus (Medewerkers, Verlof & verzuim organogram, approval queues) are hidden
- Post-switch: default landing page is DGA dashboard (not standard Dashboard)
- Reversible: mode can be switched back to `standard` with audit trail
- Event: emits `OrganisationModeChanged` for downstream consumers (pension, filing)

---

### REQ-001-002: Mode reversibility on first hire

**GIVEN** an organisation in `dga_single_person` mode  
**WHEN** an admin attempts to add a second employee record (first hire)  
**THEN** the system prompts to switch back to `standard` mode, preserves all DGA-specific data on the DGA's own employee record (which becomes "mijn loon" tab), unhides multi-employee navigation, and emits an `OrganisationModeChanged` event.

**Acceptance Criteria:**
- Prompt appears before second employee is saved
- DGA profile data is preserved (not deleted) during mode switch
- All DGA widgets/data become read-only tabs on the DGA's own employee detail page
- Multi-employee menus reappear after mode switch
- Audit trail records the mode switch and timestamp

---

### REQ-002-001: Gebruikelijk-loon norm tracking

**GIVEN** a DGA in single-person mode for a given kalenderjaar  
**WHEN** the system computes the gebruikelijk-loon norm (default EUR 56,000 for 2026, indexed annually)  
**THEN** the dashboard shows: current norm, year-to-date paid loon, projected year-end loon at current run rate, and a status indicator (green if on track, amber if <90% projected, red if <80% projected with <3 months remaining).

**Acceptance Criteria:**
- Default norm: EUR 56,000 for 2026, indexed annually per Belastingdienst table
- Norm basis stored in `hrmq_dga_profile.gebruikelijk_loon_norm_basis` with enum: `wettelijk_56000`, `meestverdienende_werknemer`, `vergelijkbare_dienstbetrekking`, `lager_aannemelijk_gemaakt`
- Non-default basis requires free-text motivering in `gebruikelijk_loon_norm_motivering` (verplicht)
- YTD tracking: sums all `loonstrook.bruto` records from 1-1 to current date
- Projected YTD: `(YTD_bruto / days_elapsed) * 365`
- Status logic: green ≥ 100%, amber 90–99%, red <90% (amber) or <80% with <3 months remaining (red)
- Norm basis change requires admin re-attestation with audit trail
- Dashboard widget updates daily (or on-demand refresh)

---

### REQ-003-001: Monthly loonstrook generation (DGA flavour)

**GIVEN** a DGA with a contract specifying monthly bruto loon  
**WHEN** the payroll engine runs the monthly cycle on the 25th  
**THEN** a loonstrook PDF is generated showing bruto, loonheffing (groene tabel if AOW-gerechtigd, witte tabel otherwise), ZVW-bijdrage (werkgeversheffing 6.57% for 2026 over max EUR 75.864), netto, plus a DGA-specific section showing year-to-date totals against the gebruikelijk-loon norm.

**Acceptance Criteria:**
- Cycle trigger: hardcoded monthly on 25th (or previous business day if 25th is weekend/holiday)
- PDF template: extends standard loonstrook with DGA section below netto
- DGA section shows:
  - YTD bruto paid this year
  - Gebruikelijk-loon norm for the year
  - Remaining amount needed to meet norm
  - Status indicator (same as dashboard)
- Loonheffing table selection: groene (AOW-gerechtigd: age ≥ 63 on 1-1 of tax year) vs. witte
- ZVW: 6.57% × bruto (capped at EUR 75.864 ceiling per Belastingsdienst 2026)
- Loonstrook filed in DGA's documents folder with standardised naming: `loonstrook_YYYY_MM.pdf`
- Email sent to configured contact address (from DGA profile)

---

### REQ-004-001: ZVW running total and werkgevers-deel reconciliation

**GIVEN** a DGA receiving monthly loon throughout the kalenderjaar  
**WHEN** the dashboard renders the ZVW widget  
**THEN** the widget shows: year-to-date ZVW werkgeversheffing paid by the BV, the maximum bijdrage-inkomen ceiling for the year, and a flag if the BV has paid >100% of the ceiling (overpayment to be reclaimed).

**Acceptance Criteria:**
- YTD ZVW: sum of all `loonstrook.zvw_werkgeversbijdrage` for the kalenderjaar
- Ceiling: EUR 75.864 for 2026, indexed annually
- Overpayment flag: `YTD_ZVW > ceiling` triggers warning "ZVW bijdrage overschreden — restitutieverzoek indienen"
- Additional inkomen note: if `hrmq_dga_profile.additional_inkomen` is set, widget shows informational: "De jaaropgaaf zal alle bronnen reconciliëren"
- Widget updates on loonstrook generation or daily refresh

---

### REQ-005-001: Jaaropgaaf generation

**GIVEN** a kalenderjaar has ended and all 12 loonstroken have been generated and finalised  
**WHEN** the DGA (or accountant with delegation) clicks "Genereer jaaropgaaf"  
**THEN** the system produces the standard Belastingdienst jaaropgaaf PDF (loon, loonheffing, ZVW, arbeidskorting, verrekende heffingskortingen) plus a machine-readable JSON copy for the IB-pakket.

**Acceptance Criteria:**
- Validation: all 12 loonstroken for the year exist and are finalised (not draft)
- PDF format: Belastingdienst loonaangifte-XSD 2026 compliant
- JSON export includes: loon, loonheffing, ZVW, arbeidskorting, verrekende heffingskortingen, FOR-saldo, lijfrente-aftrek
- Jaaropgaaf locked once generated (immutable): `status = 'definitief_aangeleverd'`
- Corrections require formal correctieboeking with audit trail
- File naming: `jaaropgaaf_YYYY.pdf` and `jaaropgaaf_YYYY.json`
- Stored in DGA's documents folder and IB-export bundle

---

### REQ-006-001: FOR-afbouw tracking

**GIVEN** a DGA with a legacy Fiscale Oudedagsreserve saldo at 1-1-2023 (the year FOR-opbouw stopped)  
**WHEN** the DGA records annual onttrekkingen (omzetting in lijfrente or opname als belast inkomen)  
**THEN** the system tracks the lopende FOR-saldo, shows it on the dashboard, and includes it in the IB-handover under "FOR-saldo per 31-12".

**Acceptance Criteria:**
- Data model: `hrmq_dga_profile.for_saldo_opening` (opening balance as of 2023-01-01)
- Onttrekkingen recorded in `hrmq_dga_profile.for_onttrekkingen` (jsonb timeline: `{ date, amount, type }` where type = `lijfrente` or `belast_inkomen`)
- Dashboard widget: shows opening + timeline, calculates current saldo
- Read-only for jaren ≥ 2023: no new dotaties possible; field is locked
- Annual snapshot: FOR-saldo as of 31-12 included in jaaropgaaf JSON
- IB-handover includes FOR-overzicht (opening + onttrekkingen + closing saldo)

---

### REQ-007-001: Lijfrente jaarruimte berekening

**GIVEN** a DGA with persoonlijke jaargegevens (premiegrondslag, AOW-leeftijd, pensioenaangroei)  
**WHEN** the system computes the lijfrente-jaarruimte for the lopende kalenderjaar  
**THEN** the dashboard shows the available aftrekruimte (formule 2026: 30% × premiegrondslag − factor-A × 6.27, max EUR 36.077), the reserveringsruimte (10 jaar terug), and a note linking to the relevant Belastingdienst-pagina.

**Acceptance Criteria:**
- Data model: `hrmq_dga_profile.lijfrente_polissen` (jsonb array: `{ verzekeraar, polisnummer, stortingen_per_jaar[], factor_a }`)
- Formula: available_ruimte = min(30% × premiegrondslag − factor_a × 6.27, 36077)
- Premiegrondslag: derived from annual loon (see REQ-003-001, YTD bruto)
- Factor A: insurer-provided annuity factor, stored per polis
- Reserveringsruimte: sum of available ruimte from past 10 years (for current + prior 9 years)
- Dashboard widget: shows current year ruimte, 10-year reserve, link to Belastingdienst info page
- Actual storting recorded by DGA and feeds IB-handover (lijfrente-overzicht)
- Widget updates annually on jaaropgaaf generation or on-demand

---

### REQ-008-001: IB-pakket export for accountant

**GIVEN** a finalised jaaropgaaf and complete FOR/lijfrente/box-2 data for a kalenderjaar  
**WHEN** the DGA clicks "Lever IB-pakket aan accountant"  
**THEN** the system bundles into a single ZIP: jaaropgaaf-PDF + JSON, FOR-overzicht, lijfrente-overzicht, aanmerkelijk-belang-overzicht (dividenduitkeringen + verkrijgingsprijs), and a manifest.json index.

**Acceptance Criteria:**
- ZIP bundle contents:
  - `jaaropgaaf_YYYY.pdf` (PDF)
  - `jaaropgaaf_YYYY.json` (machine-readable)
  - `for_overzicht_YYYY.json` (opening + onttrekkingen + closing)
  - `lijfrente_overzicht_YYYY.json` (polissen + stortingen + factor-A data)
  - `aanmerkelijk_belang_YYYY.json` (box-2 dividends + verkrijgingsprijs)
  - `manifest.json` (index with file descriptions, DGA info, creation date, accountant endpoint)
- Bundle uploaded to accountant's configured deliverypath (SFTP, Nextcloud share, email)
- Status: marked `definitief_aangeleverd` with timestamp
- Accountant acknowledgement tracked in `hrmq_dga_ib_export.accountant_acknowledgement_at`
- File naming: `ib_pakket_YYYY.zip`

---

### REQ-009-001: Accountant-of-record delegation

**GIVEN** a DGA wishes to delegate payroll-administration to an external accountant  
**WHEN** the DGA invites an accountant via the settings UI with role `accountant_of_record`  
**THEN** the accountant gains read-write access to payroll, jaaropgaaf, and IB-pakket export, but no access to the DGA's persoonlijke postvak, lijfrente-polis details (read-only), or any inhoudelijke documenten outside payroll-scope.

**Acceptance Criteria:**
- Data model: `hrmq_accountant_delegation` with `dga_employee_id`, `accountant_user_id`, `granted_at`, `revoked_at`, `permissions[]` (subset of: `read_payroll`, `write_payroll`, `read_jaaropgaaf`, `export_ib_pakket`)
- Invitation flow: DGA invites accountant by email; accountant accepts and role is granted
- Permissions: accountant can read/write payroll, generate jaaropgaaf, export IB-pakket, but:
  - NO access to persoonlijke postvak (documents outside payroll scope)
  - Lijfrente-polis details: read-only (no write)
  - NO access to DGA's contract, persoonlijke data, or HR records
- Audit trail: logs all actions by delegated accountant
- Revocation: DGA can revoke delegation at any time; accountant loses all access immediately
- Multi-tenant switching: accountant can switch between client organisations with delegated access

---

### REQ-010-001: Standards compliance

**GIVEN** DGA mode is operational  
**WHEN** the system generates loonstrook, jaaropgaaf, and IB-pakket  
**THEN** all artefacts comply with applicable Dutch fiscal standards.

**Standards enforced:**
- Loonheffingen: Wet op de loonbelasting 1964, Uitvoeringsregeling loonbelasting 2011
- Gebruikelijk-loon: Art. 12a Wet LB 1964
- ZVW werkgeversheffing: 6.57% over bijdrage-inkomen, max EUR 75.864 (2026 rates)
- FOR-afbouw: Belastingplan 2023 (geen nieuwe dotaties vanaf 2023; bestaand saldo blijft)
- Lijfrente: Art. 3.124–3.129 Wet IB 2001, jaarruimte-formule
- Jaaropgaaf-formaat: Belastingdienst loonaangifte-XSD 2026

**Acceptance Criteria:**
- Compliance testing: annual audit against Belastingdienst specs and sample test cases
- Rate tables updated annually (indexed per Belastingsdienst tables)
- PDF/JSON exports validated against XSD schemas
- Integration tests verify loonheffing + ZVW calculations against published examples

---

## Data Model Extensions

### hrmq_dga_profile

```
id                                      UUID, PK
employee_id                             UUID, FK → hrmq_employee (1:1)
aanmerkelijk_belang_percentage          decimal(5,2)
gebruikelijk_loon_norm_year             integer (e.g., 2026)
gebruikelijk_loon_norm_basis            enum (wettelijk_56000, meestverdienende_werknemer, vergelijkbare_dienstbetrekking, lager_aannemelijk_gemaakt)
gebruikelijk_loon_norm_motivering       text (required if basis != wettelijk_56000)
for_saldo_opening                       decimal(12,2)
for_onttrekkingen                       jsonb (timeline: [{date, amount, type}])
lijfrente_polissen                      jsonb (array: [{verzekeraar, polisnummer, stortingen_per_jaar[], factor_a}])
box2_dividenduitkeringen                jsonb (timeline: [{date, amount}])
box2_verkrijgingsprijs                  decimal(12,2)
created_at                              timestamp
updated_at                              timestamp
```

### hrmq_dga_ib_export

```
id                                      UUID, PK
dga_employee_id                         UUID, FK → hrmq_employee
calendar_year                           integer
status                                  enum (concept, definitief_aangeleverd)
accountant_endpoint_id                  UUID, FK → delivery_target (optional)
pakket_blob_url                         text (S3/SFTP URL)
generated_at                            timestamp
accountant_acknowledgement_at           timestamp (nullable)
created_at                              timestamp
updated_at                              timestamp
```

### hrmq_accountant_delegation

```
id                                      UUID, PK
dga_employee_id                         UUID, FK → hrmq_employee
accountant_user_id                      UUID, FK → hrmq_user
granted_at                              timestamp
revoked_at                              timestamp (nullable)
permissions                             text[] (enum subset: read_payroll, write_payroll, read_jaaropgaaf, export_ib_pakket)
created_at                              timestamp
updated_at                              timestamp
```

### hrmq_organisation (extended)

```
mode                                    enum (standard, dga_single_person) — default: standard
dga_employee_id                         UUID, FK → hrmq_employee (nullable, required if mode = dga_single_person)
```
