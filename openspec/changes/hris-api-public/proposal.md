---
kind: config
---

# Public HRIS API — mostly already delivered by OpenRegister; the genuine delta is governance, not CRUD

## Why

The 2026-05-23 draft `spec/hris-api-public` designed a custom REST v1 (cursor pagination,
OpenAPI 3.1, API-key scopes, per-tier rate limiting) **plus** a GraphQL endpoint **plus** webhooks
**plus** SCIM provisioning — a second, parallel API stack, hand-written inside humaniq. **Verified
against HEAD 2026-07-17, reading the actual sibling `openregister` app**
(`apps-extra/openregister/appinfo/routes.php` + `lib/Controller/ObjectsController.php`): humaniq
**already has** a general-purpose, RBAC-enforced REST API over every one of its 23 schemas, and it
is not aspirational — it is the same API the humaniq frontend itself calls. `ObjectsController`
exposes `GET /api/objects/{register}/{schema}` (index, filterable/paginated), `POST
/api/objects/{register}/{schema}` (create), `GET/PUT/PATCH/DELETE
/api/objects/{register}/{schema}/{id}`, each `@NoAdminRequired`/`@NoCSRFRequired` — reachable by
any authenticated Nextcloud principal, including one authenticated via a standard Nextcloud app
password (Basic Auth), Nextcloud's own built-in mechanism for exactly this "a non-interactive
client needs API access" case. RBAC is enforced inside the controller (`$query['_rbac'] =
($isAdmin === false)`, `RenderHandler::redactWriteOnlyFromRows()` strips `_render:false` secret
fields even for a caller with read access) — the same boundary `humaniq-mcp-adoption`'s design.md
already names as authoritative: **"OpenRegister RBAC remains the authoritative gate at invoke
time... the dialect declares what a tool is, never who may call it."** That statement is equally
true of a raw REST caller: whoever an NC administrator grants RBAC read access to a schema, for
whatever purpose, can already call this API today, with zero humaniq code.

Building the draft's REST v1 + GraphQL + webhooks + SCIM stack would violate ADR-022 (apps consume
OpenRegister's abstractions, they do not rebuild CRUD) at a scale this codebase has never
attempted, duplicate pagination/filtering/RBAC logic OpenRegister already gets right, and — because
it would be a **second, parallel API surface** — actually **weaken** the privacy discipline
`humaniq-mcp-adoption` just established for the fleet's sharpest privacy case (BSN, IBAN,
grossMonthlySalary, sick-leave, candidate PII across 23 schemas). The honest finding: **the CRUD
substance of "give us an API" is already delivered.** The genuine, small gap is that nothing in
humaniq documents this fact for an external integrator, and nothing lets HR/security see, in one
place, *which* external systems have been granted access to *what* — the same governance question
`humaniq-mcp-adoption` answers for LLM tool exposure has no answer at all for ordinary REST
integration exposure, which is the **wider-reaching** of the two surfaces (no allow-list gates it).

## What Changes

- **Documentation only, for the API itself**: `README.md`/`docs/` gain a "Public HRIS API" section
  naming the actual OpenRegister endpoint pattern (`/api/objects/{register}/{schema}`, the five
  CRUD verbs, filter/pagination query parameters), the auth mechanism (Nextcloud app passwords —
  Settings › Personal › Security › App passwords, Basic Auth), and the RBAC model (an NC
  administrator grants a dedicated integration account read/write access per register/schema; the
  API enforces it server-side, including automatic `writeOnly` secret redaction). No new humaniq
  route, controller, or service — this section documents OpenRegister's existing surface as it
  applies to humaniq's schemas.
- **NEW `IntegrationAccount` catalog schema** (`lib/Settings/register.d/hr-integrations.json`) — a
  governance/audit record, not an authorization mechanism: `name` (the external system's name),
  `purpose` (free text — why access was granted), `nextcloudUserId` (string, the dedicated NC
  account used for this integration's app password — plain string per the `approvedBy` convention,
  never a `$ref`), `grantedSchemas` (string array — the schemas this integration is *intended* to
  read/write, informational, not enforcing: the actual grant lives in OpenRegister's own RBAC
  configuration, outside humaniq's schema fragments), `status` (enum `actief`/`ingetrokken`),
  `reviewedBy`/`reviewedAt` (the periodic access-review record), `createdAt`. This gives HR/security
  a single place to see "which external systems have HRIS access, granted for what, last
  reviewed when" — a question humaniq cannot answer today for the REST surface, exactly as
  `humaniq-mcp-adoption` answers it (via a hard allow-list, enforced) for the LLM tool surface.
- **A documented recommended read-only schema subset for new external HRIS integrations** —
  README guidance, mirroring `humaniq-mcp-adoption`'s own AVG reasoning (Art. 9 special categories,
  BSN/IBAN, purpose limitation): recommend the same non-remunerative, non-special-category schemas
  that change already vetted (`Vacancy`, `OrgUnit`, `Asset`, `AssetAssignment`, `Timesheet`,
  `Expense`) as the default-safe starting point for a new `IntegrationAccount`, with any wider
  grant (e.g. `Payslip` for a payroll-partner integration) requiring an explicit, documented reason
  in `IntegrationAccount.purpose`. This is **guidance text**, not an enforced allow-list — see
  design.md D2 for why humaniq cannot enforce it in code.
- **Manifest**: `IntegrationAccounts` index + `IntegrationAccountDetail` pages under
  `Configuratie › Integraties` — the ADR-001-reserved sub-page slot that no existing capability has
  claimed yet (`grep -n "Integraties" src/manifest.json` — no match today), admin-only.
- **Seed data**: one `IntegrationAccount` seed (a fictitious payroll-partner integration, `status:
  actief`, `grantedSchemas: ["Vacancy", "OrgUnit"]`) demonstrating the catalog shape.

### Non-goals (named fast-follows and exclusions)

- **No REST v1, no OpenAPI publication endpoint, no cursor pagination reimplementation** —
  OpenRegister's `/api/objects/{register}/{schema}` already provides this; duplicating it is
  exactly the ADR-022 violation this change avoids.
- **No GraphQL endpoint** — no grounded need identified; OpenRegister's REST surface plus its
  existing filter grammar covers the "flexible query" use case the draft's REQ-002 wanted; a BI
  tool needing nested joins is a data-warehouse/ETL problem, not an HRIS-API problem.
- **No webhooks/event-streaming** — no event-dispatch infrastructure exists in humaniq for this
  purpose today; a genuinely-scoped webhook change would need its own design, not a paragraph here.
- **No SCIM provisioning** — SCIM manages *Nextcloud user accounts* across systems, which is an
  NC-instance-level identity concern (mirrors the `irma-digid-auth` finding: authentication/
  identity-provisioning is Nextcloud's layer, not a leaf app's); out of scope for the same reason.
- **No custom API-key/scope/rate-limit system** — Nextcloud app passwords + OpenRegister RBAC
  already provide credential issuance and authorization; humaniq inventing a parallel credential type
  would fragment, not strengthen, the security model.
- **`IntegrationAccount.grantedSchemas` does not enforce access** — it cannot: OpenRegister's RBAC
  configuration lives outside humaniq's own `register.d` fragments (no `x-openregister-rbac`-style
  block exists in any humaniq schema, verified by grep), so humaniq has no mechanism to *write* an
  enforceable grant even if it wanted to. This is stated plainly, not glossed (design.md D2).

## Capabilities

### New Capabilities

- `hris-api-public`: the `IntegrationAccount` governance catalog, the documented public-API
  contract (OpenRegister's existing REST surface, described for an external audience), and the
  recommended-subset guidance mirroring `humaniq-mcp-adoption`'s privacy discipline.

### Modified Capabilities

_None._ OpenRegister's `ObjectsController`, RBAC engine, and `RenderHandler` are read, not
modified — this change adds zero lines to any OpenRegister file. `humaniq-mcp-adoption` is referenced
as the governance precedent this change extends to the wider REST surface; none of its
requirements change.

## Impact

- `lib/Settings/register.d/hr-integrations.json` — NEW (`IntegrationAccount` schema).
- `lib/Settings/humaniq_register.json` — `info.version` bump (new fragment).
- `src/manifest.json` — `IntegrationAccounts`/`IntegrationAccountDetail` pages under `Configuratie
  › Integraties`; `npm run check:manifest` passes.
- `lib/Settings/register.d/hr-seed.json` — 1 `IntegrationAccount` seed.
- `README.md` / `docs/` — the "Public HRIS API" section (endpoint pattern, auth, RBAC model,
  recommended schema subset) — the majority of this change's actual content.
- No `lib/Controller/`, `lib/Service/`, or `appinfo/routes.php` change — no humaniq API surface is
  added; the "API" this change documents is OpenRegister's, already live.
- Related: the superseded `spec/hris-api-public` draft branch is the source material; its REST v1/
  GraphQL/webhooks/SCIM scope is recorded above as either already-delivered-by-abstraction or a
  distinct, unbuilt capability, not silently dropped. `humaniq-mcp-adoption` (active) owns the
  privacy-discipline precedent this change's recommended-subset guidance extends.
