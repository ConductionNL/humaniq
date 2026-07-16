---
capability: multi-administratie
status: done
built_by: openspec/changes/archive/2026-07-14-multi-administratie
---

# multi-administratie Specification

**Status**: done (automatic per-page scoping delivered — see #64 correction under Delivered scope)
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
  a base `administrationId` filter. **Delivered** (corrected 2026-07-16, #64 — see note below): every
  administration-scoped index/detail page's `filter` carries `administrationId:
  "@workspace.activeAdministrationId?"`; `App.vue` provides a reactive `cnWorkspaceContext` at the SPA
  root (seeded from `IInitialState`/`PageController::index()`, `loadState()` client-side) so the token
  resolves from first paint, and `AdministrationSwitcher.vue` writes into that SAME context on a
  successful switch so every page re-scopes without a reload. The `?`-optional grammar means an unset
  selection (or a single-administratie install) drops the clause and shows all accessible rows — no
  regression, exactly as originally intended.
- **REQ-MULTI-005** — ~~The `@administration` filter token is a first-class member of the CLOSED
  nextcloud-vue token vocabulary.~~ **Superseded — this requirement was a wrong assumption, not a real
  gap (#64).** The original author believed automatic per-page scoping needed a NEW filter token added
  upstream to nextcloud-vue. It did not: nextcloud-vue already ships a general **workspace** context
  (`@workspace.<key>` / `@workspace.<key>?`, `sentinelTokens.js`) for exactly this shape of problem —
  "page-level workspace state (e.g. a selected client)" — and its own vocabulary explicitly deprecates
  single-app token inventions in favour of it (e.g. pipelinq's `@currentFiscalYear` →
  `@workspace.<key>`). Inventing `@administration` would have been the anti-pattern the library
  forbids, not a legitimate follow-up. `cnWorkspaceContext` is a Vue provide/inject bag documented as
  "provided by CnDashboardPage" (page-scoped), but Vue's inject walks the WHOLE ancestor chain — hrmq
  provides it once at its own SPA root (`App.vue`) instead, which makes it available fleet-wide across
  every page type with zero nextcloud-vue change. No upstream issue was ever needed; none is filed.
- **REQ-MULTI-006** — The switch lives under `Configuratie › Administraties` (a switcher SFC backed by
  `GET/POST /api/administration/*` + a catalog list), adding no top-level menu (ADR-001 Rule 3).
  **Delivered** (the Dashboard-widget `runtime.user` visibleIf wiring remains a separate, small,
  named follow-up — unrelated to the #64 correction above; the Dashboard page itself carries no
  `administrationId`-scoped widgets today).
- **REQ-MULTI-007** — `nl-administratie-scope-consistency` (recommended severity, auto-discovered by
  the RuleEngine, vacuous when `administrationId` is absent) flags a child whose administratie
  disagrees with its parent; and scoping is documented as **NOT a security boundary** (hard
  per-administratie OpenRegister-organisation isolation is a named security fast-follow).
  **Delivered.**
- **REQ-MULTI-008** — Seeds demonstrate the switch and the isolation: two `Administration` rows
  (ADM-001/ADM-002), `AdministrationAccess` granting the admin accountant access to both, existing
  core-entity seeds backfilled to ADM-001 plus a small isolated ADM-002 set. **Delivered.**

## Delivered scope

The entire capability ships, gate-green, and is now fully delivered with **zero nextcloud-vue
changes**: the tenant schemas, the denormalized `administrationId` rollout, the guarded
active-administratie endpoints + service, the `Configuratie › Administraties` switcher, the
consistency rule, seeds, AND the automatic per-page scoping (#64) — `filter: { administrationId:
"@workspace.activeAdministrationId?" }` on every administration-scoped index/detail page, a reactive
`cnWorkspaceContext` App.vue provides at the SPA root and seeds via `IInitialState`, and
`AdministrationSwitcher.vue` writing into that same context on switch. Scoping is a convenience layer,
**never** a security boundary — hard per-administratie isolation (mapping onto an OpenRegister
organisation) remains a named security fast-follow, unrelated to #64.

**Correction (2026-07-16, #64):** REQ-MULTI-005's premise — that automatic scoping required a NEW
`@administration` token added upstream to nextcloud-vue — was wrong. It was never filed as an upstream
issue and never will be; nextcloud-vue's existing, general `@workspace.<key>` context covers this
exact case and its own vocabulary deprecates single-app token inventions like the one REQ-MULTI-005
proposed. The fix was entirely local to hrmq: use the token that already existed, and provide the
`cnWorkspaceContext` Vue already supports injecting fleet-wide from hrmq's own SPA root rather than
only page-scoped. Said plainly, so this isn't silently rewritten: the original spec's assumption was
incorrect, not merely superseded by a later design choice.
