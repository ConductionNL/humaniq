# Design: Cross-app: Payroll Cost → shillinq GL Post

## Architecture overview

De integratie volgt het externe API-adapter-patroon (ADR-003, ADR-031). hrmq schrijft geen loongegevens naar shillinq's database; het roept `shillinq.JournalEntry.post` aan via de geconfigureerde REST API. De idempotentieregistratie en de posting-status worden bijgehouden in een eigen OpenRegister-schema (`PayrollGLPost`) in het hrmq-register.

```
PayrollRun (payroll-core-basic)
    │ afsluiten run triggert
    ▼
PayrollGLPostService                 ──► shillinq API
    │ per werknemer:                     JournalEntry.post
    │  1. idempotentiecheck
    │  2. journaalregels opbouwen
    │  3. API aanroepen
    │  4. status opslaan
    ▼
PayrollGLPost (OpenRegister schema)
    └── status: pending | posted | failed | skipped
    └── shillinqJournalEntryId
    └── errorMessage
```

## Declarative-vs-imperative decision

Per ADR-031:

| Gedrag | Aanpak | Motivatie |
|---|---|---|
| Posting-status lifecycle (pending → posted / failed) | **Imperatief** (service code) | `x-openregister-lifecycle` werkt op OR-interne objecten. De toestandsovergang hangt af van een externe API-response van shillinq — een conditie die de schema-engine niet kan evalueren. |
| Idempotentieregistratie | **OpenRegister** (schema lookup) | `ObjectService.findObjects()` met (employee_id, period) als filter — geen eigen tabel, geen custom mapper. |
| Retry-logica | **Imperatief** (service code) | Externe API-fouten vereisen retry-strategie (exponential backoff); buiten scope van schema-engine. |
| Posting-overzicht (dashboard-widget) | **Declaratief** (`x-openregister-aggregations`) | Statusverdeling (posted/failed/pending) per periode → aggregatie op PayrollGLPost-schema. |

## Schema-definitie: PayrollGLPost

Locatie: `lib/Settings/hrmq_register.json` (toevoeging aan bestaand registerbestand)

```json
{
  "title": "PayrollGLPost",
  "description": "Registratie van een grootboekposting vanuit de salarisrun naar shillinq, één record per (werknemer, periode).",
  "type": "object",
  "required": ["employeeId", "period", "status"],
  "properties": {
    "employeeId": {
      "type": "string",
      "description": "OpenRegister-object-ID van de werknemer (relatie naar Employee-schema).",
      "format": "uuid"
    },
    "period": {
      "type": "string",
      "description": "Salarisperiode in formaat YYYY-MM (bijv. 2026-04).",
      "pattern": "^[0-9]{4}-[0-9]{2}$"
    },
    "status": {
      "type": "string",
      "description": "Postingstatus.",
      "enum": ["pending", "posted", "failed", "skipped"]
    },
    "shillinqJournalEntryId": {
      "type": "string",
      "description": "ID van de journaalpost in shillinq, ingevuld na succesvolle posting.",
      "nullable": true
    },
    "grossWageAmount": {
      "type": "number",
      "description": "Bruto-loon in euro (RGS 4xxx).",
      "minimum": 0
    },
    "socialChargesAmount": {
      "type": "number",
      "description": "Sociale lasten in euro (RGS 17xx).",
      "minimum": 0
    },
    "vacationReservationAmount": {
      "type": "number",
      "description": "Vakantiegeld-reservering in euro (RGS 18xx).",
      "minimum": 0
    },
    "netWagePayableAmount": {
      "type": "number",
      "description": "Netto-loonschuld in euro (RGS 14xx, creditregel).",
      "minimum": 0
    },
    "postedAt": {
      "type": "string",
      "format": "date-time",
      "description": "Tijdstip van succesvolle posting naar shillinq.",
      "nullable": true
    },
    "errorMessage": {
      "type": "string",
      "description": "Foutmelding bij status 'failed'. NOOIT teruggegeven aan client — alleen voor intern gebruik en adminlog.",
      "nullable": true
    },
    "payrollRunId": {
      "type": "string",
      "description": "OpenRegister-object-ID van de salarisrun (relatie naar PayrollRun-schema in payroll-core-basic).",
      "format": "uuid",
      "nullable": true
    }
  },
  "x-openregister-aggregations": {
    "postingStatusSummary": {
      "description": "Aantal records per status voor een gegeven periode",
      "groupBy": "status",
      "count": true
    }
  }
}
```

### RGS 2026 rekening-mapping (configuratie)

Opgeslagen via `IAppConfig` (sensitive voor de API-sleutel), niet in OpenRegister:

| Sleutel | Standaardwaarde | Beschrijving |
|---|---|---|
| `shillinq_api_key` | — | shillinq REST API-sleutel (sensitive) |
| `shillinq_api_url` | — | Base URL van de shillinq API |
| `rgs_gross_wage_account` | `4000` | RGS 4xxx bruto-loon |
| `rgs_social_charges_account` | `1700` | RGS 17xx sociale lasten |
| `rgs_vacation_reservation_account` | `1800` | RGS 18xx vakantiegeld-reservering |
| `rgs_net_wage_payable_account` | `1400` | RGS 14xx netto-loonschuld |

## Reuse Analysis (ADR-001)

| Capability | OR-abstractie gebruikt | Toelichting |
|---|---|---|
| Objectopslag PayrollGLPost | `ObjectService.saveObject()` | Geen custom mapper; schema in registerbestand |
| Idempotentiecheck | `ObjectService.findObjects($register, $schema, ['employeeId' => ..., 'period' => ...])` | Standaard OR-query |
| Status-aggregatie (dashboard) | `x-openregister-aggregations` (schema-declaratief) | `postingStatusSummary` aggregatie |
| CRUD-overzichtspagina | `CnIndexPage` + `useListView` | Standaard OR lijst; geen custom paginering |
| Audit trail | automatisch via OR | Elke statuswijziging vastgelegd |
| Admin-instellingen | `IAppConfig` met sensitive flag | API-sleutel buiten OR opgeslagen per platform-norm |
| Foutafhandeling API-responses | statische foutmelding + `logger->error()` | Per ADR-005 / ADR-015 |

Geen overlap met bestaande OpenRegister-services. De `PayrollGLPostService` is een externe API-adapter — categorie "externe integratieglue" per ADR-003.

## Journaalregel-opbouw

Per werknemer per periode worden vier journaalregels opgebouwd:

```
Debet  4000  Bruto-loon                  [grossWageAmount]
Debet  1700  Sociale lasten              [socialChargesAmount]
Debet  1800  Vakantiegeld-reservering    [vacationReservationAmount]
Credit 1400  Netto-loonschuld            [netWagePayableAmount]
```

Balanceringscontrole: `grossWageAmount + socialChargesAmount + vacationReservationAmount = netWagePayableAmount` (of de afronding binnen €0,01). Bij onevenwicht wordt de posting geweigerd met status `failed`.

## Idempotentie-contract

Een PayrollGLPost-record met `status = "posted"` is onwijzigbaar wat betreft de financiële bedragen. Een herpost-aanroep voor een bestaand `posted`-record resulteert in `status = "skipped"` (geen API-call). Voor `failed`-records wordt een nieuwe API-poging gedaan en wordt de bestaande record bijgewerkt.

## Seed Data

Drie tot vijf realistische PayrollGLPost-objecten in het hrmq-registerbestand (Dutch seed values).

### PayrollGLPost — seed-objecten

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "PayrollGLPost",
      "slug": "payroll-gl-post-bakker-2026-03"
    },
    "employeeId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "period": "2026-03",
    "status": "posted",
    "shillinqJournalEntryId": "JE-20260331-00142",
    "grossWageAmount": 3850.00,
    "socialChargesAmount": 963.00,
    "vacationReservationAmount": 308.00,
    "netWagePayableAmount": 5121.00,
    "postedAt": "2026-03-31T14:22:05Z",
    "errorMessage": null,
    "payrollRunId": "f0e1d2c3-b4a5-6789-fedc-ba0987654321"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "PayrollGLPost",
      "slug": "payroll-gl-post-devries-2026-03"
    },
    "employeeId": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
    "period": "2026-03",
    "status": "posted",
    "shillinqJournalEntryId": "JE-20260331-00143",
    "grossWageAmount": 4200.00,
    "socialChargesAmount": 1050.00,
    "vacationReservationAmount": 336.00,
    "netWagePayableAmount": 5586.00,
    "postedAt": "2026-03-31T14:22:07Z",
    "errorMessage": null,
    "payrollRunId": "f0e1d2c3-b4a5-6789-fedc-ba0987654321"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "PayrollGLPost",
      "slug": "payroll-gl-post-janssen-2026-03"
    },
    "employeeId": "c3d4e5f6-a7b8-9012-cdef-012345678902",
    "period": "2026-03",
    "status": "failed",
    "shillinqJournalEntryId": null,
    "grossWageAmount": 2900.00,
    "socialChargesAmount": 725.00,
    "vacationReservationAmount": 232.00,
    "netWagePayableAmount": 3857.00,
    "postedAt": null,
    "errorMessage": "shillinq API timeout na 30s",
    "payrollRunId": "f0e1d2c3-b4a5-6789-fedc-ba0987654321"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "PayrollGLPost",
      "slug": "payroll-gl-post-bakker-2026-04"
    },
    "employeeId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "period": "2026-04",
    "status": "pending",
    "shillinqJournalEntryId": null,
    "grossWageAmount": 3850.00,
    "socialChargesAmount": 963.00,
    "vacationReservationAmount": 308.00,
    "netWagePayableAmount": 5121.00,
    "postedAt": null,
    "errorMessage": null,
    "payrollRunId": "01234567-89ab-cdef-0123-456789abcdef"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "PayrollGLPost",
      "slug": "payroll-gl-post-vandenberg-2026-04"
    },
    "employeeId": "d4e5f6a7-b8c9-0123-defa-123456789003",
    "period": "2026-04",
    "status": "skipped",
    "shillinqJournalEntryId": "JE-20260430-00088",
    "grossWageAmount": 5100.00,
    "socialChargesAmount": 1275.00,
    "vacationReservationAmount": 408.00,
    "netWagePayableAmount": 6783.00,
    "postedAt": "2026-04-30T09:11:44Z",
    "errorMessage": null,
    "payrollRunId": "01234567-89ab-cdef-0123-456789abcdef"
  }
]
```

## Mixed-spec rationale

Niet van toepassing. Dit change-set is `kind: code`. De schema-definitie in het registerbestand is een bijproduct van de code-wijziging (≤20 LOC JSON-toevoeging), nauw gekoppeld aan de service-implementatie. Per ADR-032 thin-glue-exception: geen aparte `config`-spec gerechtvaardigd.

## Security

- API-sleutel opgeslagen via `IAppConfig` met `sensitive: true` — nooit gelogd of teruggegeven aan client.
- `errorMessage` uit shillinq-response: gelogd via `$logger->error()`, NIET opgeslagen als raw response (kan interne paden bevatten). Alleen de statische tekst "shillinq API fout" wordt opgeslagen.
- Alle mutation-endpoints: `#[NoAdminRequired]` + per-object auth via `AuthorizationService` (ADR-005).
- shillinq API-aanroep: TLS vereist; certificaatvalidatie niet uitschakelen.
