---
kind: code+config
---

# Multi-administratie — one hrmq install, many client administraties, one active tenant

## Why

Research pass 2 names multi-administratie the **dominant NL distribution wedge**: Nmbrs, Loket and
Employes all monetise the accountant channel (per-payslip pricing), where one accountancy or
administratiekantoor runs the payroll of dozens of client companies from a single seat. hrmq cannot
compete for that channel while every install is single-company. The bones already exist: ADR-001
Rule 3 ("Multi-administratie is tenant-switch, geen menu-prefix") mandates an active-administratie
indicator in the topbar plus a switch, with every page implicitly scoped to the active administratie
and menus never duplicated per tenant. And `administrationId` is already a modelled, **required**
plain-string field on the payroll AGGREGATE objects — `PayrollRun`, `PayrollMutationReport`
(hr-objects.json), `PayrollGLPost` (hr-glpost.json) and `PayrollPaymentBatch` (hr-paybatch.json) —
carried deliberately as a string, not a `$ref`, because "No Administration schema is modeled in
hrmq" (the field's own description, ADR-062 rule 7).

What is missing is the other 90%: the **core HR entities carry no `administrationId` at all**
(Employee, EmploymentContract, Payslip, Timesheet, Expense, LeaveRequest, LeaveBalance,
SickLeaveCase, Onboarding, Vacancy, Application, OrgUnit, OrgAssignment, AttendanceRecord, Asset,
AssetAssignment, ReviewCycle, PerformanceReview, PensionFiling, LoonaangifteFiling — verified against
HEAD), there is no catalog of administraties, no per-user active-administration selection, no
tenant-scoped page filter, and no access model saying which user may see which administratie. This
change delivers the tenant-switch platform ADR-001 Rule 3 promised, grounded in the app's real
manifest filter grammar and the denormalized-field precedent the app already uses for the identical
"the filter grammar cannot reach owner/parent metadata" problem (`mijn-hr-self-service`'s `userId`,
`mss-team-scope`'s `managerUserId`).

## What Changes

- **NEW `Administration` schema (config)** — a first-class catalog of administraties in a new
  fragment `lib/Settings/register.d/hr-administratie.json`: `administrationId` (the stable business
  key already used as a plain string fleet-wide), `name`, `kvkNumber`, `loonheffingennummer`,
  `active`. This is the switcher's source list and the topbar indicator's label source. It lives as
  a `SETTING` surface under `Configuratie › Administraties` per ADR-001 Rule 3 — it never adds a
  top-level menu entry.
- **NEW `AdministrationAccess` schema (config)** — the accountant multi-client access model: a
  membership row binding a Nextcloud user id (`userId`, denormalized plain string like the app's
  other user links) to an `administrationId` with a `role` (`accountant` | `hr` | `employee`). One
  accountant user gets many rows (their whole client book); a single-company HR user gets one. This
  is the closed set the switcher may offer and the setter guards against.
- **DENORMALIZE `administrationId` onto every HR/payroll schema that lacks it** — an optional,
  nullable plain-string `administrationId` property (NOT a `$ref` — it names an administratie key,
  mirroring the existing PayrollRun convention and the `userId`/`managerUserId` trade-off exactly)
  added to Employee, EmploymentContract, Payslip, Timesheet, Expense, LeaveRequest, LeaveBalance,
  SickLeaveCase, Onboarding, Vacancy, Application, OrgUnit, OrgAssignment, AttendanceRecord, Asset,
  AssetAssignment, ReviewCycle, PerformanceReview, PensionFiling and LoonaangifteFiling, with a
  version bump per schema. This is the ONLY shape the manifest filter grammar can scope on — the
  denormalized-field pattern, because owner-metadata (`@self`/OpenRegister `owner`) filtering is
  unreachable from a manifest filter and two-hop (record → Employee → active administration) joins do
  not exist in the grammar (design.md D1, the mss-team-scope precedent).
- **NEW per-user active-administration selection (code)** — a persisted per-user pointer
  (`IConfig` user value, keyed on the app id) plus a guarded setter
  `POST /api/administration/active` (`AdministrationController::setActive`, `#[NoAdminRequired]`):
  the posted `administrationId` MUST resolve to one of the caller's own `AdministrationAccess` rows
  before it is stored (the DocumentController no-admin-idor guard) — a user can never activate an
  administratie they have no access to. A `GET /api/administration/context` returns the active id +
  the caller's accessible administraties for the switcher and topbar indicator.
- **NEW `@administration` filter sentinel token (code, upstream nc-vue)** — a first-class member of
  the CLOSED `filter` token vocabulary in `@conduction/nextcloud-vue`
  (`utils/sentinelTokens.js` + the mirrored `$defs` in `app-manifest-v2.schema.json` +
  `utils/resolveFilterTokens.js`), resolving to the caller's active `administrationId` from the
  manifest `runtime.user` context the backend injects at serve time. Out-of-vocabulary tokens are
  rejected by the schema today, so the token cannot be invented app-side — it is a named upstream
  dependency (design.md D3), exactly as `mss-team-scope` named the missing two-hop join token.
- **IMPLICIT tenant scoping on every list/detail page (config)** — each index and detail page in
  `src/manifest.json` gains the fixed base `filter: { "administrationId": "@administration?" }`,
  composing with the existing `@me`/`status` base filters and the pages' user-facing `defaultFilters`
  (the `MijnUren` base-filter mechanism). The optional `?` form means "no active selection → show
  all", so a fresh install and a single-administratie tenant behave exactly as today.
- **Topbar active-administratie indicator + switch (config + code)** — ADR-001 Rule 3's topbar
  affordance. Because the manifest v2 renderer chrome does not yet expose a topbar-switcher slot
  (the known v2 renderer chrome gap), the MVP realises the switch as a `Configuratie › Administraties`
  page carrying the switcher (backed by `GET/POST /api/administration/*`) plus a Dashboard
  administratie-context widget; the true topbar chrome slot is the named renderer fast-follow.
- **NEW `nl-administratie-scope-consistency` corpus rule (code)** — a recommended-severity
  cross-object check (a `NlAdministratieChecks` CheckProvider riding `RuleAuditService`'s
  related-context, the `nl-mss-manager-consistency` precedent): a child object's denormalized
  `administrationId` SHOULD equal the one derivable from its parent (a Payslip's equals its
  PayrollRun's; an object linked to an Employee equals that Employee's), vacuous when either side is
  absent — so the denormalization cannot silently rot after a re-assignment.
- **Seed data (config)** — two seeded `Administration` rows (`example-`-prefixed) + the
  `AdministrationAccess` rows granting the dev `admin` account `accountant` access to both, the
  existing `ADM-001` core-entity seeds backfilled with `administrationId: "ADM-001"`, and a small
  second-administratie (`ADM-002`) object set so the switch and the isolation are demonstrable and
  the consistency rule evaluates non-vacuously.

### Non-goals (named fast-follows and exclusions)

- **Hard cross-administratie data isolation as a security boundary.** The manifest filter is
  SCOPING, not authorization (the `mss-team-scope` "scoping ≠ permission" rule): a determined user
  could still read another administratie's objects through the raw OpenRegister API. True isolation
  = mapping `administrationId` onto an OpenRegister organisation/tenant (`_multitenancy`,
  `TenantLifecycleService` — hydra ADR-001 "NO custom multi-tenancy logic"), the named hardening
  fast-follow. The MVP enforces access at the switcher and the guarded setter and documents the gap.
- **The topbar renderer chrome slot** — owned upstream by nc-vue; MVP uses the Configuratie page +
  Dashboard widget.
- **ZZP/DGA and eenmanszaak mode-switches** (ADR-001 Rule 4) — a sibling concern; this change ships
  the tenant axis, not the mode axis.
- **Per-administratie CAO/config overrides, per-administratie RBAC roles beyond the three-role
  membership, cross-administratie consolidated reporting** — post-MVP.

## Capabilities

### New Capabilities

- `multi-administratie`: the Administration catalog + AdministrationAccess membership, the
  denormalized `administrationId` roll-out across every HR/payroll schema, the guarded per-user
  active-administration selection + context endpoints, the `@administration` filter sentinel token
  and the implicit tenant scoping of every list/detail page, the ADR-001 Rule 3 topbar
  indicator + switch (Configuratie › Administraties MVP surface), the scope-consistency corpus rule,
  and the seed data proving the switch and the isolation.

### Modified Capabilities

<!-- none — the payroll aggregate objects already carry administrationId; this change extends the
     axis to the rest of the register and adds the switch, it does not modify existing specs -->

## Impact

- `lib/Settings/register.d/hr-administratie.json` — NEW (Administration + AdministrationAccess).
- `lib/Settings/register.d/hr-objects.json`, `hr-timesheet.json`, `hr-expense.json`, `hr-leave.json`,
  `hr-verzuim.json`, `hr-onboarding.json`, `hr-ats.json`, `hr-org.json`, `hr-attendance.json`,
  `hr-assets.json`, `hr-performance.json`, `hr-pension.json` — the denormalized `administrationId`
  property added to each schema that lacks it, with a per-schema version bump.
- `lib/Settings/register.d/hr-seed.json` — Administration/AdministrationAccess seeds, ADM-001
  backfill, ADM-002 demo set.
- `lib/Controller/AdministrationController.php` — NEW (`setActive` guarded, `context`); route entries
  in `appinfo/routes.php` BEFORE the SPA catch-all.
- `lib/Service/AdministrationService.php` — NEW (per-user active pointer via `IConfig`, access
  resolution against `AdministrationAccess`, ObjectService reads).
- `lib/Standards/Checks/NlAdministratieChecks.php` — NEW; `lib/Service/RuleAuditService.php` —
  related-context enrichment (`administratie.byId` / parent index).
- `lib/Standards/rules/*.json` — the `nl-administratie-scope-consistency` rule statement.
- `src/manifest.json` — per-page base `administrationId` filter, `Configuratie › Administraties`
  page + switcher, Dashboard administratie-context widget, `runtime.user.activeAdministrationId`
  wiring; `npm run check:manifest` passes.
- `@conduction/nextcloud-vue` (upstream) — the `@administration` token in `utils/sentinelTokens.js`,
  the mirrored `$defs` pattern in `app-manifest-v2.schema.json`, resolution in
  `utils/resolveFilterTokens.js`; a beta bump hrmq consumes.
- Coordinated with the active `hrmq-ia-navigation-alignment` change, which owns the
  `Configuratie` (menu 9) drawer this change's Administraties page lands under.
