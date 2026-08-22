---
capability: goals-okr
status: done
built_by: openspec/changes/archive/2026-07-14-goals-okr
---

# goals-okr Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [goals-okr](../../changes/archive/2026-07-14-goals-okr/) _(archived 2026-07-15)_ — `Objective` (concept→actief→afgesloten lifecycle, employee/team ownership, optional review-cycle tie) + `KeyResult` (`$ref` child, measurable start/target/current) schema pair in `hr-okr.json`, a writer-maintained `progress` % documented against the `x-openregister-calculations` limit, ADR-001 Rule 6 detail-tab surfaces (`EmployeeDetail` emp-objectives row, `ObjectiveDetail` with its Key-Results list, `ReviewCycleDetail` rc-objectives row, `KeyResultDetail`), the `MijnDoelen` `userId=@me` self-service page, deep-links/icons, and seed data (kind: config)

## Purpose

Measurable OKR tracking as a new member of the performance-review family
(ADR-001 Rule 6): an `Objective` with several measurable `KeyResult`s, each
carrying a numeric start/target/current value and a writer-maintained
progress reading tracked *between* reviews and optionally rolled up under a
`ReviewCycle`. This is the cross-review-tracking sibling of the existing
`performance-reviews` capability, whose `PerformanceReview.goals` is
deliberately a lightweight, frozen, free-text array *inside* one dossier
(design D2 there) — the right shape for a jaargesprek afspraak, but unable to
express a measurable, independently-updatable OKR. Pure declarative config:
two OpenRegister schemas, manifest detail-tab/self-service wiring, seed data.
No imperative engine, no PHP, no guards. 9-box/kalibratie (the other half of
`performance-management-advanced`), alignment/cascading trees, check-in
history, weighting and a server-computed progress rollup are explicitly out
of scope (named fast-follows).

## Requirements

### Requirement: An Objective SHALL be a declarative owned goal tied to an optional review period (REQ-OKR-001)

`lib/Settings/register.d/hr-okr.json` SHALL define an `Objective` schema (slug `Objective`) with a
required `title`, an `ownerType` (`employee|team`), a nullable `employeeId` (`$ref Employee`), a
nullable `orgUnitId` (`$ref OrgUnit`), a nullable `cycleId` (`$ref ReviewCycle`) and a nullable
`period` string. Its `status` SHALL be governed by a declarative `x-openregister-lifecycle`:
initial `concept`, terminal `afgesloten`, transitions `activeren` (`concept → actief`) and
`afsluiten` (`actief → afgesloten`) with no guard. The scored result SHALL be a **separate**
nullable `uitkomst` field (`behaald|deels-behaald|niet-behaald`) stamped on the carrying write of
`afsluiten`, kept apart from the lifecycle `status` exactly as `PerformanceReview.rating` is kept
apart from its `status`. A closed (`afgesloten`) objective SHALL NOT be reopened — a correction is a
new objective (the `ReviewCycle` no-resurrection precedent).

#### Scenario: An objective walks concept to afgesloten and records a separate outcome
- **GIVEN** an `Objective` in status `concept`
- **WHEN** `activeren` then `afsluiten` are applied
- **THEN** the status is `afgesloten`, `afgesloten` has no outgoing transition, and the scored
  `uitkomst` (e.g. `behaald`) is recorded in its own field independent of `status`
- @e2e exclude declarative lifecycle transition covered by OpenRegister's `x-openregister-lifecycle`
  engine tests; app-level e2e suite does not exist yet (tracked by humaniq-test-coverage-baseline)

#### Scenario: An objective can run without a review cycle
- **GIVEN** an `Objective` with `cycleId` null and `period` set to `2026-Q1`
- **WHEN** it is created
- **THEN** it is valid and is not tied to any `ReviewCycle`

### Requirement: A KeyResult SHALL be a measurable child of exactly one Objective (REQ-OKR-002)

`hr-okr.json` SHALL define a `KeyResult` schema (slug `KeyResult`) with a **required** `objectiveId`
(`$ref Objective`) — a separate `$ref` child rather than an array embedded in `Objective` (design
D2, the `PerformanceReview → ReviewCycle` precedent) so each Key Result is independently updatable
with its own audit trail. It SHALL carry a `title`, a `unit` (`percentage|aantal|euro|boolean`), a
required numeric `targetValue`, numeric `startValue` and `currentValue`, and a plain `status` enum
(`open|behaald|vervallen`) with no lifecycle state machine (mirroring the embedded goal-status of
the reviews MVP). A `KeyResult` without an `objectiveId` SHALL be invalid.

#### Scenario: A key result references its objective and carries measurable values
- **GIVEN** an existing `Objective`
- **WHEN** a `KeyResult` is created with that `objectiveId`, `unit: aantal`, `startValue: 0`,
  `targetValue: 12`, `currentValue: 7` and `status: open`
- **THEN** it is valid, resolves to its parent `Objective`, and stores the target/current pair

#### Scenario: A key result without an objective is rejected
- **GIVEN** a `KeyResult` payload with `objectiveId` omitted
- **WHEN** it is validated
- **THEN** it fails validation because `objectiveId` is required
- @e2e exclude schema-level required-property validation covered by OpenRegister's own JSON-schema
  validation tests; app-level e2e suite does not exist yet (tracked by humaniq-test-coverage-baseline)

### Requirement: Progress SHALL be a writer-maintained number, not a declarative calculation (REQ-OKR-003)

`progress` on both `Objective` and `KeyResult` SHALL be a plain stored `number` (percent) that is
writer/UI-maintained. It SHALL NOT be declared as an `x-openregister-calculations`/`materialise`
field, because the OKR progress computations — a KeyResult's
`(currentValue − startValue) / (targetValue − startValue) × 100` and the Objective's average of its
KeyResults' progress — require a division and a cross-row aggregation that lie outside the
declarative calc vocabulary (`prop`/`+`/`-` over numeric properties of the same object only, per the
`hr-leave.json` `LeaveBalance` calc and the `hr-attendance.json` `workedHours` note). The measurable
truth (`startValue`/`targetValue`/`currentValue` and each KeyResult's `status`) SHALL always be
stored, so a `progress` value never fabricates the underlying numbers and can be recomputed by the
named-fast-follow service. Each `progress` property description SHALL state this rationale.

#### Scenario: Progress is a stored number with a documented non-declarative rationale
- **GIVEN** the `KeyResult` schema definition
- **WHEN** its `progress` property is inspected
- **THEN** `progress` is a plain `number` with no `x-openregister-calculations`/`materialise`
  block, and its description states it is writer/UI-maintained because the percentage division and
  average are outside the `prop`/`+`/`-` calc vocabulary

#### Scenario: The measurable source values are always present
- **GIVEN** any `KeyResult`
- **WHEN** it is read
- **THEN** `startValue`, `targetValue` and `currentValue` are stored so `progress` can be recomputed
  from them and never replaces them

### Requirement: The OKR surfaces SHALL be detail-tabs per ADR-001 Rule 6 with no new top-level menu (REQ-OKR-004)

`src/manifest.json` SHALL add, without creating any new menu group or 10th top-level entry
(ADR-001 Rule 6, the 9-item cap): on `EmployeeDetail` an `emp-objectives` object-list widget row
("Doelen & OKR's", icon `BullseyeArrow`; schema `Objective`, `filter: {employeeId: "@objectId"}`,
columns `title`/`status`/`progress`/`cycleId`, `rowRoute: ObjectiveDetail`) inserted as a full-width
layout row (the `emp-reviews` pattern); an `ObjectiveDetail` detail page (route `/objectives/:id`,
**not** a menu child) with a `data` widget (excluding `employeeId`/`orgUnitId`/`cycleId`/`userId`),
a `related` widget, an `obj-keyresults` object-list ("Key results", schema `KeyResult`,
`filter: {objectiveId: "@objectId"}`, columns
`title`/`currentValue`/`targetValue`/`unit`/`progress`/`status`, `rowRoute: KeyResultDetail`),
`lifecycleActions` exposing **exactly** `activeren`/`afsluiten` with Dutch labels, and an
audit-history sidebar tab; a `KeyResultDetail` detail page (route `/key-results/:id`, not a menu
child) with a `data` widget and a `related` widget resolving `objectiveId`; and on
`ReviewCycleDetail` an `rc-objectives` object-list ("OKR's in deze cyclus", schema `Objective`,
`filter: {cycleId: "@objectId"}`, `rowRoute: ObjectiveDetail`). `npm run check:manifest` MUST pass.

#### Scenario: The personnel dossier anchors the objectives row
- **GIVEN** an employee with an `Objective` whose `employeeId` is that employee
- **WHEN** `EmployeeDetail` is opened
- **THEN** the "Doelen & OKR's" object-list row lists that objective and its row navigates to
  `ObjectiveDetail`, and no new top-level menu entry has been added anywhere
- @e2e exclude declarative widget wiring covered by the shared CnPageRenderer library tests;
  app-level e2e suite does not exist yet (tracked by humaniq-test-coverage-baseline)

#### Scenario: The objective detail lists its key results and drives the lifecycle
- **GIVEN** an `Objective` in status `concept` with two `KeyResult`s referencing it
- **WHEN** `ObjectiveDetail` is opened
- **THEN** the "Key results" list shows both KeyResults with their current/target/progress and the
  `lifecycleActions` offer exactly `activeren` (and, once `actief`, `afsluiten`)
- @e2e exclude declarative widget/lifecycleActions wiring covered by the shared CnPageRenderer
  library tests; app-level e2e suite does not exist yet (tracked by humaniq-test-coverage-baseline)

#### Scenario: The review cycle lists its tied OKRs
- **GIVEN** an `Objective` whose `cycleId` is an open `ReviewCycle`
- **WHEN** `ReviewCycleDetail` for that cycle is opened
- **THEN** the "OKR's in deze cyclus" list includes that objective
- @e2e exclude declarative widget wiring covered by the shared CnPageRenderer library tests;
  app-level e2e suite does not exist yet (tracked by humaniq-test-coverage-baseline)

### Requirement: Objectives SHALL be reachable via self-service and deep-links (REQ-OKR-005)

`src/manifest.json` SHALL add a `MijnDoelen` index page over `Objective` (route `/mijn/doelen`,
`filter: {userId: "@me"}`, columns `title`/`status`/`progress`/`cycleId`) — the `MijnBeoordelingen`
mijn-hr precedent — and `deepLinks` for `Objective` (`/apps/humaniq/objectives/{uuid}`) and `KeyResult`
(`/apps/humaniq/key-results/{uuid}`). The `Objective` `userId` SHALL denormalise the owning employee's
`nextcloudUserId` (nullable, a plain NC-user-id string, never a `$ref` — the `Timesheet.userId` /
`PerformanceReview.userId` convention) so the `@me` filter works without a cross-schema join.
`src/icons.js` SHALL register `BullseyeArrow` and `TargetVariant`.

#### Scenario: An employee sees only their own objectives on MijnDoelen
- **GIVEN** two objectives whose `userId` values are different NC user ids
- **WHEN** an employee opens `MijnDoelen`
- **THEN** only the objective whose `userId` matches the `@me` token is listed
- @e2e exclude declarative index filtering covered by the shared CnPageRenderer library tests;
  app-level e2e suite does not exist yet (tracked by humaniq-test-coverage-baseline)

#### Scenario: An objective is deep-linkable
- **GIVEN** an `Objective` with a known uuid
- **WHEN** its deep-link `/apps/humaniq/objectives/{uuid}` is followed
- **THEN** `ObjectiveDetail` for that objective opens

### Requirement: Seed data SHALL populate a runnable OKR set and the register MUST validate (REQ-OKR-006)

`lib/Settings/register.d/hr-seed.json` SHALL seed one `Objective` in status `actief`
(`ownerType: employee`, `employeeId` = the seeded employee, `cycleId` = the seeded open 2026
`ReviewCycle`, `userId` = that employee's `nextcloudUserId`, `progress` hand-set) plus 2–3
`KeyResult`s referencing it via `objectiveId`, each carrying hand-consistent
`unit`/`startValue`/`targetValue`/`currentValue`/`progress`/`status` (so the manual `progress`
equals `(currentValue − startValue) / (targetValue − startValue) × 100`). The `hr-okr.json` fragment
MUST load (schemas import, seed `$ref`s resolve) and `npm run check:manifest` MUST pass against
app-manifest-v2.

#### Scenario: A clean install renders the seeded OKR set
- **GIVEN** a freshly seeded instance
- **WHEN** the seeded employee's `EmployeeDetail` and then `ObjectiveDetail` are opened
- **THEN** the "Doelen & OKR's" row shows the seeded `actief` objective and its "Key results" list
  shows the seeded KeyResults with matching current/target/progress values
- @e2e exclude declarative seed-data rendering covered by the shared CnPageRenderer library tests;
  app-level e2e suite does not exist yet (tracked by humaniq-test-coverage-baseline)

#### Scenario: The manifest and register validate
- **WHEN** `npm run check:manifest` runs after this change
- **THEN** it passes and the `Objective`/`KeyResult` schemas and their deep-links/pages are present
