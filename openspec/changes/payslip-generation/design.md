# Design: Payslip / Loonstrook Generation

## Architecture Overview

```
SalarisRun (payroll-core-basic)
        │ completion event
        ▼
LoonstrookGeneratieJob (QueuedJob)
        │ creates Loonstrook objects per employee
        ▼
Loonstrook (OpenRegister schema)
        │ status: concept → gegenereerd
        ▼
PdfLoonstrookService
        │ renders Twig template → Dompdf → PDF bytes
        │ stores PDF via FileService
        ▼
Loonstrook.pdfUrl (FileService reference)
        │ status: gepubliceerd
        ▼
Werknemer (employee) receives NC notification
        │ opens portaal
        ▼
CnIndexPage (loonstroken list, scoped to userId)
CnDetailPage (loonstrook detail + download button)
```

The jaaropgaaf follows the same pattern, triggered manually by the salarisadministrateur via a batch action on the LoonstrookController.

## Schemas

### Loonstrook

OpenRegister schema — one object per employee per salarisperiode.

```json
{
  "title": "Loonstrook",
  "type": "object",
  "required": ["werknemer", "periode", "brutoLoon", "nettoLoon", "status"],
  "properties": {
    "werknemer": {
      "type": "string",
      "description": "OpenRegister relation to employee object (employee-master register)",
      "$ref": "relation"
    },
    "salarisRun": {
      "type": "string",
      "description": "OpenRegister relation to SalarisRun object (payroll-core-basic register)",
      "$ref": "relation"
    },
    "periode": {
      "type": "string",
      "description": "Payment period in YYYY-MM format (e.g. '2026-01')",
      "pattern": "^[0-9]{4}-(0[1-9]|1[0-2])$"
    },
    "periodeOmschrijving": {
      "type": "string",
      "description": "Human-readable period label, e.g. 'Januari 2026'"
    },
    "brutoLoon": {
      "type": "number",
      "description": "Gross salary in EUR for this period"
    },
    "nettoLoon": {
      "type": "number",
      "description": "Net salary in EUR for this period (bruto minus all deductions)"
    },
    "loonheffing": {
      "type": "number",
      "description": "Wage tax withheld (loonheffing) in EUR"
    },
    "zvwBijdrage": {
      "type": "number",
      "description": "Employee healthcare insurance contribution (ZVW) in EUR"
    },
    "pensioenpremie": {
      "type": "number",
      "description": "Employee pension premium in EUR"
    },
    "vakantiegeld": {
      "type": "number",
      "description": "Holiday allowance paid out this period in EUR"
    },
    "reserveringVakantiegeld": {
      "type": "number",
      "description": "Holiday allowance accrued (reserved) this period in EUR"
    },
    "reiskosten": {
      "type": "number",
      "description": "Travel expense reimbursement in EUR (belastingvrij)"
    },
    "toeslagen": {
      "type": "array",
      "description": "Additional allowances (e.g. onregelmatigheidstoeslag, ploegentoeslag)",
      "items": {
        "type": "object",
        "properties": {
          "omschrijving": { "type": "string" },
          "bedrag": { "type": "number" }
        }
      }
    },
    "inhoudingen": {
      "type": "array",
      "description": "Additional deductions (e.g. loonbeslag, spaarloon, ziektekosten)",
      "items": {
        "type": "object",
        "properties": {
          "omschrijving": { "type": "string" },
          "bedrag": { "type": "number" }
        }
      }
    },
    "cumulatieven": {
      "type": "object",
      "description": "Year-to-date cumulative totals (cumulatieven)",
      "properties": {
        "cumBrutoLoon": { "type": "number" },
        "cumNettoLoon": { "type": "number" },
        "cumLoonheffing": { "type": "number" },
        "cumZvwBijdrage": { "type": "number" },
        "cumPensioenpremie": { "type": "number" },
        "cumVakantiegeld": { "type": "number" }
      }
    },
    "werkgeverNaam": {
      "type": "string",
      "description": "Employer name as printed on the payslip"
    },
    "werkgeverLoonheffingsnummer": {
      "type": "string",
      "description": "Employer tax number (loonheffingsnummer) from Belastingdienst"
    },
    "status": {
      "type": "string",
      "enum": ["concept", "gegenereerd", "gepubliceerd", "gedownload"],
      "description": "Payslip lifecycle status"
    },
    "generatieDatum": {
      "type": "string",
      "format": "date-time",
      "description": "Timestamp when the PDF was generated"
    },
    "publicatieDatum": {
      "type": "string",
      "format": "date-time",
      "description": "Timestamp when the payslip was published to the employee portal"
    }
  }
}
```

### Jaaropgaaf

OpenRegister schema — one object per employee per kalenderjaar.

```json
{
  "title": "Jaaropgaaf",
  "type": "object",
  "required": ["werknemer", "jaar", "totaalBrutoLoon", "totaalLoonheffing", "status"],
  "properties": {
    "werknemer": {
      "type": "string",
      "description": "OpenRegister relation to employee object",
      "$ref": "relation"
    },
    "jaar": {
      "type": "integer",
      "description": "Fiscal year (e.g. 2025)",
      "minimum": 2020
    },
    "werkgeverNaam": {
      "type": "string",
      "description": "Employer name"
    },
    "werkgeverLoonheffingsnummer": {
      "type": "string",
      "description": "Employer loonheffingsnummer"
    },
    "totaalBrutoLoon": {
      "type": "number",
      "description": "Total gross income for the year (kolom 1 loonbelasting)"
    },
    "totaalLoonheffing": {
      "type": "number",
      "description": "Total wage tax withheld for the year (kolom 2)"
    },
    "totaalZvwWerknemersaandeel": {
      "type": "number",
      "description": "Total employee ZVW contribution for the year"
    },
    "totaalPensioenpremie": {
      "type": "number",
      "description": "Total pension premium for the year"
    },
    "totaalVakantiegeld": {
      "type": "number",
      "description": "Total holiday allowance paid for the year"
    },
    "totaalReiskosten": {
      "type": "number",
      "description": "Total travel reimbursement for the year"
    },
    "aantalLoonperioden": {
      "type": "integer",
      "description": "Number of pay periods covered"
    },
    "status": {
      "type": "string",
      "enum": ["concept", "gegenereerd", "gepubliceerd"],
      "description": "Annual statement lifecycle status"
    },
    "generatieDatum": {
      "type": "string",
      "format": "date-time"
    }
  }
}
```

## Seed Data

Seed data loaded via `lib/Settings/hrmq_register.json` using the `@self` envelope pattern (ADR-001). Three to five objects per schema; realistic Dutch values; general organisations (municipality, consultancy, travel agency, non-profit).

### Loonstrook seed objects

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "loonstrook",
      "slug": "loonstrook-anna-de-boer-2026-01"
    },
    "periode": "2026-01",
    "periodeOmschrijving": "Januari 2026",
    "werkgeverNaam": "Gemeente Delft",
    "werkgeverLoonheffingsnummer": "0503.01.234.L01",
    "brutoLoon": 3450.00,
    "nettoLoon": 2461.83,
    "loonheffing": 896.40,
    "zvwBijdrage": 70.04,
    "pensioenpremie": 172.50,
    "vakantiegeld": 0.00,
    "reserveringVakantiegeld": 276.00,
    "reiskosten": 150.77,
    "toeslagen": [],
    "inhoudingen": [],
    "cumulatieven": {
      "cumBrutoLoon": 3450.00,
      "cumNettoLoon": 2461.83,
      "cumLoonheffing": 896.40,
      "cumZvwBijdrage": 70.04,
      "cumPensioenpremie": 172.50,
      "cumVakantiegeld": 0.00
    },
    "status": "gepubliceerd",
    "generatieDatum": "2026-01-28T09:00:00Z",
    "publicatieDatum": "2026-01-28T10:00:00Z"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "loonstrook",
      "slug": "loonstrook-mehmet-yilmaz-2026-01"
    },
    "periode": "2026-01",
    "periodeOmschrijving": "Januari 2026",
    "werkgeverNaam": "Conduction B.V.",
    "werkgeverLoonheffingsnummer": "8612.34.567.L01",
    "brutoLoon": 5100.00,
    "nettoLoon": 3418.22,
    "loonheffing": 1571.40,
    "zvwBijdrage": 103.53,
    "pensioenpremie": 255.00,
    "vakantiegeld": 0.00,
    "reserveringVakantiegeld": 408.00,
    "reiskosten": 247.15,
    "toeslagen": [
      { "omschrijving": "Onregelmatigheidstoeslag", "bedrag": 320.00 }
    ],
    "inhoudingen": [],
    "cumulatieven": {
      "cumBrutoLoon": 5100.00,
      "cumNettoLoon": 3418.22,
      "cumLoonheffing": 1571.40,
      "cumZvwBijdrage": 103.53,
      "cumPensioenpremie": 255.00,
      "cumVakantiegeld": 0.00
    },
    "status": "gepubliceerd",
    "generatieDatum": "2026-01-28T09:05:00Z",
    "publicatieDatum": "2026-01-28T10:00:00Z"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "loonstrook",
      "slug": "loonstrook-sofia-hendriks-2026-01"
    },
    "periode": "2026-01",
    "periodeOmschrijving": "Januari 2026",
    "werkgeverNaam": "Reisadviesbureau Horizons B.V.",
    "werkgeverLoonheffingsnummer": "7213.56.789.L01",
    "brutoLoon": 2980.00,
    "nettoLoon": 2179.44,
    "loonheffing": 710.80,
    "zvwBijdrage": 60.52,
    "pensioenpremie": 149.00,
    "vakantiegeld": 0.00,
    "reserveringVakantiegeld": 238.40,
    "reiskosten": 119.76,
    "toeslagen": [],
    "inhoudingen": [
      { "omschrijving": "Ziektekostenpremie eigen bijdrage", "bedrag": 28.00 }
    ],
    "cumulatieven": {
      "cumBrutoLoon": 2980.00,
      "cumNettoLoon": 2179.44,
      "cumLoonheffing": 710.80,
      "cumZvwBijdrage": 60.52,
      "cumPensioenpremie": 149.00,
      "cumVakantiegeld": 0.00
    },
    "status": "gepubliceerd",
    "generatieDatum": "2026-01-28T09:10:00Z",
    "publicatieDatum": "2026-01-28T10:00:00Z"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "loonstrook",
      "slug": "loonstrook-pieter-van-dijk-2026-02"
    },
    "periode": "2026-02",
    "periodeOmschrijving": "Februari 2026",
    "werkgeverNaam": "Stichting Welzijn Rijnland",
    "werkgeverLoonheffingsnummer": "3491.78.012.L01",
    "brutoLoon": 3750.00,
    "nettoLoon": 2632.15,
    "loonheffing": 997.50,
    "zvwBijdrage": 76.19,
    "pensioenpremie": 187.50,
    "vakantiegeld": 0.00,
    "reserveringVakantiegeld": 300.00,
    "reiskosten": 143.34,
    "toeslagen": [],
    "inhoudingen": [],
    "cumulatieven": {
      "cumBrutoLoon": 7500.00,
      "cumNettoLoon": 5264.30,
      "cumLoonheffing": 1995.00,
      "cumZvwBijdrage": 152.38,
      "cumPensioenpremie": 375.00,
      "cumVakantiegeld": 0.00
    },
    "status": "gepubliceerd",
    "generatieDatum": "2026-02-25T09:00:00Z",
    "publicatieDatum": "2026-02-25T10:00:00Z"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "loonstrook",
      "slug": "loonstrook-fatima-el-amrani-2026-02"
    },
    "periode": "2026-02",
    "periodeOmschrijving": "Februari 2026",
    "werkgeverNaam": "Conduction B.V.",
    "werkgeverLoonheffingsnummer": "8612.34.567.L01",
    "brutoLoon": 4200.00,
    "nettoLoon": 2934.80,
    "loonheffing": 1134.00,
    "zvwBijdrage": 85.30,
    "pensioenpremie": 210.00,
    "vakantiegeld": 0.00,
    "reserveringVakantiegeld": 336.00,
    "reiskosten": 163.90,
    "toeslagen": [
      { "omschrijving": "Thuiswerkvergoeding", "bedrag": 62.00 }
    ],
    "inhoudingen": [],
    "cumulatieven": {
      "cumBrutoLoon": 8400.00,
      "cumNettoLoon": 5869.60,
      "cumLoonheffing": 2268.00,
      "cumZvwBijdrage": 170.60,
      "cumPensioenpremie": 420.00,
      "cumVakantiegeld": 0.00
    },
    "status": "gegenereerd",
    "generatieDatum": "2026-02-25T09:15:00Z"
  }
]
```

### Jaaropgaaf seed objects

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "jaaropgaaf",
      "slug": "jaaropgaaf-anna-de-boer-2025"
    },
    "jaar": 2025,
    "werkgeverNaam": "Gemeente Delft",
    "werkgeverLoonheffingsnummer": "0503.01.234.L01",
    "totaalBrutoLoon": 41400.00,
    "totaalLoonheffing": 10752.00,
    "totaalZvwWerknemersaandeel": 840.48,
    "totaalPensioenpremie": 2070.00,
    "totaalVakantiegeld": 3312.00,
    "totaalReiskosten": 1809.24,
    "aantalLoonperioden": 12,
    "status": "gepubliceerd",
    "generatieDatum": "2026-01-15T08:00:00Z"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "jaaropgaaf",
      "slug": "jaaropgaaf-sofia-hendriks-2025"
    },
    "jaar": 2025,
    "werkgeverNaam": "Reisadviesbureau Horizons B.V.",
    "werkgeverLoonheffingsnummer": "7213.56.789.L01",
    "totaalBrutoLoon": 35760.00,
    "totaalLoonheffing": 8529.60,
    "totaalZvwWerknemersaandeel": 726.24,
    "totaalPensioenpremie": 1788.00,
    "totaalVakantiegeld": 2860.80,
    "totaalReiskosten": 1437.12,
    "aantalLoonperioden": 12,
    "status": "gepubliceerd",
    "generatieDatum": "2026-01-15T08:30:00Z"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "jaaropgaaf",
      "slug": "jaaropgaaf-mehmet-yilmaz-2025"
    },
    "jaar": 2025,
    "werkgeverNaam": "Conduction B.V.",
    "werkgeverLoonheffingsnummer": "8612.34.567.L01",
    "totaalBrutoLoon": 61200.00,
    "totaalLoonheffing": 18856.80,
    "totaalZvwWerknemersaandeel": 1242.36,
    "totaalPensioenpremie": 3060.00,
    "totaalVakantiegeld": 4896.00,
    "totaalReiskosten": 2965.80,
    "aantalLoonperioden": 12,
    "status": "gegenereerd",
    "generatieDatum": "2026-01-15T09:00:00Z"
  }
]
```

## Reuse Analysis

Per ADR-001 and ADR-012, the following OpenRegister platform capabilities are reused — no custom implementations:

| Capability needed | Platform service / component used |
|---|---|
| Object CRUD (Loonstrook, Jaaropgaaf) | `ObjectService.saveObject()`, `findObjects()`, `findObject()` |
| List with pagination and filtering | `CnIndexPage` + `useListView` composable |
| Detail view | `CnDetailPage` + `CnDetailCard` |
| Schema-driven create/edit forms | `CnFormDialog` reads Loonstrook/Jaaropgaaf schema |
| File storage (PDF bytes) | `FileService` — upload, download, share link |
| Audit trail (who downloaded, when) | `AuditTrailService` — automatic via `CnObjectSidebar → CnAuditTrailTab` |
| Notifications (new payslip available) | `x-openregister-notifications` (declarative, see below) |
| Employee self-service object store | `createObjectStore` with `filesPlugin` + `auditTrailsPlugin` |
| Export (bulk PDF download) | `CnMassExportDialog` for admin export; per-object PDF via FileService |

No overlap found with existing OpenRegister services for the PDF rendering itself — `TextExtractionService` extracts text FROM PDFs but does not generate them. `PdfLoonstrookService` and `PdfJaaropgaafService` are net-new.

## Declarative-vs-Imperative Decisions

Per ADR-031, every behaviour is evaluated against available `x-openregister-*` extensions.

| Behaviour | Decision | Rationale |
|---|---|---|
| Loonstrook status lifecycle (concept → gegenereerd → gepubliceerd → gedownload) | **Declarative** — `x-openregister-lifecycle` in schema register | Standard state machine; all 4 states have clear triggers; OR lifecycle provides audit trail of every transition automatically |
| New payslip notification to employee | **Declarative** — `x-openregister-notifications` triggered on `gepubliceerd` transition | Recipient = `werknemer` relation; OR notification engine handles NC notification + optional email; no custom NotificationService needed |
| isGedownload / daysSincePublicatie calculated fields | **Declarative** — `x-openregister-calculations` | Derived from `publicatieDatum + now`; available on every read without service round-trip; usable in dashboard widgets |
| PDF loonstrook rendering | **Imperative** — `PdfLoonstrookService` (PHP + Dompdf + Twig) | ADR-031 explicit exception: "Document/PDF/document-template generation with app-specific templates." OR has no PDF rendering extension. |
| PDF jaaropgaaf rendering | **Imperative** — `PdfJaaropgaafService` (same rationale as above) | Same ADR-031 exception applies. |
| LoonstrookGeneratieJob (batch create after SalarisRun) | **Imperative** — `QueuedJob` | OR has no declarative cross-schema event trigger for "create N objects when another object transitions". Orchestrates external-system-like integration between payroll-core-basic and hrmq schemas. ADR-031 exception: scheduled bulk orchestration. |
| Jaaropgaaf totals (year-to-date aggregation) | **Imperative** — computed in `JaaropgaafService.aggregate()` from existing Loonstrook objects | OR `x-openregister-aggregations` supports declarative aggregation but requires all source objects to be in the same schema register. Year-end aggregation across 12 Loonstrook objects is a strong candidate for declarative migration once OR supports `@filter` on aggregation periods — document gap. |

Exception note for Jaaropgaaf aggregation: open an issue on `openregister` referencing ADR-031 requesting `@filter` support on `x-openregister-aggregations` with a date-range parameter. This service method can be removed when the extension lands.

## API Design

### LoonstrookController

```
GET  /index.php/apps/hrmq/api/loonstroken           — list (admin: all; employee: own)
GET  /index.php/apps/hrmq/api/loonstroken/{id}      — detail
POST /index.php/apps/hrmq/api/loonstroken           — create (admin only)
PUT  /index.php/apps/hrmq/api/loonstroken/{id}      — update status/metadata (admin only)
POST /index.php/apps/hrmq/api/loonstroken/{id}/pdf  — trigger PDF generation
POST /index.php/apps/hrmq/api/loonstroken/{id}/publish — publish to employee portal
GET  /index.php/apps/hrmq/api/loonstroken/{id}/download — download PDF (employee or admin)
```

### JaaropgaafController

```
GET  /index.php/apps/hrmq/api/jaaropgaven           — list (admin: all; employee: own)
GET  /index.php/apps/hrmq/api/jaaropgaven/{id}      — detail
POST /index.php/apps/hrmq/api/jaaropgaven/batch     — batch generate for a year (admin only)
POST /index.php/apps/hrmq/api/jaaropgaven/{id}/pdf  — trigger PDF generation
POST /index.php/apps/hrmq/api/jaaropgaven/{id}/publish — publish
GET  /index.php/apps/hrmq/api/jaaropgaven/{id}/download — download PDF
```

All endpoints use Nextcloud built-in auth (ADR-005). Employee-facing GET endpoints carry `#[NoAdminRequired]` and filter by `IUserSession->getUser()->getUID()` at the service layer — not the controller. Admin-only POST/PUT endpoints use `#[AuthorizedAdminSetting(Application::APP_ID)]`.

## PDF Template Design

The NL loonstrook standard layout (used by all 6 competitors):

```
┌──────────────────────────────────────────────────────┐
│ Werkgever: [naam]          Loonstrook [periode]       │
│ Loonheffingsnummer: [nr]   Datum: [generatieDatum]    │
├──────────────────────────────────────────────────────┤
│ Werknemer: [naam]          BSN: *** (masked)          │
│ Functie: [functie]         Afdeling: [afdeling]       │
├──────────────┬───────────────────────────────────────┤
│ BRUTO LOON   │                           € [bruto]    │
│   Toeslagen  │ [omschrijving]            € [bedrag]   │
├──────────────┼───────────────────────────────────────┤
│ INHOUDINGEN  │                                        │
│   Loonheffing│                           € [lhf]      │
│   ZVW        │                           € [zvw]      │
│   Pensioen   │                           € [pen]      │
│   [overige]  │ [omschrijving]            € [bedrag]   │
├──────────────┼───────────────────────────────────────┤
│ NETTO LOON   │                           € [netto]    │
├──────────────┼───────────────────────────────────────┤
│ CUMULATIEVEN │ Brutoloon  Loonheffing  ZVW  Pensioen  │
│ Dit jaar     │ [cum.bruto] [cum.lhf]  ...  ...        │
└──────────────┴───────────────────────────────────────┘
```

BSN is always masked in the PDF display (`***`) — stored only in the employee-master schema under encrypted field per compliance-reporting-avg.

Dompdf is the PDF engine (PHP, no external service dependency, EUPL-compatible). Twig template at `lib/Templates/loonstrook.html.twig`.

## Frontend Architecture

Two portal surfaces:

1. **Admin surface** (salarisadministrateur/HR): Full `CnIndexPage` with all loonstroken; batch publish action via `CnMassActionBar`; status filter via `CnFilterBar`; lifecycle actions (generate PDF, publish) in `CnRowActions`.

2. **Employee surface**: Same `CnIndexPage` but object store filtered to `werknemerId = currentUser.uid`. No create/edit/delete actions. Download button only. Employee cannot see other employees' payslips (enforced server-side).

The employee filtering is enforced in `LoonstrookController::index()` — if `#[NoAdminRequired]`, the service layer appends `werknemerId = getUser()->getUID()` to the findObjects query. Admin check uses `IGroupManager::isAdmin()` — never trust a frontend-sent `isAdmin` flag.
