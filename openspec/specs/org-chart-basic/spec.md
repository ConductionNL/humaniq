---
capability: org-chart-basic
status: done
built_by: openspec/changes/archive/2026-07-13-org-chart-basic
---

# org-chart-basic Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [org-chart-basic](../../changes/archive/2026-07-13-org-chart-basic/) _(archived 2026-07-13)_ — new `OrgUnit` (self-referencing hierarchy with type/cost-center/manager) and effective-dated `OrgAssignment` schemas in a new `hr-org` fragment, `$ref`-driven related surfaces, 2 new machine-checkable org-integrity rules (framework `hr-org-core`), org pages under Personeel and an EmployeeDetail assignments list (kind: config)

## Purpose

Give humaniq its first organisational model: an `OrgUnit` hierarchy
(self-referencing parent, type, cost-center, manager) and effective-dated
`OrgAssignment` placements linking employees to units, in a new `hr-org`
register fragment — with `$ref`-driven related surfaces so
OrgUnit ↔ OrgAssignment ↔ Employee resolve on every detail page, two
machine-checkable integrity rules in the labour corpus (assignment
consistency, cycle-free hierarchy), org pages under Personeel, and a seeded
Directie → Consultancy/Backoffice example. The interactive D3 tree, exports,
snapshots and reorg tooling from the round-0 draft are explicitly out of
scope (see the original proposal's Non-goals): `org-chart-visualization`
(nc-vue first) and snapshot materialisation are declared follow-ups.

## Requirements

### REQ-ORG-001: A new `hr-org` fragment SHALL define the `OrgUnit` schema with a self-referencing parent

`lib/Settings/register.d/hr-org.json` (new file, `x-humaniq-fragment: hr-org`, OpenAPI 3.0.0 `components.schemas` shape like `hr-leave.json`) declares `OrgUnit` (slug `OrgUnit`, icon `SitemapOutline`, version `0.1.0`, `x-schema-org: schema:Organization`) with properties: `name` (string), `type` (enum `afdeling|team|kostenplaats`), `parentUnitId` (string, format uuid, `$ref: OrgUnit`, nullable — self-reference, null for roots), `costCenter` (string, nullable), `managerId` (string, format uuid, `$ref: Employee`, nullable), `active` (boolean, default `true`). `required: [name, type]`. The existing register Repair import picks the fragment up without code changes.

#### Scenario: Schema validates a root unit
- **GIVEN** the imported hrmq register
- **WHEN** an object `{name: "Directie", type: "afdeling"}` is created
- **THEN** creation succeeds with `active` defaulted to `true` and `parentUnitId`/`costCenter`/`managerId` null

#### Scenario: Unknown unit type rejected
- **WHEN** an object is written with `type: "divisie"`
- **THEN** OpenRegister schema validation rejects it (enum mismatch)

### REQ-ORG-002: The `hr-org` fragment SHALL define the effective-dated `OrgAssignment` schema

The same fragment declares `OrgAssignment` (slug `OrgAssignment`, icon `AccountArrowRightOutline`, version `0.1.0`, `x-schema-org: schema:EmployeeRole`) with properties: `employeeId` (string, format uuid, `$ref: Employee`), `orgUnitId` (string, format uuid, `$ref: OrgUnit`), `role` (string, nullable), `startDate` (string, format date), `endDate` (string, format date, nullable — null while the placement is current). `required: [employeeId, orgUnitId, startDate]`. Neither `OrgUnit` nor `OrgAssignment` carries an `x-openregister-lifecycle` (plain records, no workflow).

#### Scenario: Placement without end date is valid
- **WHEN** an object `{employeeId: <employee uuid>, orgUnitId: <unit uuid>, role: "Consultant", startDate: "2024-01-01"}` is created
- **THEN** creation succeeds with `endDate` null (an open-ended, current placement)

#### Scenario: Missing unit reference rejected
- **WHEN** an object is written without `orgUnitId`
- **THEN** OpenRegister schema validation rejects it (required property)

### REQ-ORG-003: The relations SHALL be canonical `$ref`s that the renderer's related machinery resolves

All four reference fields (`OrgUnit.parentUnitId`→OrgUnit, `OrgUnit.managerId`→Employee, `OrgAssignment.employeeId`→Employee, `OrgAssignment.orgUnitId`→OrgUnit) are declared as `$ref` relations per ADR-062 rule 7 (every target schema exists in this register set), stored as UUIDs. Outbound refs resolve in the `related` widget on the detail pages (the TimesheetDetail `employeeId` behaviour); inbound relations surface as FK-scoped `object-list` widgets (child units on OrgUnitDetail, assignments on OrgUnitDetail and EmployeeDetail).

#### Scenario: Assignment detail resolves both ends
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** a seeded OrgAssignment opened on `OrgAssignmentDetail`
- **WHEN** the page renders
- **THEN** the Related panel lists the referenced Employee and OrgUnit by name, each linking to its detail page

### REQ-ORG-004: The labour corpus SHALL gain two machine-checkable org-integrity rules

`lib/Standards/rules/labour.json` gains `nl-org-assignment-consistency` and `nl-org-unit-cycle` (both `domain: labour`, `jurisdiction: NL`, `framework: hr-org-core`, `severity: mandatory`, `machineCheckable: true`, control-style `source` — administration-integrity controls, the `xc-*` precedent, not statute transcriptions). `hr-org-core` is a new framework slug, added to the examples list in `lib/Standards/rules/SCHEMA.md`. `RuleCatalogue::VERSION` was bumped from `2026-07.1` to `2026-07.2` per SCHEMA.md's "bump on any change" rule.

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ humaniq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads without error and reports both new rules as enforced (each has a CheckProvider predicate)

### REQ-ORG-005: `NlOrgChecks` SHALL enforce assignment consistency and cycle-free hierarchies via the related-context

New auto-discovered provider `lib/Standards/Checks/NlOrgChecks.php` (implements `CheckProvider`; empty `seedSpec()` — seeds live in hr-seed.json, and a self-contained provider sample cannot carry resolvable cross-references). `RuleAuditService::buildRelatedContext()` was extended with an `OrgUnit` index — `context['related']['OrgUnit']['byId']` mapping each unit id to `{id, parentUnitId, active}` — same single pre-pass, same degrade-to-empty behaviour when the schema is not yet imported. Predicates (pure `fn(array $object, array $context): bool`):

1. **`nl-org-assignment-consistency`** (on `OrgAssignment`) — violates when `endDate` is present and earlier than `startDate`, or when the assignment is active (no `endDate`, or `endDate` on/after the audit run date) and `orgUnitId` does not resolve in the OrgUnit index or resolves to a unit whose `active` is not `true` (fail-closed on dangling references).
2. **`nl-org-unit-cycle`** (on `OrgUnit`) — walks the `parentUnitId` chain through the OrgUnit index carrying a visited set; violates when the walk re-enters a visited id (including a unit parented to itself). A dangling parent ends the walk without a violation (missing-node, not a cycle).

#### Scenario: Incoherent dates flagged
- **GIVEN** the seed assignment `orgassignment-bakker-consultancy` with `startDate: 2026-06-01` and `endDate: 2026-05-01`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** a `nl-org-assignment-consistency` violation is reported for that assignment

#### Scenario: Active assignment on an inactive unit flagged
- **GIVEN** an OrgAssignment with no `endDate` whose referenced OrgUnit has `active: false`
- **WHEN** the audit runs
- **THEN** a `nl-org-assignment-consistency` violation is reported

#### Scenario: Ended assignment on an inactive unit passes
- **GIVEN** an OrgAssignment with coherent dates and an `endDate` in the past, referencing an OrgUnit with `active: false`
- **WHEN** the audit runs
- **THEN** no `nl-org-assignment-consistency` violation is reported for it (historical placements may point at retired units)

#### Scenario: Parent cycle flagged on every unit in the cycle
- **GIVEN** OrgUnit A with `parentUnitId` = B and OrgUnit B with `parentUnitId` = A
- **WHEN** the audit runs
- **THEN** a `nl-org-unit-cycle` violation is reported for both A and B

#### Scenario: Deep acyclic chain passes
- **GIVEN** the seeded Directie → Consultancy/Backoffice hierarchy
- **WHEN** the audit runs
- **THEN** no `nl-org-unit-cycle` violation is reported

Pinned by `tests/Unit/Standards/Checks/NlOrgChecksTest.php` (both predicates, including empty-context-index degrade-to-safe behaviour) and `tests/Unit/Service/RuleAuditServiceTest.php` (the `buildRelatedContext()` OrgUnit index exercised end-to-end through `RuleAuditService::audit()` against the exact seeded fixture shapes, a two-node cycle, and a dangling `orgUnitId`).

### REQ-ORG-006: New org pages SHALL surface the hierarchy and placements under Personeel

`src/manifest.json` gains (a) an `OrgUnits` index page (route `/org-units`, register `hrmq`, schema `OrgUnit`) with columns `name`, `type`, `costCenter`, `managerId`, `active`, filters `type`/`active`, default sort `name` ascending; (b) an `OrgUnitDetail` detail page (route `/org-units/:id`) with a `data` widget (excluding `parentUnitId`/`managerId` — Related resolves both by name), a `related` widget, an FK-scoped `object-list` "Child units" (`OrgUnit`, `filter: { parentUnitId: "@objectId" }`, rowRoute `OrgUnitDetail`) and an FK-scoped `object-list` "Assignments" (`OrgAssignment`, `filter: { orgUnitId: "@objectId" }`, rowRoute `OrgAssignmentDetail`), plus an audit-history sidebar tab — and no lifecycleActions (no lifecycle on the schema); (c) an `OrgAssignments` index page (route `/org-assignments`) with columns `employeeId`, `orgUnitId`, `role`, `startDate`, `endDate`, sort `startDate` descending; (d) an `OrgAssignmentDetail` detail page (route `/org-assignments/:id`) with a `data` widget (excluding `employeeId`/`orgUnitId`), a `related` widget and an audit-history sidebar tab; (e) `EmployeesGroup` (Personeel) menu children `OrgUnits` ("Organisatie-eenheden", icon `SitemapOutline`) and `OrgAssignments` ("Plaatsingen", icon `AccountArrowRightOutline`) after Contracten; (f) `deepLinks` entries for `OrgUnit` (`/apps/humaniq/org-units/{uuid}`) and `OrgAssignment` (`/apps/humaniq/org-assignments/{uuid}`). The manifest validates (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Unit detail shows children and placements
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** the seeded `orgunit-directie` opened on `OrgUnitDetail`
- **WHEN** the page renders
- **THEN** the Child units list shows Consultancy and Backoffice, each row navigating to that unit's detail page

### REQ-ORG-007: `EmployeeDetail` SHALL list the employee's assignments

The `EmployeeDetail` page gains an "Assignments" `object-list` widget (`register: hrmq`, `schema: OrgAssignment`, `filter: { employeeId: "@objectId" }`, sort `startDate` descending, columns unit/role/start/end, rowRoute `OrgAssignmentDetail`, viewAllRoute `OrgAssignments`, viewAllQuery `{ employeeId: "@objectId" }`, emptyText for the no-placements case) — declared exactly like the existing emp-contracts/emp-timesheets lists and added as a new layout row before the Personnel-file Files widget, which shifts down. No other EmployeeDetail widget changes.

#### Scenario: Employee page lists placements
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** the seeded `employee-jansen` opened on `EmployeeDetail`
- **WHEN** the page renders
- **THEN** the Assignments list shows the Consultancy placement with its role and start date

### REQ-ORG-008: Seed data SHALL provide the example hierarchy and one deliberately inconsistent placement

`lib/Settings/register.d/hr-seed.json` gains three OrgUnit seeds — `orgunit-directie` (root, `afdeling`, manager `employee-jansen`), `orgunit-consultancy` (`team`, parent `orgunit-directie`, costCenter `CC-100`, manager `employee-devries`), `orgunit-backoffice` (`afdeling`, parent `orgunit-directie`, costCenter `CC-200`, no manager) — and three OrgAssignment seeds referencing by slug (the Timesheet `employeeId` mechanism): `orgassignment-jansen-consultancy` (Consultant, from `2024-01-01`, open-ended), `orgassignment-devries-backoffice` (Officemanager, from `2025-03-01`, open-ended), and `orgassignment-bakker-consultancy` (Junior consultant) whose `endDate` (`2026-05-01`) deliberately precedes its `startDate` (`2026-06-01`) so the consistency rule fires on seed data. No seeded cycle (`nl-org-unit-cycle` stays green on seeds; its violating path is pinned by unit tests). All identifiers are obvious placeholders; the employee slugs are the ones hr-seed.json already uses.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import (and `occ humaniq:rules:seed-testdata`) runs twice
- **THEN** the three units and three assignments exist exactly once

#### Scenario: Exactly one seeded org violation
- **GIVEN** the seeded data
- **WHEN** the audit runs
- **THEN** exactly one `nl-org-assignment-consistency` violation (the bakker placement) and zero `nl-org-unit-cycle` violations are reported, and no pre-existing check regresses

Pinned by `RuleAuditServiceTest::testSeededOrgDataFlagsExactlyOneAssignmentConsistencyViolation` and `::testSeededOrgDataHasNoUnitCycleViolations`, run against the exact seeded fixture shapes.
