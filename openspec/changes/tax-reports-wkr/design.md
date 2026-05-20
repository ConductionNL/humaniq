# Design: Werkkostenregeling (WKR) calc + eindheffing

## Architecture Overview

De WKR-module voegt drie nieuwe OpenRegister-schema's toe aan de hrmq-app: `WkrVergoedingsoort`, `WkrVergoeding` en `WkrBudget`. Berekeningen (vrije ruimte, eindheffing) worden declaratief gedaan via `x-openregister-calculations` en `x-openregister-aggregations` per ADR-031. De frontend gebruikt bestaande `CnIndexPage` / `CnDetailPage` componenten van `@conduction/nextcloud-vue`.

```
┌─────────────────────────────────────────────────────────┐
│                    hrmq (Nextcloud app)                  │
│                                                         │
│  Frontend (Vue 2 + Pinia)                               │
│  ┌─────────────────┐  ┌───────────────┐  ┌──────────┐  │
│  │ WkrBudgetIndex  │  │ WkrVergoeding │  │ WkrVerg- │  │
│  │ (CnIndexPage)   │  │ Index         │  │ Soort    │  │
│  └────────┬────────┘  └──────┬────────┘  └────┬─────┘  │
│           │                  │                │         │
│  Store Layer (createObjectStore)               │         │
│  ┌────────▼──────────────────▼────────────────▼──────┐  │
│  │  wkrBudgetStore  wkrVergoedingStore  wkrSoortStore │  │
│  └──────────────────────────┬───────────────────────-─┘  │
│                             │ OpenRegister ObjectService  │
└─────────────────────────────┼───────────────────────────-┘
                              │ REST API
┌─────────────────────────────▼───────────────────────────┐
│                   OpenRegister                           │
│  ┌───────────────┐ ┌──────────────┐ ┌────────────────┐  │
│  │  WkrBudget    │ │ WkrVergoeding│ │ WkrVergoeding- │  │
│  │  (schema)     │ │ (schema)     │ │ soort (schema) │  │
│  │  + calc       │ │              │ │                │  │
│  │  + aggreg.    │ │              │ │                │  │
│  └───────────────┘ └──────────────┘ └────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

## Declarative-vs-Imperative Decision (ADR-031)

| Behaviour | Decision | Rationale |
|---|---|---|
| Vrije ruimte tier-1 berekening | **Declaratief** `x-openregister-calculations` | Puur wiskundige afleiding van `loonsomBedrag`; geen externe data nodig |
| Vrije ruimte tier-2 berekening | **Declaratief** `x-openregister-calculations` | Idem |
| Vrije ruimte totaal | **Declaratief** `x-openregister-calculations` | Som van twee berekende velden |
| Toegewezen bedrag (aggregaat) | **Declaratief** `x-openregister-aggregations` | Som van alle `WkrVergoeding.bedrag` voor dit boekjaar |
| Overschrijding | **Declaratief** `x-openregister-calculations` | `max(0, toegewezenBedrag − vrijeRuimteTotaal)` |
| Eindheffing | **Declaratief** `x-openregister-calculations` | `80% × overschrijding` |
| Budgetwaarschuwing bij aanmaken vergoeding | **Imperatief** (Vue computed) | Client-side UX-logica; geen nieuwe PHP service nodig |

Geen PHP service-klasse nodig voor berekeningen — alle WKR-math valt binnen de OpenRegister declaratieve engine.

## Data Model

### Schema 1: WkrVergoedingsoort

Categoriseert soorten WKR-vergoedingen. Bepaalt of een vergoeding een **gerichte vrijstelling** is (volledig vrijgesteld tot een maximum) of ten laste van de **vrije ruimte** komt.

```json
{
  "title": "WkrVergoedingsoort",
  "type": "object",
  "required": ["naam", "code", "vrijstellingstype"],
  "properties": {
    "naam": {
      "type": "string",
      "description": "Naam van de vergoedingsoort (schema:name)",
      "example": "Thuiswerkvergoeding"
    },
    "code": {
      "type": "string",
      "description": "Unieke afkortingscode (schema:identifier)",
      "example": "THUIS"
    },
    "vrijstellingstype": {
      "type": "string",
      "enum": ["gerichteVrijstelling", "vrijeRuimte"],
      "description": "Of de vergoeding volledig vrijgesteld is of ten laste van de vrije ruimte komt"
    },
    "maximumBedrag": {
      "type": "number",
      "nullable": true,
      "description": "Maximaal vrijgesteld bedrag per jaar per medewerker (schema:maxPrice). Null = geen maximum.",
      "example": 2160
    },
    "omschrijving": {
      "type": "string",
      "description": "Toelichting op de vergoedingsoort (schema:description)"
    },
    "actief": {
      "type": "boolean",
      "default": true,
      "description": "Of de vergoedingsoort nog in gebruik is"
    }
  }
}
```

### Schema 2: WkrVergoeding

Individuele WKR-vergoeding toegekend aan een medewerker. Koppelt medewerker, vergoedingsoort, bedrag en boekjaar.

```json
{
  "title": "WkrVergoeding",
  "type": "object",
  "required": ["boekjaar", "bedrag", "toewijzingsdatum", "medewerker", "vergoedingsoort"],
  "properties": {
    "boekjaar": {
      "type": "integer",
      "description": "Fiscaal jaar waarop de vergoeding betrekking heeft (schema:temporalCoverage)",
      "example": 2026
    },
    "bedrag": {
      "type": "number",
      "description": "Bedrag van de vergoeding in euro's (schema:price)",
      "example": 528.00
    },
    "toewijzingsdatum": {
      "type": "string",
      "format": "date",
      "description": "Datum waarop de vergoeding is toegewezen (schema:startDate)",
      "example": "2026-03-01"
    },
    "omschrijving": {
      "type": "string",
      "description": "Optionele toelichting (schema:description)"
    },
    "medewerker": {
      "$ref": "#/components/schemas/Medewerker",
      "description": "OpenRegister-relatie naar de medewerker (schema:employee)"
    },
    "vergoedingsoort": {
      "$ref": "#/components/schemas/WkrVergoedingsoort",
      "description": "OpenRegister-relatie naar de vergoedingsoort"
    }
  }
}
```

**Relations (x-openregister-relations):**
- `medewerker` → register: `hrmq`, schema: `Medewerker` (provided by `payroll-core-basic` change)
- `vergoedingsoort` → register: `hrmq`, schema: `WkrVergoedingsoort`

### Schema 3: WkrBudget

Jaarlijks WKR-budget per boekjaar. Bevat de loonsom als primaire invoer; alle overige velden zijn berekend of geaggregeerd.

```json
{
  "title": "WkrBudget",
  "type": "object",
  "required": ["boekjaar", "loonsomBedrag"],
  "properties": {
    "boekjaar": {
      "type": "integer",
      "description": "Fiscaal boekjaar (schema:temporalCoverage)",
      "example": 2026
    },
    "loonsomBedrag": {
      "type": "number",
      "description": "Totale loonsom van de organisatie voor dit boekjaar in euro's (schema:amount)",
      "example": 920000.00
    },
    "omschrijving": {
      "type": "string",
      "description": "Optionele opmerking bij het budget (schema:description)"
    }
  },
  "x-openregister-calculations": {
    "vrijeRuimteTier1": {
      "expression": "min(@self.loonsomBedrag, 400000) * 0.03",
      "description": "Vrije ruimte over eerste schijf (3% × min(loonsom, €400.000))"
    },
    "vrijeRuimteTier2": {
      "expression": "max(0, @self.loonsomBedrag - 400000) * 0.0118",
      "description": "Vrije ruimte over tweede schijf (1,18% × max(0, loonsom − €400.000))"
    },
    "vrijeRuimteTotaal": {
      "expression": "@self.vrijeRuimteTier1 + @self.vrijeRuimteTier2",
      "description": "Totale vrije ruimte (tier1 + tier2)"
    },
    "overschrijding": {
      "expression": "max(0, @self.toegewezenBedrag - @self.vrijeRuimteTotaal)",
      "description": "Bedrag waarmee de vergoedingen de vrije ruimte overschrijden"
    },
    "eindheffing": {
      "expression": "@self.overschrijding * 0.80",
      "description": "Verschuldigde eindheffing (80% over overschrijding)"
    },
    "resterendBudget": {
      "expression": "max(0, @self.vrijeRuimteTotaal - @self.toegewezenBedrag)",
      "description": "Nog beschikbaar vrij budget"
    }
  },
  "x-openregister-aggregations": {
    "toegewezenBedrag": {
      "source": {
        "register": "hrmq",
        "schema": "WkrVergoeding",
        "filter": { "boekjaar": "@self.boekjaar" }
      },
      "operation": "sum",
      "field": "bedrag",
      "description": "Totaal toegewezen WKR-bedrag voor dit boekjaar"
    },
    "aantalVergoedingen": {
      "source": {
        "register": "hrmq",
        "schema": "WkrVergoeding",
        "filter": { "boekjaar": "@self.boekjaar" }
      },
      "operation": "count",
      "description": "Aantal geregistreerde vergoedingen voor dit boekjaar"
    }
  }
}
```

## Register Template (`lib/Settings/hrmq_register.json`)

De drie schema's worden toegevoegd aan de bestaande `hrmq_register.json`. Relevante sectie:

```json
{
  "x-openregister": { "type": "application" },
  "components": {
    "schemas": {
      "WkrVergoedingsoort": { ... },
      "WkrVergoeding": { ... },
      "WkrBudget": { ... }
    }
  }
}
```

## Seed Data

Per ADR-011 (seed data requirements): 3-5 realistische objecten per schema met Nederlandse veldwaarden.

### WkrVergoedingsoort — seed objects

```json
[
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoedingsoort", "slug": "wkr-soort-thuiswerk" },
    "naam": "Thuiswerkvergoeding",
    "code": "THUIS",
    "vrijstellingstype": "gerichteVrijstelling",
    "maximumBedrag": 2160,
    "omschrijving": "Vaste thuiswerkvergoeding van max. €2,40 per thuiswerkdag (Belastingdienst 2026). Gerichte vrijstelling.",
    "actief": true
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoedingsoort", "slug": "wkr-soort-fiets" },
    "naam": "Fietsplan",
    "code": "FIETS",
    "vrijstellingstype": "gerichteVrijstelling",
    "maximumBedrag": 749,
    "omschrijving": "Vergoeding voor een zakelijke fiets (max. €749 per drie jaar). Gerichte vrijstelling.",
    "actief": true
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoedingsoort", "slug": "wkr-soort-kerstpakket" },
    "naam": "Kerstpakket",
    "code": "KERST",
    "vrijstellingstype": "vrijeRuimte",
    "maximumBedrag": null,
    "omschrijving": "Jaarlijks kerstpakket. Valt ten laste van de vrije ruimte.",
    "actief": true
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoedingsoort", "slug": "wkr-soort-maaltijd" },
    "naam": "Maaltijdvergoeding",
    "code": "MAAL",
    "vrijstellingstype": "vrijeRuimte",
    "maximumBedrag": null,
    "omschrijving": "Maaltijdvergoeding voor overwerk of bijzondere gelegenheden. Vrije ruimte.",
    "actief": true
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoedingsoort", "slug": "wkr-soort-jubileum" },
    "naam": "Jubileumuitkering",
    "code": "JUBIL",
    "vrijstellingstype": "vrijeRuimte",
    "maximumBedrag": null,
    "omschrijving": "Uitkering bij 12,5-, 25- of 40-jarig dienstverband. Vrije ruimte.",
    "actief": true
  }
]
```

### WkrVergoeding — seed objects

```json
[
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoeding", "slug": "wkr-verg-2026-001" },
    "boekjaar": 2026,
    "bedrag": 528.00,
    "toewijzingsdatum": "2026-01-15",
    "omschrijving": "Thuiswerkvergoeding Q1 2026 — 220 werkdagen × €2,40",
    "medewerker": { "register": "hrmq", "schema": "Medewerker", "objectId": "med-fictief-001" },
    "vergoedingsoort": { "register": "hrmq", "schema": "WkrVergoedingsoort", "objectSlug": "wkr-soort-thuiswerk" }
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoeding", "slug": "wkr-verg-2026-002" },
    "boekjaar": 2026,
    "bedrag": 749.00,
    "toewijzingsdatum": "2026-02-01",
    "omschrijving": "Fietsplan 2026 — elektrische fiets",
    "medewerker": { "register": "hrmq", "schema": "Medewerker", "objectId": "med-fictief-002" },
    "vergoedingsoort": { "register": "hrmq", "schema": "WkrVergoedingsoort", "objectSlug": "wkr-soort-fiets" }
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoeding", "slug": "wkr-verg-2026-003" },
    "boekjaar": 2026,
    "bedrag": 75.00,
    "toewijzingsdatum": "2026-12-15",
    "omschrijving": "Kerstpakket 2026 — alle medewerkers",
    "medewerker": { "register": "hrmq", "schema": "Medewerker", "objectId": "med-fictief-003" },
    "vergoedingsoort": { "register": "hrmq", "schema": "WkrVergoedingsoort", "objectSlug": "wkr-soort-kerstpakket" }
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoeding", "slug": "wkr-verg-2025-001" },
    "boekjaar": 2025,
    "bedrag": 500.00,
    "toewijzingsdatum": "2025-06-01",
    "omschrijving": "Jubileumuitkering 25 jaar dienst",
    "medewerker": { "register": "hrmq", "schema": "Medewerker", "objectId": "med-fictief-001" },
    "vergoedingsoort": { "register": "hrmq", "schema": "WkrVergoedingsoort", "objectSlug": "wkr-soort-jubileum" }
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrVergoeding", "slug": "wkr-verg-2025-002" },
    "boekjaar": 2025,
    "bedrag": 150.00,
    "toewijzingsdatum": "2025-09-10",
    "omschrijving": "Maaltijdvergoeding projectafsluiting Rotterdam",
    "medewerker": { "register": "hrmq", "schema": "Medewerker", "objectId": "med-fictief-004" },
    "vergoedingsoort": { "register": "hrmq", "schema": "WkrVergoedingsoort", "objectSlug": "wkr-soort-maaltijd" }
  }
]
```

### WkrBudget — seed objects

Vrije ruimte berekening voor controle:
- Boekjaar 2026: loonsom €920.000 → tier1 = 3% × €400.000 = €12.000 + tier2 = 1,18% × €520.000 = €6.136 → totaal **€18.136**
- Boekjaar 2025: loonsom €850.000 → tier1 = €12.000 + tier2 = 1,18% × €450.000 = €5.310 → totaal **€17.310**

```json
[
  {
    "@self": { "register": "hrmq", "schema": "WkrBudget", "slug": "wkr-budget-2026" },
    "boekjaar": 2026,
    "loonsomBedrag": 920000.00,
    "omschrijving": "WKR budget boekjaar 2026 — Demo BV (fictief)"
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrBudget", "slug": "wkr-budget-2025" },
    "boekjaar": 2025,
    "loonsomBedrag": 850000.00,
    "omschrijving": "WKR budget boekjaar 2025 — Demo BV (fictief)"
  },
  {
    "@self": { "register": "hrmq", "schema": "WkrBudget", "slug": "wkr-budget-2024" },
    "boekjaar": 2024,
    "loonsomBedrag": 780000.00,
    "omschrijving": "WKR budget boekjaar 2024 — Demo BV (fictief)"
  }
]
```

## Reuse Analysis (ADR-012)

| Capability | OpenRegister / @conduction/nextcloud-vue component | Toelichting |
|---|---|---|
| CRUD vergoedingsoorten | `ObjectService.saveObject()` + `CnIndexPage` + `CnFormDialog` | Geen custom controller nodig |
| CRUD vergoedingen | `ObjectService.saveObject()` + `CnIndexPage` + `CnFormDialog` | Relaties via OpenRegister-relatiemechanisme |
| CRUD budgetten | `ObjectService.saveObject()` + `CnIndexPage` + `CnDetailPage` | Berekende velden via `x-openregister-calculations` |
| Vrije ruimte berekening | `x-openregister-calculations` | Declaratief in schema register |
| Eindheffing aggregatie | `x-openregister-aggregations` | Declaratief in schema register |
| Dashboard KPI-kaarten | `CnStatsBlock` + `CnKpiGrid` | Generiek dashboard-component |
| Pagination + search | `useListView` composable | Platform-component |
| Audit trail | `CnObjectSidebar` → `CnAuditTrailTab` | Automatisch via OpenRegister |
| Import/export | `CnMassImportDialog` + `CnMassExportDialog` | Gratis via platform |
| Seed data laden | `ConfigurationService::importFromApp()` | Bestaand import-mechanisme |

**Geen overlappende services gevonden** in `openregister/lib/Service/` voor WKR-specifieke berekeningen. De `ObjectService`, `SchemaService` en `ConfigurationService` worden gebruikt als foundation, niet gedupliceerd.

## Frontend Routes

Per ADR-004 en ADR-016 (history mode, flat routes):

```
/apps/hrmq/wkr-budget              → WkrBudgetIndex.vue
/apps/hrmq/wkr-budget/:id          → WkrBudgetDetail.vue
/apps/hrmq/wkr-vergoedingen        → WkrVergoedingIndex.vue
/apps/hrmq/wkr-vergoedingen/:id    → WkrVergoedingDetail.vue
/apps/hrmq/wkr-vergoedingsoorten   → WkrVergoedingsoortIndex.vue
/apps/hrmq/wkr-vergoedingsoorten/:id → WkrVergoedingsoortDetail.vue
```

Navigatiepunten worden toegevoegd aan `MainMenu.vue` onder een "Werkkostenregeling" sectie.

## API Endpoints

Per ADR-002 (REST, `/index.php/apps/{app}/api/{resource}`):

| Method | Path | Doel |
|---|---|---|
| GET | `/api/wkr-budget` | Lijst WkrBudgetten (paginering) |
| POST | `/api/wkr-budget` | Nieuw budget aanmaken |
| GET | `/api/wkr-budget/{id}` | Budget detail (inclusief berekende velden) |
| PUT | `/api/wkr-budget/{id}` | Budget bijwerken |
| DELETE | `/api/wkr-budget/{id}` | Budget verwijderen |
| GET | `/api/wkr-vergoedingen` | Lijst vergoedingen (filter op `boekjaar`) |
| POST | `/api/wkr-vergoedingen` | Vergoeding registreren |
| GET | `/api/wkr-vergoedingen/{id}` | Vergoeding detail |
| PUT | `/api/wkr-vergoedingen/{id}` | Vergoeding bijwerken |
| DELETE | `/api/wkr-vergoedingen/{id}` | Vergoeding verwijderen |
| GET | `/api/wkr-vergoedingsoorten` | Lijst vergoedingsoorten |
| POST | `/api/wkr-vergoedingsoorten` | Vergoedingsoort aanmaken |
| GET | `/api/wkr-vergoedingsoorten/{id}` | Vergoedingsoort detail |
| PUT | `/api/wkr-vergoedingsoorten/{id}` | Vergoedingsoort bijwerken |
| DELETE | `/api/wkr-vergoedingsoorten/{id}` | Vergoedingsoort verwijderen |

Alle endpoints: `#[NoAdminRequired]` + per-object autorisatiecheck (ADR-005).

## Security Considerations (ADR-005)

- Alle mutatie-endpoints hebben `#[NoAdminRequired]` + per-object autorisatie via `AuthorizationService`.
- BSN en salarisgegevens komen **niet** voor in WKR-schema's (valt onder `payroll-core-basic` / `compliance-reporting-avg`).
- Geen PII in logregels of foutresponses.
- `boekjaar` als integer — geen SQL-injection risico bij gebruik via ObjectService.

## Mixed-spec Rationale (ADR-032)

Dit is een net-nieuwe feature (geen migratie), dus de chain-splitsing uit ADR-032 is niet vereist. De schema-declaraties (config) en de frontend-code (code) worden in één PR opgeleverd omdat:

1. De schema's zijn precondition voor elk renderbaar frontend-component — ze kunnen niet los worden geland zonder een onbruikbare UI achter te laten.
2. De code-wijzigingen buiten de schema's zijn beperkt: routes in `manifest.json`, 6 Vue-componenten (CnIndexPage-wrappers), store-registraties in `store.js` en navigatie-items in `MainMenu.vue`.
3. Er is geen bestaande imperatieve implementatie om te migreren.

Thin-glue-uitzondering is **niet** van toepassing (>20 LOC). Dit spec valt in de categorie "net-new mixed feature" die redelijkerwijs als één PR wordt opgeleverd per organisatorisch oordeel van het team.
