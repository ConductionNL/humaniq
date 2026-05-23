---
status: proposed
change: cao-onderwijs-vo
version: 1.0
---

# Design: CAO Voortgezet Onderwijs

## Architecture Overview

The CAO-VO module is a configuration-driven subsystem that:

1. **Loads versioned pay-scale tables** per CAO agreement (renegotiated ~every 2 years).
2. **Extends the employee record** with VO-specific fields (schaal, trede, bevoegdheid, Lerarenregister-ID).
3. **Hooks into payroll calculations** to apply VO rules (step increments, hour-cap surtax, market-scarcity supplements).
4. **Integrates with external services** (Lerarenregister for certification, DUO for pupil-enrollment funding, ABP for pension, Vervangingsfonds for substitute claims).
5. **Exposes teacher self-service views** of CAO elements (schaal explanation, audit trail of promotions, pension-saldo).

## Data Model

### Core Entities

#### `hrmq_cao_vo_schaal_table`
Versioned pay-scale table per CAO agreement.

| Column | Type | Notes |
|--------|------|-------|
| id | UUID | Primary key |
| cao_version | VARCHAR | e.g., "2024-2026", "2026-2028" |
| geldig_vanaf | DATE | Effective date; typically 1 Aug (start of Dutch school year) |
| geldig_tot | DATE | NULL = current active table |
| schaal | ENUM(LA, LB, LC, LD, LE) | Pay scale; LA lowest, LE highest |
| trede | INT (1–20) | Step within scale; higher = longer service |
| bruto_maandloon_fulltime | DECIMAL(10,2) | Monthly salary 1.0 FTE, EUR |
| bruto_jaarloon_fulltime | DECIMAL(12,2) | Annual salary 1.0 FTE, EUR |
| created_at | TIMESTAMP | Audit |
| created_by | UUID | HR-admin who imported |

**Indexes:** (cao_version, geldig_vanaf, schaal, trede); (geldig_tot) for active-table queries.

**Seed data:**
```
ID: 550e8400-e29b-41d4-a716-446655440001, cao_version: 2024-2026, geldig_vanaf: 2024-08-01, geldig_tot: 2026-07-31
  schaal=LB, trede=1, bruto_maandloon=3.245,00, bruto_jaarloon=38.940,00
  schaal=LB, trede=2, bruto_maandloon=3.412,00, bruto_jaarloon=40.944,00
  ...
  schaal=LC, trede=1, bruto_maandloon=3.890,00, bruto_jaarloon=46.680,00
  schaal=LC, trede=12, bruto_maandloon=5.120,00, bruto_jaarloon=61.440,00

ID: 550e8400-e29b-41d4-a716-446655440002, cao_version: 2026-2028, geldig_vanaf: 2026-08-01, geldig_tot: NULL (current)
  schaal=LB, trede=1, bruto_maandloon=3.342,00, bruto_jaarloon=40.104,00 (3% increase)
  ...
```

#### `hrmq_cao_vo_arbeidsmarkttoelage_table`
Market-scarcity supplements (skill shortages).

| Column | Type | Notes |
|--------|------|-------|
| id | UUID | Primary key |
| cao_version | VARCHAR | Links to schaal_table |
| geldig_vanaf | DATE | Effective date |
| geldig_tot | DATE | NULL = active |
| vakgebied | VARCHAR | "wiskunde", "natuurkunde", "scheikunde", "Duits", "Frans", "informatica" |
| toelage_pct | DECIMAL(5,2) | Percentage of bruto, e.g., 4.5% for wiskunde |
| created_at | TIMESTAMP | Audit |

**Seed data:**
```
ID: 660e8400-e29b-41d4-a716-446655440001, cao_version: 2024-2026, geldig_vanaf: 2024-08-01, geldig_tot: 2026-07-31
  vakgebied=wiskunde, toelage_pct=4.50
  vakgebied=natuurkunde, toelage_pct=4.50
  vakgebied=scheikunde, toelage_pct=4.50
  vakgebied=Duits, toelage_pct=3.00
  vakgebied=Frans, toelage_pct=2.00
  vakgebied=informatica, toelage_pct=3.50

ID: 660e8400-e29b-41d4-a716-446655440002, cao_version: 2026-2028, geldig_vanaf: 2026-08-01, geldig_tot: NULL
  vakgebied=wiskunde, toelage_pct=5.00 (increased scarcity)
  ...
```

#### `hrmq_cao_vo_employee_extension`
1:1 extension of `employee` record with VO-specific fields.

| Column | Type | Notes |
|--------|------|-------|
| id | UUID | Primary key |
| employee_id | UUID | Foreign key to employee |
| organisation_id | UUID | School organisation |
| schaal | ENUM(LA, LB, LC, LD, LE) | Current pay scale |
| trede | INT (1–20) | Current step |
| laatste_periodiek_datum | DATE | Last annual step increment; triggers next on anniversary |
| vakgebieden | JSONB | ["wiskunde", "Duits"] — subjects taught; drives toelage calculation |
| bevoegdheid | ENUM(eerstegraads, tweedegraads, onbevoegd) | Teaching qualification level |
| lerarenregister_id | VARCHAR | Unique ID in national teacher register |
| lerarenregister_geldig_tot | DATE | Certification expiry; NULL = no expiry |
| lerarenregister_checked_at | TIMESTAMP | Last validation against Lerarenregister API |
| taakomvang_lesuren_per_jaar | INT | Annual classroom hours contracted, e.g., 750 (standard) or 450 (0.6 FTE) |
| taakomvang_overige_uren | INT | Administrative/prep hours per year (if tracked separately) |
| vakvolledigheid_pct | DECIMAL(5,2) | Workload coverage %; affects SV-contributions (typically 100%) |
| bapo_regeling | ENUM(geen, 60plus, 57plus) | Senior regulation (None, 60+, 57+) |
| bapo_omvang_uren | INT | Annual hours reduction under senior rule, e.g., 170 for 57+ |
| uitloopschaal | BOOLEAN | TRUE = teacher is on holdover scale (promotes upon new hire, not this employee) |
| trede_blokkering_reden | VARCHAR | If trede cannot advance (e.g., "max bereikt"), reason code |
| created_at | TIMESTAMP | Audit |
| updated_at | TIMESTAMP | Audit |

**Seed data:**
```
ID: 770e8400-e29b-41d4-a716-446655440001, employee_id: 001, organisation_id: ORG-GYM-AMSTERDAM
  schaal=LC, trede=8, laatste_periodiek_datum=2025-08-01, vakgebieden=["wiskunde","nask1"]
  bevoegdheid=eerstegraads, lerarenregister_id=NL-REG-12345, lerarenregister_geldig_tot=2028-06-30
  taakomvang_lesuren_per_jaar=750, vakvolledigheid_pct=100, bapo_regeling=geen

ID: 770e8400-e29b-41d4-a716-446655440002, employee_id: 002, organisation_id: ORG-GYM-AMSTERDAM
  schaal=LD, trede=12, laatste_periodiek_datum=2024-08-01, vakgebieden=["Duits"]
  bevoegdheid=tweedegraads, lerarenregister_id=NL-REG-12346, lerarenregister_geldig_tot=2026-12-31
  taakomvang_lesuren_per_jaar=450 (0.6 FTE), vakvolledigheid_pct=100, bapo_regeling=57plus, bapo_omvang_uren=170

ID: 770e8400-e29b-41d4-a716-446655440003, employee_id: 003, organisation_id: ORG-VMBO-ROTTERDAM
  schaal=LB, trede=3, laatste_periodiek_datum=2025-06-01, vakgebieden=["kunst"]
  bevoegdheid=onbevoegd, lerarenregister_id=NULL, lerarenregister_geldig_tot=NULL
  taakomvang_lesuren_per_jaar=750, vakvolledigheid_pct=100, bapo_regeling=geen
```

#### `hrmq_cao_vo_school`
School-specific CAO configuration and state.

| Column | Type | Notes |
|--------|------|-------|
| id | UUID | Primary key |
| organisation_id | UUID | Foreign key to organisation |
| brin_nummer | VARCHAR(4) | DUO identifier (4 digits); e.g., "0200" |
| vestigingsnummer | VARCHAR(2) | School site code; 0–99 |
| bestuursnummer | VARCHAR(5) | School board code (for multi-school boards) |
| leerlingen_teldatum | DATE | Official pupil count date (typically 1 Oct) |
| leerlingen_aantal_per_onderwijssoort | JSONB | { "vmbo_b": 120, "vmbo_k": 85, "havo": 150, "vwo": 200 } |
| bekostigingsbedrag_per_kwartaal | DECIMAL(12,2) | Expected DUO grant per quarter, EUR |
| created_at | TIMESTAMP | Audit |
| updated_at | TIMESTAMP | Audit |

**Seed data:**
```
ID: 880e8400-e29b-41d4-a716-446655440001, organisation_id: ORG-GYM-AMSTERDAM
  brin_nummer=0200, vestigingsnummer=01, bestuursnummer=10001
  leerlingen_teldatum=2025-10-01
  leerlingen_aantal_per_onderwijssoort={"havo":150,"vwo":200}
  bekostigingsbedrag_per_kwartaal=487.500,00

ID: 880e8400-e29b-41d4-a716-446655440002, organisation_id: ORG-VMBO-ROTTERDAM
  brin_nummer=0215, vestigingsnummer=01, bestuursnummer=10005
  leerlingen_teldatum=2025-10-01
  leerlingen_aantal_per_onderwijssoort={"vmbo_b":120,"vmbo_k":85}
  bekostigingsbedrag_per_kwartaal=175.250,00
```

#### `hrmq_cao_vo_taakverdeling`
Annual task allocation per teacher (lesson schedule, hour cap compliance).

| Column | Type | Notes |
|--------|------|-------|
| id | UUID | Primary key |
| employee_id | UUID | Foreign key |
| schooljaar | VARCHAR(9) | "2026-2027" |
| lesuren_per_vak | JSONB | { "wiskunde": 300, "Duits": 150, "Engels": 300 } — totals must match taakomvang |
| taakuren_per_taak | JSONB | { "examinator": 40, "mentoraat": 60 } — non-teaching task hours |
| overschrijdingstoeslag_uren | INT | Hours >750 cap (or pro-rata for part-time); 0 if compliant |
| goedkeuring_docent_at | TIMESTAMP | Teacher formal sign-off on task allocation (if overschrijding present) |
| created_at | TIMESTAMP | Audit |
| updated_at | TIMESTAMP | Audit |

**Seed data:**
```
ID: 990e8400-e29b-41d4-a716-446655440001, employee_id: 001, schooljaar: 2026-2027
  lesuren_per_vak={"wiskunde":300,"nask1":450}, taakuren_per_taak={"examinator":40,"mentoraat":60}
  overschrijdingstoeslag_uren=0, goedkeuring_docent_at=2026-06-15T10:30:00Z

ID: 990e8400-e29b-41d4-a716-446655440002, employee_id: 002, schooljaar: 2026-2027
  lesuren_per_vak={"Duits":450}, taakuren_per_taak={"mentor":50}
  overschrijdingstoeslag_uren=0 (part-time, 0.6 FTE = 450 max), goedkeuring_docent_at=2026-06-10T14:00:00Z
```

#### `hrmq_cao_vo_vervanging`
Substitute-teacher claims to the Vervangingsfonds (absence pooling).

| Column | Type | Notes |
|--------|------|-------|
| id | UUID | Primary key |
| employee_id | UUID | Foreign key |
| absence_start_date | DATE | First day of absence |
| absence_end_date | DATE | Last day of absence |
| absence_reason | ENUM(ziek, zwanger, ouderschapsverlof, ...) | Absence category |
| claim_status | ENUM(ingediend, goedgekeurd, uitbetaald, afgewezen) | Claim lifecycle |
| claim_bedrag | DECIMAL(10,2) | Claimed amount, EUR |
| vfpf_referentie | VARCHAR | Vervangingsfonds claim ID (returned by API) |
| created_at | TIMESTAMP | Audit |
| updated_at | TIMESTAMP | Audit |

**Seed data:**
```
ID: aa0e8400-e29b-41d4-a716-446655440001, employee_id: 001
  absence_start_date=2026-03-15, absence_end_date=2026-03-19
  absence_reason=ziek, claim_status=uitbetaald, claim_bedrag=1.240,50
  vfpf_referentie=VF-2026-001234

ID: aa0e8400-e29b-41d4-a716-446655440002, employee_id: 002
  absence_start_date=2026-06-01, absence_end_date=2026-08-31
  absence_reason=zwanger, claim_status=ingediend, claim_bedrag=3.750,00
  vfpf_referentie=NULL (pending)
```

#### `hrmq_cao_vo_duo_bekostiging`
Quarterly DUO funding reconciliation per school.

| Column | Type | Notes |
|--------|------|-------|
| id | UUID | Primary key |
| organisation_id | UUID | Foreign key |
| kwartaal | INT | 1–4 (Q1 = Jan–Mar, etc.) |
| schooljaar | VARCHAR(9) | "2026-2027" |
| verwacht_bedrag | DECIMAL(12,2) | Expected based on pupil enrollment, EUR |
| ontvangen_bedrag | DECIMAL(12,2) | Actual received from DUO, EUR |
| verschil | DECIMAL(12,2) | ontvangen - verwacht (can be negative) |
| verschil_reden | VARCHAR | HR-admin note if discrepancy, e.g., "Late pupil count update" |
| created_at | TIMESTAMP | Audit |
| updated_at | TIMESTAMP | Audit |

**Seed data:**
```
ID: bb0e8400-e29b-41d4-a716-446655440001, organisation_id: ORG-GYM-AMSTERDAM, kwartaal: 1, schooljaar: 2026-2027
  verwacht_bedrag=487.500,00, ontvangen_bedrag=487.500,00, verschil=0,00

ID: bb0e8400-e29b-41d4-a716-446655440002, organisation_id: ORG-GYM-AMSTERDAM, kwartaal: 2, schooljaar: 2026-2027
  verwacht_bedrag=487.500,00, ontvangen_bedrag=484.250,00, verschil=-3.250,00
  verschil_reden="VWO-enrollment 5 students lower; DUO adjusted retroactively"
```

## Integration Points

### Lerarenregister API
- **Trigger:** When promoting teacher to LC/LD or importing new teacher record.
- **Call:** `GET /teacher/{lerarenregister_id}` to validate `bevoegdheid` is current.
- **Caching:** 24-hour TTL; failed checks log "retry pending" and block promotion until resolved.

### DUO-zakelijk API
- **Trigger:** 1st of each quarter (1 Jan, Apr, Jul, Oct).
- **Call:** POST `quarterly_funding_request` with `brin_nummer`, `leerlingen_aantal_per_onderwijssoort`.
- **Response:** Expected bedrag, expected receipt date (usually 4–6 weeks later).
- **Polling:** Check actual receipt via bank-statement reconciliation (handled by payroll-engine-nl).

### ABP-OW Pension
- **Trigger:** Contract start (new teacher), contract end (leave).
- **Call:** UPA (Uniforme Pensioen Aangifte) XML upload per employee; quarterly reconciliation.
- **Franchise:** 2026: EUR 18.275/year; premium 27.9%.

### Vervangingsfonds API
- **Trigger:** Sick-leave >2 days registered by HR.
- **Call:** POST `claim_register` with BSN, schaal, trede, dates, fte.
- **Response:** Claim ID (vfpf_referentie); poll status monthly.

## Data Flow

1. **Import new CAO table** (August, every 2 years)
   - HR uploads XLSX / JSON via `Configuratie › CAO's & regelingen`.
   - System inserts `schaal_table` and `arbeidsmarkttoelage_table` rows.
   - Previous table's `geldig_tot` is set to 1 day before new table's `geldig_vanaf`.

2. **Monthly payroll run**
   - Payroll-engine-nl iterates employees and calls CAO-VO module hooks.
   - For each employee, load active `schaal_table` and `arbeidsmarkttoelage_table` by run-date.
   - Check `last_periodiek_datum`; if anniversary, auto-increment `trede` and emit `PeriodiekToegekend` event.
   - Retrieve `taakverdeling` for schooljaar; if `overschrijdingstoeslag_uren > 0`, apply surtax.
   - Retrieve `vakgebieden`; for each vakgebied, look up toelage% and apply pro-rata.
   - Calculate bruto-loon and pass to loonheffingen-engine (payroll-engine-nl).

3. **Teacher promotion to LC/LD**
   - HR selects new schaal; system validates `bevoegdheid = eerstegraads` or `lc_lc_traject_voltooid`.
   - Query Lerarenregister API to confirm bevoegdheid is current.
   - If valid: create promotion addendum via document-template-engine, update `employee_extension.schaal`, emit `PromotionApproved` event.
   - If invalid: block with clear error.

4. **Sick-leave >2 days**
   - HR registers absence in hrmq.
   - System auto-generates Vervangingsfonds claim with employee's schaal/trede/dates.
   - Calls Vervangingsfonds API; stores claim ID and initial status.
   - Monthly reconciliation job polls claim status and updates `hrmq_cao_vo_vervanging.claim_status`.

5. **Quarterly DUO funding**
   - On 1st of each quarter, system assembles DUO request with leerlingen-aantallen (from hrmq_cao_vo_school).
   - Sends via DUO-zakelijk API; stores expected bedrag in `hrmq_cao_vo_duo_bekostiging`.
   - Bank-reconciliation job (handled separately) matches actual receipt and sets `ontvangen_bedrag`.
   - If variance >5%, flag in HR-admin worklist.

## UI Placement

Per ADR-001 (Information Architecture):
- **Placement type:** SETTING — under `Configuratie › CAO's & regelingen`.
- **Sub-pages:**
  - Schaal-tabellen versioning (import new, view history, activate/deactivate).
  - Arbeidsmarkttoelage configuratie.
  - DUO-bekostiging dashboard (quarterly tracking, variance alerts).
  - Vervangingsfonds claims (status, payment history).
- **Employee detail tabs:**
  - Schaal & trede (with periodieke-verhoging countdown).
  - Bevoegdheid & Lerarenregister (validation status, expiry).
  - Taakverdeling & lesuren-cap (annual schedule, overschrijding flag).
  - ABP-saldo & pensioen.
- **Teacher self-service:**
  - Loonstrook (with arbeidsmarkttoelage & ABP lines itemized).
  - Jaaropgaaf (annual statement with tax/pension breakdown).
  - CAO-compliance audit trail (promotions, step increments, hour-cap changes).

## Scalability & Performance

- **Read-heavy:** Payroll runs load schaal/toelage tables 110k× per month; index on (geldig_vanaf, schaal, trede).
- **Write-light:** Promotion/claim-registration ~100/month per school.
- **API calls:** Lerarenregister checks on demand + daily batch (1k calls/day max); DUO 4 calls/quarter; Vervangingsfonds 10–20 calls/month per school.
- **Cache:** schaal/toelage tables cached in memory-store (refresh on import); Lerarenregister results cached 24h with stale-on-error fallback.

## Migration & Rollout

1. **Phase 1 (Q3 2026):** Load 2024-2026 CAO tables, pilot with 3–5 schools, validate periodieke-verhoging logic against their records.
2. **Phase 2 (Q4 2026):** GA release with integrations (DUO, Lerarenregister, Vervangingsfonds); expand to 50 schools.
3. **Phase 3 (Q1 2027):** BAPO rules, cost-per-teacher analytics, VO-raad export.
4. **Data remediation:** For schools with legacy payroll, backfill taakverdeling/bevoegdheid data from existing HR records or manual import.

