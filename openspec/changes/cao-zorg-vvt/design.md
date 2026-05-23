---
status: draft
---

# Design: CAO VVT 2024-2026 Implementation

## Context

hrmq's payroll-engine currently supports generic salary components (basisloon, percentage-toeslagen, vaste vergoedingen) but lacks the granular, time-bound ORT (Onregelmatigheidstoeslag) calculation rules and stackability logic required by the CAO VVT. The CAO specifies different ORT rates per:
- Day of week (maandag–zondag)
- Time of day (avond 18:00–00:00, nacht 00:00–06:00, etc.)
- Holiday status (CAO feestdagen, nationale feestdagen)
- Shift type (regular, standby, sleep shift)

A shift spanning 20:00 (kerstavond) → 08:00 (kerstdag) must decompose into 4 ORT-rule segments:
- 20:00–24:00 kerstavond: 22% (evening, not a CAO holiday)
- 00:00–06:00 kerstdag: 85% (nacht + feestdag, stacked)
- 06:00–08:00 kerstdag: 47% (feestdag only, night ends at 06:00)

The design establishes the master data model, calculation algorithm, validation rules, and seed data for the full 2024–2026 CAO cycle including retroactive pay adjustments.

## Goals

**Goals:**
- Implement per-minute ORT decomposition with correct stackability per CAO article 7.1
- Support all 25+ ORT rule variants (doordeweeks, zaterdag, zondag, feestdag combinations) with priority-based collision handling
- Enforce ATW (Arbeidstijdenwet) + CAO work-hour limits (52 hrs/week, 7 consecutive nights, 11 hrs daily rest) during roster planning
- Enable retroactive pay recalculation when CAO is ratified late (e.g., ratified April 2024, effective January 2024)
- Auto-enroll all CAO VVT employees in PFZW and dispatch UPA messages on hire/mutation/exit
- Support FWG (Functiewaardering Gezondheidszorg) function grades 35–80 with multi-period salary scales
- Track standby (bereidheidsdienst) and sleep shifts (slaapdienst) as first-class entities with correct pay/hour handling
- Validate Wzd (Wet zorg en dwang) qualifications for duty-of-care departments

**Non-Goals:**
- Custom ORT rules per organization (only CAO VVT master rules + approved amendments from ActiZ)
- Collective bargaining negotiation UI (CAO is read-only configuration)
- Travel-time calculation by GPS (route distance is user-input or CAK-sourced)
- Wzd training course enrollment system (tracking only; courses managed by extern LMS)
- Sector-specific leave accrual variants beyond CAO VVT (e.g., cao-rijk leave rules = separate spec)

## Decisions

### Decision 1: ORT Master Data Structure — Timeslot Rules + Stackability Matrix

**Approach:**  
Every ORT rule is defined as a time-slot (e.g., "avond doordeweeks", "nacht", "feestdag") with:
- `dagenVanDeWeek`: list of day names or "feestdag" keyword
- `tijdVan` / `tijdTot`: UTC time band (e.g., "18:00" – "00:00")
- `ortPercentage`: single rate (22%, 38%, 47%)
- `prioriteit`: collision-resolution order (higher = takes precedence if overlapping rule is active)
- `stapelbaarMet`: list of other rule names this rule can combine with (e.g., nacht stapels with feestdag → 85%)
- `uitsluitendBij`: rules that mutually exclude this one

A shift is decomposed into 1-minute segments; each segment is checked against all rules; matching rules are sorted by prioriteit; if multiple rules match and stapelbaarMet allows, their percentages are summed (capped per CAO article).

**Why:**  
This data-driven approach avoids hard-coding 25+ nested if/then branches. Mutations to ORT rules by ActiZ (amendment in 2025) are a 3-line YAML/JSON edit, not code.

**How to apply:**  
- **Data import**: CAO_VVT_Versie master record + ORT_Regel table pre-populated from ActiZ CAO-VVT-2024-2026 annex 7.1
- **Calculation**: Shift._calculateORT() iterates time-slices, applies rule-match → stackability logic, writes ORT_Decomposition rows
- **Validation**: Unit test matrix covering all CAO examples (evening, night, feestdag, weekend combos)

### Decision 2: Retroactive Pay Adjustment — Batch ID + Historical Snapshots

**Approach:**  
When CAO is ratified late (e.g., April 2024 with Jan 2024 effective date), a retroactivo-loonrun is created with:
1. A single `retroactive_batch_id` linking all affected pay periods
2. A calculation of "old payroll" (using pre-CAO rates) for Jan–Mar 2024
3. A "new payroll" using CAO rates for the same periods
4. A delta amount booked on May loonrun as "Nabetaling CAO VVT 2024-01-01"
5. For employees who exited before the retroactive date, a separate SEPA payment + letter

**Why:**  
Retroactive pay is rare but critical for compliance. Hard-deleting old pay-periods loses the audit trail. A batch_id lets HR & auditors trace "which pay periods were recalculated and why."

**How to apply:**  
- Loonrun creation UI includes "Is this a retroactive run?" toggle
- If yes: date-range picker (from date, to date) + effective-date
- Engine replays the calculation using the effective-date CAO version
- Deltas are written as separate "nabetaling" components on the next non-retroactive run
- Affected employees receive email notification + amended payslips

### Decision 3: ATW/CAO Guardrails in Rooster — Blocking vs. Warning

**Approach:**  
During roster shift creation, validate:
1. **Blocking** (refuse shift):
   - Would exceed 52 hrs/week CAO limit
   - Would create 8+ consecutive night shifts (CAO max 7)
   - Would create <11 hrs rest after 12+ hr shift
2. **Warning** (allow, but flag):
   - Approaches ORT budget cap (organizationally configured per department)
   - Creates high consecutive-night count (e.g., 5, 6 nights — warn manager)

**Why:**  
Hard blocks prevent payroll violations. Warnings are advisory for cost control & wellness; managers may override with formal ATW-exception docs.

**How to apply:**  
- Rooster-planning calls cao-zorg-vvt.validateShiftGuardrails(shift) API
- API returns `{ allowed: bool, violations: [{ code, severity, message }] }`
- If severity='blocking' && allowed=false: disable shift UI, show error
- If severity='warning': show yellow banner, allow proceed

### Decision 4: Standby (Bereidheidsdienst) Dual-State: Standby Hours + Active Hours

**Approach:**  
A bereidheidsdienst period (e.g., 22:00–08:00, 10 hours) is:
- By default: €3.50/hr × 10 hrs = €35.00 (standby fee, non-work)
- If activated (called for work): actual work hours are extracted, recalculated with full ORT + uurtarief
- Example: called 03:15–04:45 (1.5 hrs work + 0.5 hrs travel = 2 hrs compensated). Nacht ORT (38%) applies → €49/hr × 2 = €98. Total: €35 standby + €98 active = €133.

**Why:**  
CAO 7.3 explicitly differentiates standby-fee (low rate, no ORT) from actual-work (full rate + ORT). Blending them loses auditability.

**How to apply:**  
- Bereidheidsdienst record: `totaalUren`, `vergoedingPerUur` = fixed
- Oproep (activation) sub-record: `startTijd`, `eindTijd`, `reistijdHeen`, `reistijdTerug`, `uurtariefMetOrt`
- Loonstrook shows both rows; finance can separately report "standby costs" vs. "active-call costs"

### Decision 5: PFZW Enrollment — Mandatory, Auto-Dispatch UPA

**Approach:**  
Every employee hired under CAO VVT is auto-enrolled in PFZW (Pensioenfonds Zorg en Welzijn) at hire. HR-medewerker cannot override this choice (field is locked read-only, pre-set to "PFZW"). On any contract mutation (deeltijdfactor, salary scale, end-of-service), the employee-master triggers a UPA (Uitvoering Pensioenaangifte) dispatch to PFZW's intake.

**Why:**  
PFZW enrollment is CAO-mandated (article 14), not optional. Auto-dispatch prevents missed pension contributions (a compliance violation). The loonrun must include a PFZW-premie % per schaal.

**How to apply:**  
- Employee.pensioenuitvoerder field: locked to "PFZW" for CAO VVT contracts
- Mutation workflow: post-save, fire event `EmployeeContractMutated(employee_id, mutation_type, old_values, new_values)`
- Pension-intake listener: builds UPA-record, queues SFTP/secure-https dispatch to PFZW
- Loonrun includes row: "PFZW-pensioenpremie 10.25%" (rate per FWG schaal per CAO annex)

### Decision 6: Seed Data — Minimal Master, Example Employee Records

**Approach:**  
The CAO VVT spec ships with:
1. **Master CAO_VVT_Versie** record (single row): caoCode="VVT", versieNummer="2024-2026", ingangsdatum=2024-01-01, loonsverhogingen=[3%, 2.5%, 2%]
2. **25 ORT_Regel seed rows**: all combinations from CAO article 7.1 (avond, nacht, zondag, feestdag, etc.)
3. **FWG Function scales**: FWG 50 (Verpleegkundige), FWG 55 (Senior Verpl.), FWG 40 (Verzorgende), etc. with periodiek tables
4. **Example Shift** (1 row): Christmas Eve 24hr shift with multi-segment ORT decomposition
5. **Example Bereidheidsdienst & Slaapdienst**: illustrate dual-state and fixed-fee logic

**Why:**  
Seed data demonstrates the data model & calculation correctness. HR can immediately test a loonrun without waiting for organization to populate 200 employee records. Seed data is not production-ready (fictitious rates) but blocks on correct structure.

**How to apply:**  
- Migration script: load seed JSON from openspec/changes/cao-zorg-vvt/seeddata.json into DB on activate
- Seed data is org-generic (applies to all care orgs); org-specific rates are imported via separate CAO-config upload
- Seed includes documented examples with expected outputs (test oracle)

## Seed Data

### CAO_VVT_Versie (Master Configuration)

```json
{
  "caoId": "cao-vvt-2024-2026",
  "caoCode": "VVT",
  "versieNummer": "2024-2026",
  "ingangsdatum": "2024-01-01",
  "einddatum": "2026-12-31",
  "actiznBron": "https://actiz.nl/cao-vvt-2024-2026",
  "loonsverhogingen": [
    {"datum": "2024-01-01", "percentage": 3.0, "geldigVanaf": "2024-01-01"},
    {"datum": "2025-01-01", "percentage": 2.5, "geldigVanaf": "2025-01-01"},
    {"datum": "2026-01-01", "percentage": 2.0, "geldigVanaf": "2026-01-01"}
  ],
  "pfzwAansluitingVerplicht": true,
  "werkurenMaximumPerWeek": 52,
  "ortVanToepassing": true,
  "slaapdienstVergoedingTarief": 24.50,
  "bereidheidsVergoedingPerUur": 3.50,
  "maximumConsecutieveNachtdiensten": 7,
  "minimumDagelijkeRust": 11.0,
  "maximumUrenNa12UurDienst": 12.0,
  "ondertekenaars": ["ActiZ", "Zorgthuisnl", "FNV", "CNV", "FBZ", "NU91"]
}
```

### ORT_Regel Sample (5 of 25 rules)

```json
[
  {
    "ortId": "ort-avond-doordeweeks",
    "caoVersieId": "cao-vvt-2024-2026",
    "regelNaam": "Avond doordeweeks",
    "dagenVanDeWeek": ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag"],
    "tijdVan": "18:00",
    "tijdTot": "24:00",
    "ortPercentage": 22.0,
    "prioriteit": 100,
    "stapelbaarMet": ["nacht-doordeweeks", "feestdag"],
    "uitsluitendBij": ["avond-zondag", "avond-zaterdag"],
    "geldigVanaf": "2024-01-01",
    "caoArtikel": "7.1(a)"
  },
  {
    "ortId": "ort-nacht-allen",
    "caoVersieId": "cao-vvt-2024-2026",
    "regelNaam": "Nacht (alle dagen)",
    "dagenVanDeWeek": ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag", "zaterdag", "zondag"],
    "tijdVan": "00:00",
    "tijdTot": "06:00",
    "ortPercentage": 38.0,
    "prioriteit": 200,
    "stapelbaarMet": ["feestdag"],
    "uitsluitendBij": [],
    "geldigVanaf": "2024-01-01",
    "caoArtikel": "7.1(b)"
  },
  {
    "ortId": "ort-feestdag-nationaal",
    "caoVersieId": "cao-vvt-2024-2026",
    "regelNaam": "Feestdag (nationaal of CAO-feestdag)",
    "dagenVanDeWeek": "feestdag",
    "tijdVan": "00:00",
    "tijdTot": "24:00",
    "ortPercentage": 47.0,
    "prioriteit": 300,
    "stapelbaarMet": [],
    "uitsluitendBij": ["zaterdag-ort", "zondag-ort"],
    "geldigVanaf": "2024-01-01",
    "feestdagenLijst": [
      {"datum": "2024-01-01", "naam": "Nieuwjaarsdag"},
      {"datum": "2024-12-25", "naam": "Eerste Kerstdag"},
      {"datum": "2024-12-26", "naam": "Tweede Kerstdag"}
    ],
    "caoArtikel": "7.1(d)"
  },
  {
    "ortId": "ort-zondag-dag",
    "caoVersieId": "cao-vvt-2024-2026",
    "regelNaam": "Zondag (06:00-18:00)",
    "dagenVanDeWeek": ["zondag"],
    "tijdVan": "06:00",
    "tijdTot": "18:00",
    "ortPercentage": 38.0,
    "prioriteit": 150,
    "stapelbaarMet": [],
    "uitsluitendBij": [],
    "geldigVanaf": "2024-01-01",
    "caoArtikel": "7.1(c)"
  },
  {
    "ortId": "ort-zaterdag-avond",
    "caoVersieId": "cao-vvt-2024-2026",
    "regelNaam": "Zaterdag avond (18:00-24:00)",
    "dagenVanDeWeek": ["zaterdag"],
    "tijdVan": "18:00",
    "tijdTot": "24:00",
    "ortPercentage": 22.0,
    "prioriteit": 105,
    "stapelbaarMet": ["nacht-allen"],
    "uitsluitendBij": [],
    "geldigVanaf": "2024-01-01",
    "caoArtikel": "7.1(c)"
  }
]
```

### Example Employee & Shift (Loonstrook Illustration)

```json
{
  "medewerkerId": "emp-voorbeeld-001",
  "voornaam": "Maria",
  "achternaam": "Garcia",
  "persoonsNummer": "123456789",
  "caoCode": "VVT",
  "fwgGrade": "FWG 50",
  "fwgSalarisSchaal": "Verpleegkundige niveau 4",
  "periodiekeInschaling": 4,
  "basisSalaris": 26.00,
  "uurtarief": 26.00,
  "pfzwAanmelding": "2024-01-15",
  "pensioenuitvoerder": "PFZW",
  "wzdBevoegdheid": null,
  "bigRegistratieNummer": "V123456789"
}
```

```json
{
  "shiftId": "shift-kerstavond-2024",
  "medewerkerId": "emp-voorbeeld-001",
  "datum": "2024-12-24",
  "startTijd": "2024-12-24T20:00:00+01:00",
  "eindTijd": "2024-12-25T08:00:00+01:00",
  "shiftType": "nachtdienst",
  "afdeling": "Somatiek 1",
  "functieTijdensShift": "Verpleegkundige niveau 4",
  "totaalUren": 12.0,
  "pauzeUren": 1.0,
  "betaaldeUren": 11.0,
  "uurtarief": 26.00,
  "ortBerekening": [
    {
      "segment": 1,
      "van": "20:00",
      "tot": "24:00",
      "uren": 4.0,
      "ortRegels": ["ort-avond-doordeweeks"],
      "ortPercentage": 22.0,
      "bedrag": 22.88
    },
    {
      "segment": 2,
      "van": "00:00",
      "tot": "06:00",
      "uren": 6.0,
      "ortRegels": ["ort-nacht-allen", "ort-feestdag-nationaal"],
      "ortPercentage": 85.0,
      "bedrag": 132.60
    },
    {
      "segment": 3,
      "van": "06:00",
      "tot": "08:00",
      "uren": 2.0,
      "ortRegels": ["ort-feestdag-nationaal"],
      "ortPercentage": 47.0,
      "bedrag": 24.44
    }
  ],
  "totaalOrtBedrag": 179.92,
  "basisloon": 286.00,
  "totaalShiftLoon": 465.92,
  "loonrekeningsDatum": "2024-12-31"
}
```

### FWG Function Scales (Sample)

```json
[
  {
    "fwgGrade": "FWG 50",
    "fwgBeschrijving": "Verpleegkundige niveau 4",
    "periodiekenPerSchaal": 12,
    "schaalBedragen": [
      {"periodiek": 1, "bruto_maand_2024": 3100, "bruto_maand_2025": 3177, "bruto_maand_2026": 3240},
      {"periodiek": 2, "bruto_maand_2024": 3200, "bruto_maand_2025": 3281, "bruto_maand_2026": 3347},
      {"periodiek": 12, "bruto_maand_2024": 3900, "bruto_maand_2025": 4000, "bruto_maand_2026": 4080}
    ]
  }
]
```
