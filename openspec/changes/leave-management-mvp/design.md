# Design: Leave Management MVP

## Context

hrmq is een Nextcloud-app voor HR-administratie in Nederland. De Leave Management MVP voegt vakantie-opbouw, aanvraag/goedkeuring en saldo-registratie toe als kernmodule. Alle domeindata wordt opgeslagen als OpenRegister-objecten (ADR-001). De module hangt af van de `employee-master` change voor de Employee-entiteit.

## Goals / Non-Goals

**Goals:**
- Verloftypes met wettelijke en bovenwettelijke categorieën beheren
- CAO-verlofbeleid met opbouwregels configureren
- Maandelijkse opbouw automatisch verwerken via een achtergrondtaak
- Verlofaanvraag/goedkeuring workflow met saldo-validatie uitvoeren
- Verlofsaldo real-time berekenen inclusief overdracht
- Uitbetalingsberekening bij uitdiensttreding genereren

**Non-Goals:**
- Verzuimregistratie en WVP-cyclus (aparte change)
- Bijzonder verlof regelgeving (complex NL rechtskader; phase 2)
- Koppeling met loonadministratie voor uitbetaling (aparte integratiemodule)
- Kalenderintegratie (phase 2)
- Self-service portal voor medewerkers (valt buiten Nextcloud-scope MVP)

---

## Data Model

Alle entiteiten worden als OpenRegister-objecten opgeslagen. Schema-vocabulaire volgt schema.org (ADR-011). Relaties zijn OpenRegister-relaties — geen foreign keys.

### Schema: LeaveType (Verlofsoort)

| Property | Type | Required | Beschrijving |
|---|---|---|---|
| `name` | `string` | ja | Naam van het verloftype |
| `description` | `string` | nee | Toelichting |
| `category` | `string` enum: `wettelijk`, `bovenwettelijk`, `bijzonder` | ja | Wettelijke categorie |
| `isStatutory` | `boolean` | ja | Wettelijk minimum (BW art. 7:634) |
| `defaultHoursPerYear` | `number` | nee | Standaard jaarlijkse uren (indien geen beleid gekoppeld) |
| `accrualMethod` | `string` enum: `proportioneel`, `lump_sum`, `geen` | ja | Opbouwmethode |
| `carryOverMaxHours` | `number` | nee | Max. overdraagbare uren naar volgend jaar (0 = geen overdracht) |
| `isPaidOutOnTermination` | `boolean` | ja | Uitbetaalbaar bij uitdiensttreding |
| `requiresApproval` | `boolean` | ja | Goedkeuring leidinggevende vereist |

### Schema: LeavePolicy (Verlofbeleid)

| Property | Type | Required | Beschrijving |
|---|---|---|---|
| `name` | `string` | ja | Naam van het beleid (bijv. "CAO Gemeenten 2025") |
| `caoReference` | `string` | nee | CAO-naam of artikel referentie |
| `leaveType` | OpenRegister relation → `LeaveType` | ja | Gekoppeld verloftype |
| `annualHours` | `number` | ja | Totaal jaarlijks op te bouwen uren |
| `accrualPeriod` | `string` enum: `maandelijks`, `wekelijks`, `jaarlijks` | ja | Opbouwfrequentie |
| `carryOverMaxHours` | `number` | nee | Maximale overdracht (overschrijft LeaveType-waarde indien opgegeven) |
| `validFrom` | `string` format: `date` | ja | Startdatum geldigheid |
| `validTo` | `string` format: `date` | nee | Einddatum geldigheid (leeg = onbepaald) |

### Schema: LeaveBalance (Verlofsaldo)

| Property | Type | Required | Beschrijving |
|---|---|---|---|
| `employee` | OpenRegister relation → `Employee` | ja | Medewerker |
| `leaveType` | OpenRegister relation → `LeaveType` | ja | Verloftype |
| `year` | `integer` | ja | Kalenderjaar |
| `accruedHours` | `number` | ja | Opgebouwde uren dit jaar |
| `usedHours` | `number` | ja | Opgenomen uren dit jaar |
| `carriedOverHours` | `number` | ja | Overgedragen uren uit vorig jaar |
| `remainingHours` | `number` | nee | **Berekend**: `accruedHours + carriedOverHours - usedHours` |
| `lastRecalculated` | `string` format: `date-time` | nee | Tijdstip laatste herberekening |

> `remainingHours` wordt gedeclareerd als `x-openregister-calculations` — geen service nodig.

### Schema: LeaveRequest (Verlofaanvraag)

| Property | Type | Required | Beschrijving |
|---|---|---|---|
| `employee` | OpenRegister relation → `Employee` | ja | Aanvragende medewerker |
| `leaveType` | OpenRegister relation → `LeaveType` | ja | Verloftype |
| `startDate` | `string` format: `date` | ja | Eerste verlofdag |
| `endDate` | `string` format: `date` | ja | Laatste verlofdag |
| `startPartDay` | `boolean` | nee | Halve dag aan het begin |
| `endPartDay` | `boolean` | nee | Halve dag aan het einde |
| `totalHours` | `number` | ja | Totaal aangevraagde uren |
| `reason` | `string` | nee | Toelichting medewerker |
| `status` | `string` enum: `concept`, `ingediend`, `goedgekeurd`, `afgewezen`, `ingetrokken` | ja | Workflowstatus |
| `approvedBy` | `string` (userId) | nee | UID van goedkeurende leidinggevende/HR |
| `approvedAt` | `string` format: `date-time` | nee | Beslismoment |
| `rejectionReason` | `string` | nee | Afwijzingsreden |
| `submittedAt` | `string` format: `date-time` | nee | Indieningstijdstip |

### Schema: LeaveAccrualLog (Opbouwlog)

| Property | Type | Required | Beschrijving |
|---|---|---|---|
| `employee` | OpenRegister relation → `Employee` | ja | Medewerker |
| `leaveType` | OpenRegister relation → `LeaveType` | ja | Verloftype |
| `period` | `string` | ja | Opbouwperiode in formaat `YYYY-MM` |
| `hoursAccrued` | `number` | ja | Opgebouwde uren in deze periode |
| `calculationBasis` | `string` | nee | Grondslag berekening (bijv. "40u/week × 1/12 × 20d") |
| `policySnapshot` | `string` | nee | LeavePolicy-slug gebruikt bij berekening |
| `createdAt` | `string` format: `date-time` | ja | Aanmaaktijdstip log-entry |

---

## Declarative-vs-Imperative Decisions (ADR-031)

### LeaveRequest lifecycle → `x-openregister-lifecycle`

De statusmachine `concept → ingediend → goedgekeurd/afgewezen/ingetrokken` past exact in het `x-openregister-lifecycle`-patroon. Elke overgang krijgt automatisch een audit-trail, CloudEvent en RBAC-guard via het OR-engine.

Toegestane transities:
- `concept → ingediend` (medewerker)
- `ingediend → goedgekeurd` (leidinggevende / HR; vereist `LeaveRequestGuard` saldo-check)
- `ingediend → afgewezen` (leidinggevende / HR)
- `ingediend → concept` (medewerker — intrekken vóór indiening)
- `goedgekeurd → ingetrokken` (medewerker; triggert saldo-terugboeking)
- `afgewezen → concept` (medewerker — opnieuw bewerken)

**PHP guard:** `lib/Lifecycle/LeaveRequestGuard.php` — implementeert `requires: OCA\Hrmq\Lifecycle\LeaveRequestGuard`. Guard controleert of het resterend saldo van de medewerker voldoende is voor de aangevraagde uren. Thin, single-method, called by lifecycle engine.

### LeaveBalance.remainingHours → `x-openregister-calculations`

`remainingHours = accruedHours + carriedOverHours - usedHours` is een deterministische berekening op basis van velden van hetzelfde object. Gedeclareerd als `x-openregister-calculations`. Geen service-methode nodig.

### Openstaande aanvragen per leidinggevende → `x-openregister-aggregations`

Dashboard-widget "Openstaande aanvragen" is een count per `status=ingediend` gefilterd op afdeling/manager. Gedeclareerd als `x-openregister-aggregations`. Geen `LeaveAnalyticsService` of loopende `findAll()`.

### Notificaties → `x-openregister-notifications`

Drie notificatie-events worden declaratief ingesteld:
1. **aanvraag-ingediend**: medewerker indient → notificeer leidinggevende
2. **aanvraag-goedgekeurd**: leidinggevende accordeert → notificeer medewerker
3. **aanvraag-afgewezen**: leidinggevende wijst af → notificeer medewerker met reden

**Uitzondering (imperatief):** `LeaveAccrualJob.php` — maandelijkse opbouw is een achtergrondtaak die objecten van de Employee-entiteit doorloopt, het toepasselijke LeavePolicy opzoekt en LeaveBalance bijwerkt. Per ADR-031 is een ScheduledWorkflow via n8n de voorkeurskeuze; als n8n echter niet beschikbaar is op de doelinstallatie, biedt de QueuedJob een Nextcloud-native fallback. De keuze wordt gedocumenteerd in `tasks.md` Task 6.

---

## Reuse Analysis (ADR-001 / ADR-011)

| Onderdeel | OpenRegister / @conduction/nextcloud-vue | Eigen code vereist |
|---|---|---|
| CRUD verloftypes en aanvragen | `ObjectService.saveObject()` + `CnFormDialog` | Nee |
| Lijst met verlofaanvragen | `CnIndexPage` + `useListView` | Nee |
| Aanvraag detail | `CnDetailPage` + `CnObjectSidebar` | Nee |
| Aanvraag lifecycle | `x-openregister-lifecycle` + `lifecyclePlugin` | Guard-klasse (thin) |
| Saldo berekening | `x-openregister-calculations` | Nee |
| Dashboard widgets | `CnStatsBlock` + `CnChartWidget` | Nee (aggregations config) |
| Notificaties | `x-openregister-notifications` | Nee |
| Audit-trail | `CnObjectSidebar` → `CnAuditTrailTab` | Nee |
| Maandelijkse opbouw | `LeaveAccrualJob` (QueuedJob) | Ja — externe data (Employee contracturen) + business rules |

Geen overlap gevonden met bestaande OpenRegister-services. De `LeaveRequestGuard` en `LeaveAccrualJob` zijn vereiste app-specifieke logica die het OR-engine niet kan vervangen.

---

## Seed Data

Seed data wordt geladen via `lib/Settings/hrmq_register.json` met het `@self`-envelope (ADR-001). Alle waarden zijn fictief maar realistisch voor een Nederlandse gemeente of adviesorganisatie.

### LeaveType seed objects

```json
[
  {
    "@self": { "register": "hrmq", "schema": "leave-type", "slug": "vakantie-wettelijk" },
    "name": "Vakantie (wettelijk)",
    "description": "Wettelijk minimum conform BW art. 7:634: 4× weekuren per jaar",
    "category": "wettelijk",
    "isStatutory": true,
    "defaultHoursPerYear": 160,
    "accrualMethod": "proportioneel",
    "carryOverMaxHours": 40,
    "isPaidOutOnTermination": true,
    "requiresApproval": true
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-type", "slug": "vakantie-bovenwettelijk" },
    "name": "Vakantie (bovenwettelijk)",
    "description": "Bovenwettelijk verlof conform CAO Gemeenten: 5 extra dagen per jaar",
    "category": "bovenwettelijk",
    "isStatutory": false,
    "defaultHoursPerYear": 40,
    "accrualMethod": "proportioneel",
    "carryOverMaxHours": 80,
    "isPaidOutOnTermination": true,
    "requiresApproval": true
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-type", "slug": "bijzonder-verlof-huwelijk" },
    "name": "Bijzonder verlof – huwelijk",
    "description": "Twee betaalde verlofdagen bij eigen huwelijk",
    "category": "bijzonder",
    "isStatutory": false,
    "defaultHoursPerYear": 16,
    "accrualMethod": "geen",
    "carryOverMaxHours": 0,
    "isPaidOutOnTermination": false,
    "requiresApproval": true
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-type", "slug": "bijzonder-verlof-overlijden" },
    "name": "Bijzonder verlof – overlijden naaste",
    "description": "Vier betaalde verlofdagen bij overlijden partner, kind of ouder",
    "category": "bijzonder",
    "isStatutory": false,
    "defaultHoursPerYear": 32,
    "accrualMethod": "geen",
    "carryOverMaxHours": 0,
    "isPaidOutOnTermination": false,
    "requiresApproval": false
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-type", "slug": "ouderschapsverlof" },
    "name": "Ouderschapsverlof",
    "description": "Gedeeltelijk betaald ouderschapsverlof conform Wet arbeid en zorg",
    "category": "bijzonder",
    "isStatutory": true,
    "defaultHoursPerYear": 0,
    "accrualMethod": "geen",
    "carryOverMaxHours": 0,
    "isPaidOutOnTermination": false,
    "requiresApproval": true
  }
]
```

### LeavePolicy seed objects

```json
[
  {
    "@self": { "register": "hrmq", "schema": "leave-policy", "slug": "cao-gemeenten-wettelijk-2025" },
    "name": "CAO Gemeenten 2025 – wettelijk vakantieverlof",
    "caoReference": "CAO Gemeenten art. 6.1",
    "leaveType": "vakantie-wettelijk",
    "annualHours": 160,
    "accrualPeriod": "maandelijks",
    "carryOverMaxHours": 40,
    "validFrom": "2025-01-01",
    "validTo": "2025-12-31"
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-policy", "slug": "cao-gemeenten-bovenwettelijk-2025" },
    "name": "CAO Gemeenten 2025 – bovenwettelijk vakantieverlof",
    "caoReference": "CAO Gemeenten art. 6.2",
    "leaveType": "vakantie-bovenwettelijk",
    "annualHours": 40,
    "accrualPeriod": "maandelijks",
    "carryOverMaxHours": 80,
    "validFrom": "2025-01-01",
    "validTo": "2025-12-31"
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-policy", "slug": "cao-zorg-wettelijk-2025" },
    "name": "CAO VVT 2025 – wettelijk vakantieverlof",
    "caoReference": "CAO VVT 2025 art. 8.1",
    "leaveType": "vakantie-wettelijk",
    "annualHours": 184,
    "accrualPeriod": "maandelijks",
    "carryOverMaxHours": 40,
    "validFrom": "2025-01-01",
    "validTo": "2025-12-31"
  }
]
```

### LeaveBalance seed objects

```json
[
  {
    "@self": { "register": "hrmq", "schema": "leave-balance", "slug": "saldo-devries-wettelijk-2025" },
    "employee": "jan-de-vries",
    "leaveType": "vakantie-wettelijk",
    "year": 2025,
    "accruedHours": 80,
    "usedHours": 32,
    "carriedOverHours": 16,
    "remainingHours": 64,
    "lastRecalculated": "2026-05-01T00:05:00Z"
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-balance", "slug": "saldo-janssen-wettelijk-2025" },
    "employee": "maria-janssen",
    "leaveType": "vakantie-wettelijk",
    "year": 2025,
    "accruedHours": 80,
    "usedHours": 0,
    "carriedOverHours": 8,
    "remainingHours": 88,
    "lastRecalculated": "2026-05-01T00:05:00Z"
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-balance", "slug": "saldo-devries-bovenwettelijk-2025" },
    "employee": "jan-de-vries",
    "leaveType": "vakantie-bovenwettelijk",
    "year": 2025,
    "accruedHours": 20,
    "usedHours": 0,
    "carriedOverHours": 40,
    "remainingHours": 60,
    "lastRecalculated": "2026-05-01T00:05:00Z"
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-balance", "slug": "saldo-vandenberg-wettelijk-2025" },
    "employee": "pieter-van-den-berg",
    "leaveType": "vakantie-wettelijk",
    "year": 2025,
    "accruedHours": 80,
    "usedHours": 56,
    "carriedOverHours": 0,
    "remainingHours": 24,
    "lastRecalculated": "2026-05-01T00:05:00Z"
  }
]
```

### LeaveRequest seed objects

```json
[
  {
    "@self": { "register": "hrmq", "schema": "leave-request", "slug": "aanvraag-devries-zomervakantie" },
    "employee": "jan-de-vries",
    "leaveType": "vakantie-wettelijk",
    "startDate": "2025-07-14",
    "endDate": "2025-07-25",
    "startPartDay": false,
    "endPartDay": false,
    "totalHours": 80,
    "reason": "Zomervakantie met gezin",
    "status": "goedgekeurd",
    "approvedBy": "anne-bakker",
    "approvedAt": "2025-06-15T10:23:00Z",
    "submittedAt": "2025-06-10T08:45:00Z"
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-request", "slug": "aanvraag-janssen-pinksteren" },
    "employee": "maria-janssen",
    "leaveType": "vakantie-bovenwettelijk",
    "startDate": "2025-06-09",
    "endDate": "2025-06-09",
    "startPartDay": false,
    "endPartDay": false,
    "totalHours": 8,
    "reason": "",
    "status": "ingediend",
    "submittedAt": "2025-05-28T14:12:00Z"
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-request", "slug": "aanvraag-vandenberg-september" },
    "employee": "pieter-van-den-berg",
    "leaveType": "vakantie-wettelijk",
    "startDate": "2025-09-01",
    "endDate": "2025-09-05",
    "startPartDay": false,
    "endPartDay": false,
    "totalHours": 40,
    "reason": "Schoolvakantie regio Noord",
    "status": "concept",
    "submittedAt": null
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-request", "slug": "aanvraag-devries-afgewezen" },
    "employee": "jan-de-vries",
    "leaveType": "vakantie-wettelijk",
    "startDate": "2025-12-22",
    "endDate": "2025-12-31",
    "startPartDay": false,
    "endPartDay": false,
    "totalHours": 64,
    "reason": "Kerstvakantie",
    "status": "afgewezen",
    "approvedBy": "anne-bakker",
    "approvedAt": "2025-11-20T09:00:00Z",
    "rejectionReason": "Onvoldoende saldo. Beschikbaar: 32 uren, aangevraagd: 64 uren.",
    "submittedAt": "2025-11-18T11:30:00Z"
  }
]
```

### LeaveAccrualLog seed objects

```json
[
  {
    "@self": { "register": "hrmq", "schema": "leave-accrual-log", "slug": "opbouw-devries-jan-2025" },
    "employee": "jan-de-vries",
    "leaveType": "vakantie-wettelijk",
    "period": "2025-01",
    "hoursAccrued": 13.33,
    "calculationBasis": "40u/week × 4 weken/mnd factor × 1/12 jaar",
    "policySnapshot": "cao-gemeenten-wettelijk-2025",
    "createdAt": "2025-01-31T23:00:00Z"
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-accrual-log", "slug": "opbouw-janssen-jan-2025" },
    "employee": "maria-janssen",
    "leaveType": "vakantie-wettelijk",
    "period": "2025-01",
    "hoursAccrued": 13.33,
    "calculationBasis": "40u/week × 4 weken/mnd factor × 1/12 jaar",
    "policySnapshot": "cao-gemeenten-wettelijk-2025",
    "createdAt": "2025-01-31T23:00:00Z"
  },
  {
    "@self": { "register": "hrmq", "schema": "leave-accrual-log", "slug": "opbouw-vandenberg-jan-2025" },
    "employee": "pieter-van-den-berg",
    "leaveType": "vakantie-wettelijk",
    "period": "2025-01",
    "hoursAccrued": 13.33,
    "calculationBasis": "40u/week × 4 weken/mnd factor × 1/12 jaar",
    "policySnapshot": "cao-gemeenten-wettelijk-2025",
    "createdAt": "2025-01-31T23:00:00Z"
  }
]
```

---

## API Design

REST endpoints conform ADR-002 (`/index.php/apps/hrmq/api/{resource}`):

| Method | Path | Auth | Beschrijving |
|---|---|---|---|
| GET | `/api/leave-types` | `#[NoAdminRequired]` | Lijst verloftypes |
| POST | `/api/leave-types` | `#[AuthorizedAdminSetting]` | Verloftype aanmaken |
| PUT | `/api/leave-types/{id}` | `#[AuthorizedAdminSetting]` | Verloftype bijwerken |
| DELETE | `/api/leave-types/{id}` | `#[AuthorizedAdminSetting]` | Verloftype verwijderen |
| GET | `/api/leave-policies` | `#[NoAdminRequired]` | Lijst verlofbeleid |
| POST | `/api/leave-policies` | `#[AuthorizedAdminSetting]` | Beleid aanmaken |
| GET | `/api/leave-balances` | `#[NoAdminRequired]` | Saldo's (gefilterd op huidige gebruiker tenzij admin) |
| GET | `/api/leave-requests` | `#[NoAdminRequired]` | Aanvragen (gefilterd op owner/manager/admin) |
| POST | `/api/leave-requests` | `#[NoAdminRequired]` | Aanvraag indienen |
| PUT | `/api/leave-requests/{id}` | `#[NoAdminRequired]` | Aanvraag bijwerken (eigen aanvraag of manager) |
| POST | `/api/leave-requests/{id}/transition` | `#[NoAdminRequired]` | Lifecycle-transitie uitvoeren |

Per-object autorisatie op elke `#[NoAdminRequired]`-mutatie (ADR-005): medewerker mag alleen eigen aanvragen muteren; leidinggevende/HR mag aanvragen van directe rapportages behandelen; admins mogen alles.

---

## Frontend Pages (ADR-024)

Drie pages worden toegevoegd aan `src/manifest.json`:

| Page key | Type | Route | Beschrijving |
|---|---|---|---|
| `leave-requests` | `index` | `/verlofaanvragen` | Lijst aanvragen met status-filter |
| `leave-balances` | `index` | `/verlofsaldo` | Saldo-overzicht per medewerker/type/jaar |
| `leave-types` | `index` | `/verloftypes` | Admin: verloftypes en beleid beheren |

Dashboard-extensie: `CnStatsBlock`-widgets via `x-openregister-widgets` in het schema-register.
