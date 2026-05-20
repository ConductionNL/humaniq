# Design: Pension Admin MVP

## Context

De Pension Admin MVP introduceert volledige ondersteuning voor maandelijkse UPA-pensioenaangifte aan vijf verplichte bedrijfstak-pensioenfondsen: PFZW, ABP, BPL, bpfBOUW en StPVG. De app is een nieuwe Nextcloud-app in het HRMQ-ecosysteem en is afhankelijk van `payroll-core-basic` voor werknemer- en salarisdata. Alle domeindata leeft als OpenRegister-objecten. Externe aanlevering loopt via OpenConnector zodat geen credentials in de app worden opgeslagen.

## Goals / Non-Goals

**Goals:**
- OpenRegister-schema's voor PensionFund, PensionScheme, PensionParticipant, PensionDeclaration, PensionDeclarationLine
- Declaratieve lifecycle voor PensionDeclaration via `x-openregister-lifecycle`
- UPA XML-generatie conform Pensioenfederatie-standaard
- Premieberekening per fondsregeling met grondslag, franchise en parttime-correctie
- Digitale aanlevering via OpenConnector per fonds
- HR-dashboard met KPI's en aangifte-statusoverzicht

**Non-Goals:**
- Pensioenaangifte voor buitenlandse of branche-overstijgende fondsen buiten de vijf genoemde
- Directe HTTP-verbindingen naar fonds-API's (loopt altijd via OpenConnector)
- Berekening bijzondere beloningen (vakantiegeld, 13e maand, bonus) — dit is payroll-core-basic
- Pensioenopgave richting werknemer (UPO — Uniform Pensioenoverzicht) — out of scope MVP
- Automatische deelnemersynchronisatie vanuit CAO-databronnen

## Decisions

### Decision 1: UPA XML-generatie als imperatieve service

UPA XML-generatie valt onder de documentgeneratie-uitzondering van ADR-031. De Pensioenfederatie UPA XSD bevat fonds-specifieke berichtstructuren en namespace-vereisten die niet door de OR schema-engine kunnen worden uitgedrukt. `UpaGeneratorService::generate(string $declarationId): string` assembleert de XML, voegt correctienummer en fonds-specifieke berichtcode toe en valideert tegen de XSD.

### Decision 2: Premieberekening als domeinregelkiezer

Elk fonds heeft eigen berekeningsregels: PFZW gebruikt een andere franchise-staffel dan ABP; BPL kent deeltijd-correcties afwijkend van bpfBOUW. Dit is de "domain rule engine" uitzondering in ADR-031. `PensionCalculationService` selecteert de fondsspecifieke berekeningslogica op basis van `fundCode`. Zo is de service uitbreidbaar voor nieuwe fondsen zonder de bestaande regels te raken.

### Decision 3: Aanlevering via OpenConnector (ADR-019)

Externe integraties lopen via OpenConnector conform ADR-019. `PensionFundGatewayService::submit(string $declarationId, string $xml): SubmissionResult` delegeert het transport aan de geconfigureerde OpenConnector-bron per fonds. Credentials worden beheerd door OpenConnector, niet door de app.

### Decision 4: Aangifte-lifecycle declaratief (ADR-031)

De status-machine van PensionDeclaration (`concept → ingediend → bevestigd / afgewezen`) past volledig in `x-openregister-lifecycle`. Dit vermijdt een custom `PensionDeclarationStatusService`. De lifecycle wordt gedeclareerd in `lib/Settings/pensionadmin_register.json` en levert gratis audit trail, RBAC per status en CloudEvents op.

### Decision 5: Notificaties declaratief

Indiening- en bevestigingsnotificaties worden gedeclareerd via `x-openregister-notifications` op PensionDeclaration. Geen custom NotificationService nodig.

## Declarative-vs-Imperative Decision

| Behaviour | Path | Rationale |
|---|---|---|
| PensionDeclaration status lifecycle (`concept → ingediend → bevestigd / afgewezen`) | **Declaratief** — `x-openregister-lifecycle` | Standaard state machine; OR lifecycle-engine ondersteunt dit native; gratis audit trail + RBAC per status. |
| Notificatie bij indiening (naar HR-medewerker) | **Declaratief** — `x-openregister-notifications` | Standaard OR-notificatiepatroon; geen custom fanout nodig. |
| Notificatie bij bevestiging/afwijzing (naar HR-medewerker) | **Declaratief** — `x-openregister-notifications` | Idem. |
| UPA XML-generatie | **Imperatief** — `UpaGeneratorService` | Documentgeneratie-uitzondering ADR-031: Pensioenfederatie XSD + namespace-vereisten; OR engine heeft geen XML-template-rendering. |
| Digitale aanlevering (PFZW/ABP/BPL/bpfBOUW/StPVG) | **Imperatief** — `PensionFundGatewayService` via OpenConnector | Externe API-integratie-uitzondering ADR-031. |
| Grondslag- en premieberekening | **Imperatief** — `PensionCalculationService` | Domeinregelkiezer-uitzondering ADR-031: elke fondscode heeft eigen franchise-staffels, deeltijdregels en CAO-specifieke maxima. |

## Reuse Analysis

| Functionaliteit | OpenRegister / @conduction/nextcloud-vue |
|---|---|
| CRUD alle entiteiten | `ObjectService.saveObject()`, `deleteObject()`, `findAll()` |
| Lifecycle PensionDeclaration | `x-openregister-lifecycle` in register JSON |
| Audit trail (alle mutaties + transities) | `AuditTrailService` — automatisch |
| XML-bestand opslaan als bijlage bij aangifte | `FileService` via `CnObjectSidebar → CnFilesTab` |
| Bulk-import deelnemers (CSV) | `ImportService` + `CnMassImportDialog` |
| Export aangifte-overzicht | `ExportService` + `CnMassExportDialog` |
| Dashboard KPI's en widgets | `CnDashboardPage` + `CnStatsBlock` + `CnTableWidget` |
| Lijstpagina's (fondsen, deelnemers, aangiften) | `CnIndexPage` + `useListView` + `createObjectStore` |
| Detailpagina's | `CnDetailPage` + `CnDetailCard` + `CnObjectSidebar` |
| Formulieren (aanmaken/bewerken) | `CnFormDialog` (schema-driven) |
| RBAC per object | `AuthorizationService` + `PropertyRbacHandler` |
| Notificaties indiening/bevestiging/afwijzing | `x-openregister-notifications` in register JSON |
| Statusbadge in lijstweergave | `CnStatusBadge` |
| Lifecycle-voortgang in detail | `CnTimelineStages` |

Geen custom CRUD-controllers, bestandsupload-handlers, zoekfunctionaliteit, audit logging of notificatieservices nodig.

## Schema Definitions

### PensionFund (`schema:Organization` subtype)

```json
{
  "title": "PensionFund",
  "type": "object",
  "required": ["name", "fundCode"],
  "properties": {
    "name":                 { "type": "string",  "description": "Officiële naam van het pensioenfonds" },
    "fundCode":             { "type": "string",  "enum": ["PFZW","ABP","BPL","bpfBOUW","StPVG","OTHER"], "description": "Fondscode" },
    "aansluitingsnummer":   { "type": "string",  "description": "Aansluitingsnummer van de werkgever bij dit fonds" },
    "sector":               { "type": "string",  "description": "Bedrijfstak, bijv. Zorg en Welzijn" },
    "openConnectorSourceId":{ "type": "string",  "description": "ID van de OpenConnector-bron voor digitale aanlevering" },
    "serviceUrl":           { "type": "string",  "format": "uri", "description": "Informatief API-endpoint fonds" }
  }
}
```

### PensionScheme (`schema:FinancialProduct` subtype)

```json
{
  "title": "PensionScheme",
  "type": "object",
  "required": ["name", "pensionFundId", "premiePercentageWerkgever", "premiePercentageWerknemer", "franchise"],
  "properties": {
    "name":                        { "type": "string" },
    "pensionFundId":               { "type": "string", "description": "OpenRegister relatie → PensionFund (slug)" },
    "premiePercentageWerkgever":   { "type": "number", "minimum": 0, "maximum": 100 },
    "premiePercentageWerknemer":   { "type": "number", "minimum": 0, "maximum": 100 },
    "franchise":                   { "type": "number", "description": "Jaarlijkse franchise in euro (bijv. 17350)" },
    "maximumPensioensalaris":      { "type": "number", "description": "Jaarsalaris-maximum (0 = geen maximum)" },
    "ingangsdatum":                { "type": "string", "format": "date" },
    "einddatum":                   { "type": "string", "format": "date" }
  }
}
```

### PensionParticipant (`schema:Role`)

```json
{
  "title": "PensionParticipant",
  "type": "object",
  "required": ["employeeId", "pensionFundId", "schemeId", "ingangsdatum"],
  "properties": {
    "employeeId":           { "type": "string", "description": "ObjectId werknemer in payroll-core-basic" },
    "bsn":                  { "type": "string", "pattern": "^[0-9]{9}$", "description": "BSN (11-proef geldig)" },
    "pensionFundId":        { "type": "string", "description": "OpenRegister relatie → PensionFund (slug)" },
    "schemeId":             { "type": "string", "description": "OpenRegister relatie → PensionScheme (slug)" },
    "deelnemersnummer":     { "type": "string", "description": "Deelnemersnummer bij het fonds" },
    "ingangsdatum":         { "type": "string", "format": "date" },
    "uittreedatum":         { "type": "string", "format": "date" },
    "partTimePercentage":   { "type": "number", "minimum": 0, "maximum": 100, "default": 100 }
  }
}
```

### PensionDeclaration (`schema:Report`)

```json
{
  "title": "PensionDeclaration",
  "type": "object",
  "required": ["pensionFundId", "aangiftePeriode", "status"],
  "properties": {
    "pensionFundId":            { "type": "string", "description": "OpenRegister relatie → PensionFund (slug)" },
    "aangiftePeriode":          { "type": "string", "pattern": "^[0-9]{4}-[0-9]{2}$", "description": "Periode JJJJ-MM" },
    "status":                   { "type": "string", "enum": ["concept","ingediend","bevestigd","afgewezen"] },
    "totalePremieWerkgever":    { "type": "number" },
    "totalePremieWerknemer":    { "type": "number" },
    "aantalDeelnemers":         { "type": "integer" },
    "ingediendOp":              { "type": "string", "format": "date-time" },
    "bevestigdOp":              { "type": "string", "format": "date-time" },
    "correctienummer":          { "type": "integer", "default": 0 },
    "foutmelding":              { "type": "string", "description": "Foutbeschrijving bij status afgewezen" }
  }
}
```

### PensionDeclarationLine

```json
{
  "title": "PensionDeclarationLine",
  "type": "object",
  "required": ["declarationId", "participantId", "pensioensalaris", "grondslag"],
  "properties": {
    "declarationId":    { "type": "string", "description": "OpenRegister relatie → PensionDeclaration (slug)" },
    "participantId":    { "type": "string", "description": "OpenRegister relatie → PensionParticipant (slug)" },
    "bsn":              { "type": "string", "pattern": "^[0-9]{9}$" },
    "pensioensalaris":  { "type": "number", "description": "Maandelijks pensioensalaris (na deeltijdcorrectie)" },
    "grondslag":        { "type": "number", "description": "Pensioengrondslag (salaris - franchise/12, min 0)" },
    "premieWerknemer":  { "type": "number" },
    "premieWerkgever":  { "type": "number" },
    "partTimeCorrectie":{ "type": "number", "description": "Toegepaste parttimefactor (0.0–1.0)" }
  }
}
```

## Seed Data

Conform ADR-001: 3–5 objecten per schema, fictieve maar realistische Nederlandse waarden, idempotent via slug.

### PensionFund (3 objecten)

```json
[
  {
    "@self": { "register": "pensionadmin", "schema": "PensionFund", "slug": "pfzw-hoofdkantoor" },
    "name": "Pensioenfonds Zorg en Welzijn",
    "fundCode": "PFZW",
    "aansluitingsnummer": "PZ-1234567",
    "sector": "Zorg en Welzijn",
    "serviceUrl": "https://YOUR_PFZW_ENDPOINT_HERE"
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionFund", "slug": "abp-overheid" },
    "name": "Algemeen Burgerlijk Pensioenfonds",
    "fundCode": "ABP",
    "aansluitingsnummer": "AB-9876543",
    "sector": "Overheid en Onderwijs",
    "serviceUrl": "https://YOUR_ABP_ENDPOINT_HERE"
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionFund", "slug": "bpl-landbouw" },
    "name": "Bedrijfstakpensioenfonds voor de Landbouw",
    "fundCode": "BPL",
    "aansluitingsnummer": "BL-1111222",
    "sector": "Agrarisch",
    "serviceUrl": "https://YOUR_BPL_ENDPOINT_HERE"
  }
]
```

### PensionScheme (3 objecten)

```json
[
  {
    "@self": { "register": "pensionadmin", "schema": "PensionScheme", "slug": "pfzw-basis-2026" },
    "name": "PFZW Basisregeling 2026",
    "pensionFundId": "pfzw-hoofdkantoor",
    "premiePercentageWerkgever": 14.3,
    "premiePercentageWerknemer": 4.9,
    "franchise": 17350.00,
    "maximumPensioensalaris": 71628.00,
    "ingangsdatum": "2026-01-01"
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionScheme", "slug": "abp-middelloon-2026" },
    "name": "ABP Middelloon 2026",
    "pensionFundId": "abp-overheid",
    "premiePercentageWerkgever": 14.3,
    "premiePercentageWerknemer": 8.6,
    "franchise": 17350.00,
    "maximumPensioensalaris": 71628.00,
    "ingangsdatum": "2026-01-01"
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionScheme", "slug": "bpl-basis-2026" },
    "name": "BPL Pensioen Basisregeling 2026",
    "pensionFundId": "bpl-landbouw",
    "premiePercentageWerkgever": 11.4,
    "premiePercentageWerknemer": 4.5,
    "franchise": 17350.00,
    "maximumPensioensalaris": 71628.00,
    "ingangsdatum": "2026-01-01"
  }
]
```

### PensionParticipant (3 objecten)

BSNs zijn fictief maar voldoen aan de 11-proef (verificatie: 154/11=14, 198/11=18, 220/11=20).

```json
[
  {
    "@self": { "register": "pensionadmin", "schema": "PensionParticipant", "slug": "deelnemer-jansen-pfzw" },
    "employeeId": "employee-001",
    "bsn": "123456782",
    "pensionFundId": "pfzw-hoofdkantoor",
    "schemeId": "pfzw-basis-2026",
    "deelnemersnummer": "PFZ-1001001",
    "ingangsdatum": "2020-03-01",
    "partTimePercentage": 100
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionParticipant", "slug": "deelnemer-bakker-abp" },
    "employeeId": "employee-002",
    "bsn": "234567892",
    "pensionFundId": "abp-overheid",
    "schemeId": "abp-middelloon-2026",
    "deelnemersnummer": "ABP-2002002",
    "ingangsdatum": "2018-08-15",
    "partTimePercentage": 80
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionParticipant", "slug": "deelnemer-devries-bpl" },
    "employeeId": "employee-003",
    "bsn": "345678904",
    "pensionFundId": "bpl-landbouw",
    "schemeId": "bpl-basis-2026",
    "deelnemersnummer": "BPL-3003003",
    "ingangsdatum": "2022-04-01",
    "partTimePercentage": 100
  }
]
```

### PensionDeclaration (3 objecten — één per fonds, verschillende statussen)

```json
[
  {
    "@self": { "register": "pensionadmin", "schema": "PensionDeclaration", "slug": "aangifte-pfzw-2026-04" },
    "pensionFundId": "pfzw-hoofdkantoor",
    "aangiftePeriode": "2026-04",
    "status": "bevestigd",
    "totalePremieWerkgever": 393.85,
    "totalePremieWerknemer": 134.95,
    "aantalDeelnemers": 1,
    "ingediendOp": "2026-05-08T09:15:00Z",
    "bevestigdOp": "2026-05-10T14:30:00Z",
    "correctienummer": 0
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionDeclaration", "slug": "aangifte-abp-2026-04" },
    "pensionFundId": "abp-overheid",
    "aangiftePeriode": "2026-04",
    "status": "ingediend",
    "totalePremieWerkgever": 265.15,
    "totalePremieWerknemer": 159.46,
    "aantalDeelnemers": 1,
    "ingediendOp": "2026-05-07T11:00:00Z",
    "correctienummer": 0
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionDeclaration", "slug": "aangifte-bpl-2026-04" },
    "pensionFundId": "bpl-landbouw",
    "aangiftePeriode": "2026-04",
    "status": "concept",
    "totalePremieWerkgever": 0,
    "totalePremieWerknemer": 0,
    "aantalDeelnemers": 0,
    "correctienummer": 0
  }
]
```

### PensionDeclarationLine (3 objecten)

Berekening Jansen (PFZW): salaris 4200, franchise 1445.83, grondslag 2754.17, WN 4.9%=134.95, WG 14.3%=393.85.
Berekening Bakker (ABP 80%): salaris 3800, franchise 1445.83×0.8=1156.67, grondslag 2643.33, WN 8.6%=227.33, WG 14.3%=378.00.
Berekening De Vries (BPL): salaris 3200, franchise 1445.83, grondslag 1754.17, WN 4.5%=78.94, WG 11.4%=199.98.

```json
[
  {
    "@self": { "register": "pensionadmin", "schema": "PensionDeclarationLine", "slug": "regel-jansen-2026-04" },
    "declarationId": "aangifte-pfzw-2026-04",
    "participantId": "deelnemer-jansen-pfzw",
    "bsn": "123456782",
    "pensioensalaris": 4200.00,
    "grondslag": 2754.17,
    "premieWerknemer": 134.95,
    "premieWerkgever": 393.85,
    "partTimeCorrectie": 1.0
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionDeclarationLine", "slug": "regel-bakker-2026-04" },
    "declarationId": "aangifte-abp-2026-04",
    "participantId": "deelnemer-bakker-abp",
    "bsn": "234567892",
    "pensioensalaris": 3800.00,
    "grondslag": 2643.33,
    "premieWerknemer": 227.33,
    "premieWerkgever": 378.00,
    "partTimeCorrectie": 0.8
  },
  {
    "@self": { "register": "pensionadmin", "schema": "PensionDeclarationLine", "slug": "regel-devries-2026-04" },
    "declarationId": "aangifte-bpl-2026-04",
    "participantId": "deelnemer-devries-bpl",
    "bsn": "345678904",
    "pensioensalaris": 3200.00,
    "grondslag": 1754.17,
    "premieWerknemer": 78.94,
    "premieWerkgever": 199.98,
    "partTimeCorrectie": 1.0
  }
]
```
