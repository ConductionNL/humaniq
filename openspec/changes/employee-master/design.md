# Design: Employee Master (NAW, BSN, IBAN, AVG)

## Architecture Overview

Employee data is stored entirely in OpenRegister objects under the `hrmq` register.
The platform provides CRUD, audit trail, field-level RBAC, pagination, search,
and GDPR data-subject access request handling for free.

Custom code is restricted to:
1. **BsnEncryptionService** — AES-256 encrypt/decrypt for BSN field (no OR extension exists)
2. **PropertyRbacHandler configuration** — `bsn:read` / `iban:read` permissions wired via
   schema `x-permissions` metadata
3. **Employee schema register** — lifecycle, calculations, and retention declared declaratively

```
HR Administrator
      │
      ▼
[Vue: EmployeeIndexView]  [Vue: EmployeeDetailView]
      │                           │
      ▼                           ▼
[Pinia: employeeStore]  (createObjectStore + plugins)
      │
      ▼
[GET/POST/PUT/DELETE /index.php/apps/hrmq/api/employees]
      │
      ▼
[OpenRegister: ObjectService]
      │
      ├── BsnEncryptionService (hook: before save / after load)
      ├── PropertyRbacHandler (bsn:read, iban:read)
      ├── AuditTrailService (automatic)
      └── RetentionService (declarative via schema register)
```

## Data Model

### Entity: Employee (`schema:Person`)

Aligned with schema.org/Person and vCard properties (ADR-011).
Dutch government fields use the international schema.org name as primary;
Dutch field names are documentation aliases only.

| Property | Type | Required | schema.org / vCard | Dutch alias | Notes |
|---|---|---|---|---|---|
| `givenName` | string | yes | schema:givenName | voornamen | First name(s) |
| `additionalName` | string | no | schema:additionalName | tussenvoegsel | Name infix |
| `familyName` | string | yes | schema:familyName | achternaam | Family name |
| `callSign` | string | no | — | roepnaam | Preferred first name |
| `birthDate` | date | yes | schema:birthDate | geboortedatum | ISO 8601 |
| `gender` | string | no | schema:gender | geslacht | male / female / nonbinary |
| `nationality` | string | no | schema:nationality | nationaliteit | ISO 3166-1 alpha-2 |
| `bsnEncrypted` | string | no | — | BSN (encrypted) | AES-256 ciphertext; plaintext never stored |
| `email` | string (email) | no | vCard: email | email | |
| `telephone` | string | no | vCard: tel | telefoon | |
| `streetAddress` | string | no | schema:streetAddress | straatnaam + huisnummer | |
| `postalCode` | string | no | schema:postalCode | postcode | Pattern: `[1-9][0-9]{3}[A-Z]{2}` |
| `addressLocality` | string | no | schema:addressLocality | woonplaats | |
| `addressCountry` | string | no | schema:addressCountry | land | Default: NL |
| `iban` | string | no | — | IBAN | Bank account for salary |
| `startDate` | date | yes | schema:startDate | indatumInDienst | Employment start |
| `endDate` | date | no | schema:endDate | indatumUitDienst | Employment end; required for `uitdienst` |
| `status` | string | yes | — | status | Lifecycle: actief / inactief / uitdienst |
| `emergencyContactName` | string | no | — | noodcontact naam | |
| `emergencyContactTelephone` | string | no | — | noodcontact telefoon | |
| `emergencyContactRelation` | string | no | — | noodcontact relatie | e.g. partner, ouder |

**Calculated fields** (read-only, derived by OpenRegister engine):

| Calculated Property | Formula | Notes |
|---|---|---|
| `retentionExpiresAt` | `endDate + 7 years` | Null when endDate is null |
| `dienstjaren` | `floor((today - startDate) / 365.25)` | Years of service |
| `retentionExpired` | `retentionExpiresAt < today` | Boolean flag for AVG destruction review |

### Register + Schema Structure

```
Register: hrmq
  Schema: Employee
    slug: employee
    source: lib/Settings/hrmq_register.json
```

## BSN Encryption Design

BSN handling is the only custom PHP service in this change.

**Class:** `OCA\Hrmq\Service\BsnEncryptionService`

**Encryption:** AES-256-CBC via PHP `openssl_encrypt`  
**Key storage:** `IAppConfig` with sensitive flag — key never in config UI or logs  
**Key derivation:** HKDF-SHA256 from master secret configured at install  
**IV:** Random 16-byte IV prepended to ciphertext, base64-encoded for storage  

**Integration with OpenRegister:**  
The service hooks into OR's object lifecycle via an event listener registered in
`lib/AppInfo/Application.php`:
- `BeforeObjectSavedEvent` → encrypt `bsn` field → replace with `bsnEncrypted`
- `AfterObjectLoadedEvent` → decrypt `bsnEncrypted` → expose as `bsn` for permitted users only

**Masking:** Frontend receives a masked token (`•••••{last3}`) for display; full decrypt
is returned only when the requesting user has `bsn:read` permission.

**Key rotation:** Out of scope for this change. Tracked as a future `bsn-key-rotation` change.

## Declarative-vs-Imperative Decision (ADR-031)

| Behaviour | Decision | Rationale |
|---|---|---|
| Employee status lifecycle (`actief → inactief → uitdienst`) | **Declarative** — `x-openregister-lifecycle` in `hrmq_register.json` | OR lifecycle engine provides audit trail, RBAC-per-state, CloudEvents, and terminal-state enforcement at no code cost |
| `retentionExpiresAt` (endDate + 7y) | **Declarative** — `x-openregister-calculations` | Pure derived field; no side effects; OR calculates on read |
| `dienstjaren` (years of service) | **Declarative** — `x-openregister-calculations` | Pure date arithmetic; OR expressions cover this |
| `retentionExpired` flag | **Declarative** — `x-openregister-calculations` | Boolean derived from `retentionExpiresAt < @today` |
| BSN encryption/decryption | **Imperative** — `BsnEncryptionService` | OR has no `x-openregister-encryption` extension. ADR-031 exception type 1: OR extension missing. Issue to be opened on openregister repo. |
| IBAN field-level access | **Declarative** — `x-permissions` on schema property | PropertyRbacHandler handles field-level RBAC from schema metadata |

## Reuse Analysis (ADR-001)

The following OpenRegister platform services are used directly — no custom equivalents built:

| Platform Capability | OR Service / Component | Used For |
|---|---|---|
| CRUD + pagination | `ObjectService.saveObject()`, `findAll()` | Employee create, update, list |
| Schema-driven forms | `CnFormDialog` | New employee form, edit form |
| Detail view | `CnDetailPage` + `CnDetailCard` | Employee detail page |
| List view | `CnIndexPage` + `CnDataTable` | Employee index |
| Audit trail | `AuditTrailService` (automatic) | Change history per employee |
| Field RBAC | `PropertyRbacHandler` | BSN and IBAN visibility |
| GDPR data requests | `inzageverzoek()`, `verwerkingsregister()` | AVG compliance |
| Retention policy | `RetentionService` | 7-year retention window |
| Full-text search | `IndexService` + `CnFilterBar` | Employee search |
| Object store | `createObjectStore('employee')` | Pinia store with CRUD |

No custom Entity, Mapper, or search controller is written.

## Seed Data (ADR-001)

Five fictional-but-realistic employee objects for dev/test. BSN values pass the
Nederlandse elfproef. Street names are real Dutch streets; person data is fictional.

BSNs are stored pre-encrypted in `hrmq_register.json` using a fixed dev-mode AES key
(`HRMQ_DEV_BSN_KEY` environment variable, documented in `README.md`).
These objects MUST NOT be loaded in production.

### Employee 1 — Janine de Vries (actief)

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "employee",
    "slug": "medewerker-janine-de-vries"
  },
  "givenName": "Janine",
  "familyName": "de Vries",
  "birthDate": "1987-03-14",
  "gender": "female",
  "nationality": "NL",
  "bsnEncrypted": "<AES256:dev:123456782>",
  "email": "j.devries@fictiefbedrijf.nl",
  "telephone": "06-12345678",
  "streetAddress": "Keizersgracht 45",
  "postalCode": "1015CJ",
  "addressLocality": "Amsterdam",
  "addressCountry": "NL",
  "iban": "NL91ABNA0417164300",
  "startDate": "2019-06-01",
  "status": "actief",
  "emergencyContactName": "Peter de Vries",
  "emergencyContactTelephone": "06-87654321",
  "emergencyContactRelation": "partner"
}
```

### Employee 2 — Mohammed Al-Hassan (actief)

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "employee",
    "slug": "medewerker-mohammed-al-hassan"
  },
  "givenName": "Mohammed",
  "familyName": "Al-Hassan",
  "birthDate": "1991-09-22",
  "gender": "male",
  "nationality": "NL",
  "bsnEncrypted": "<AES256:dev:234567892>",
  "email": "m.alhassan@fictiefbedrijf.nl",
  "telephone": "06-23456789",
  "streetAddress": "Binnenhof 12a",
  "postalCode": "2513AA",
  "addressLocality": "Den Haag",
  "addressCountry": "NL",
  "iban": "NL18RABO0310000001",
  "startDate": "2021-01-15",
  "status": "actief",
  "emergencyContactName": "Fatima Al-Hassan",
  "emergencyContactTelephone": "06-34567890",
  "emergencyContactRelation": "partner"
}
```

### Employee 3 — Sandra Pieters-van Dijk (inactief)

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "employee",
    "slug": "medewerker-sandra-pieters-van-dijk"
  },
  "givenName": "Sandra",
  "additionalName": "van",
  "familyName": "Pieters-van Dijk",
  "birthDate": "1979-11-30",
  "gender": "female",
  "nationality": "NL",
  "bsnEncrypted": "<AES256:dev:345678916>",
  "email": "s.pieters@fictiefbedrijf.nl",
  "telephone": "06-45678901",
  "streetAddress": "Oudegracht 88",
  "postalCode": "3511AP",
  "addressLocality": "Utrecht",
  "addressCountry": "NL",
  "iban": "NL58INGB0000012345",
  "startDate": "2015-03-01",
  "status": "inactief"
}
```

### Employee 4 — Erik Jan Bakker (uitdienst — within retention window)

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "employee",
    "slug": "medewerker-erik-jan-bakker"
  },
  "givenName": "Erik Jan",
  "familyName": "Bakker",
  "birthDate": "1975-05-08",
  "gender": "male",
  "nationality": "NL",
  "bsnEncrypted": "<AES256:dev:456789121>",
  "email": "ej.bakker@fictiefbedrijf.nl",
  "telephone": "06-56789012",
  "streetAddress": "Coolsingel 103",
  "postalCode": "3011AG",
  "addressLocality": "Rotterdam",
  "addressCountry": "NL",
  "iban": "NL36TRIO0212345678",
  "startDate": "2010-09-01",
  "endDate": "2023-12-31",
  "status": "uitdienst",
  "emergencyContactName": "Lies Bakker",
  "emergencyContactTelephone": "06-67890123",
  "emergencyContactRelation": "partner"
}
```

_Calculated: `retentionExpiresAt` = 2030-12-31 (within window at time of seed)_

### Employee 5 — Fatima Yilmaz (actief)

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "employee",
    "slug": "medewerker-fatima-yilmaz"
  },
  "givenName": "Fatima",
  "familyName": "Yilmaz",
  "birthDate": "1995-07-19",
  "gender": "female",
  "nationality": "NL",
  "bsnEncrypted": "<AES256:dev:876543219>",
  "email": "f.yilmaz@fictiefbedrijf.nl",
  "telephone": "06-78901234",
  "streetAddress": "Vrijthof 7",
  "postalCode": "6211LA",
  "addressLocality": "Maastricht",
  "addressCountry": "NL",
  "iban": "NL69BUNQ2025457408",
  "startDate": "2023-04-01",
  "status": "actief",
  "emergencyContactName": "Mehmet Yilmaz",
  "emergencyContactTelephone": "06-89012345",
  "emergencyContactRelation": "vader"
}
```

## API Surface

All CRUD via OpenRegister REST API. No custom controller needed.

```
GET    /index.php/apps/hrmq/api/employees          # list (paginated, filterable)
POST   /index.php/apps/hrmq/api/employees          # create
GET    /index.php/apps/hrmq/api/employees/{id}     # get single
PUT    /index.php/apps/hrmq/api/employees/{id}     # update
DELETE /index.php/apps/hrmq/api/employees/{id}     # delete (AVG retention check)
```

## Register Schema Skeleton (`hrmq_register.json`)

```json
{
  "x-openregister": { "type": "application", "version": "1.0.0" },
  "components": {
    "registers": {
      "hrmq": { "title": "HRMQ", "description": "HR Management Qua" }
    },
    "schemas": {
      "employee": {
        "title": "Employee",
        "description": "Personal record (NAW, BSN, IBAN, AVG)",
        "type": "object",
        "required": ["givenName", "familyName", "birthDate", "startDate", "status"],
        "properties": { "...see data model table above..." },
        "x-openregister-lifecycle": {
          "field": "status",
          "states": ["actief", "inactief", "uitdienst"],
          "initial": "actief",
          "transitions": [
            { "from": "actief",    "to": "inactief",  "label": "Op non-actief zetten" },
            { "from": "inactief",  "to": "actief",    "label": "Heractiveren" },
            { "from": "actief",    "to": "uitdienst", "label": "Uit dienst" },
            { "from": "inactief",  "to": "uitdienst", "label": "Uit dienst" }
          ],
          "terminal": ["uitdienst"]
        },
        "x-openregister-calculations": [
          {
            "field": "retentionExpiresAt",
            "type": "date",
            "expression": "addYears(@self.endDate, 7)",
            "condition": "@self.endDate != null"
          },
          {
            "field": "dienstjaren",
            "type": "integer",
            "expression": "floor(dateDiff(@self.startDate, today(), 'years'))"
          },
          {
            "field": "retentionExpired",
            "type": "boolean",
            "expression": "@self.retentionExpiresAt != null && @self.retentionExpiresAt < today()"
          }
        ]
      }
    }
  }
}
```
