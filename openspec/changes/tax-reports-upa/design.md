# Design: UPA Loonaangifte to Belastingdienst

## Context

UPA loonaangifte (Uniform Pensioenaangifte) is de Nederlandse standaard voor maandelijkse loonheffingen-aangiften ingediend bij de Belastingdienst via de Loonheffingen (LH) portaal. Aangiften conformeren aan XSD versie 2026, gevalideerd door de Belastingdienst. De change bouwt voort op `payroll-core-basic` (bruto-netto berekening, loonheffing tabellen 2026) die berekende loon-totalen per werknemer per tijdvak levert.

Het MVP routeert indiening via Loonnext of Nmbrs relay om de vereiste SBR-kanaalkoppeling (mTLS + aanleveraarsnummer-provisioning) te omzeilen in de eerste versie.

## Goals / Non-Goals

**Goals:**
- UPA XML genereren per tijdvak conform XSD 2026
- Indienen via Loonnext/Nmbrs relay met statusbewaking
- Correctieberichten ondersteunen voor eerder ingediende tijdvakken
- WKR administratie: vrije ruimte, eindheffing, categorisering
- Jaaropgaven en jaarwerk loonbelasting per werknemer
- Volledige audit trail van alle indieningen en Belastingdienst-responses

**Non-Goals:**
- Directe SBR-kanaalkoppeling met Belastingdienst aanleveraarscertificaat (post-MVP)
- Automatisch CA-certificaatbeheer
- Real-time Belastingdienst OB-portaal (alleen relay in MVP)
- Fiscaal advies of complianceconsultatie
- Multi-land payroll (alleen NL)

## Architecture Decisions

### Decision 1: MVP via Relay Service

Directe indiening bij de Belastingdienst vereist een `aanleveraarsnummer` en SBR-channel mTLS-certificaat. Deze zijn werkgever-specifiek en kosten weken om te provisionen. Het MVP routeert via Loonnext API of Nmbrs relay die loondata ontvangen en de SBR-kanaalkoppeling namens de werkgever afhandelen. Dit ontsluit vroege adoptie. Relay-provider, aanleveraarsnummer en API-credentials worden opgeslagen in `IAppConfig` met de `sensitive` flag conform ADR-003.

### Decision 2: UPA XML Generatie in PHP

UPA XSD versie 2026 vereist strikt gevalideerde XML. Generatie via PHP's `DOMDocument` met `DOMDocument::schemaValidate()` vóór indiening. De XML wordt opgeslagen in het LoonaangifteRun-object (property `xmlPayload`, base64-encoded) voor audit-doeleinden. Schema-validatie voor indiening voorkomt afwijzing door Belastingdienst vanwege structuurfouten.

### Decision 3: Asynchrone Indiening via QueuedJob

Indiening via relay duurt meerdere seconden en kan tijdelijk mislukken. `UpaIndienenJob` is een `QueuedJob` die wordt aangemaakt nadat de gebruiker indiening bevestigt. Exponentiële retry-logica: 3 pogingen met wachttijd 60s, 300s, 900s. Status van de LoonaangifteRun wordt na elke poging bijgewerkt. Beheerder ontvangt Nextcloud-notificatie bij definitief succes of falen via `NotificationService`.

### Decision 4: OpenRegister voor alle domeindata

Alle aangiftedata opgeslagen als OpenRegister-objecten conform ADR-001. Geen custom Entity/Mapper. Drie schemas: `LoonaangifteRun` (aangifte-batch per tijdvak), `WkrAdministratie` (WKR-posten per jaar), `Jaaropgave` (jaaropgave per werknemer). Register: `hrmq-belastingdienst`.

### Decision 5: Spec kind: code

Deze change raakt PHP-services, Vue-componenten en OpenRegister schema-configuratie. Per ADR-032 ligt het zwaartepunt bij code (externe relay-integratie, XML-generatie, bedrijfslogica). Geclassificeerd als `kind: code`.

## Data Model

Schemas volgen schema.org-vocabulaire waar van toepassing (ADR-011). Domein-specifieke workflow-statussen gebruiken custom enums per de uitzondering in ADR-011.

### Schema: LoonaangifteRun

Één UPA loonaangifte-indiening voor een tijdvak (periode). Bevat zowel initiële als correctie-aangiften.

| Property | Type | Required | Description |
|---|---|---|---|
| `aangiftetijdvak` | string | ✓ | Tijdvak in JJJJ-PP formaat (bijv. "2026-03") |
| `aangifte_type` | string (enum) | ✓ | `initieel`, `correctie`, `nul` |
| `aanleveraarsnummer` | string | ✓ | Belastingdienst aanleveraarsnummer van de werkgever |
| `loonheffingennummer` | string | ✓ | Belastingdienst loonheffingennummer (OB-nummer formaat) |
| `status` | string (enum) | ✓ | `concept`, `gereed`, `ingediend`, `verwerkt`, `fout`, `ingetrokken` |
| `relayProvider` | string (enum) | ✓ | `loonnext`, `nmbrs` |
| `relayReferentie` | string | | Transactie-ID van relay-service |
| `responsecode` | string | | Responscode van Belastingdienst (via relay) |
| `responseomschrijving` | string | | Omschrijving van de Belastingdienst-response |
| `ingediendOp` | string (dateTime) | | Tijdstip van succesvolle indiening |
| `aantalDienstverbanden` | integer | | Aantal werknemers in deze aangifte |
| `totaalLoonheffing` | number | | Totale loonheffing dit tijdvak (EUR) |
| `totaalSvLoon` | number | | Totale SV-loon dit tijdvak (EUR) |
| `origineelTijdvak` | string | | Voor correcties: tijdvak van de originele aangifte |
| `origineelRun` | relation | | Voor correcties: relatie naar originele LoonaangifteRun |
| `xmlPayload` | string | | Base64-encoded UPA XML (opgeslagen voor audit) |

### Schema: WkrAdministratie

WKR-post (vergoeding of verstrekking) voor werkkostenregeling-administratie per kalenderjaar en werkgever.

| Property | Type | Required | Description |
|---|---|---|---|
| `jaar` | integer | ✓ | Kalenderjaar (bijv. 2026) |
| `werkgever` | string | ✓ | Loonheffingennummer van de werkgever |
| `omschrijving` | string | ✓ | Omschrijving van de vergoeding of verstrekking |
| `bedrag` | number | ✓ | Bedrag in EUR |
| `categorie` | string (enum) | ✓ | `gerichte-vrijstelling`, `vrije-ruimte`, `belast-loon` |
| `werknemer` | string | | Werknemernummer (optioneel, voor individuele posten) |
| `eindheffingPercentage` | number | | Van toepassing zijnd eindheffingpercentage (0.0 of 0.80) |
| `toelichting` | string | | Nadere onderbouwing van de categorisering |

### Schema: Jaaropgave

Jaarlijkse loonbelastingopgave per werknemer, gegenereerd uit gereconcilieerde maandaangiften.

| Property | Type | Required | Description |
|---|---|---|---|
| `jaar` | integer | ✓ | Kalenderjaar (bijv. 2025) |
| `werknemerNummer` | string | ✓ | Salarissysteemnummer van de werknemer |
| `werknemerNaam` | string | ✓ | Volledige naam van de werknemer |
| `bsnGemaskeerd` | string | ✓ | Laatste 4 cijfers van het BSN (privacybescherming) |
| `totaalLoon` | number | ✓ | Totaal belastbaar loon voor het jaar (EUR) |
| `totaalLoonheffing` | number | ✓ | Totaal ingehouden loonheffing (EUR) |
| `totaalZvwBijdrage` | number | ✓ | Totale ZVW-werkgeversbijdrage (EUR) |
| `heffingskorting` | boolean | ✓ | Of heffingskorting is toegepast |
| `gegenereerd` | boolean | ✓ | Of de jaaropgave is afgegenereerd |
| `gegenereedOp` | string (dateTime) | | Tijdstip van generatie |
| `verstuurd` | boolean | ✓ | Of de jaaropgave is verstuurd aan de werknemer |
| `verstuurdOp` | string (dateTime) | | Tijdstip van verzending aan werknemer |

## Reuse Analysis

Per ADR-001 worden de volgende OpenRegister-services direct gebruikt. Geen overlap gevonden met bestaande services in `openregister/lib/Service/` voor UPA XML-generatie, loonheffingen relay-integratie of WKR vrije ruimte-berekening — dit is domein-specifieke logica voor hrmq.

| Service / Component | Gebruik |
|---|---|
| `ObjectService` | CRUD voor LoonaangifteRun, WkrAdministratie, Jaaropgave |
| `AuditTrailService` | Automatische audit trail voor alle statuswijzigingen (auto via OpenRegister) |
| `FileService` | UPA XML-exports en jaaropgave PDF-bijlagen opslaan |
| `NotificationService` | Payroll-admin notificeren bij verwerkt/fout van Belastingdienst |
| `ImportService` | Bulk-import WKR-posten uit CSV/Excel |
| `ExportService` | Jaaropgaven exporteren als Excel/CSV voor accountant |
| `CnIndexPage` | Aangifte-overzicht met statusfiltering per tijdvak |
| `CnDetailPage` | Aangifte-detail met sidebar (audit trail, XML-bijlage) |
| `CnFormDialog` | Schema-driven formulier voor WKR-post aanmaken/bewerken |
| `CnDashboardPage` | WKR vrije ruimte dashboard met KPI-widgets |
| `CnStatsBlock` | KPI-kaarten: ingediend, verwerkt, fout, openstaand per maand |
| `createObjectStore` | Pinia stores voor LoonaangifteRun, WkrAdministratie, Jaaropgave |
| `useListView` | Composable voor aangifte-lijstweergave met filtering |
| `CnTimelineStages` | Workflow-progressie visualisatie (concept → gereed → ingediend → verwerkt) |

## Seed Data

Seed data opgenomen in `lib/Settings/hrmq_register.json` conform ADR-001. Nederlandse waarden, realistisch maar fictief.

### LoonaangifteRun objects

```json
[
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "LoonaangifteRun",
      "slug": "run-2026-03-gemeente-westland"
    },
    "aangiftetijdvak": "2026-03",
    "aangifte_type": "initieel",
    "aanleveraarsnummer": "853100942L01",
    "loonheffingennummer": "853100942L01",
    "status": "verwerkt",
    "relayProvider": "loonnext",
    "relayReferentie": "LN-2026-03-00142",
    "responsecode": "0000",
    "responseomschrijving": "Aangifte verwerkt",
    "ingediendOp": "2026-03-31T09:15:00+02:00",
    "aantalDienstverbanden": 47,
    "totaalLoonheffing": 128450.00,
    "totaalSvLoon": 412800.00
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "LoonaangifteRun",
      "slug": "run-2026-02-fictief-consultancy-cor"
    },
    "aangiftetijdvak": "2026-02",
    "aangifte_type": "correctie",
    "aanleveraarsnummer": "810294763L01",
    "loonheffingennummer": "810294763L01",
    "status": "verwerkt",
    "relayProvider": "nmbrs",
    "relayReferentie": "NMBRS-COR-20260215-009",
    "responsecode": "0000",
    "responseomschrijving": "Correctiebericht verwerkt",
    "ingediendOp": "2026-02-15T14:30:00+01:00",
    "aantalDienstverbanden": 12,
    "totaalLoonheffing": 31200.00,
    "totaalSvLoon": 98400.00,
    "origineelTijdvak": "2026-02"
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "LoonaangifteRun",
      "slug": "run-2026-03-stichting-welzijn-midden-holland"
    },
    "aangiftetijdvak": "2026-03",
    "aangifte_type": "initieel",
    "aanleveraarsnummer": "862047813L01",
    "loonheffingennummer": "862047813L01",
    "status": "concept",
    "relayProvider": "loonnext",
    "aantalDienstverbanden": 23,
    "totaalLoonheffing": 67890.00,
    "totaalSvLoon": 213400.00
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "LoonaangifteRun",
      "slug": "run-2026-01-reisadvies-nederland"
    },
    "aangiftetijdvak": "2026-01",
    "aangifte_type": "initieel",
    "aanleveraarsnummer": "803721049L01",
    "loonheffingennummer": "803721049L01",
    "status": "fout",
    "relayProvider": "loonnext",
    "relayReferentie": "LN-2026-01-00078",
    "responsecode": "0141",
    "responseomschrijving": "Onbekend aanleveraarsnummer bij Belastingdienst",
    "ingediendOp": "2026-01-31T11:45:00+01:00",
    "aantalDienstverbanden": 8,
    "totaalLoonheffing": 18250.00,
    "totaalSvLoon": 57600.00
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "LoonaangifteRun",
      "slug": "run-2026-03-zbo-keuringsinstituut"
    },
    "aangiftetijdvak": "2026-03",
    "aangifte_type": "initieel",
    "aanleveraarsnummer": "841039207L01",
    "loonheffingennummer": "841039207L01",
    "status": "ingediend",
    "relayProvider": "nmbrs",
    "relayReferentie": "NMBRS-20260331-114",
    "ingediendOp": "2026-03-31T16:00:00+02:00",
    "aantalDienstverbanden": 34,
    "totaalLoonheffing": 89100.00,
    "totaalSvLoon": 287300.00
  }
]
```

### WkrAdministratie objects

```json
[
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "WkrAdministratie",
      "slug": "wkr-2026-853100942-thuiswerk"
    },
    "jaar": 2026,
    "werkgever": "853100942L01",
    "omschrijving": "Thuiswerkvergoeding €2,35 per thuiswerkdag",
    "bedrag": 29400.00,
    "categorie": "gerichte-vrijstelling",
    "toelichting": "€2,35 per thuiswerkdag × 200 medewerkers × gemiddeld 62,5 thuiswerkdagen"
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "WkrAdministratie",
      "slug": "wkr-2026-810294763-kerstpakket"
    },
    "jaar": 2026,
    "werkgever": "810294763L01",
    "omschrijving": "Kerstpakket 2025",
    "bedrag": 3600.00,
    "categorie": "vrije-ruimte",
    "toelichting": "€300 per medewerker × 12 personen; valt binnen vrije ruimte 2026"
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "WkrAdministratie",
      "slug": "wkr-2026-862047813-fiets-van-de-zaak"
    },
    "jaar": 2026,
    "werkgever": "862047813L01",
    "omschrijving": "Fiets van de zaak regeling",
    "bedrag": 18750.00,
    "categorie": "gerichte-vrijstelling",
    "toelichting": "5 medewerkers × fietswaarde €3.750 inclusief accessoires; gerichte vrijstelling fiets"
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "WkrAdministratie",
      "slug": "wkr-2026-841039207-personeelsfeest"
    },
    "jaar": 2026,
    "werkgever": "841039207L01",
    "omschrijving": "Personeelsfeest inclusief partner",
    "bedrag": 8800.00,
    "categorie": "vrije-ruimte",
    "eindheffingPercentage": 0.80,
    "toelichting": "Personeelsuitje inclusief partners; €220 per deelnemer × 40 deelnemers; overschrijdt vrije ruimte, eindheffing 80%"
  }
]
```

### Jaaropgave objects

```json
[
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "Jaaropgave",
      "slug": "jaaropgave-2025-wl-10042"
    },
    "jaar": 2025,
    "werknemerNummer": "WL-10042",
    "werknemerNaam": "H.M. de Vries-Bakker",
    "bsnGemaskeerd": "****342",
    "totaalLoon": 52800.00,
    "totaalLoonheffing": 14784.00,
    "totaalZvwBijdrage": 3696.00,
    "heffingskorting": true,
    "gegenereerd": true,
    "gegenereedOp": "2026-01-31T08:00:00+01:00",
    "verstuurd": true,
    "verstuurdOp": "2026-02-01T09:00:00+01:00"
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "Jaaropgave",
      "slug": "jaaropgave-2025-fc-00012"
    },
    "jaar": 2025,
    "werknemerNummer": "FC-00012",
    "werknemerNaam": "R.J. van den Berg",
    "bsnGemaskeerd": "****819",
    "totaalLoon": 78400.00,
    "totaalLoonheffing": 26656.00,
    "totaalZvwBijdrage": 5488.00,
    "heffingskorting": false,
    "gegenereerd": true,
    "gegenereedOp": "2026-01-31T08:00:00+01:00",
    "verstuurd": true,
    "verstuurdOp": "2026-02-01T09:00:00+01:00"
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "Jaaropgave",
      "slug": "jaaropgave-2025-sw-00301"
    },
    "jaar": 2025,
    "werknemerNummer": "SW-00301",
    "werknemerNaam": "F.A. Janssen",
    "bsnGemaskeerd": "****527",
    "totaalLoon": 36200.00,
    "totaalLoonheffing": 7604.00,
    "totaalZvwBijdrage": 2534.00,
    "heffingskorting": true,
    "gegenereerd": true,
    "gegenereedOp": "2026-01-31T08:00:00+01:00",
    "verstuurd": false
  },
  {
    "@self": {
      "register": "hrmq-belastingdienst",
      "schema": "Jaaropgave",
      "slug": "jaaropgave-2025-zk-00087"
    },
    "jaar": 2025,
    "werknemerNummer": "ZK-00087",
    "werknemerNaam": "M.T. Hofstede",
    "bsnGemaskeerd": "****093",
    "totaalLoon": 61500.00,
    "totaalLoonheffing": 19065.00,
    "totaalZvwBijdrage": 4305.00,
    "heffingskorting": true,
    "gegenereerd": false,
    "verstuurd": false
  }
]
```
