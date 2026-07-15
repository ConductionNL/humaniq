# Delta — multi-administratie

The tenant-switch platform ADR-001 Rule 3 mandates: a catalog of administraties, a per-user access
model, a persisted access-guarded active-administration selection, implicit tenant scoping of every
list/detail page via a denormalized `administrationId` + a first-class `@administration` filter
token, the topbar indicator + switch under `Configuratie › Administraties`, a scope-consistency
corpus rule, and seeds proving the switch and the isolation.

## ADDED Requirements

### Requirement: Every HR/payroll schema SHALL carry a denormalized plain-string administrationId (REQ-MULTI-001)

Every scoped hrmq schema SHALL carry an optional, nullable, plain-string `administrationId` property that is never a `$ref`, so the manifest filter grammar can scope a page on it.

Because the manifest filter grammar cannot reach the OpenRegister object owner/`@self` metadata and
cannot two-hop from a record to its employee's administration, the active administratie can only be a
denormalized literal field on the object itself — the `userId` / `managerUserId` precedent. The
property SHALL be added to Employee, EmploymentContract, Payslip, Timesheet, Expense, LeaveRequest,
LeaveBalance, SickLeaveCase, Onboarding, Vacancy, Application, OrgUnit, OrgAssignment,
AttendanceRecord, Asset, AssetAssignment, ReviewCycle, PerformanceReview, PensionFiling and
LoonaangifteFiling (the payroll aggregates PayrollRun / PayrollMutationReport / PayrollGLPost /
PayrollPaymentBatch already carry it), each with a schema version bump, and SHALL reuse the existing
plain-string convention verbatim (ADR-062 rule 7: no `$ref`, since no per-object Administration
target is joined). It SHALL remain optional/nullable so the addition is non-breaking and an
un-backfilled record stays valid.

#### Scenario: A Timesheet can be scoped by administration
- **GIVEN** the multi-administratie fragment is imported
- **WHEN** a Timesheet object is read
- **THEN** it carries an optional plain-string `administrationId` (not a `$ref`) that a manifest page
  filter can match on

#### Scenario: The addition is non-breaking
- **GIVEN** a pre-existing Employee seeded before this change with no `administrationId`
- **WHEN** the register re-imports
- **THEN** the object stays schema-valid and `administrationId` is simply absent/null

### Requirement: An Administration catalog and an AdministrationAccess membership SHALL model the tenant axis (REQ-MULTI-002)

The register MUST gain an `Administration` catalog schema and an `AdministrationAccess` membership schema binding a Nextcloud user to an administratie with a role.

`Administration` SHALL carry `administrationId` (the business key already used as a plain string
fleet-wide, required), `name`, `kvkNumber`, `loonheffingennummer` and `active`; it is the switcher's
source list and the topbar indicator's label source, and child objects still hold a copy of the
string key rather than a `$ref` to it (REQ-MULTI-001). `AdministrationAccess` SHALL carry `userId`
(denormalized NC user id, plain string), `administrationId`, and `role` enumerated
`accountant | hr | employee` — one row per (user, administratie); an accountant user carries many
rows (their client book), a company HR user carries one. Both are ordinary OpenRegister objects
(domain data → OpenRegister per ADR-001 data-layer), not Nextcloud groups.

#### Scenario: An accountant has access to multiple administraties
- **GIVEN** an accountant user with AdministrationAccess rows for ADM-001 and ADM-002
- **WHEN** their accessible administraties are resolved
- **THEN** both ADM-001 and ADM-002 are returned, each with role `accountant`

### Requirement: The active administratie SHALL be a per-user selection persisted behind an access-guarded setter (REQ-MULTI-003)

`AdministrationController::setActive` MUST resolve the posted `administrationId` to one of the caller's own AdministrationAccess rows before storing it, and MUST refuse anything outside that set.

The active administratie is per-user state, stored as a per-user `IConfig` value keyed on the app id
(not an `IAppConfig` instance value, not an OpenRegister object). `POST /api/administration/active`
(`#[NoAdminRequired]`) SHALL resolve the posted id under the caller's ambient RBAC and collapse
unknown or not-accessible to `404` (the DocumentController no-admin-idor guard) BEFORE persisting, so
a user can never activate an administratie they have no membership in. The setter SHALL be registered
in `appinfo/routes.php` before the SPA catch-all.

#### Scenario: Activating an accessible administratie succeeds
- **GIVEN** a user with an AdministrationAccess row for ADM-002
- **WHEN** they POST `/api/administration/active` with `administrationId` ADM-002
- **THEN** the per-user active pointer is set to ADM-002 and a success response is returned

#### Scenario: Activating an inaccessible administratie is refused
- **GIVEN** a user with no AdministrationAccess row for ADM-099
- **WHEN** they POST `/api/administration/active` with `administrationId` ADM-099
- **THEN** the response is 404 and the active pointer is unchanged

### Requirement: Every list and detail page SHALL be implicitly scoped to the active administratie (REQ-MULTI-004)

Every index and detail page in `src/manifest.json` SHALL carry a fixed base filter scoping its records to the active administratie, composing with the existing base and default filters.

Each page SHALL gain `filter: { "administrationId": "@administration?" }` ANDed with its existing
`@me` / `status` / `@objectId` base filters (the `MijnUren` base-filter mechanism) and its
user-facing `defaultFilters`. The optional `?` form means an unset selection resolves to show-all —
so a fresh install and a single-administratie tenant behave exactly as before, and menus are NEVER
duplicated per administratie (ADR-001 Rule 3). A `GET /api/administration/context` endpoint SHALL
return the active id plus the caller's accessible administraties for the switcher and topbar.

#### Scenario: A scoped list shows only the active administratie's records
- **GIVEN** ADM-002 is the caller's active administratie and Timesheets exist under both ADM-001 and ADM-002
- **WHEN** the Timesheets index page loads
- **THEN** only the ADM-002 Timesheets are listed

#### Scenario: No active selection shows all records
- **GIVEN** a caller with no active administratie set
- **WHEN** a scoped index page loads
- **THEN** the `@administration?` filter is dropped and records across all administraties are listed

### Requirement: The @administration filter token SHALL be a first-class member of the closed nc-vue vocabulary (REQ-MULTI-005)

The `@administration` token MUST be added to the CLOSED manifest filter-token vocabulary in `@conduction/nextcloud-vue` and resolve to the caller's active administrationId, never invented app-side.

The `filter` token set is a closed, schema-validated vocabulary (`utils/sentinelTokens.js` mirrored
into `app-manifest-v2.schema.json` `$defs`, enforced by a byte-equality unit test), so an
out-of-vocabulary token is rejected by manifest validation. `@administration` (and the optional
`@administration?`) SHALL be added to that `filter` partition and resolved in
`utils/resolveFilterTokens.js` to `runtime.user.activeAdministrationId`, which the hrmq backend
stamps into the served manifest from the per-user active pointer (REQ-MULTI-003). Until the upstream
token beta is consumable, pages MAY interim-scope on `@workspace.activeAdministrationId?` written by
the switcher — a page-scoped fallback that MUST be documented as interim.

#### Scenario: An unknown tenant token is rejected by validation
- **GIVEN** a manifest filter using a token outside the closed vocabulary
- **WHEN** `npm run check:manifest` runs
- **THEN** validation fails, proving the token cannot be invented app-side and must be added upstream

#### Scenario: The token resolves to the active administratie
- **GIVEN** the caller's active administratie is ADM-002 and a page filter uses `@administration`
- **WHEN** the filter is resolved at fetch time
- **THEN** `@administration` resolves to ADM-002 before the OpenRegister request is sent

### Requirement: The topbar indicator and switch SHALL live under Configuratie without adding a menu (REQ-MULTI-006)

The active-administratie switch SHALL surface as a `Configuratie › Administraties` page plus a Dashboard context widget, and SHALL NOT add any top-level menu entry (ADR-001 Rule 3).

ADR-001 Rule 3 mandates a topbar indicator + switch with no per-tenant menu duplication. Because the
manifest v2 renderer chrome does not yet expose a topbar-switcher slot, the MVP SHALL realise the
switch as a `Configuratie › Administraties` page (the Rule 3 `SETTING` home) carrying the switcher
backed by `GET/POST /api/administration/*` and the catalog list, plus a Dashboard
administratie-context widget showing the active administratie and offering the switch. The manifest
MUST validate (`npm run check:manifest`). The true topbar chrome slot is a named renderer
fast-follow; the `Configuratie` drawer is coordinated with `hrmq-ia-navigation-alignment` as a clean
union (neither re-declares the group).

#### Scenario: Switching active administratie re-scopes the app
- **GIVEN** an accountant on the Administraties page with ADM-001 active
- **WHEN** they switch the active administratie to ADM-002
- **THEN** the per-user pointer becomes ADM-002 and subsequently loaded scoped pages show ADM-002's records

#### Scenario: No per-tenant menu is added
- **WHEN** the manifest menu is rendered for a multi-administratie install
- **THEN** the top-level menu is unchanged and administraties are reachable only via the switch

### Requirement: A scope-consistency rule SHALL keep the denormalization honest and scoping SHALL NOT be treated as a security boundary (REQ-MULTI-007)

A recommended-severity `nl-administratie-scope-consistency` rule MUST flag a child object whose administrationId provably differs from its parent's, and the README MUST state that filter scoping is not an authorization boundary.

`NlAdministratieChecks` (riding `RuleAuditService::buildRelatedContext()`, the
`nl-mss-manager-consistency` precedent) SHALL evaluate: a Payslip's `administrationId` SHOULD equal
its PayrollRun's; an Employee-anchored object's SHOULD equal that Employee's. It SHALL **violate**
only when both sides resolve to non-empty differing values and be **vacuous** when either side is
absent or unresolvable — reporting provable inconsistency, never punishing un-backfilled data — at
recommended severity so a mid-migration register does not turn red. The README SHALL document that
the manifest `administrationId` filter is SCOPING, not authorization (the raw OpenRegister API is not
partitioned by administratie), and that true isolation is the named OpenRegister-organisation
(`_multitenancy` / `TenantLifecycleService`) hardening fast-follow.

#### Scenario: A mismatched child administratie is flagged
- **GIVEN** a Payslip with `administrationId` ADM-001 whose PayrollRun carries ADM-002
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-administratie-scope-consistency` violation is reported at recommended severity

#### Scenario: An un-backfilled record is vacuous
- **GIVEN** a Payslip with no `administrationId` yet
- **WHEN** the audit runs
- **THEN** the rule reports no violation for it

### Requirement: Seed data SHALL demonstrate the switch and the isolation (REQ-MULTI-008)

The seed set MUST include two Administration rows, AdministrationAccess granting the dev account accountant access to both, the ADM-001 backfill of existing seeds, and a small isolated ADM-002 set.

Following the ADR-001 seed markers (editorial schemas → `example-` slug prefix, `Example:` title,
`**Example — seed data**` banner), the seeds SHALL add `Administration` rows for ADM-001
("Example: Conduction Demo B.V.") and ADM-002 ("Example: Tweede Klant B.V."); `AdministrationAccess`
rows granting `admin` `role: accountant` on BOTH (making the demo account the multi-client
accountant) plus one `hr` shape row; `administrationId: "ADM-001"` backfilled onto every existing
core-entity seed so the current world is one coherent administratie; and a small ADM-002 set (one
Employee, Timesheet and Payslip) so switching shows a genuinely different, isolated world and the
consistency rule evaluates non-vacuously. No seeded violation is included deliberately.

#### Scenario: Switching to ADM-002 shows its isolated world
- **GIVEN** the seeded dev account with accountant access to ADM-001 and ADM-002
- **WHEN** they activate ADM-002 and open the Employees index
- **THEN** only the ADM-002 employee is listed, demonstrating the isolation
