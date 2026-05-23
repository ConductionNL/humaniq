# Functiehuis HR21: Design Document

**Change ID:** functiehuis-hr21  
**Status:** design  
**Date:** 2026-05-23  

## Data Model

The functiehuis-hr21 module is built on OpenRegister with declarative schema definitions. All domain data lives in OpenRegister objects; no custom Entity/Mapper classes.

### Schemas

#### HR21_Normfunctie

Central library of VNG-standardized municipal functions. Read-only on first sync from HR21 Leeuwendaal source; versioned on updates.

**Location:** `lib/Settings/functiehuis-hr21_register.json` → `HR21Normfunctie` schema

**Properties:**
- `functieCode` (string, unique): e.g. "HR21-BELEIDSMEDEWERKER-II"
- `functieNaam` (string): Display name, e.g. "Beleidsmedewerker B"
- `functieFamilie` (string, relation to HR21Functiefamilie): Grouping family code
- `niveau` (enum: I, II, III, Senior, Strategisch): Seniority level
- `schaalBereik` (object): `{ min: int, max: int }` → valid CAO salary scales
- `voorkeurschaal` (int): Typical entry scale for this function
- `korteOmschrijving` (string): One-line description
- `kerntaken` (array of string): Core responsibilities (5-8 items)
- `vereisteCompetenties` (array): `{ competentie: string, niveau: enum(basis|gevorderd|expert) }`
- `vereisteOpleiding` (string): Required education level (e.g. "HBO werk- en denkniveau")
- `vereisteErvaring` (string): Required experience (e.g. "Minimaal 3 jaar relevante werkervaring")
- `fuwasysScore` (int): ODRP-equivalent competency score (0-48 on 14-trait, 5-dimension ODRP scale)
- `functiewaarderingsmethode` (enum: ODRP): Evaluation method identifier
- `geldigVanaf` (date): Version valid from
- `geldigTot` (date, nullable): Version archived from
- `vngBron` (url): Link to official VNG HR21 source
- `versie` (string): Version identifier from HR21 Leeuwendaal, e.g. "2024.1"

**Indexes:** `(functieCode)` unique, `(functieFamilie)`, `(niveau)`

#### HR21_Functiefamilie

Grouping of related normfuncties for career-path discovery.

**Properties:**
- `familieCode` (string, unique): e.g. "BELEIDSADVISERING"
- `familieNaam` (string): Display name
- `korteOmschrijving` (string): Family description
- `normfunctiesInFamilie` (array of string, relations): List of functieCode values in family
- `schaalBereikFamilie` (object): `{ min: int, max: int }` → union of member scales

**Indexes:** `(familieCode)` unique

#### Maatwerkfunctie

Custom function for municipality-specific role when no HR21 normfunctie fits. Requires explicit business case + high-level approval.

**Properties:**
- `maatwerkFunctieId` (string, uuid, pk)
- `functieCode` (string, unique per gemeente): e.g. "MW-AMSTERDAM-DATA-ETHIEK-ADVISEUR"
- `functieNaam` (string): Display name
- `gemeenteCode` (string, relation to OrganisationUnit): Organization code
- `onderbouwingMaatwerk` (string, >=250 chars): Business case explaining why HR21 normfunctie insufficient
- `afgeleidVanNormfunctie` (string, nullable): Closest HR21 normfunctie code if applicable
- `voorgesteldeSchaal` (int): Proposed salary scale
- `schaalOnderbouwing` (string): Why this scale relative to reference normfunctie
- `kerntaken` (array of string): Function-specific core responsibilities
- `vereisteCompetenties` (array): Required competencies for this role
- `ingangsdatum` (date): Effective from date
- `goedgekeurdDoor` (string, uuid, relation to Employee): Approver (Director HR or CFO)
- `goedkeuringsdatum` (date): Approval date
- `reviewDatum` (date): Date this custom function should be re-reviewed for possible HR21 replacement
- `aangemaakt` (date): Creation date
- `aanmaaktijd` (time): Creation time
- `aanmaker` (string, uuid, relation to Employee): Creator (HR advisor)

**Indexes:** `(functieCode, gemeenteCode)` unique, `(gemeenteCode)`, `(reviewDatum)`  
**Lifecycle:** `x-openregister-lifecycle` with states: draft → submitted → approved → archived  
**Audit Trail:** Full change history via AuditTrailService

#### Functietoekenning

Binding of function to employee. Historized: every change creates a new record; previous record linked via `vorigeToekenning`.

**Properties:**
- `toekenningId` (string, uuid, pk)
- `medewerkerId` (string, uuid, relation to Employee): Link to employee-master
- `functieCode` (string, relation to HR21Normfunctie | Maatwerkfunctie): Function code
- `functieType` (enum: normfunctie | maatwerkfunctie): Type of function
- `ingangsdatum` (date): Effective from
- `einddatum` (date, nullable): Effective to (null = current)
- `schaal` (int, 1-19): Salary scale per CAO Gemeenten
- `periodiek` (int, 1-15): Periodiek within scale (CAO-driven)
- `afdeling` (string): Department assignment
- `leidinggevende` (string, uuid, relation to Employee): Manager
- `fte` (decimal, 0.0-1.0): FTE allocation (0.8889 = 32/36 hours typical)
- `indelingsbesluitId` (string, uuid, relation to DecisionRecord): Formal decision record
- `indelingsproces` (enum: regulier | collectieve_hercategorisatie): Process type
- `status` (enum: voorstel | in_behandeling | vastgesteld | betwist | gearchiveerd): Workflow status
- `vorigeToekenning` (string, uuid, nullable, relation to Functietoekenning): Link to previous assignment
- `wijzigingsreden` (enum): Code reason: eerste_indiensttreding | promotie | demotie | hercategorisatie_collectief | functiewijziging | andere
- `auditTrail` (array, via AuditTrailService): Full change history with actor, action, timestamp, details

**Indexes:** `(medewerkerId, einddatum IS NULL)` → current assignment, `(ingangsdatum)`, `(functieCode)`  
**Lifecycle:** `x-openregister-lifecycle` with states: voorstel → in_behandeling → vastgesteld → betwist | gearchiveerd  
**Relations:**
- `x-openregister-relations`: links to Employee, HR21Normfunctie, Maatwerkfunctie, ManagerApproval, Indelingsworkflow, Bezwaarprocedure

#### Indelingsworkflow

Process record tracking a single classification proposal from HR advisor submission through manager approval, employee notice, and finalization.

**Properties:**
- `workflowId` (string, uuid, pk)
- `medewerkerId` (string, uuid, relation to Employee)
- `type` (enum: nieuwe_indeling | hercategorisatie_individueel | hercategorisatie_collectief): Process type
- `status` (enum: voorstel | in_behandeling | vastgesteld | afgewezen | geannuleerd): Workflow status
- `huidigeStap` (enum): Current step name
- `stappen` (array of WorkflowStep objects):
  - `stapNaam` (enum: voorstel_door_hr | akkoord_leidinggevende | inzage_medewerker | hoorzitting_commissie | definitief_vaststellen)
  - `status` (enum: afgerond | in_uitvoering | wacht | overgeslagen)
  - `uitvoerder` (uuid, relation to Employee, nullable)
  - `afrondingsdatum` (date, nullable)
  - `deadline` (date, nullable)
  - `voorstel` (object, nullable): Draft of proposed function code, scale, periodiek, motivatie
  - `resultaat` (object, nullable): `{ akkoord: boolean, motivatie?: string }`
  - `bezwaartermijnDagen` (int, default 42): Days for employee to file objection (per Awb)
- `ingediendOp` (datetime): Submission timestamp
- `verwachteAfrondingsDatum` (date): Expected completion date
- `actualAfrondingsDatum` (date, nullable): Actual completion date

**Indexes:** `(medewerkerId)`, `(status)`, `(huidigeStap)`, `(ingediendOp)`  
**Lifecycle:** `x-openregister-lifecycle` with built-in SLA tracking (step deadlines)  
**Notifications:** `x-openregister-notifications` for step transitions (mail to HR advisor, manager, employee)

#### Bezwaarprocedure

Formal objection procedure per Awb when employee disputes a classification decision. Governs 6-week statutory timeline + hoor-en-wederhoor requirements.

**Properties:**
- `bezwaarId` (string, uuid, pk)
- `medewerkerId` (string, uuid, relation to Employee)
- `tegenIndelingsbesluit` (string, uuid, relation to Functietoekenning): Contested assignment
- `bezwaarsgrond` (string, >=100 chars): Employee's stated reason for objection
- `indieningsdatum` (date): Filing date (triggers 6-week processing deadline)
- `behandelaar` (string, uuid, relation to Employee): HR advisor assigned to handle objection
- `status` (enum: ontvangen | ontvankelijk | ontvankelijkheid_betwist | vooronderzoek | hoorzitting_commissie | advies_ontvangen | beslissing_gegeven | ingetrokken): Process status per Awb
- `stappen` (array of BezwaarStap):
  - `stap` (enum): ontvangstbevestiging | ontvankelijkheidstoets | vooronderzoek | hoorzitting_commissie | advies_commissie | beslissing_op_bezwaar | uitvoering_beslissing
  - `datum` (date, nullable)
  - `uitkomst` (enum, nullable): ontvankelijk | niet_ontvankelijk | gegrond | ongegrond | deels_gegrond
  - `notities` (string, nullable)
- `wettelijkeTermijnAfloop` (date): Hard deadline (6 weeks from filing per Awb art. 7:11)
- `commissieHoorzittingDatum` (date, nullable): Scheduled hearing date
- `adviesCommissie` (string, nullable): Formal advisory opinion text
- `beslissingOp Bezwaar` (object, nullable):
  - `uitkomst` (enum): gegrond | ongegrond | deels_gegrond
  - `motivatie` (string)
  - `gevolg` (string): What happens as a result (e.g. "functie herzien naar HR21-SENIOR-BELEIDSMEDEWERKER")
  - `beslissendAmbtenaar` (uuid, relation to Employee)
  - `datum` (date)

**Indexes:** `(medewerkerId)`, `(tegenIndelingsbesluit)`, `(status)`, `(wettelijkeTermijnAfloop)`  
**Lifecycle:** `x-openregister-lifecycle` with Awb-driven state machine + deadline guards  
**Audit Trail:** Every step fully logged for legal defensibility

## Seed Data

Three representative objects per schema for development and QA.

### HR21_Normfunctie (3 seed objects)

```json
[
  {
    "@self": {
      "register": "functiehuis-hr21",
      "schema": "HR21Normfunctie",
      "slug": "normfunctie-beleidsmedewerker-b"
    },
    "functieCode": "HR21-BELEIDSMEDEWERKER-II",
    "functieNaam": "Beleidsmedewerker B",
    "functieFamilie": "BELEIDSADVISERING",
    "niveau": "II",
    "schaalBereik": { "min": 9, "max": 11 },
    "voorkeurschaal": 10,
    "korteOmschrijving": "Ontwikkelt beleid op deelterreinen, adviseert bestuur en management.",
    "kerntaken": [
      "Beleidsvoorbereiding op afgebakend terrein",
      "Adviseren management en bestuur",
      "Schrijven van bestuursvoorstellen en raadsstukken",
      "Onderhouden contacten met externe partners",
      "Bijdragen aan beleidsuitvoering en evaluatie"
    ],
    "vereisteCompetenties": [
      { "competentie": "Analytisch vermogen", "niveau": "gevorderd" },
      { "competentie": "Schriftelijk uitdrukkingsvermogen", "niveau": "gevorderd" },
      { "competentie": "Bestuurlijke sensitiviteit", "niveau": "basis" },
      { "competentie": "Resultaatgerichtheid", "niveau": "gevorderd" },
      { "competentie": "Samenwerken", "niveau": "gevorderd" }
    ],
    "vereisteOpleiding": "HBO werk- en denkniveau, bij voorkeur in relevante studierichting",
    "vereisteErvaring": "Minimaal 3 jaar relevante werkervaring",
    "fuwasysScore": 42,
    "functiewaarderingsmethode": "ODRP",
    "geldigVanaf": "2020-01-01",
    "geldigTot": null,
    "vngBron": "https://hr21.nl/normfuncties/beleidsmedewerker-b",
    "versie": "2024.1"
  },
  {
    "@self": {
      "register": "functiehuis-hr21",
      "schema": "HR21Normfunctie",
      "slug": "normfunctie-boa"
    },
    "functieCode": "HR21-BOA-MEDEWERKER",
    "functieNaam": "BOA Medewerker",
    "functieFamilie": "HANDHAVING",
    "niveau": "I",
    "schaalBereik": { "min": 4, "max": 6 },
    "voorkeurschaal": 5,
    "korteOmschrijving": "Buitengewoon Opsporingsambtenaar voor lokale handhaving, toezicht en controle.",
    "kerntaken": [
      "Uitvoering handhavingstaken (parkeren, hinderlijke dieren, overlastmeldingen)",
      "Opstellen van processen-verbaal",
      "Communicatie met burgers en ondernemers",
      "Ondersteuning politie bij noodzakelijk",
      "Registratie en documentatie van bevindingen"
    ],
    "vereisteCompetenties": [
      { "competentie": "Assertiviteit", "niveau": "gevorderd" },
      { "competentie": "Stressbestendigheid", "niveau": "gevorderd" },
      { "competentie": "Probleemoplossend vermogen", "niveau": "basis" },
      { "competentie": "Klantgerichtheid", "niveau": "gevorderd" }
    ],
    "vereisteOpleiding": "MBO niveau 4 of gelijkwaardig",
    "vereisteErvaring": "Minimaal 1 jaar relevante werkervaring of bereidheid tot scholing",
    "fuwasysScore": 28,
    "functiewaarderingsmethode": "ODRP",
    "geldigVanaf": "2019-01-01",
    "geldigTot": null,
    "vngBron": "https://hr21.nl/normfuncties/boa-medewerker",
    "versie": "2024.1"
  },
  {
    "@self": {
      "register": "functiehuis-hr21",
      "schema": "HR21Normfunctie",
      "slug": "normfunctie-civiel-werkvoorbereider"
    },
    "functieCode": "HR21-CIVIEL-WERKVOORBEREIDER",
    "functieNaam": "Civiel Werkvoorbereider",
    "functieFamilie": "INFRA_EN_ONDERHOUD",
    "niveau": "II",
    "schaalBereik": { "min": 6, "max": 9 },
    "voorkeurschaal": 7,
    "korteOmschrijving": "Bereidt civiele werken voor; coördineert planning, budgettering, veiligheid.",
    "kerntaken": [
      "Voorbereiding civiele werkprojecten (riolering, wegen, parken)",
      "Opstellen werkschema's en budgetten",
      "Coördinatie van veiligheid op werklocaties",
      "Communicatie met aannemers en stakeholders",
      "Opvolging en rapportage van projectvoortgang"
    ],
    "vereisteCompetenties": [
      { "competentie": "Technisch inzicht", "niveau": "gevorderd" },
      { "competentie": "Organisatorisch vermogen", "niveau": "gevorderd" },
      { "competentie": "Leiding geven", "niveau": "basis" },
      { "competentie": "Aandacht voor veiligheid", "niveau": "expert" }
    ],
    "vereisteOpleiding": "MBO niveau 4 civiele techniek of HBO werk- en denkniveau",
    "vereisteErvaring": "Minimaal 4 jaar ervaring in civiele werkvoorbereiding",
    "fuwasysScore": 36,
    "functiewaarderingsmethode": "ODRP",
    "geldigVanaf": "2018-01-01",
    "geldigTot": null,
    "vngBron": "https://hr21.nl/normfuncties/civiel-werkvoorbereider",
    "versie": "2024.1"
  }
]
```

### HR21_Functiefamilie (2 seed objects)

```json
[
  {
    "@self": {
      "register": "functiehuis-hr21",
      "schema": "HR21Functiefamilie",
      "slug": "functiefamilie-beleidsadvisering"
    },
    "familieCode": "BELEIDSADVISERING",
    "familieNaam": "Beleidsadvisering",
    "korteOmschrijving": "Functies gericht op beleidsontwikkeling, beleidsadvisering en beleidsondersteuning.",
    "normfunctiesInFamilie": [
      "HR21-BELEIDSMEDEWERKER-I",
      "HR21-BELEIDSMEDEWERKER-II",
      "HR21-BELEIDSMEDEWERKER-III",
      "HR21-SENIOR-BELEIDSMEDEWERKER",
      "HR21-STRATEGISCH-BELEIDSADVISEUR"
    ],
    "schaalBereikFamilie": { "min": 8, "max": 14 }
  },
  {
    "@self": {
      "register": "functiehuis-hr21",
      "schema": "HR21Functiefamilie",
      "slug": "functiefamilie-infra-onderhoud"
    },
    "familieCode": "INFRA_EN_ONDERHOUD",
    "familieNaam": "Infrastructuur & Onderhoud",
    "korteOmschrijving": "Functies gericht op voorbereiding, uitvoering en onderhoud van civiele werkzaamheden.",
    "normfunctiesInFamilie": [
      "HR21-CIVIEL-ARBEIDER",
      "HR21-CIVIEL-WERKVOORBEREIDER",
      "HR21-CIVIEL-OPZICHTER"
    ],
    "schaalBereikFamilie": { "min": 4, "max": 10 }
  }
]
```

### Maatwerkfunctie (1 seed object)

```json
[
  {
    "@self": {
      "register": "functiehuis-hr21",
      "schema": "Maatwerkfunctie",
      "slug": "maatwerkfunctie-amsterdam-data-ethiek"
    },
    "maatwerkFunctieId": "550e8400-e29b-41d4-a716-446655440000",
    "functieCode": "MW-AMSTERDAM-DATA-ETHIEK-ADVISEUR",
    "functieNaam": "Adviseur Data-ethiek",
    "gemeenteCode": "GM0363",
    "onderbouwingMaatwerk": "Functie ontstaan in 2023 vanwege toenemende vraagstukken rond ethische data-toepassing in smarte stad-initiatieven. Amsterdam heeft geen passende HR21-normfunctie beschikbaar die het combinatie van technische data-competentie en ethische reflectie dekt. Deze rol is uniek voor grote steden met actieve data-science-teams.",
    "afgeleidVanNormfunctie": "HR21-SENIOR-BELEIDSMEDEWERKER",
    "voorgesteldeSchaal": 12,
    "schaalOnderbouwing": "Vergelijkbaar met Senior Beleidsmedewerker qua strategische bijdrage en complexiteit, maar met specialistische technische component. Schaal 12 positioneert de rol als specialist-adviserend, niet management-lijn.",
    "kerntaken": [
      "Adviseren over ethische aspecten van data-gebruik in gemeente",
      "Opstellen van data-ethische kaders en policies",
      "Beoordelen van algoritmes op fairness, bias en discriminatie",
      "Vertegenwoordigen gemeente in landelijke data-ethics fora",
      "Training en awareness bij collega's"
    ],
    "vereisteCompetenties": [
      { "competentie": "Analytisch vermogen", "niveau": "expert" },
      { "competentie": "Ethische reflectie", "niveau": "expert" },
      { "competentie": "Bestuurlijke sensitiviteit", "niveau": "gevorderd" },
      { "competentie": "Communicatief vermogen", "niveau": "gevorderd" }
    ],
    "ingangsdatum": "2024-03-01",
    "goedgekeurdDoor": "db1c5d9a-3f2c-4e7d-8a1b-7e4d5f6c8a9b",
    "goedkeuringsdatum": "2024-02-15",
    "reviewDatum": "2027-03-01",
    "aangemaakt": "2024-02-10",
    "aanmaker": "c5d9a3f2-2c4e-7d8a-1b7e-4d5f6c8a9b0c"
  }
]
```

### Functietoekenning (2 seed objects)

```json
[
  {
    "@self": {
      "register": "functiehuis-hr21",
      "schema": "Functietoekenning",
      "slug": "functietoekenning-janssen-beleidsmedewerker"
    },
    "toekenningId": "660e8400-e29b-41d4-a716-446655440001",
    "medewerkerId": "a1b2c3d4-e5f6-47g8-h9i0-j1k2l3m4n5o6",
    "functieCode": "HR21-BELEIDSMEDEWERKER-II",
    "functieType": "normfunctie",
    "ingangsdatum": "2024-03-01",
    "einddatum": null,
    "schaal": 10,
    "periodiek": 4,
    "afdeling": "Sociaal Domein",
    "leidinggevende": "b2c3d4e5-f6g7-48h9-i0j1-k2l3m4n5o6p7",
    "fte": 1.0,
    "indelingsbesluitId": "770e8400-e29b-41d4-a716-446655440002",
    "indelingsproces": "regulier",
    "status": "vastgesteld",
    "vorigeToekenning": null,
    "wijzigingsreden": "eerste_indiensttreding"
  },
  {
    "@self": {
      "register": "functiehuis-hr21",
      "schema": "Functietoekenning",
      "slug": "functietoekenning-klerkx-boa"
    },
    "toekenningId": "880e8400-e29b-41d4-a716-446655440003",
    "medewerkerId": "c3d4e5f6-g7h8-49i0-j1k2-l3m4n5o6p7q8",
    "functieCode": "HR21-BOA-MEDEWERKER",
    "functieType": "normfunctie",
    "ingangsdatum": "2023-06-15",
    "einddatum": null,
    "schaal": 5,
    "periodiek": 6,
    "afdeling": "Handhaving & Toezicht",
    "leidinggevende": "d4e5f6g7-h8i9-50j0-k1l2-m3n4o5p6q7r8",
    "fte": 1.0,
    "indelingsbesluitId": "990e8400-e29b-41d4-a716-446655440004",
    "indelingsproces": "regulier",
    "status": "vastgesteld",
    "vorigeToekenning": null,
    "wijzigingsreden": "eerste_indiensttreding"
  }
]
```

## Architectural Decisions

### Data & Schema Approach

**Decision:** All HR21 data lives in OpenRegister schemas. No custom Entity/Mapper classes per ADR-001 (data-layer).

**Why:** Enables automatic audit trails, RBAC per field, cross-app querying (GraphQL), and webhook integration out of the box. The OR engine handles ~250+ backend methods (CRUD, search, aggregation, import/export); custom code would duplicate that.

**Consequence:** Normfunctie and Functiefamilie are read-only; Maatwerkfunctie, Functietoekenning, Indelingsworkflow, and Bezwaarprocedure are mutable with full lifecycle + audit.

### Declarative Business Logic

**Decision:** Use `x-openregister-lifecycle` for Indelingsworkflow and Bezwaarprocedure state machines instead of custom PHP service classes (per ADR-031: schema-declarative business logic).

**Why:** 
- Every transition is audit-trailed with before/after state + actor + timestamp (legal defensibility for Awb disputes)
- RBAC per state (e.g. only HR advisor can move from `voorstel` to `in_behandeling`)
- Notifications are declarative (mail to manager on `akkoord_leidinggevende` step)
- Replayable for audit + restore scenarios

**Consequence:** Indelingsworkflow and Bezwaarprocedure don't need custom service classes. The register file declares the state graph, transitions, guards (via PHP lifecycle guards), and notifications as metadata.

### Salary Calculation Integration

**Decision:** Functietoekenning does NOT store computed brutomaandsalaris; it stores only (schaal, periodiek). Salary is looked up at read-time from cao-gemeenten via relation + query.

**Why:** 
- CAO salary tables change yearly (3% raise, etc.); storing computed salary creates stale data.
- Functietoekenning is the source of truth for (schaal, periodiek); cao-gemeenten provides the exchange rate.
- Hercategorisatie calculation (horizontaal-aansluitende-bedrag) happens in a temporary "proposal" context during workflow; not persisted.

**Consequence:** Salary queries require a cao-gemeenten dependency check at read time. Frontend cannot display salary without a synchronous lookup.

### Audit Trail Design

**Decision:** Functietoekenning leverages AuditTrailService (automatic via OpenRegister). Indelingsworkflow and Bezwaarprocedure have explicit `stappen` arrays for workflow-specific milestones.

**Why:** 
- AuditTrailService is automatic (no code needed); tracks ALL field changes + actor + timestamp.
- Workflow stappen are NOT fields; they are milestones within a state machine. They belong in the data model, not audit-only.
- Legal disputes require both: "When did the salaris change?" (audit) and "What step was the workflow in?" (stappen).

**Consequence:** Bezwaarprocedure can trace a complete timeline: received date → ontvankelijkheidstoets outcome → commissie hoorzitting date → final decision date. All immutable.

### Maatwerkfunctie Governance

**Decision:** Maatwerkfunctie requires explicit approval by Director HR or CFO (not direct manager). Triggers OR instemmingsrecht notification.

**Why:** Custom functions expand the salary budget and create precedent. Direct manager approval is insufficient; executive accountability is required.

**Consequence:** Maatwerkfunctie creation workflow has a hard dependency on OR approval chain + notification. No maatwerk classification can be finalized without executive sign-off.

## Reuse Analysis

**OpenRegister abstractions leveraged:**

| Abstraction | Used For | Rationale |
|---|---|---|
| ObjectService CRUD | Normfunctie, Functiefamilie, Maatwerkfunctie, Functietoekenning, Indelingsworkflow, Bezwaarprocedure | Standard object lifecycle; no custom repository |
| AuditTrailService | Functietoekenning mutations + all custom function changes | Every change trail for legal disputes + GDPR-DSR |
| `x-openregister-lifecycle` | Indelingsworkflow, Bezwaarprocedure, Maatwerkfunctie | State machine + audit trail + RBAC per state |
| `x-openregister-notifications` | Indelingsworkflow step transitions | Mail to HR advisor, manager, employee on each milestone |
| `x-openregister-relations` | Links between Functietoekenning ↔ Maatwerkfunctie, Bezwaarprocedure ↔ Functietoekenning, etc. | Typed cross-schema references without foreign keys |
| ImportService | HR21 normfunctie sync from Leeuwendaal/VNG | Annual bulk import of ~150 functions with version tracking |
| ExportService | Functietoekenning history reports for payroll/audit | CSV/Excel export of classification audit trail |
| CnFormDialog | Maatwerkfunctie proposal form | Auto-generated from schema; no custom form code |
| CnDetailPage | Functietoekenning detail view | Full history + audit trail tabs; auto-rendered |

**No custom PHP services needed** (all declarative in register):
- State machines → `x-openregister-lifecycle`
- Notifications → `x-openregister-notifications`
- Aggregations (maatwerkfunctie count by gemeente) → `x-openregister-aggregations` (future use)

## Frontend Architecture

**Placement:** SUB_PAGE under Medewerkers › Functiehuis (per ADR-001: information architecture).

**Surfaces:**
1. **Medewerkers › Functiehuis › Normfuncties** (read-only): Search, browse, detail view of ~150 standard functions
2. **Medewerkers › Functiehuis › Maatwerkfuncties** (CRUD): Manage custom functions per gemeente
3. **Medewerkers › Functiehuis › Indelingsworkflow** (dashboard): HR advisor sees pending proposals; manager sees approvals due; employee sees own classifications
4. **Medewerkers › Functiehuis › Bezwaarenprocedures** (workflow): Handle formal objections (Awb timeline)
5. **Mijn HR › Mijn Indeling** (self-service): Employee views own function + motivatie + bezwaarrecht option
6. **Dashboard › Functiehuis KPI** (widget): Director HR sees maatwerk count + family distribution + alerts

**Role-based views:**
- **HR Advisor:** Full CRUD on Maatwerkfunctie + Indelingsworkflow proposal/approval oversight
- **Manager:** Approve/reject Indelingsworkflow proposals (mobile-optimized)
- **Directeur HR:** Maatwerkfunctie approvals + functiehuis dashboard + bezwaarprocedure oversight
- **Medewerker:** View own Functietoekenning + file bezwaar (Mijn HR)

## Integration Dependencies

- **cao-gemeenten:** Lookup active salary scales per (schaal, periodiek) for display + hercategorisatie calculations
- **employee-master:** Employee ID, name, manager link
- **OR-portaal:** Notify OR / vakbond on maatwerkfunctie creations (art. 27 WOR)
- **decidesk:** Formal bezwaarprocedure (if not handled within this module)
- **docudesk:** Generate indelingsbesluit, hercategorisatie-brief, bezwaarbeschikking PDFs
- **openconnector / HR21-Leeuwendaal:** Annual sync of updated normfunctie library

## Out of Scope

- Migration of existing classifications from legacy systems
- FUWASYS (education sector; separate module)
- Periodic periodiek advancement (payroll-engine responsibility)
- Salaris calculation engine (cao-gemeenten module; this module only stores scale ref)
