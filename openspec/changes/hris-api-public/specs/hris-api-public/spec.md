# Delta — hris-api-public

Modernises the 2026-05 `spec/hris-api-public` draft (a bespoke REST v1 + GraphQL + webhooks + SCIM
stack) against current HEAD with the honest finding that the CRUD/pagination/filtering/RBAC
substance of "a public HRIS API" is **already delivered** by OpenRegister's `ObjectsController`
(`GET/POST/PUT/PATCH/DELETE /api/objects/{register}/{schema}`, authenticated via standard
Nextcloud app passwords, RBAC-enforced server-side). The genuine delta is a small governance
catalog — `IntegrationAccount` — extending `hrmq-mcp-adoption`'s privacy-review discipline from the
LLM tool surface to the wider, ungated REST surface, plus documentation of the API contract that
already exists.

## ADDED Requirements

### Requirement: hrmq SHALL NOT build a parallel REST, GraphQL, webhook, or SCIM API stack (REQ-HRIS-001)

hrmq SHALL document OpenRegister's existing `/api/objects/{register}/{schema}` REST surface (GET index, POST create, GET/PUT/PATCH/DELETE by id) as its public HRIS API contract. This change SHALL add no new hrmq route, controller, or service implementing object CRUD, filtering, pagination, GraphQL resolution, webhook dispatch, or SCIM provisioning.

#### Scenario: No duplicate API surface exists in the diff
- **GIVEN** this change's full diff
- **WHEN** it is searched for any new file under `lib/Controller/` or `appinfo/routes.php` additions implementing object CRUD
- **THEN** none exists — the only new PHP-adjacent artefact is the `IntegrationAccount` schema fragment

#### Scenario: The documented endpoint matches the live one
- **GIVEN** the README's "Public HRIS API" section
- **WHEN** `GET /api/objects/hrmq/Vacancy` is called with a valid authenticated Nextcloud session or app password
- **THEN** it returns a paginated JSON response, exactly as documented, served entirely by OpenRegister's `ObjectsController` with no hrmq code in the request path

### Requirement: External integrator authentication SHALL use standard Nextcloud app passwords; no custom credential system SHALL be built (REQ-HRIS-002)

hrmq's documentation SHALL describe Nextcloud app passwords (Settings › Personal › Security, Basic Auth) as the credential mechanism for external HRIS integrations. This change SHALL NOT introduce a bespoke API-key, token-scope, or rate-limit-tier system.

#### Scenario: No custom credential schema or service exists
- **GIVEN** this change's full diff
- **WHEN** it is searched for any schema or service implementing API-key issuance, scopes, or rate limiting
- **THEN** none exists

#### Scenario: Documentation names the real credential mechanism
- **GIVEN** the README's "Public HRIS API" section
- **WHEN** it describes how an external system authenticates
- **THEN** it names Nextcloud app passwords issued via Settings › Personal › Security, not a custom hrmq-issued key

### Requirement: An IntegrationAccount catalog SHALL record which external systems have HRIS access, for governance and audit, without enforcing that access (REQ-HRIS-003)

`lib/Settings/register.d/hr-integrations.json` SHALL define an `IntegrationAccount` schema carrying `name`, `purpose`, `nextcloudUserId` (plain string, never a `$ref`), `grantedSchemas` (string array), `status` (enum `actief`/`ingetrokken`, default `actief`), `reviewedBy`, `reviewedAt`, and `createdAt`. The schema description for `grantedSchemas` SHALL state explicitly that it is an informational record of intent and does NOT itself grant or enforce access — the actual access-control grant is configured through OpenRegister's RBAC mechanism, outside this schema.

#### Scenario: A catalog record validates
- **GIVEN** the imported hrmq register
- **WHEN** an `IntegrationAccount` object is created with `name`, `purpose`, `nextcloudUserId`, and `grantedSchemas`
- **THEN** the object validates and `status` defaults to `actief`

#### Scenario: The catalog does not gate the underlying API
- **GIVEN** an `IntegrationAccount` with `status: ingetrokken` (revoked) and `grantedSchemas: ["Vacancy"]`
- **WHEN** the associated Nextcloud account's app password is still active and RBAC-granted at the OpenRegister level
- **THEN** `GET /api/objects/hrmq/Vacancy` still succeeds for that account — the `IntegrationAccount` record alone changes nothing about actual access; revoking access requires revoking the app password and/or the RBAC grant, a fact the schema's description states

### Requirement: Recommended external-access defaults SHALL reuse hrmq-mcp-adoption's AVG-grounded schema classification, not a new analysis (REQ-HRIS-004)

hrmq's documentation SHALL recommend, as the default starting grant for a new `IntegrationAccount`, the same six read-only, non-special-category schemas `hrmq-mcp-adoption` already vetted (`Vacancy`, `OrgUnit`, `Asset`, `AssetAssignment`, `Timesheet`, `Expense`), and SHALL state that any wider grant (e.g. access to `Payslip`, `Employee`, or any schema carrying BSN/IBAN/health/special-category data) requires an explicit, documented reason recorded in `IntegrationAccount.purpose`.

#### Scenario: Documentation cites the existing classification, not a fresh one
- **GIVEN** the README's recommended-subset guidance
- **WHEN** it is read
- **THEN** it names the same six schemas `hrmq-mcp-adoption`'s design.md allow-lists, with a direct reference to that change rather than a restated AVG analysis

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
