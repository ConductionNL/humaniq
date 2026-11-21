---
capability: hris-api-public
status: done
built_by: openspec/changes/archive/2026-09-07-hris-api-public
---

# hris-api-public Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [hris-api-public](../../changes/archive/2026-09-07-hris-api-public/) _(archived 2026-09-07)_ — humaniq builds no parallel REST/GraphQL/webhook/SCIM stack; external access is Nextcloud app passwords over OpenRegister's own API, catalogued by a new IntegrationAccount schema under Configuratie (kind: config)
- [matrix-rows-without-a-requirement](../../changes/archive/2026-11-21-matrix-rows-without-a-requirement/) _(archived 2026-11-21)_ — adds the requirements above that the HR matrix rows named without one

## Purpose

Give external systems access to HR data through the existing API, and keep a record of who
has it. humaniq builds no API of its own. External systems call the OpenRegister object API the
humaniq frontend already uses, and log in with a standard Nextcloud app password. The
`IntegrationAccount` catalog records which external systems have access, so an administrator can
review and audit it. The catalog records access. It does not grant or block it.

Modernises the 2026-05 `spec/hris-api-public` draft (a bespoke REST v1 + GraphQL + webhooks + SCIM
stack) against current HEAD with the honest finding that the CRUD/pagination/filtering/RBAC
substance of "a public HRIS API" is **already delivered** by OpenRegister's `ObjectsController`
(`GET/POST/PUT/PATCH/DELETE /api/objects/{register}/{schema}`, authenticated via standard
Nextcloud app passwords, RBAC-enforced server-side). The genuine delta is a small governance
catalog — `IntegrationAccount` — extending `humaniq-mcp-adoption`'s privacy-review discipline from the
LLM tool surface to the wider, ungated REST surface, plus documentation of the API contract that
already exists.

## Requirements

### Requirement: humaniq SHALL NOT build a parallel REST, GraphQL, webhook, or SCIM API stack (REQ-HRIS-001)

humaniq SHALL document OpenRegister's existing `/api/objects/{register}/{schema}` REST surface (GET index, POST create, GET/PUT/PATCH/DELETE by id) as its public HRIS API contract. This change SHALL add no new humaniq route, controller, or service implementing object CRUD, filtering, pagination, GraphQL resolution, webhook dispatch, or SCIM provisioning.

#### Scenario: No duplicate API surface exists in the diff
- **GIVEN** this change's full diff
- **WHEN** it is searched for any new file under `lib/Controller/` or `appinfo/routes.php` additions implementing object CRUD
- **THEN** none exists — the only new PHP-adjacent artefact is the `IntegrationAccount` schema fragment

#### Scenario: The documented endpoint matches the live one
- **GIVEN** the README's "Public HRIS API" section
- **WHEN** `GET /api/objects/humaniq/Vacancy` is called with a valid authenticated Nextcloud session or app password
- **THEN** it returns a paginated JSON response, exactly as documented, served entirely by OpenRegister's `ObjectsController` with no humaniq code in the request path

### Requirement: External integrator authentication SHALL use standard Nextcloud app passwords; no custom credential system SHALL be built (REQ-HRIS-002)

humaniq's documentation SHALL describe Nextcloud app passwords (Settings › Personal › Security, Basic Auth) as the credential mechanism for external HRIS integrations. This change SHALL NOT introduce a bespoke API-key, token-scope, or rate-limit-tier system.

#### Scenario: No custom credential schema or service exists
- **GIVEN** this change's full diff
- **WHEN** it is searched for any schema or service implementing API-key issuance, scopes, or rate limiting
- **THEN** none exists

#### Scenario: Documentation names the real credential mechanism
- **GIVEN** the README's "Public HRIS API" section
- **WHEN** it describes how an external system authenticates
- **THEN** it names Nextcloud app passwords issued via Settings › Personal › Security, not a custom humaniq-issued key

### Requirement: An IntegrationAccount catalog SHALL record which external systems have HRIS access, for governance and audit, without enforcing that access (REQ-HRIS-003)

`lib/Settings/register.d/hr-integrations.json` SHALL define an `IntegrationAccount` schema carrying `name`, `purpose`, `nextcloudUserId` (plain string, never a `$ref`), `grantedSchemas` (string array), `status` (enum `actief`/`ingetrokken`, default `actief`), `reviewedBy`, `reviewedAt`, and `createdAt`. The schema description for `grantedSchemas` SHALL state explicitly that it is an informational record of intent and does NOT itself grant or enforce access — the actual access-control grant is configured through OpenRegister's RBAC mechanism, outside this schema.

#### Scenario: A catalog record validates
- **GIVEN** the imported hrmq register
- **WHEN** an `IntegrationAccount` object is created with `name`, `purpose`, `nextcloudUserId`, and `grantedSchemas`
- **THEN** the object validates and `status` defaults to `actief`

#### Scenario: The catalog does not gate the underlying API
- **GIVEN** an `IntegrationAccount` with `status: ingetrokken` (revoked) and `grantedSchemas: ["Vacancy"]`
- **WHEN** the associated Nextcloud account's app password is still active and RBAC-granted at the OpenRegister level
- **THEN** `GET /api/objects/humaniq/Vacancy` still succeeds for that account — the `IntegrationAccount` record alone changes nothing about actual access; revoking access requires revoking the app password and/or the RBAC grant, a fact the schema's description states

### Requirement: Recommended external-access defaults SHALL reuse humaniq-mcp-adoption's AVG-grounded schema classification, not a new analysis (REQ-HRIS-004)

humaniq's documentation SHALL recommend, as the default starting grant for a new `IntegrationAccount`, the same six read-only, non-special-category schemas `humaniq-mcp-adoption` already vetted (`Vacancy`, `OrgUnit`, `Asset`, `AssetAssignment`, `Timesheet`, `Expense`), and SHALL state that any wider grant (e.g. access to `Payslip`, `Employee`, or any schema carrying BSN/IBAN/health/special-category data) requires an explicit, documented reason recorded in `IntegrationAccount.purpose`.

#### Scenario: Documentation cites the existing classification, not a fresh one
- **GIVEN** the README's recommended-subset guidance
- **WHEN** it is read
- **THEN** it names the same six schemas `humaniq-mcp-adoption`'s design.md allow-lists, with a direct reference to that change rather than a restated AVG analysis

#### Scenario: A wider grant is documented, not silently allowed
- **GIVEN** the seeded `IntegrationAccount` example
- **WHEN** its `grantedSchemas` is limited to `["Vacancy", "OrgUnit"]` (within the recommended default)
- **THEN** it requires no special justification in `purpose` beyond its stated business reason; a hypothetical future record naming `Payslip` would be expected to state why in `purpose`, per the documented guidance

### Requirement: The IntegrationAccount catalog SHALL be reachable under Configuratie › Integraties, an existing reserved placement (REQ-HRIS-005)

`src/manifest.json` SHALL expose `IntegrationAccounts` (index) and `IntegrationAccountDetail` (data + audit sidebar) as admin-only pages under the existing `Configuratie › Integraties` sub-page, a slot ADR-001 already reserves and which no prior capability has claimed.

#### Scenario: The Integraties slot was previously empty
- **GIVEN** `src/manifest.json` before this change
- **WHEN** it is searched for "Integraties"
- **THEN** no match exists — confirming this change is the first to occupy the reserved slot

#### Scenario: IntegrationAccounts is reachable after this change
- **GIVEN** the manifest after this change
- **WHEN** an admin navigates to `Configuratie › Integraties`
- **THEN** an `IntegrationAccounts` entry is present, and the top-level menu count is unchanged from ADR-001's frozen 9 (8 menus + Configuratie)

### Requirement: An integrator SHALL read and write HR records through the objects API with an app password (REQ-HRIS-006)

An external system SHALL read and write humaniq records through OpenRegister's objects API at
`/api/objects/humaniq/{schema}`, signed in with a Nextcloud app password of a service account.
Every call SHALL get exactly the access that account has in the app: the same role checks, the
same field access and the same administration scope as a person signed in with that account.
humaniq SHALL document the calls an integrator needs for the employee and the contract.

Rows: `plt-public-api` (humaniq matrix).

#### Scenario: A payroll bureau reads an employee

@e2e exclude Needs a service account in humaniq-payroll with an app password; covered by tests/Integration/IntegratorObjectsApiTest, run with phpunit-integration.xml against a live instance.
- **GIVEN** a service account in the payroll group with an app password
- **WHEN** the bureau calls `GET /api/objects/humaniq/Employee/{id}` with that app password
- **THEN** the response holds the employee with the fields the payroll role may read

#### Scenario: An identity manager updates a work e-mail address

@e2e exclude Needs a service account in humaniq-hr with an app password; covered by tests/Integration/IntegratorObjectsApiTest (PATCH and the audit trail naming the account).
- **GIVEN** a service account in the HR group with an app password
- **WHEN** it calls `PATCH /api/objects/humaniq/Employee/{id}` with a new work e-mail address
- **THEN** the employee carries the new address and the audit trail names the service account

#### Scenario: An account without the role gets nothing
- **GIVEN** a service account in no humaniq group
- **WHEN** it calls `GET /api/objects/humaniq/Employee`
- **THEN** no employee is returned
