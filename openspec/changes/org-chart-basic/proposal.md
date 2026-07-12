---
kind: config
---

# Org Chart Basic (effective-dated organisational units and assignments on the register)

## Why

The Specter intelligence build-list (Spectr insight `hrmq-insight-ranked-buildlist`) and the round-0 draft spec (`spec/org-chart-basic`, Specter Intelligence 2026-05-23) both flag organisational structure as a blocking gap: hrmq has **no org model at all** — no departments, no teams, no cost-center owners, no record of who is placed where. The draft's integration rationale still holds verbatim: manager self-service needs current reporting lines to derive a team scope, finance apps (shillinq, expense routing) need to resolve cost-center owners, and onboarding/offboarding needs a placement to open/close. Today the closest thing is a bare `costCenter` string on Timesheet. The draft proposed OrgUnit/OrgAssignment/OrgChartSnapshot with SQL migrations, bespoke REST endpoints and a D3.js tree — legacy-shaped for this codebase. This change delivers the same *data* capability the modern hrmq way: two declarative schemas in a register fragment, `$ref`-driven related widgets, two machine-checkable corpus rules, and manifest pages — no bespoke PHP models, migrations, or hand-written Vue.

## What Changes

- **New register fragment `lib/Settings/register.d/hr-org.json`** with two schemas:
  - **`OrgUnit`** — a node in the organisational hierarchy: `name`, `type` (enum `afdeling`/`team`/`kostenplaats`), self-referencing `parentUnitId` (`$ref: OrgUnit`, nullable — root units have none), `costCenter` (string, nullable), `managerId` (`$ref: Employee`, nullable), `active` (boolean).
  - **`OrgAssignment`** — an effective-dated placement of an employee in a unit (the draft's effective-dating, kept): `employeeId` (`$ref: Employee`, required), `orgUnitId` (`$ref: OrgUnit`, required), `role` (functie, string), `startDate` (required), `endDate` (nullable — open-ended while current).
- **Declarative relations**: all four reference fields are canonical `$ref`s (ADR-062 rule 7 — every target schema exists in this register set), so the renderer's `related` widget resolves them by name on the detail pages with zero code, exactly as `employeeId` does on TimesheetDetail/ExpenseDetail.
- **Two new machine-checkable NL rules** in the labour corpus (`lib/Standards/rules/labour.json`) + a new check provider `lib/Standards/Checks/NlOrgChecks.php`:
  - `nl-org-assignment-consistency` — an active OrgAssignment must reference an active OrgUnit, and its dates must be coherent (`startDate <= endDate` when both present);
  - `nl-org-unit-cycle` — `parentUnitId` chains must be acyclic (the check walks parents with a visited set via the audit's cross-type related-context, the `RuleAuditService::buildRelatedContext()` mechanism established by pension-filing-upa-mvp, extended consistently).
- **Manifest pages** under the existing Personeel (`EmployeesGroup`) menu: `OrgUnits` index + `OrgUnitDetail` (data + related + FK-scoped child-units and assignments lists), `OrgAssignments` index + `OrgAssignmentDetail`; `EmployeeDetail` gains an Assignments `object-list` section mirroring its existing Contracts/Timesheets lists; deep links for both schemas.
- **Seed data**: 3 OrgUnits (Directie → Consultancy team + Backoffice) and 3 OrgAssignments for the employee slugs hr-seed.json already uses, one deliberately date-inconsistent (`endDate < startDate`) so the consistency rule visibly fires on seed data.

### Non-goals

- **No `OrgChartSnapshot`** — the draft's cached materialised views solve a query-volume problem hrmq does not have; OpenRegister object audit trails already record history. Materialisation is a scale follow-up spec.
- **No interactive D3.js tree and no PNG/PDF/SVG/JSON export** — a custom visual tree widget belongs in nc-vue first (Vue logic lives in nc-vue, never per-app), then lands here as a manifest widget type; follow-up spec `org-chart-visualization`.
- **No reporting-line resolution API / cost-center REST endpoint** — the draft's `/api/cost-centers/{code}` wrapper would be a pass-through OpenRegister already serves (`/apps/openregister/api/objects`, ADR-022); shillinq queries OrgUnit by `costCenter` directly.
- **No bulk-reorg workflow, matrix/dotted-line reporting, or primary-assignment uniqueness enforcement** — deferred with the draft's `org-hierarchy-reorg` / `org-chart-matrix` follow-ups.
- **No `Employee.managerId` migration** — Employee carries no manager field today; nothing to keep backwards-compatible.

## Capabilities

### New Capabilities

- `org-chart-basic`: the OrgUnit/OrgAssignment schemas + `hr-org` fragment, their `$ref`-driven related surfaces, the assignment-consistency and unit-cycle corpus rules and checks, the org pages, and the seed hierarchy.

### Modified Capabilities

<!-- none — existing specs are untouched; EmployeeDetail's new assignments list is additive page config owned by this capability, not a change to another spec's requirements -->

## Impact

- `lib/Settings/register.d/hr-org.json` — **new** fragment: `OrgUnit` + `OrgAssignment` schemas (no lifecycle — plain records, no guarded workflow).
- `lib/Standards/rules/labour.json` — 2 new NL rules (`nl-org-assignment-consistency`, `nl-org-unit-cycle`); `lib/Standards/rules/SCHEMA.md` framework examples gain `hr-org-core`; `RuleCatalogue::VERSION` per SCHEMA.md's bump rule.
- `lib/Standards/Checks/NlOrgChecks.php` — **new** auto-discovered check provider.
- `lib/Service/RuleAuditService.php` — `buildRelatedContext()` gains an `OrgUnit` index (`{id, parentUnitId, active}` by id) so the cycle walk and the active-unit lookup stay pure `fn(array $o, array $context)` predicates.
- `src/manifest.json` — `OrgUnits`/`OrgUnitDetail`/`OrgAssignments`/`OrgAssignmentDetail` pages, two `EmployeesGroup` menu children, two `deepLinks` entries, `EmployeeDetail` assignments object-list.
- `lib/Settings/register.d/hr-seed.json` — 3 OrgUnit + 3 OrgAssignment seeds (one inconsistent on purpose).
- `lib/Repair/InitializeRegister.php` — no change (fragment glob picks up the new file).
- Related active changes: `hrmq-ia-navigation-alignment` (owns any later ADR-001 menu re-homing — this change follows the current Personeel placement), `hrmq-employee-relations-widget` (independent; both touch EmployeeDetail additively).
