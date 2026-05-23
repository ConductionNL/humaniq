---
status: draft
app: hrmq
spec: stagiair-bbl-admin
version: 0.1.0
owners: [hrmq-team]
---

# Stagiair & BBL-leerling Administratie — Design

## System Boundaries

```
┌─────────────────────────────────────────────────────────────────┐
│ hrmq (this module)                                              │
│                                                                 │
│ ┌──────────────────────────────────────────────────────────┐   │
│ │ Stagiair & BBL Admin                                     │   │
│ │                                                          │   │
│ │ • Stagiair entity                                        │   │
│ │ • BBLLeerling entity (+ linked Employee)                │   │
│ │ • PraktijkLeerOvereenkomst (POK)                         │   │
│ │ • SBB-erkenning lookup & cache                          │   │
│ │ • Evaluatie-tracking                                     │   │
│ │ • Uitstroom-workflow                                     │   │
│ └──────────────────────────────────────────────────────────┘   │
│                                                                 │
│ Dependencies:                                                   │
│ → employee-master (Employee refs, create BBL-linked records)  │
│ → contract-management (POK as contract type)                   │
│ → payroll-engine-nl (BBL-staffel application)                  │
│ → document-storage (POK PDF, diploma PDF, SBB-proof)          │
│ → task-management (instroom blockers, eval reminders)          │
│ → finance-export (subsidie + stagevergoeding reporting)        │
└─────────────────────────────────────────────────────────────────┘

External systems:
• SBB publieke register (erkenningen per CREBO)
• RVO Subsidieregeling Praktijkleren (API for aanvraag + uitkering)
```

## Data Model

### Entity: Stagiair

```typescript
interface Stagiair {
  id: UUID
  bsn: string // AVG-justified (fisscale obligation or educational need)
  voornaam: string
  achternaam: string
  geboortedatum: Date
  
  onderwijsinstelling_id: UUID // ref to educational institution
  onderwijsinstelling_naam: string
  opleiding: string
  niveau: "HBO" | "WO" | "MBO-BOL"
  studierichting: string
  stagetype: "snuffel" | "meeloop" | "afstudeer"
  
  startdatum: Date
  einddatum: Date
  aantal_dagen_per_week: number // 1-5
  
  stagebegeleider_intern_id?: UUID // ref to Employee
  stagebegeleider_extern?: string // name if external
  
  stagevergoeding_per_maand?: number // in EUR, must validate <= fiscal limit
  reiskosten_vergoeding?: number
  
  pok_id: UUID // ref to PraktijkLeerOvereenkomst
  pok_ondertekening_status: "concept" | "wacht_leerbedrijf" | "wacht_onderwijs" | "wacht_deelnemer" | "compleet"
  
  verzekering_status: "niet_geverifieerd" | "aansprakelijkheid_ok" | "afgewezen" | string
  verzekering_via?: string // "onderwijsinstelling" for stagiair
  
  evaluatie_punten: [
    {
      naam: string
      geplande_datum: Date
      voltooide_datum?: Date
      resultaat?: string // json: opmerkingen, score, aanbevelingen
    }
  ]
  
  beoordeling_eindcijfer?: number
  diploma_behaald?: boolean
  diploma_behaald_datum?: Date
  
  createdAt: DateTime
  updatedAt: DateTime
  archivedAt?: DateTime // archivering per Archiefwet na uitstroom
}
```

### Entity: BBLLeerling

```typescript
interface BBLLeerling {
  id: UUID
  bsn: string
  voornaam: string
  achternaam: string
  geboortedatum: Date
  
  roc_instelling_id: UUID
  roc_instelling_naam: string
  crebo_code: string // validates against SBBErkenning
  niveau: 2 | 3 | 4
  
  werknemer_id: UUID // required ref to created Employee record
  // Employee.contractvorm = "bbl-arbeidsovereenkomst"
  // Employee.salaris = BBL-staffel jaar 1 (from CAO)
  
  leerbedrijf_erkenning_id: UUID // ref to SBBErkenning
  // SBBErkenning MUST be valid and contain this CREBO_code
  
  pok_id: UUID // ref to PraktijkLeerOvereenkomst
  pok_ondertekening_status: "concept" | "wacht_leerbedrijf" | "wacht_roc" | "wacht_leerling" | "compleet"
  
  bbl_staffel_jaar: 1 | 2 | 3
  bbl_staffel_jaar_gewijzigd_datum?: Date
  // triggers Employee.salaris update via payroll-engine-nl
  
  vouchers_toegekend?: number // count
  
  subsidie_praktijkleren_aangevraagd: boolean
  subsidie_aanvraag_id?: UUID // ref to SubsidieAanvraagPraktijkleren
  subsidie_bedrag_uitgekeerd?: number
  
  praktijkbeoordelaar_id?: UUID // ref to Employee
  
  evaluatie_punten: [
    {
      naam: string
      geplande_datum: Date
      voltooide_datum?: Date
      resultaat?: string
    }
  ]
  
  startdatum: Date
  einddatum: Date
  beoordeling_eindcijfer?: number
  diploma_behaald?: boolean
  diploma_behaald_datum?: Date
  
  createdAt: DateTime
  updatedAt: DateTime
  archivedAt?: DateTime
}
```

### Entity: PraktijkLeerOvereenkomst (POK)

```typescript
interface PraktijkLeerOvereenkomst {
  id: UUID
  
  // Linked entity — may be referenced by both Stagiair AND BBLLeerling
  stagiair_id?: UUID
  bbl_leerling_id?: UUID
  
  // Three-party contract
  partij_leerbedrijf: {
    naam: string
    kvk: string
    contactperson: string
    ondertekend: boolean
    ondertekend_datum?: Date
  }
  
  partij_onderwijsinstelling: {
    naam: string
    contactperson: string
    ondertekend: boolean
    ondertekend_datum?: Date
  }
  
  partij_deelnemer: {
    bsn: string
    naam: string
    ondertekend: boolean
    ondertekend_datum?: Date
  }
  
  // Contract substance
  ingangsdatum: Date
  einddatum: Date
  onderwerp: string // e.g., "HBO Bedrijfskunde 3de jaars afstudeerplaatsing"
  leerdoelen: {
    id: string
    beschrijving: string
    niveau?: string
  }[]
  
  praktijkbeoordelaar: {
    naam: string
    contact?: string
  }
  
  aantal_begeleidingsuren_per_week: number
  
  // Evaluations
  tussentijdse_evaluaties: [
    {
      moment_percentage: 25 | 50 | 75
      geplande_datum: Date
      voltooide_datum?: Date
      opmerkingen?: string
      score?: string
    }
  ]
  
  // Document storage
  pok_document_url?: string // PDF in document-storage
  pok_ondertekend_volledig_url?: string // signed PDF
  archief_url?: string // long-term retention
  
  // Compliance
  ondertekend_compleet_datum?: Date // all three signed
  archivering_startdatum?: DateTime // 7 jaren na uitstroom per Archiefwet
  archivering_verwijderplandatum?: Date
  
  createdAt: DateTime
  updatedAt: DateTime
}
```

### Entity: SBBErkenning

```typescript
interface SBBErkenning {
  id: UUID
  
  kvk: string // unique per company
  sbb_erkenningsnummer: string // from SBB register
  
  erkende_crebos: string[] // array of CREBO codes this org is recognized for
  
  erkenningsdatum: Date
  vervaldatum: Date
  status: "geldig" | "verlopen" | "ingetrokken"
  
  praktijkopleider_named: {
    id: UUID // ref to Employee
    naam: string
  }
  
  // Cache of last sync from SBB API
  sbb_synced_at: DateTime
  sbb_sync_status: "ok" | "error" | "not_found"
  sbb_sync_error?: string
  
  createdAt: DateTime
  updatedAt: DateTime
}
```

### Entity: SubsidieAanvraagPraktijkleren

```typescript
interface SubsidieAanvraagPraktijkleren {
  id: UUID
  
  bbl_leerling_id: UUID
  studiejaar: number // academic year (e.g., 2025-2026)
  
  // RVO submission
  aangevraagd_datum: Date
  rvo_referentienr?: string // from RVO after submit
  
  // Amounts (in EUR)
  bedrag_aangevraagd: number // typically €2.700
  bedrag_toegekend?: number
  uitkeringsdatum?: Date
  
  // Supporting docs
  bewijsstukken: {
    pok_url?: string
    urenregistratie_url?: string
    evaluatieverslag_url?: string
    diploma_url?: string
  }
  
  status: "concept" | "ingediend" | "goedgekeurd" | "afgewezen" | "uitgekeerd"
  
  createdAt: DateTime
  updatedAt: DateTime
}
```

## Seed Data

### Stagiair Example

```yaml
id: "550e8400-e29b-41d4-a716-446655440000"
bsn: "123456789"
voornaam: "Emma"
achternaam: "Jansen"
geboortedatum: "2002-03-15"
onderwijsinstelling_naam: "HvA Amsterdam"
opleiding: "Bedrijfskunde"
niveau: "HBO"
studierichting: "International Business"
stagetype: "afstudeer"
startdatum: "2025-09-01"
einddatum: "2026-02-28"
aantal_dagen_per_week: 4
stagebegeleider_intern: "Jan Pieterzoon"
stagevergoeding_per_maand: 400 # unbraced, within fiscal limit
reiskosten_vergoeding: 50
pok_ondertekening_status: "compleet"
verzekering_status: "aansprakelijkheid_ok"
evaluatie_punten:
  - naam: "25% Voortgangsevaluatie"
    geplande_datum: "2025-11-15"
  - naam: "50% Midevaluatie"
    geplande_datum: "2025-12-15"
  - naam: "75% Finalevaluatie"
    geplande_datum: "2026-02-01"
beoordeling_eindcijfer: 8
diploma_behaald: true
```

### BBLLeerling Example

```yaml
id: "550e8400-e29b-41d4-a716-446655440001"
bsn: "987654321"
voornaam: "Marc"
achternaam: "de Vries"
geboortedatum: "2004-07-22"
roc_instelling_naam: "ROC Amsterdam"
crebo_code: "12345"
niveau: 3
werknemer_id: "550e8400-e29b-41d4-a716-446655440050" # linked Employee
leerbedrijf_erkenning_id: "550e8400-e29b-41d4-a716-446655440100"
pok_ondertekening_status: "compleet"
bbl_staffel_jaar: 1
subsidie_praktijkleren_aangevraagd: true
subsidie_aanvraag_id: "550e8400-e29b-41d4-a716-446655440200"
startdatum: "2025-09-01"
einddatum: "2026-08-31"
beoordeling_eindcijfer: 7.5
diploma_behaald: false
```

### SBBErkenning Example

```yaml
id: "550e8400-e29b-41d4-a716-446655440100"
kvk: "12345678"
sbb_erkenningsnummer: "A-12345-001"
erkende_crebos:
  - "12345"
  - "12346"
  - "12347"
erkenningsdatum: "2022-01-15"
vervaldatum: "2027-01-14"
status: "geldig"
praktijkopleider_named:
  naam: "Kees Vermeulen"
sbb_synced_at: "2025-05-20T14:30:00Z"
sbb_sync_status: "ok"
```

### PraktijkLeerOvereenkomst Example (shared)

```yaml
id: "550e8400-e29b-41d4-a716-446655440300"
bbl_leerling_id: "550e8400-e29b-41d4-a716-446655440001"
partij_leerbedrijf:
  naam: "TechCorps BV"
  kvk: "12345678"
  contactperson: "Kees Vermeulen"
  ondertekend: true
  ondertekend_datum: "2025-08-20"
partij_onderwijsinstelling:
  naam: "ROC Amsterdam"
  contactperson: "Ing. Bart Mulder"
  ondertekend: true
  ondertekend_datum: "2025-08-22"
partij_deelnemer:
  bsn: "987654321"
  naam: "Marc de Vries"
  ondertekend: true
  ondertekend_datum: "2025-08-25"
ingangsdatum: "2025-09-01"
einddatum: "2026-08-31"
onderwerp: "MBO-3 Bedrijfsmatig werken — leerplaatsing ICT"
leerdoelen:
  - id: "1"
    beschrijving: "Zelfstandig kleine ICT-projecten implementeren"
    niveau: "MBO-3"
aantal_begeleidingsuren_per_week: 8
praktijkbeoordelaar:
  naam: "Petra Jansen"
  contact: "p.jansen@roc-amsterdam.nl"
tussentijdse_evaluaties:
  - moment_percentage: 25
    geplande_datum: "2025-11-15"
  - moment_percentage: 50
    geplande_datum: "2025-12-15"
  - moment_percentage: 75
    geplande_datum: "2026-07-01"
ondertekend_compleet_datum: "2025-08-25"
```

### SubsidieAanvraagPraktijkleren Example

```yaml
id: "550e8400-e29b-41d4-a716-446655440200"
bbl_leerling_id: "550e8400-e29b-41d4-a716-446655440001"
studiejaar: "2025-2026"
aangevraagd_datum: "2026-06-30"
bedrag_aangevraagd: 2700 # standard for full year
status: "goedgekeurd"
bedrag_toegekend: 2700
uitkeringsdatum: "2026-10-15"
```

## User Flows

### Flow 1: Stagiair Registration & POK Setup

```
HR-admin opens "Medewerkers › Stagiairs & BBL"
  ↓
Clicks "+ Stagiair registreren"
  ↓
Form: BSN, naam, geboorte, instelling, opleiding, niveau, stagetype
  ↓
Form: startdatum, einddatum, dagen/week, vergoeding (validates <= fiscal limit)
  ↓
Form: select stagebegeleider_intern (from Employee list)
  ↓
Creates Stagiair record
  ↓
Automatically creates PraktijkLeerOvereenkomst (concept)
  ↓
Redirects to POK-detail page
  ↓
HR-admin fills leerdoelen, evaluatiemoment-dates
  ↓
POK-workflow: "Send to Leerbedrijf for signature"
  ↓
  → Email sent to leerbedrijf contactperson with signing link
  → Leerbedrijf signs digitally (doc-storage integration)
  → Stagebegeleider notified, signs
  → Deelnemer notified, signs via link
  ↓
Once all 3 signed: pok_ondertekening_status = "compleet"
  ↓
HR-admin verifies verzekering_status = "ok"
  ↓
System sets evaluatie_punten based on 25%/50%/75% rule & looptijd
  ↓
T-7 days before startdatum: Task created if POK not complete
```

### Flow 2: BBLLeerling Registration & Employee Linkage

```
HR-admin opens "Medewerkers › Stagiairs & BBL"
  ↓
Clicks "+ BBL-leerling registreren"
  ↓
Form: BSN, naam, geboorte, ROC, CREBO, niveau
  ↓
  → System validates CREBO against SBBErkenning.erkende_crebos for org KVK
  → If no valid erkenning or CREBO not in list: error "Vraag erkenning aan via s-bb.nl"
  ↓
Form: select werknemer (from Employee list) OR "Create new Employee"
  ↓
If "Create new":
  → Auto-creates Employee with contractvorm = "bbl-arbeidsovereenkomst"
  → Fetches BBL-staffel jaar 1 from CAO
  → Sets Employee.salaris accordingly
  ↓
Creates BBLLeerling record + linked Employee
  ↓
Auto-creates PraktijkLeerOvereenkomst
  ↓
Same POK signing flow as Stagiair
  ↓
Once POK complete & verzekering verified:
  → payroll-engine-nl applied BBL-staffel jaar 1
  → Employee in next loonrun
```

### Flow 3: Evaluation Tracking

```
Stagebegeleider logs in
  ↓
Dashboard shows "Evaluatiemomenten vandaag" or "Nog open"
  ↓
Clicks on Stagiair/BBL record
  ↓
Detail page shows evaluatie_punten with status (geplande_datum, voltooide_datum)
  ↓
Clicks "Evaluatie afgerond" on 25%, 50%, 75% points
  ↓
Opens modal: "Voortgangsevaluatie registreren"
  ↓
Fills: resultaat (opmerkingen, score if numeric)
  ↓
Saves → marked complete in tussentijdse_evaluaties array
  ↓
Audit trail records who, when, what changed
```

### Flow 4: BBL Staffel Step-up (Year 2)

```
HR-admin receives task: "BBL-leerling Marc de Vries bevorderd naar jaar 2"
  ↓
Clicks link → BBLLeerling detail
  ↓
Clicks "Registreer leerjaar-overgang"
  ↓
Confirms: Year 1 → Year 2 transition
  ↓
  → System updates bbl_staffel_jaar = 2
  → Fetches BBL-staffel jaar 2 amount from CAO (contract-management)
  → Creates payroll_mutation (effective first of next month)
  ↓
Finance notified via task: "Approve BBL salary step-up for Marc"
  ↓
Finance reviews, approves
  ↓
Next loonrun applies new BBL-staffel jaar 2 salary
```

### Flow 5: Subsidie Praktijkleren Aanvraag (Automatic)

```
T-30 days before einddatum (end of academic year, default 31 juli):
  ↓
System checks all BBLLeerling records for status = "active" + 40 weeks completed
  ↓
For each eligible BBLLeerling:
  → Creates SubsidieAanvraagPraktijkleren (concept)
  → Checks for POK, urenregistratie, evaluatierapporten in document-storage
  → Populates bewijsstukken array
  ↓
Finance notified via task: "Subsidie Praktijkleren aanvraag klaar — submit by 16 sept"
  ↓
Finance reviews, clicks "Dien in bij RVO"
  ↓
System submits to RVO API
  ↓
  → Awaits rvo_referentienr confirmation
  → Status = "ingediend"
  → Finance tracks uitkering_status
  ↓
T+3 maanden: If status = "goedgekeurd", RVO bedrag_toegekerd
  → System records uitkeringsdatum
  → Finance exports to finance-export module for reporting
```

---

## Design Decisions & Trade-offs

1. **Why separate entities (Stagiair, BBLLeerling) instead of Employee mode-flag?**
   - Stagiair has fundamentally different contracts (no contract), payroll (no payroll), compliance (no CAO). Mixing with Employee would force every payroll/CAO rule to have "unless Stagiair" guards.
   - BBLLeerling shares contracts & payroll but has distinct subsidies, erkenning checks, staffel progression. Modeling as separate entity with Employee link is cleaner than a sub-type.

2. **Why POK as shared entity between Stagiair & BBLLeerling?**
   - Both use the same three-party contract template. Code reuse, audit trail, storage. One POK type instead of POK-Stagiair + POK-BBL.

3. **Why cache SBBErkenning instead of always querying SBB API?**
   - SBB API is external & latency-sensitive. Cache with sbb_synced_at timestamp allows quick validation during registration. Backfill runs nightly via task-management.

4. **Why enforce CREBO lookup at registration time?**
   - Early validation prevents incorrect placements. A blocking error at registration (not later) is less painful than discovering an invalid erkenning 3 months in.

5. **Why automatic evaluation-point scheduling?**
   - Prevents forgotten evaluations. 25%/50%/75% rule is standard in NL education. Auto-scheduling ensures reminder tasks fire on time.

6. **Why 7-year archiefretention per Archiefwet?**
   - Dutch law. POK + beoordeling must be retention-locked for 7 years post-exit. document-storage integration handles delete-prevention.
