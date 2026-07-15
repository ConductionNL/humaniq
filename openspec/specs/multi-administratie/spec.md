---
capability: multi-administratie
status: done
built_by: openspec/changes/archive/2026-07-14-multi-administratie
---

# multi-administratie Specification

**Status**: done (hrmq side complete; automatic per-page scoping awaits an upstream nextcloud-vue token — see Delivered scope)
**Scope**: hrmq (`kind: code+config`) — the accountant multi-client / multi-company tenant model.
Reuses the `PayrollRun` plain-string `administrationId` convention (ADR-062 rule 7: never a `$ref`),
the `userId`/`managerUserId` denormalization precedent, and ADR-001 Rule 3 (tenant switch, no menu
duplication). Adds zero payroll-engine logic.
**OpenSpec changes**:
- [multi-administratie](../../changes/archive/2026-07-14-multi-administratie/) _(archived 2026-07-14)_ —
  Administration + AdministrationAccess schemas, a denormalized `administrationId` rolled out across
  the HR/payroll schemas, an access-guarded per-user active-administratie selection
  (`GET/POST /api/administration/*`), a `Configuratie › Administraties` switcher, a
  `nl-administratie-scope-consistency` corpus rule, and seeds proving the switch.

## Purpose

Let one hrmq instance carry multiple administraties (companies/clients) — the dominant NL
distribution wedge, where one accountant's office runs payroll for many SMBs (Nmbrs, Loket and
Employes all monetize this per-payslip). The tenant axis is a denormalized plain-string
`administrationId` on every scoped object plus a per-user active-administratie pointer, selected
behind an access guard, with a machine-checkable consistency rule keeping the denormalization honest.

## Requirements

- **REQ-MULTI-001** — Every scoped HR/payroll schema carries an optional, nullable, plain-string
  `administrationId` (never a `$ref`), because the manifest filter grammar cannot reach OpenRegister
  owner/`@self` metadata or two-hop from a record to its employee's administration. Rolled out to
  Employee, EmploymentContract, Payslip, Timesheet, Expense, LeaveRequest, LeaveBalance,
  SickLeaveCase, Onboarding, Vacancy, Application, OrgUnit, OrgAssignment, AttendanceRecord, Asset,
  AssetAssignment, ReviewCycle, PerformanceReview, PensionFiling, LoonaangifteFiling (the payroll
  aggregates already carried it). **Delivered.**
- **REQ-MULTI-002** — An `Administration` catalog (name/KvK/loonheffingennummer/active) and an
  `AdministrationAccess` membership (userId → administratie, role accountant|hr|employee) model the
  tenant axis. **Delivered.**
- **REQ-MULTI-003** — The active administratie is a per-user selection persisted behind an
  access-guarded setter: `POST /api/administration/active` resolves the posted id to a caller
  `AdministrationAccess` row *before* storing (unknown/inaccessible → 404); routes precede the SPA
  catch-all. **Delivered.**
- **REQ-MULTI-004** — Every list and detail page is implicitly scoped to the active administratie via
  a base `administrationId` filter. **Partially delivered / upstream-blocked:** the hrmq side is
  ready, but the automatic per-page filter requires the `@administration` token (REQ-MULTI-005) and
  is deferred until that token lands upstream. Until then the `?`-optional default shows all
  accessible administraties (no regression for single-administratie installs).
- **REQ-MULTI-005** — The `@administration` filter token is a first-class member of the CLOSED
  nextcloud-vue token vocabulary. **Upstream-blocked:** the token lives in
  `nextcloud-vue/src/utils/{sentinelTokens,resolveFilterTokens}.js` + the mirrored manifest-schema
  `$defs`; it cannot be invented app-side (that fails `check:manifest`). Filed as a nextcloud-vue
  follow-up together with stamping `runtime.user.activeAdministrationId` into the served manifest.
- **REQ-MULTI-006** — The switch lives under `Configuratie › Administraties` (a switcher SFC backed by
  `GET/POST /api/administration/*` + a catalog list), adding no top-level menu (ADR-001 Rule 3).
  **Delivered** (the Dashboard-widget `runtime.user` visibleIf wiring is deferred with the upstream
  cluster).
- **REQ-MULTI-007** — `nl-administratie-scope-consistency` (recommended severity, auto-discovered by
  the RuleEngine, vacuous when `administrationId` is absent) flags a child whose administratie
  disagrees with its parent; and scoping is documented as **NOT a security boundary** (hard
  per-administratie OpenRegister-organisation isolation is a named security fast-follow).
  **Delivered.**
- **REQ-MULTI-008** — Seeds demonstrate the switch and the isolation: two `Administration` rows
  (ADM-001/ADM-002), `AdministrationAccess` granting the admin accountant access to both, existing
  core-entity seeds backfilled to ADM-001 plus a small isolated ADM-002 set. **Delivered.**

## Delivered scope vs upstream follow-up

The entire hrmq side ships and is gate-green (PHPUnit 526/526, `check:manifest` PASS, `npm run build`
exit 0): the tenant schemas, the denormalized `administrationId` rollout, the guarded
active-administratie endpoints + service, the `Configuratie › Administraties` switcher, the
consistency rule, and seeds. What remains is a single, well-scoped nextcloud-vue change — add
`@administration` to the closed filter-token vocabulary + resolver, stamp
`runtime.user.activeAdministrationId` into the served manifest, and add the per-page
`filter: { administrationId: "@administration?" }` — tracked as a follow-up issue. Scoping is a
convenience layer, **never** a security boundary.
