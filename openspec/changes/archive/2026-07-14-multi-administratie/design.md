# Design — multi-administratie

## Context

**Verified against HEAD 2026-07-15.** The strategic driver is ADR-001 Rule 3
(`openspec/architecture/adr-001-information-architecture.md`): *"Multi-administratie surfaces as an
active-administratie indicator in the topbar plus a switch. Every page in the app is implicitly
scoped to the active administratie. Menus are NOT duplicated per administratie … the
multi-administratie spec itself lives as a `SETTING` under `Configuratie › Administraties` and never
adds menu items."* This design realises exactly that, on the app's real code.

Real code the design is grounded in (all read at HEAD):

- **`administrationId` today** — a plain-string, `$ref`-free field, present and **required** ONLY on
  the payroll aggregate objects: `PayrollRun` + `PayrollMutationReport` (`hr-objects.json`),
  `PayrollGLPost` (`hr-glpost.json`), `PayrollPaymentBatch` (`hr-paybatch.json`). Its own
  description states the modelling law reused here: *"No Administration schema is modeled in hrmq —
  plain string, not a `$ref` relation (ADR-062 rule 7: only fields whose target exists in this
  register set get `$ref`)."* Every other schema in the register — Employee, EmploymentContract,
  Payslip, Timesheet, Expense, LeaveRequest, LeaveBalance, SickLeaveCase, Onboarding, Vacancy,
  Application, OrgUnit, OrgAssignment, AttendanceRecord, Asset, AssetAssignment, ReviewCycle,
  PerformanceReview, PensionFiling, LoonaangifteFiling — carries **no** `administrationId`.
- **The manifest filter-token vocabulary is CLOSED** (`nextcloud-vue/src/utils/sentinelTokens.js`):
  the `filter` partition is exactly `^@(?:me|now|today|monthStart|quarterStart|yearStart)$|^@today[+-][0-9]+d$`.
  A unit test asserts byte-equality with the `$defs` patterns mirrored into
  `app-manifest-v2.schema.json`, so an out-of-vocabulary token is REJECTED by manifest validation and
  cannot be invented app-side.
- **`@workspace.<key>` and `@config.<key>`** (`resolveFilterTokens.js`): `@workspace.<key>` resolves
  page-level workspace state another widget writes — the resolver docstring's own example is *"a
  selected client"* — with a trailing `?` marking it optional (unset → drop the filter key, show
  all). It is PAGE-scoped and resets on navigation. `@config.<key>` resolves an `IAppConfig` value —
  per-instance, not per-user. Neither is an app-global per-user active tenant.
- **`runtime.user`** (`app-manifest-v2.schema.json` top-level `runtime`): per-user context the
  backend injects at serve time, against which `visibleIf` context-path predicates resolve. This is
  the injection point for a per-user `activeAdministrationId`.
- **Base-filter mechanism** — `MijnUren` et al. carry a fixed `filter: { "userId": "@me" }` that
  composes with a page's user-facing `defaultFilters`/`filters` (proven throughout the manifest).
- **The denormalized-field precedent** — `mijn-hr-self-service` (`userId`) and `mss-team-scope`
  (`managerUserId`) both hit the identical wall: the manifest filter grammar cannot join across
  schemas nor reach the OpenRegister object owner/`@self` metadata, so each shipped a **denormalized
  plain-string field** + an `@me`-style token + a recommended-severity corpus consistency rule that
  keeps the denormalization from rotting. Multi-administratie is the same problem on the tenant axis.
- **Guard precedent** — `DocumentController::generate` / `PayrollController::calculate`: resolve the
  posted id under the caller's ambient RBAC BEFORE acting; unknown and unauthorized collapse to 404.
- **RuleAuditService related-context** — `buildRelatedContext()` pre-loads cross-object indexes once
  and degrades to empty; the `nl-mss-manager-consistency` predicate rides it.

## Goals / Non-Goals

**Goals:** a catalog of administraties + a per-user access model; a persisted, access-guarded
per-user active-administration selection; every list/detail page implicitly scoped to it via a
denormalized `administrationId` + a first-class `@administration` filter token; the ADR-001 Rule 3
topbar indicator + switch under `Configuratie › Administraties`; a consistency rule keeping the
denormalization honest; seeds that demonstrate the switch and the isolation.

**Non-Goals (binding):** hard cross-administratie isolation as a security boundary (scoping ≠
permission — the OpenRegister-organisation hardening path is named, not built); the topbar renderer
chrome slot (upstream nc-vue); ZZP/DGA + eenmanszaak mode-switches (ADR-001 Rule 4, sibling axis);
per-administratie CAO/config overrides; cross-administratie consolidated reporting.

## Decisions

### D1 — Scope on a denormalized plain-string `administrationId`, never on owner/parent metadata

The manifest filter grammar can scope a page only on a literal field of the listed object. It cannot
reach the OpenRegister object `owner`/`@self` metadata (unreachable from a manifest filter) and it
cannot two-hop (record → Employee → the employee's administration) — the exact walls
`mss-team-scope` verified for the manager axis. Therefore the active administratie MUST live as a
**denormalized, optional, nullable plain-string `administrationId`** on every scoped schema — never a
`$ref` (it names an administratie key, not an object, and it reuses the existing PayrollRun field
verbatim), maintained by HR/back-office when a record is created. The consistency rule (D6) surfaces
staleness record by record; the trade-off is the same one `userId`/`managerUserId` already accepted
and documented. Only the payroll aggregates carry it today; this change extends it to the other 20
schemas with a version bump each. `Administration` (D2) is the ONE schema that is a real object with
`administrationId` as its own business key — the switcher's catalog — but child objects still hold a
**copy of the string**, not a `$ref` to it, so no fleet-wide relation churn and no two-hop lookups.

### D2 — Administration catalog + AdministrationAccess membership are declarative schemas

- **`Administration`** (new fragment `hr-administratie.json`): `administrationId` (business key,
  required), `name`, `kvkNumber`, `loonheffingennummer`, `active`. The switcher lists the active
  ones; the topbar indicator reads `name`. It is the catalog only — child scoping stays on the
  denormalized string (D1).
- **`AdministrationAccess`**: `userId` (denormalized NC user id, plain string — the app's user-link
  convention), `administrationId`, `role` (`accountant` | `hr` | `employee`). One membership row per
  (user, administratie). An accountant user has many rows — their client book; a company HR user has
  one. This is the closed set the setter (D4) validates against and the switcher (D5) may offer.
  Membership is a plain declarative object (ADR-001 data-layer: domain data → OpenRegister objects),
  NOT NC groups — an accountant's client book is app data, not an instance-admin concern.

### D3 — `@administration` is a new first-class member of the CLOSED filter-token vocabulary (upstream)

Because the `filter` token set is closed and schema-validated (Context), the tenant token cannot be
invented in the app's manifest. It is added upstream in `@conduction/nextcloud-vue`:
`utils/sentinelTokens.js` (`filter` pattern gains `@administration`), the mirrored `$defs` pattern in
`app-manifest-v2.schema.json` (the byte-equality unit test updated), and `resolveFilterTokens.js`
resolves `@administration` (and the optional `@administration?`) to the caller's active
`administrationId`, sourced from the manifest `runtime.user.activeAdministrationId` the backend
injects at serve time (the `runtime.user` mechanism `visibleIf` already uses). This is a named
upstream dependency — precisely how `mss-team-scope` named the missing two-hop join token rather than
faking it. **MVP fallback if the token beta is not yet consumable:** the switcher writes the active
id into the page `cnWorkspaceContext` and pages filter on `@workspace.activeAdministrationId?` (the
resolver's own "selected client" example) — page-scoped, resetting on navigation, and honestly
documented as the interim until the app-global token lands.

### D4 — The active-administration selection is per-user, persisted, and access-guarded

The active administratie is **per-user session-ish state**, not domain data and not per-instance
config — so it is stored as a per-user `IConfig` user value keyed on the app id (NOT an
`IAppConfig` instance value, whose granularity is wrong, and NOT an OpenRegister object, which would
itself need scoping). `AdministrationController::setActive` (`POST /api/administration/active`,
`#[NoAdminRequired]`) MUST resolve the posted `administrationId` to one of the caller's own
`AdministrationAccess` rows (via ObjectService under the caller's ambient RBAC) BEFORE storing it —
unknown / not-accessible collapse to 404 (the DocumentController guard). A user can therefore never
activate an administratie they have no membership in. `GET /api/administration/context` returns
`{ activeAdministrationId, administrations: [...accessible] }` for the switcher and the topbar
indicator, and the backend stamps `runtime.user.activeAdministrationId` from the same source so the
`@administration` token (D3) and `visibleIf` see one consistent value.

### D5 — Every list/detail page is implicitly scoped; the topbar switch lives under Configuratie

- **Implicit scope**: every index and detail page in `src/manifest.json` gains the fixed base
  `filter: { "administrationId": "@administration?" }`, composing with the existing `@me`/`status`
  base filters and the pages' `defaultFilters` (the `MijnUren` mechanism). The optional `?` form
  means an unset selection shows all — so a fresh install and a single-administratie tenant are
  unchanged, and HR with no active pick keeps the global view (the `mss-team-scope` HR-fallback
  spirit). Detail pages scope their FK-child object-lists the same way, ANDed with the existing
  `{ parentId: "@objectId" }` filter.
- **The switch**: ADR-001 Rule 3 wants a topbar indicator + switch. The manifest v2 renderer chrome
  does not expose a topbar-switcher slot today (the known v2 renderer chrome gap), so the MVP surface
  is a `Configuratie › Administraties` page (ADR-001 Rule 3's mandated `SETTING` home) carrying the
  switcher (`GET/POST /api/administration/*`) and the catalog list, plus a Dashboard
  administratie-context widget showing the active administratie and offering the switch. NO top-level
  menu entry is added (Rule 3). The true topbar chrome slot is the named renderer fast-follow. The
  `Configuratie` (menu 9) drawer itself is owned by the active `hrmq-ia-navigation-alignment` change;
  this change lands its Administraties page under that drawer without re-declaring it (clean union).

### D6 — `nl-administratie-scope-consistency` keeps the denormalization honest (imperative CheckProvider)

A recommended-severity cross-object rule (`NlAdministratieChecks`, riding
`RuleAuditService::buildRelatedContext()` — the `nl-mss-manager-consistency` precedent): a child
object's denormalized `administrationId` SHOULD equal the one derivable from its parent — a Payslip's
equals its `PayrollRun`'s (`payrollRunId` resolves the run's `administrationId`); an
Employee-anchored object's equals that Employee's `administrationId`. **Violates** only when both
sides resolve to non-empty values that differ; **vacuous (passes)** when either side is absent, the
parent is unresolvable, or the field is empty — it reports *provable* inconsistency, never punishes
un-backfilled data. Severity recommended (visible, non-blocking) so a mid-migration register does not
turn red. The related-context pre-pass adds an `administratie` index (`byId`) and a parent index
(runsById reuse where present) — one degrade-to-empty load, no per-object IO.

### D7 — RBAC and the accountant multi-client access model

- **Who sees which administratie**: the `AdministrationAccess` rows for the caller's `userId` (D2).
  The switcher (D5) offers exactly those; the setter (D4) refuses anything outside them.
- **Accountant multi-client**: an accountant user carries many access rows and switches active tenant
  between client administraties from one seat — the wedge. An `hr` user carries the one row of their
  employer; an `employee` sees their own administratie (their `Mijn HR` surface already scopes on
  `userId: @me`, and now additionally on the active administratie).
- **Honest boundary (scoping ≠ permission)**: the manifest `administrationId` filter is a SCOPING
  convenience, NOT an authorization boundary — the identical caveat `mss-team-scope` documented. A
  determined user could still read another administratie's objects through the raw OpenRegister API,
  because OpenRegister enforces its own RBAC (register/schema/object owner), which this change does
  NOT partition by administratie. **True isolation** = mapping each `administrationId` onto an
  OpenRegister **organisation/tenant** (`_multitenancy`, `TenantLifecycleService` — hydra ADR-001's
  "use OpenRegister organisations, NO custom multi-tenancy logic"), so a cross-administratie read is
  refused server-side regardless of the UI. That is the named hardening fast-follow; the MVP enforces
  access at the switcher and the guarded setter and states the gap plainly (README + rule).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Administration catalog + AdministrationAccess membership | **declarative** schemas (new fragment) | ADR-031 default; domain data → OpenRegister objects (ADR-001 data-layer) |
| Denormalized `administrationId` on every scoped schema | **declarative** schema property + version bump | the only shape the manifest filter grammar can scope on (D1); the `userId`/`managerUserId` precedent |
| Implicit page scoping | **declarative** manifest base `filter` + `@administration?` token | ADR-001 Rule 3 "every page implicitly scoped"; the `MijnUren` base-filter mechanism |
| The `@administration` filter token | **declarative direction**, added to the CLOSED nc-vue vocabulary (upstream) | out-of-vocabulary tokens are schema-rejected — it cannot be an app-side invention (D3) |
| Per-user active-administration selection + access guard | **imperative** controller/service (`IConfig` user value, ObjectService access check) | per-user session state is not domain data and not per-instance config; the guard is the DocumentController no-admin-idor pattern (D4/D7) |
| Scope-consistency check | **imperative** CheckProvider (`NlAdministratieChecks`) | a JSON schema cannot express a cross-object parent-vs-child comparison — the established ADR-031 exception (D6) |
| Related-context indexes for the check | **imperative** pre-pass in `RuleAuditService::buildRelatedContext()` | the established related-context mechanism, extended consistently |
| Topbar switch chrome | renderer (upstream) / MVP Configuratie page + Dashboard widget | the v2 renderer chrome gap (D5) |

## Seed Data (ADR-001)

The seed set makes the switch and the isolation demonstrable and evaluates the consistency rule
non-vacuously, following the ADR-001 seed markers (editorial schemas → `example-` slug prefix,
`Example:` title, `**Example — seed data**` banner):

- **Two `Administration` rows**: `example-adm-001` (`administrationId: "ADM-001"`, name
  "Example: Conduction Demo B.V.") and `example-adm-002` (`administrationId: "ADM-002"`, name
  "Example: Tweede Klant B.V."). ADM-001 is the administratie the whole existing seed world already
  references (the seeded `payrollrun-2026-05/-06` carry `administrationId: "ADM-001"`).
- **`AdministrationAccess` rows**: grant the dev `admin` account (`userId: "admin"`) `role:
  accountant` on BOTH ADM-001 and ADM-002 — so the demo account is the multi-client accountant and
  the switch has two real targets. A single-administratie `hr` row is seeded as the shape example.
- **ADM-001 backfill**: every existing core-entity seed (employee-jansen, timesheets, expenses,
  leave, onboarding, org units/assignments, assets, reviews, payslips, filings) gets
  `administrationId: "ADM-001"` so the existing world is a coherent single administratie under the
  new axis and the consistency rule passes on it.
- **A small ADM-002 set**: one Employee (`example-employee-adm002`) + one Timesheet + one Payslip
  under `administrationId: "ADM-002"`, so switching to ADM-002 shows a genuinely different, isolated
  world and the isolation is visible in the UI (not merely asserted). No seeded violation
  deliberately (the `mss-team-scope` / `org-chart` convention — a wrong seed would corrupt the demo
  to light a recommended lamp); the violating and each vacuous path are pinned by unit tests instead.

## Risks / Trade-offs

- **Scoping is not a security boundary (D7).** The headline risk: a UI that looks tenant-isolated but
  is not enforced server-side. Mitigated by naming it in the README + rule and by the guarded setter
  (a user cannot even *activate* an administratie they lack access to); resolved only by the
  OpenRegister-organisation hardening fast-follow.
- **Denormalization staleness (D1/D6).** Re-assigning an employee to another administratie leaves
  stale `administrationId` copies on their child records until HR restamps — surfaced record by
  record by the recommended-severity consistency rule, exactly as `managerUserId` staleness is.
- **Upstream token dependency (D3).** The clean app-global scope needs the `@administration` token
  merged + a beta bump in nc-vue; until then the `@workspace` fallback is page-scoped. The dependency
  is explicit, not hidden.
- **20 schema version bumps.** Adding the field touches most fragments; each is an additive,
  non-breaking optional property (ADR-001 schema-standards) — a re-import, no data migration.

## Open Questions

- None blocking. The topbar renderer chrome slot and the OpenRegister-organisation isolation
  hardening are named fast-follows; the `Configuratie` drawer coordination with
  `hrmq-ia-navigation-alignment` is a clean union (both add children, neither re-declares the group).
