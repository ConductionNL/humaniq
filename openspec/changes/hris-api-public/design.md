# Design — hris-api-public

## Context

**Verified against HEAD 2026-07-17**, reading the actual sibling `openregister` app checkout
(`apps-extra/openregister/`, not hrmq's own code — hrmq owns no controller in this area, ADR-022):

- `appinfo/routes.php:718-733` — the real, live route table: `GET /api/objects/{register}/{schema}`
  (index), `POST /api/objects/{register}/{schema}` (create), `GET/PUT/PATCH/DELETE
  /api/objects/{register}/{schema}/{id}` (show/update/patch/destroy), plus
  `.../{id}/can-delete`, `.../{id}/merge`, `.../export`, `.../geo-search`. This is not a future
  surface — it is the exact API the hrmq Vue frontend itself calls for every declarative manifest
  page (ADR-031's own architecture: "Uses OpenRegister API directly from the frontend, no own
  backend CRUD" — `openspec/config.yaml`'s own `rules.design` entry).
- `lib/Controller/ObjectsController.php:1120` (`index()`), `:2415` (`show()`), `:2612` (`create()`)
  — all reachable via the route table above; the class carries `@NoAdminRequired`/`@NoCSRFRequired`
  docblock annotations throughout (confirmed by grepping the file directly), meaning any
  authenticated Nextcloud principal can call these methods — not merely an interactive session.
  Nextcloud's own platform-level auth stack accepts a session cookie, an OIDC/SAML bearer token
  (if configured), or Basic Auth with an **app password** (Settings › Personal › Security › "App
  passwords", Nextcloud core, present on every instance) for any non-public route — this is a
  platform mechanism, not something hrmq or OpenRegister implements.
- `ObjectsController.php:916-925` — `SEC-CTRL-1` comment block: `$query['_rbac'] = ($isAdmin ===
  false)` — RBAC enforcement is derived server-side from the caller's actual admin status, not
  trusted from request input. `:947` — `RenderHandler::redactWriteOnlyFromRows(rows: $results,
  _rbac: $query['_rbac'] ?? true)` — the `writeOnly`/`_render:false` secret-stripping boundary
  (the fleet's own `or-writeonly-render-boundary` lesson) applies to this exact path, for any
  caller, RBAC-gated or not.
- `openspec/changes/hrmq-mcp-adoption/design.md` — "OpenRegister RBAC remains the authoritative
  gate at invoke time: the dialect declares *what a tool is*, never *who may call it*." Written
  about the MCP-derived tool layer, but the statement is equally true, word for word, of a raw
  REST caller: the MCP allow-list (6 schemas) narrows what an *LLM agent* can reach; it does not
  narrow what a *REST caller with sufficient RBAC* can reach. The two surfaces are independent —
  confirmed by reading `hrmq-mcp-adoption`'s own scope: it edits `configuration.x-openregister-mcp`
  blocks only, never touches `ObjectsController` or RBAC configuration.
- `grep -rn "x-openregister-rbac\|rbac" lib/Settings/register.d/*.json` (hrmq's own worktree) — no
  match. RBAC grants are configured at the OpenRegister/Nextcloud-admin level (per-user/per-group
  ACLs on a register or schema), not declared inside any hrmq schema fragment. hrmq has no
  mechanism to *write* an RBAC grant even if this change wanted one to.
- ADR-022 (apps consume OpenRegister's abstractions) and `openspec/config.yaml`'s own
  `rules.proposal` entry: "HRMQ owns no backend CRUD; HR objects live in the OpenRegister `hrmq`
  register" — the standing architectural rule this change's Non-Goals directly enforce against the
  draft's REST v1/GraphQL/webhooks/SCIM stack.

## Goals / Non-Goals

**Goals:** state plainly that the CRUD/pagination/filtering/auth/RBAC substance of "a public HRIS
API" is already delivered by OpenRegister; document that fact for an external-integrator audience;
add the one genuine gap — a governance catalog so HR/security can see which external systems have
been granted access, for what purpose, reviewed when — extending `hrmq-mcp-adoption`'s privacy
discipline from the LLM surface to the wider REST surface.

**Non-Goals (binding, from the proposal):** a parallel REST v1 stack; GraphQL; webhooks/event-
streaming; SCIM provisioning; a custom API-key/scope/rate-limit system;
`IntegrationAccount.grantedSchemas` as an enforced access-control list (it cannot be — D2).

## Decisions

### D1 — The API is delivered; the governance catalog is the actual delta

Every REQ-001-style scenario in the draft ("an external system calls `GET /employees` with a
Bearer token and receives a paginated, RBAC-filtered response") is already true today, verbatim in
substance, against `GET /api/objects/hrmq/Employee` with Basic Auth and an app password (Context).
Writing hrmq code to reproduce that would be pure duplication. What hrmq genuinely lacks is
**visibility**: today, if an NC administrator grants a payroll-partner's integration account RBAC
read access to `Payslip`, nothing in hrmq records that this happened, why, or when it was last
reviewed — exactly the gap `hrmq-mcp-adoption`'s own design.md would call out if it existed for
REST instead of MCP. `IntegrationAccount` closes that visibility gap. It is deliberately NOT an
access-control schema (D2) — it is an audit/governance record, the same category of object
`Administration`/`AdministrationAccess` (`multi-administratie`) already established for a
different axis (tenant access, not external-system access).

### D2 — grantedSchemas cannot enforce anything; stated as guidance, not a gate

hrmq's schema fragments (`lib/Settings/register.d/*.json`) have no RBAC-declaring block (Context —
confirmed by grep). RBAC is configured entirely at the OpenRegister/Nextcloud-admin layer, outside
any file this app owns. `IntegrationAccount.grantedSchemas` therefore **cannot** be the mechanism
that grants access — it can only be a **record of intent**, filled in when an admin manually
configures the matching RBAC grant elsewhere. This design states that limitation in the schema's
own property description (gate-28 discipline) so a future reader does not mistake the catalog for
an enforcement point — the same honesty `avg-dsr` D1 applies to storing `employeeId` instead of a
raw `bsn` ("the low-sensitivity, stable linkage", not the sensitive value itself).

### D3 — Recommended schema subset reuses hrmq-mcp-adoption's AVG reasoning, not a fresh analysis

`hrmq-mcp-adoption` already did the hard work of classifying all 23 schemas against AVG art. 9
(special categories), the BSN's Wet BSN regime, and purpose limitation, arriving at a 6-schema
read-only allow-list (`Vacancy`, `OrgUnit`, `Asset`, `AssetAssignment`, `Timesheet`, `Expense`).
That reasoning is not specific to LLM agents — it is specific to "what may leave hrmq's boundary
toward an external, less-trusted consumer", which describes a third-party REST integration exactly
as well as it describes an MCP tool call. Re-deriving the same analysis from scratch for REST would
either reach the same six (redundant work) or a different set (an unexplained, unjustified
divergence). This design reuses the existing analysis by reference, as README guidance for the
"what should a new `IntegrationAccount` request by default" question, rather than re-litigating it.

### D4 — No custom credential system: Nextcloud app passwords are the credential

The draft's REQ-001 designs a bespoke API-key model (`Authorization: Bearer <api-key>`, scopes,
`expires_at`, rate-limit tiers). Nextcloud already ships app passwords — a credential scoped to one
NC user account, revocable from Settings › Personal › Security, usable as HTTP Basic Auth against
any non-public authenticated route (Context) — precisely the "a non-interactive client needs
durable API access" primitive the draft was reinventing. `IntegrationAccount.nextcloudUserId`
names *which* NC account (typically a dedicated service account, not a real employee's) an external
system authenticates as; the app password itself is issued and revoked through Nextcloud's own
Settings UI, never hrmq code — the same "Nextcloud already owns this layer" finding
`irma-digid-auth` reaches for authentication generally.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `IntegrationAccount` record shape | declarative schema, no lifecycle | a plain audit/governance record; `status` is a simple enum an admin edits directly, no guarded transition needed |
| CRUD/filter/pagination for every hrmq schema | OpenRegister's existing `ObjectsController` | zero new code — the abstraction this change documents rather than duplicates (ADR-022) |
| RBAC enforcement | OpenRegister's existing engine, admin-configured | outside hrmq's schema fragments entirely (D2) |
| Recommended schema subset | README guidance text | not a schema constraint — cannot be enforced from hrmq (D2/D3) |
| Index/detail pages | declarative manifest | ADR-031 default |

## Seed Data (ADR-001)

One `IntegrationAccount` seed: a fictitious payroll-partner integration (`name: "Voorbeeld
Payroll Partner BV"`, `purpose: "Maandelijkse export van vacatures en organisatie-eenheden naar
partnerplatform"`, `nextcloudUserId: "integration-payrollpartner"`, `grantedSchemas: ["Vacancy",
"OrgUnit"]`, `status: actief`, `reviewedBy: "admin"`, `reviewedAt` within the last quarter) —
demonstrates the catalog shape and the recommended-subset guidance in practice (both named
schemas are in `hrmq-mcp-adoption`'s own six-schema allow-list).

Dev-container verification gate: after seed import, the `IntegrationAccounts` index page shows the
one seeded record; `GET /api/objects/hrmq/Vacancy` (with an admin session, proving the endpoint is
live) returns a normal paginated response — confirming the documented API contract matches the
actually-running one, not merely the design's description of it.

## Risks / Trade-offs

- **`IntegrationAccount` can drift from the real RBAC configuration** (D2) — nothing keeps
  `grantedSchemas` synchronised with the actual OpenRegister grant; the catalog is only as accurate
  as the admin's manual discipline in keeping both in sync. Named, not hidden — the same trust
  boundary `Loonbeslag.beslagvrijeVoet`/`EmploymentContract.writtenContract` already accept for
  other HR-entered facts.
- **No enforcement means a misconfigured RBAC grant is invisible to this catalog** — if an admin
  grants broader access than `IntegrationAccount.grantedSchemas` states, nothing in hrmq detects
  the mismatch. A future audit tool cross-checking OpenRegister's actual RBAC config against this
  catalog is a real, named possibility, not built here (would need an OpenRegister-side read API
  for RBAC grants that this design has not verified exists).
- **Recommending hrmq-mcp-adoption's six schemas as a REST default (D3) is a judgement call, not a
  hard rule** — a legitimate integration (e.g. a payroll partner) genuinely needs `Payslip`/
  `Employee` access the MCP allow-list deliberately excludes; the guidance names this explicitly
  ("any wider grant requires an explicit, documented reason") rather than pretending the six-schema
  set is universally correct for every integration purpose.

## Open Questions

- None blocking. A future OpenRegister-side RBAC-audit cross-check is named as a possible fast-
  follow (Risks), not a blocker for this change's own scope.
