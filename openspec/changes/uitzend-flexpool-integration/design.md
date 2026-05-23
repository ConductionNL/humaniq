---
name: uitzend-flexpool-integration-design
title: Design — Uitzendkrachten & Flexpool-integratie
version: 0.1.0
status: draft
---

# Design: Uitzendkrachten & Flexpool-integratie

## Data Model

### Entity: Bureau

Registratie van uitzendbureaus, payrollbedrijven, detacheerders.

```json
{
  "id": "uuid",
  "kvk": "string (8 digits, unique)",
  "naam": "string",
  "type": "enum [uitzend, payroll, detachering, zzp-bemiddelaar]",
  "sna_keurmerk_status": "enum [onbekend, geldig, verlopen, geweigerd]",
  "sna_vervaldatum": "date",
  "nen_4400_1_certificaat": "boolean",
  "nen_4400_2_certificaat": "boolean",
  "g_rekening_iban": "string (NL only)",
  "g_rekening_percentage": "number (0-100, default 25)",
  "abu_of_nbbu_lid": "enum [ABU, NBBU, geen]",
  "contract_raamovereenkomst_ref": "document-storage ref",
  "created_at": "timestamp",
  "updated_at": "timestamp",
  "created_by": "employee-ref",
  "updated_by": "employee-ref"
}
```

**Validation rules:**
- `kvk`: must validate against KvK registry (async, daily check)
- `sna_keurmerk_status`: if `geldig`, `sna_vervaldatum` must be ≥ today
- `g_rekening_iban`: must be NL IBAN; if provided, `g_rekening_percentage` ≥ 5
- `nen_4400_1_certificaat` OR `nen_4400_2_certificaat`: at least one must be true if `sna_keurmerk_status=geldig`

---

### Entity: InhuurOpdracht

Single assignment of a candidate to work for a cost center under a hiring bureau.

```json
{
  "id": "uuid",
  "opdracht_nr": "string (unique per year, format: 2026-001)",
  "bureau_ref": "Bureau id",
  "kandidaat_naam": "string",
  "kandidaat_bsn_optional": "string (11 digits, encrypted if present)",
  "inhurende_manager_ref": "employee-master ref (required)",
  "inhurende_kostenplaats": "cost-center ref (required)",
  "functie_titel": "string",
  "referent_eigen_functie_ref": "employee-master FunctieProfiel id (required for inlenersbeloning)",
  "cao_toepassing": "enum [ABU, NBBU, branche-cao, geen]",
  "fase": "string (A/B/C for ABU, 1/2/3/4 for NBBU)",
  "startdatum": "date (required)",
  "geplande_einddatum": "date",
  "werkelijke_einddatum": "date",
  "uurtarief_inkoop": "decimal (EUR/hour)",
  "aantal_uren_per_week": "number (0-40)",
  "werklocatie": "string",
  "status": "enum [draft, actief, verlengd, beëindigd, gearchiveerd]",
  "inlenersbeloning_onderbouwing_ref": "InlenersBeloningOnderbouwing id (required if status=actief)",
  "notes": "text",
  "created_at": "timestamp",
  "updated_at": "timestamp",
  "created_by": "employee-ref",
  "updated_by": "employee-ref"
}
```

**Validation rules:**
- `bureau_ref`: Bureau must have `sna_keurmerk_status=geldig` AND G-rekening configured, else block with message: "Bureau voldoet niet aan ketenaansprakelijkheid-eisen"
- `referent_eigen_functie_ref`: must exist in employee-master FunctieProfiel
- `inhurende_manager_ref`: must be active employee with `role=manager` or higher
- `startdatum` ≤ today + 90 days
- `geplande_einddatum` > `startdatum`
- `status=actief` requires `inlenersbeloning_onderbouwing_ref` to exist and be `geldig`

**State transitions:**
- `draft` → `actief`: requires inlenersbeloning-onderbouwing + manager approval
- `actief` → `verlengd`: extends `geplande_einddatum`
- `actief` → `beëindigd`: sets `werkelijke_einddatum` = today, generates offboarding task
- `*` → `gearchiveerd`: after 24 months, manual archive

---

### Entity: InlenersBeloningOnderbouwing

Justification that the flex-worker's remuneration meets the 6-element rule.

```json
{
  "id": "uuid",
  "opdracht_ref": "InhuurOpdracht id",
  "vaststellingsdatum": "date (required)",
  "loon_per_uur_eigen_werknemer": "decimal (EUR/hour)",
  "adv_dagen_per_jaar": "number",
  "toeslagen_overzicht": "object { overwerk: %, onregelmatigheid: %, etc. }",
  "periodieken_staffel": "string (references CAO staffel code)",
  "kostenvergoedingen_overzicht": "object { reistijd: EUR, tools: EUR, etc. }",
  "vakantiebijslag_percentage": "number (0-25)",
  "aanvullende_voorzieningen": "object { pensoen: bool, rv: bool, etc. }",
  "onderbouwing_document_url": "document-storage ref",
  "geldig_tot": "date (default: vaststellingsdatum + 12 months)",
  "revised_from_id": "InlenersBeloningOnderbouwing id (if this is a revision)",
  "created_at": "timestamp",
  "created_by": "employee-ref"
}
```

**Validation rules:**
- All six elements must be present (loon, ADV, toeslagen, periodieken, vergoedingen, vakantiebijslag)
- `loon_per_uur_eigen_werknemer` must be ≥ CAO-minimum for referent-functie
- `onderbouwing_document_url`: at least one document (PDF/scan) must be attached

**Auto-revision trigger:**
- When `payroll-engine-nl` signals CAO-mutation on referent-functie, create task "Revisie inlenersbeloning voor opdracht X"

---

### Entity: UrenRegistratieFlex

Weekly hours logged by the flex-worker or bureau, pending manager approval.

```json
{
  "id": "uuid",
  "opdracht_ref": "InhuurOpdracht id",
  "week_nr": "number (1-53)",
  "jaar": "number (YYYY)",
  "uren_per_dag": "array[number] (Mon-Fri, including zeros)",
  "overuren": "number (hours > 40/week)",
  "goedgekeurd_door_manager_ref": "employee-ref (nullable until approved)",
  "goedgekeurd_datum": "date (nullable)",
  "factuur_ref": "FactuurFlex id (once billed)",
  "status": "enum [ingevoerd, in_afwachting_goedkeuring, goedgekeurd, gefactureerd, betwist]",
  "created_at": "timestamp",
  "created_by": "employee-ref or bureau-api",
  "updated_at": "timestamp"
}
```

**Validation rules:**
- `uren_per_dag` sum must not exceed 10 per day (regulatory max)
- If `overuren` > 0, `cao_toepassing` determines rules (ABU allows unlimited, NBBU limits to 10%)
- Only `goedgekeurd` records can be included in a FactuurFlex

**Workflow:**
1. `ingevoerd`: registered by bureau or flex-worker self-service
2. On register, create task for `inhurende_manager`: "Goedkeuring uren week {week} opdracht {opdracht_nr}"
3. If no approval within 3 days: auto-reminder
4. If no approval within 7 days: escalate to `inhuur_coordinator` + `finance_admin`
5. `goedgekeurd`: manager sets status + timestamp
6. Later included in `FactuurFlex` → status becomes `gefactureerd`

---

### Entity: FactuurFlex

Monthly invoice from bureau, matched against approved hours.

```json
{
  "id": "uuid",
  "bureau_ref": "Bureau id",
  "factuurnr": "string (unique per bureau)",
  "factuurdatum": "date",
  "periode_van": "date (first day of month)",
  "periode_tot": "date (last day of month)",
  "regels": "array[object] {
    opdracht_ref: InhuurOpdracht id,
    uren_goedgekeurd: number,
    tarief_per_uur: decimal (EUR),
    subtotaal: decimal (EUR)
  }",
  "subtotaal": "decimal (EUR, sum of regels)",
  "btw": "decimal (EUR, 21%)",
  "totaal": "decimal (EUR)",
  "g_rekening_split": "object {
    percentage_naar_g_rekening: number,
    bedrag_naar_g_rekening: decimal (EUR),
    bedrag_naar_reguliere_rekening: decimal (EUR)
  }",
  "status": "enum [ontvangen, gematcht, dispute_open, goedgekeurd_betaling, betaald, gearchiveerd]",
  "match_afwijkingen": "array[object] {
    opdracht_ref, type: [uren_mismatch, tarief_mismatch, opdracht_onbekend],
    verwacht, ontvangen, opmerking
  }",
  "goedgekeurd_door_ref": "employee-ref (finance-admin)",
  "goedgekeurd_datum": "date",
  "betaaldatum": "date (nullable)",
  "created_at": "timestamp"
}
```

**Matching logic:**
1. For each regel: find `UrenRegistratieFlex` records for `opdracht_ref` in periode_van..periode_tot
2. Sum goedgekeurde uren from those records
3. Compare tegen `uren_goedgekeurd` in regel:
   - If uren_mismatch > 10%: create dispute, block payment
   - If tarief_per_uur ≠ InhuurOpdracht.uurtarief_inkoop: create dispute
   - If opdracht_ref not found: create dispute "Unknown opdracht"
4. If all regels matched with ≤10% tolerance: status → `gematcht`
5. Finance-admin reviews disputes + approves → status → `goedgekeurd_betaling`
6. Once paid → status → `betaald`

---

### Entity: FAQABUPhaseProgressionTable

Codebook for ABU phase thresholds (embedded in settings, not a database entity).

```json
{
  "ABU": {
    "fase_A": { "max_weeks": 52, "transition_trigger": "52_weeks_elapsed_since_2024-01-01", "next_phase": "fase_B" },
    "fase_B": { "max_contracts": 6, "max_duration_years": 4, "next_phase": "fase_C" },
    "fase_C": { "notes": "Indefinite duration, StiPP-Plus pension applies" }
  },
  "NBBU": {
    "fase_1": { "max_weeks": 39, "notes": "Probationary" },
    "fase_2": { "max_weeks": 78, "next_phase": "fase_3" },
    "fase_3": { "max_weeks": 156, "next_phase": "fase_4" },
    "fase_4": { "notes": "Indefinite duration" }
  }
}
```

---

## Seed Data

### Bureau

```json
[
  {
    "id": "bur-001",
    "kvk": "34567890",
    "naam": "Randstad Uitzendarbeid B.V.",
    "type": "uitzend",
    "sna_keurmerk_status": "geldig",
    "sna_vervaldatum": "2027-06-30",
    "nen_4400_1_certificaat": true,
    "nen_4400_2_certificaat": false,
    "g_rekening_iban": "NL91ABNA0417164300",
    "g_rekening_percentage": 25,
    "abu_of_nbbu_lid": "ABU",
    "contract_raamovereenkomst_ref": "doc-rando-2026"
  },
  {
    "id": "bur-002",
    "kvk": "45678901",
    "naam": "Kelly Services Nederland",
    "type": "uitzend",
    "sna_keurmerk_status": "geldig",
    "sna_vervaldatum": "2026-12-31",
    "nen_4400_1_certificaat": true,
    "nen_4400_2_certificaat": true,
    "g_rekening_iban": "NL45INGD0123456789",
    "g_rekening_percentage": 20,
    "abu_of_nbbu_lid": "ABU",
    "contract_raamovereenkomst_ref": "doc-kelly-2025"
  },
  {
    "id": "bur-003",
    "kvk": "56789012",
    "naam": "Proflex Payrolling B.V.",
    "type": "payroll",
    "sna_keurmerk_status": "geldig",
    "sna_vervaldatum": "2027-03-15",
    "nen_4400_1_certificaat": true,
    "nen_4400_2_certificaat": false,
    "g_rekening_iban": "NL13RABO0300065264",
    "g_rekening_percentage": 30,
    "abu_of_nbbu_lid": "NBBU",
    "contract_raamovereenkomst_ref": "doc-proflex-2026"
  }
]
```

### InhuurOpdracht

```json
[
  {
    "id": "odr-2026-001",
    "opdracht_nr": "2026-001",
    "bureau_ref": "bur-001",
    "kandidaat_naam": "Mark van der Berg",
    "kandidaat_bsn_optional": null,
    "inhurende_manager_ref": "emp-012",
    "inhurende_kostenplaats": "kp-marketing-001",
    "functie_titel": "Copywriter",
    "referent_eigen_functie_ref": "func-copywriter-mid",
    "cao_toepassing": "ABU",
    "fase": "A",
    "startdatum": "2026-06-01",
    "geplande_einddatum": "2026-12-31",
    "werkelijke_einddatum": null,
    "uurtarief_inkoop": 28.50,
    "aantal_uren_per_week": 40,
    "werklocatie": "Amsterdam",
    "status": "actief",
    "inlenersbeloning_onderbouwing_ref": "ilbo-2026-001",
    "notes": "Flexpool voor zomerproject marketing; verlenging mogelijk"
  },
  {
    "id": "odr-2026-002",
    "opdracht_nr": "2026-002",
    "bureau_ref": "bur-002",
    "kandidaat_naam": "Anna Kowalski",
    "kandidaat_bsn_optional": null,
    "inhurende_manager_ref": "emp-034",
    "inhurende_kostenplaats": "kp-it-ops",
    "functie_titel": "Senior DevOps Engineer",
    "referent_eigen_functie_ref": "func-devops-senior",
    "cao_toepassing": "ABU",
    "fase": "B",
    "startdatum": "2025-09-15",
    "geplande_einddatum": "2026-09-14",
    "werkelijke_einddatum": null,
    "uurtarief_inkoop": 65.00,
    "aantal_uren_per_week": 40,
    "werklocatie": "Utrecht",
    "status": "actief",
    "inlenersbeloning_onderbouwing_ref": "ilbo-2026-002",
    "notes": "Fase B sinds juli 2026 (52 weeks gewerkt)"
  },
  {
    "id": "odr-2026-003",
    "opdracht_nr": "2026-003",
    "bureau_ref": "bur-003",
    "kandidaat_naam": "Jan Pieterse",
    "kandidaat_bsn_optional": null,
    "inhurende_manager_ref": "emp-078",
    "inhurende_kostenplaats": "kp-finance-ops",
    "functie_titel": "Finance Analyst",
    "referent_eigen_functie_ref": "func-analyst-finance-mid",
    "cao_toepassing": "NBBU",
    "fase": "1",
    "startdatum": "2026-04-15",
    "geplande_einddatum": "2026-10-15",
    "werkelijke_einddatum": null,
    "uurtarief_inkoop": 35.00,
    "aantal_uren_per_week": 32,
    "werklocatie": "Den Haag",
    "status": "actief",
    "inlenersbeloning_onderbouwing_ref": "ilbo-2026-003",
    "notes": "Payroll werknemer via Proflex; contract bepaald tijd"
  }
]
```

### InlenersBeloningOnderbouwing

```json
[
  {
    "id": "ilbo-2026-001",
    "opdracht_ref": "odr-2026-001",
    "vaststellingsdatum": "2026-05-15",
    "loon_per_uur_eigen_werknemer": 28.50,
    "adv_dagen_per_jaar": 25,
    "toeslagen_overzicht": { "overwerk": "150%", "onregelmatigheid": "10%" },
    "periodieken_staffel": "CAO-marketing-2026-staffel-A",
    "kostenvergoedingen_overzicht": { "reistijd": 0, "tools": 50 },
    "vakantiebijslag_percentage": 8.33,
    "aanvullende_voorzieningen": { "pensoen": false, "rv": true },
    "onderbouwing_document_url": "doc-ilbo-2026-001-pdf",
    "geldig_tot": "2027-05-15",
    "revised_from_id": null
  },
  {
    "id": "ilbo-2026-002",
    "opdracht_ref": "odr-2026-002",
    "vaststellingsdatum": "2025-09-01",
    "loon_per_uur_eigen_werknemer": 62.50,
    "adv_dagen_per_jaar": 28,
    "toeslagen_overzicht": { "overwerk": "125%", "unsocial": "25%" },
    "periodieken_staffel": "CAO-IT-2025-staffel-senior",
    "kostenvergoedingen_overzicht": { "reistijd": 8.50, "tools": 200, "training": 500 },
    "vakantiebijslag_percentage": 8.0,
    "aanvullende_voorzieningen": { "pensoen": true, "rv": true },
    "onderbouwing_document_url": "doc-ilbo-2026-002-pdf",
    "geldig_tot": "2026-09-01",
    "revised_from_id": null
  },
  {
    "id": "ilbo-2026-003",
    "opdracht_ref": "odr-2026-003",
    "vaststellingsdatum": "2026-04-01",
    "loon_per_uur_eigen_werknemer": 35.00,
    "adv_dagen_per_jaar": 25,
    "toeslagen_overzicht": {},
    "periodieken_staffel": "NBBU-finance-2026-staffel-1",
    "kostenvergoedingen_overzicht": { "reistijd": 5.50 },
    "vakantiebijslag_percentage": 8.33,
    "aanvullende_voorzieningen": { "pensoen": false, "rv": false },
    "onderbouwing_document_url": "doc-ilbo-2026-003-pdf",
    "geldig_tot": "2027-04-01",
    "revised_from_id": null
  }
]
```

### UrenRegistratieFlex (sample)

```json
[
  {
    "id": "ur-2026-w24-001",
    "opdracht_ref": "odr-2026-001",
    "week_nr": 24,
    "jaar": 2026,
    "uren_per_dag": [8, 8, 8, 8, 8],
    "overuren": 0,
    "goedgekeurd_door_manager_ref": "emp-012",
    "goedgekeurd_datum": "2026-06-15",
    "factuur_ref": "fac-bur001-202606",
    "status": "gefactureerd"
  },
  {
    "id": "ur-2026-w25-001",
    "opdracht_ref": "odr-2026-001",
    "week_nr": 25,
    "jaar": 2026,
    "uren_per_dag": [8, 8, 8, 7, 8],
    "overuren": 0,
    "goedgekeurd_door_manager_ref": null,
    "goedgekeurd_datum": null,
    "factuur_ref": null,
    "status": "in_afwachting_goedkeuring"
  }
]
```

### FactuurFlex (sample)

```json
[
  {
    "id": "fac-2026-001",
    "bureau_ref": "bur-001",
    "factuurnr": "RANDO-2026-06-001",
    "factuurdatum": "2026-06-25",
    "periode_van": "2026-06-01",
    "periode_tot": "2026-06-30",
    "regels": [
      {
        "opdracht_ref": "odr-2026-001",
        "uren_goedgekeurd": 160,
        "tarief_per_uur": 28.50,
        "subtotaal": 4560.00
      }
    ],
    "subtotaal": 4560.00,
    "btw": 957.60,
    "totaal": 5517.60,
    "g_rekening_split": {
      "percentage_naar_g_rekening": 25,
      "bedrag_naar_g_rekening": 1140.00,
      "bedrag_naar_reguliere_rekening": 4377.60
    },
    "status": "goedgekeurd_betaling",
    "match_afwijkingen": [],
    "goedgekeurd_door_ref": "emp-finance-001",
    "goedgekeurd_datum": "2026-07-02",
    "betaaldatum": "2026-07-05"
  }
]
```

---

## UI Layout

### Page: Medewerkers › Uitzendkrachten

**Main view:** Tabbed interface
- **Tab 1: Actieve opdrachten** — List of status=actief/verlengd InhuurOpdrachten
  - Columns: Opdracht nr, Kandidaat, Bureau, Manager, Functie, Fase, Startdatum, Geplande einde, Uurtarief, Acties
  - Filters: Bureau, Manager, CAO type, Fase, Kostenplaats
  - Actions: [Bekijk detail], [Heractiveer], [Beëindig]

- **Tab 2: Urenregistratie** — List of UrenRegistratieFlex records filtered by status=ingevoerd/in_afwachting_goedkeuring
  - For managers: list of uren-approval tasks with week, opdracht, totaal uren, manager-action buttons
  - Columns: Week, Opdracht, Kandidaat, Uren, Overuren, Status, Goedgekeurd door, Acties
  - Auto-highlights if not approved after 3d

- **Tab 3: Bureaus & relaties** — List of Bureau records
  - Columns: Naam, Type, SNA status, SNA vervaldatum, G-rekening, Lid, Acties
  - Warnings: SNA vervaldatum < 30 days, G-rekening missing
  - Actions: [Bekijk detail], [Raamovereenkomst], [Keurmerk-status updaten]

- **Tab 4: Facturen** — List of FactuurFlex records
  - Columns: Factuurnr, Periode, Bureau, Subtotaal, Status, Disputes, Acties
  - Highlights: status=dispute_open in red
  - Actions: [Bekijk detail], [Match uren], [Goedkeuren betaling], [Download PDF]

- **Tab 5: TCO Dashboard** — Pivot table + chart
  - Group by: Kostenplaats, Manager
  - Metrics: Aantal opdrachten, Totaal FTE-inhuur, Gemiddeld uurtarief, Maandlast, Vs-vast indicator
  - Row actions: drill-down to opdracht list

---

### Detail panel: InhuurOpdracht

**Form sections:**
1. **Basis info** — Opdracht nr (read-only), Bureau (required, dropdown), Kandidaat naam (required), Startdatum (required), Geplande einde (required)
2. **Inhuring context** — Inhurende manager (required, employee lookup), Kostenplaats (required), Functie titel (required)
3. **Tarificering** — Uurtarief (required, decimal EUR/h), Uren per week (required, 0-40), Werklocatie
4. **CAO & fase** — CAO toepassing (ABU/NBBU/branche/geen), Fase (A/B/C or 1/2/3/4, auto-set based on uren-history)
5. **Inlenersbeloning** — Referent functie (required, FunctieProfiel lookup), Link onderbouwing (required), Review onderbouwing status (read-only: geldig/verlopen/revisie-nodig)
6. **Status & notities** — Status (draft/actief/verlengd/beëindigd/gearchiveerd), Notes (text)

**Validation on save:**
- Bureau SNA + G-rekening check
- Referent functie must exist
- Inlenersbeloning onderbouwing if status=actief
- All required fields present

**Actions on detail:**
- [Opslaan] → if new: status=draft; if existing: update
- [Activeer] → (for draft) requires inlenersbeloning → status=actief
- [Heractiveer] → extends geplande_einddatum if not yet beëindigd
- [Beëindig] → sets status=beëindigd + werkelijke_einddatum=today
- [Revisie inlenersbeloning] → creates new InlenersBeloningOnderbouwing record

---

## Integration Points

### From employee-master:
- `FunctieProfiel` lookup for referent-functie + CAO-staffel data
- `Employee` lookup for inhurende-manager validation
- On CAO-staffel mutation → signal InlenersBeloningOnderbouwing revision needed

### From finance-export:
- FactuurFlex import (CSV/API): create FactuurFlex record, trigger matching
- On successful match: create betaalopdracht with G-rekening split
- Export: download FactuurFlex as PDF/CSV for filing

### From task-management:
- Create approval task for uren-registration (manager)
- Create dispute task for factuur-matching (finance-admin)
- Create revision task for inlenersbeloning (coordinator, triggered by payroll-engine)

### From organisations-master:
- Bureau KvK lookup + validation
- Daily batch: check SNA-keurmerk status against external registry

