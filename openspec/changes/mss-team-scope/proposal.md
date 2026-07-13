---
kind: config
---

# MSS Team Scope (manager self-service scoped by the org structure)

## Why

The Spectr canon insight `hrmq-canon-mss-team-scope` scores manager self-service team scoping at **3/9 competitive coverage** — a differentiating gap in the Dutch-SMB segment — and hrmq now owns both halves of the story without connecting them: round 2 shipped the org structure (`org-chart-basic`: `OrgUnit.managerId` + effective-dated `OrgAssignment`) and `mijn-hr-self-service` shipped the approver surface, but that surface is **global** — the Dashboard's "Te beoordelen uren/declaraties" widgets and the `TimesheetApproval`/`ExpenseApproval`/`LeaveApproval` pages count and list *every* employee's submitted records. A team lead sees the whole company's queue; there is no "mijn team" anywhere. ADR-001 Rule 2 fixes where the fix goes: manager self-service is **Dashboard widgets plus scoped acties inside existing menus** — never a "Manager portaal" top-level and never a sibling app.

The investigation (design.md, "Scoping mechanism (investigated)") re-verified against the vendored manifest schema that the round-2 conclusion still holds at HEAD: the filter-token grammar (`@me`, `@now`, `@today±Nd`, `@monthStart`/`@quarterStart`/`@yearStart` — `sentinelFilterToken` in `app-manifest-v2.schema.json`) has **no two-hop/join form**, so a manifest page cannot filter records to "employees whose active OrgAssignment's unit is managed by @me". The honest MVP is therefore the same one-hop denormalization trade-off `mijn-hr-self-service` made for `userId`: an optional `managerUserId` property on the approval-carrying schemas, populated by HR/back-office alongside `userId`, kept honest by a machine-checkable consistency rule that cross-checks it against the org structure.

## What Changes

- **Denormalized manager scoping on the approval schemas** — new optional `managerUserId` property (plain NC-user-id string, never a `$ref`, the `userId` convention) on `Timesheet`, `Expense` and `LeaveRequest`. HR/back-office maintains it alongside `userId`; it names the NC account of the employee's manager, so team pages and widgets can filter it with `@me`. Schema version bumps; register version bump.
- **Three team-scoped approval pages** ("scoped acties inside existing menus", ADR-001 Rule 2): `TeamUrengoedkeuring`, `TeamDeclaratiegoedkeuring`, `TeamVerlofgoedkeuring` — each the existing approval page pre-filtered with a fixed base filter `{ "managerUserId": "@me" }` plus the approval pages' `defaultFilters: { "status": "submitted" }`, added as menu children next to the existing global approval entries in `Uren`, `Onkosten` and `Verlof & verzuim`. **No new top-level menu** and no "Manager portaal".
- **Dashboard approver widgets re-scoped** — `dash-approve-hours` and `dash-approve-expenses` change from global submitted-counts to `{ "managerUserId": "@me", "status": "submitted" }` and deep-link to the new team pages; a new team-scoped leave widget completes the trio; and a new **global fallback row for HR** restores exactly the two global counts the re-scope removed (deep-linking to the existing global approval pages) — adjusting the round-2 widgets in place, not duplicating them.
- **Corpus rule `nl-mss-manager-consistency`** (severity `recommended`, machine-checkable): a record's `managerUserId` SHOULD equal the `nextcloudUserId` of the manager (`OrgUnit.managerId`) of the record's employee's *active* `OrgAssignment` unit — vacuous whenever any hop of that chain is absent (no managerUserId, no active placement, unmanaged unit, unresolvable manager, manager without an NC account). Enforced by extending `NlOrgChecks` (it owns the `hr-org-core` org-integrity predicates and the OrgUnit index) with predicates on the three record types, fed by consistently-extended `RuleAuditService::buildRelatedContext()` indexes. `RuleCatalogue::VERSION` bumps `2026-07.5` → `2026-07.6`.
- **Seed data** — stamp `managerUserId: "admin"` on the three existing submitted-status seeds (`timesheet-jansen-2026-05`, `expense-jansen-hotel`, `leave-jansen-zomer`) and re-point `orgunit-consultancy.managerId` from `employee-devries` to `employee-jansen` (whose `nextcloudUserId` is `admin`), so the dev-instance `admin` login demos a populated team queue AND the consistency rule evaluates non-vacuously green on seed data.

### Non-goals

- **No manager portal / top-level menu** — ADR-001 Rule 2 hard rule; the team surface is widgets + scoped pages inside existing groups.
- **No two-hop manifest filtering** — does not exist in the token grammar (re-verified at HEAD); building it belongs to nc-vue/OpenRegister, not to an app manifest. If a join-token ever lands, `managerUserId` can be retired the way any denormalization is.
- **No automatic `managerUserId` stamping** — same limitation as round-2 `userId`: the renderer has no create-form token defaults, so population is seed + back-office maintained. The consistency rule is exactly the guard-rail that makes the manual population auditable.
- **No `managerUserId` on Payslip/AttendanceRecord/SickLeaveCase** — no manager-approval surface exists for those records; scoping fields nobody filters would be dead data.
- **No org-derived approval authorization** — `NoSelfApprovalGuard` semantics are untouched; `managerUserId` scopes *lists and counts*, it grants nothing (publish/RBAC stays OpenRegister's).

## Capabilities

### New Capabilities

- `mss-team-scope`: the denormalized `managerUserId` scoping property on Timesheet/Expense/LeaveRequest, the three team-scoped approval pages, the re-scoped Dashboard approver widgets + HR fallback row, and the `nl-mss-manager-consistency` corpus rule with its `NlOrgChecks` predicates and audit-context extensions.

### Modified Capabilities

<!-- none — org-chart-basic's schemas/rules/pages are untouched; the one seed VALUE this change re-points (orgunit-consultancy.managerId) is documented as a deliberate supersession of the archived org-chart-basic seed fixture (REQ-MSS-006), not a change to that spec's requirements -->

## Impact

- `lib/Settings/register.d/hr-timesheet.json` — `Timesheet`: add `managerUserId`; version 0.3.0 → 0.4.0.
- `lib/Settings/register.d/hr-expense.json` — `Expense`: add `managerUserId`; version 0.2.0 → 0.3.0.
- `lib/Settings/register.d/hr-leave.json` — `LeaveRequest`: add `managerUserId`; version 0.2.0 → 0.3.0.
- `lib/Settings/hrmq_register.json` — register `info.version` 0.5.0 → 0.6.0.
- `src/manifest.json` — 3 new index pages, 3 new menu children (inside existing groups), Dashboard widget re-scope + 1 team-leave widget + 2 HR-fallback widgets + layout re-flow.
- `lib/Standards/rules/labour.json` — 1 new rule (`nl-mss-manager-consistency`, framework `hr-org-core` — an existing slug, so no `SCHEMA.md` edit); `RuleCatalogue::VERSION` → `2026-07.6`.
- `lib/Standards/Checks/NlOrgChecks.php` — 3 new object-type keys sharing one manager-consistency predicate.
- `lib/Service/RuleAuditService.php` — `buildRelatedContext()`: OrgUnit index gains `managerId`, Employee index gains `nextcloudUserId`, new `OrgAssignment.byEmployeeId` index.
- `lib/Settings/register.d/hr-seed.json` — 3 `managerUserId` stamps + 1 seed-value re-point.
- `tests/Unit/` — PHPUnit coverage for the predicate and the context extensions.
- Related active changes: `verzuim-analytics-widgets` (sibling; also appends Dashboard widgets — whichever lands second union-merges `config.widgets`/`config.layout` and re-flows `gridY`), `hrmq-ia-navigation-alignment` (owns any later menu re-homing; this change follows the current group placement), `hrmq-rule-compliance-enforcement` (its audit exit-gate keys on `mandatory` severity — the new `recommended` rule never trips it).
