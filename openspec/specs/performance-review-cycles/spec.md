---
capability: performance-review-cycles
status: done
built_by: openspec/changes/archive/2026-07-13-performance-reviews-mvp
---

# performance-review-cycles Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [performance-reviews-mvp](../../changes/archive/2026-07-13-performance-reviews-mvp/) _(archived 2026-07-13)_ — `ReviewCycle` schema (new fragment hr-performance.json) with declarative concept→open→gesloten lifecycle, ReviewCycles/ReviewCycleDetail sub-pages under the existing Personeel group (ADR-001 Rule 6 — no new menu), seeded open 2026 cycle (kind: config)

## Purpose

The orchestration container of the performance-review MVP: a generic
`ReviewCycle` (jaargesprek / beoordeling / tussentijds) with a declarative
open/close lifecycle, surfaced as SUB_PAGEs under the existing Personeel
group per ADR-001 Rule 6 — performance is dossier-anchored; there is no
performance module and no 10th top-level menu. The cycle is the container
`PerformanceReview.cycleId` references (see `performance-reviews`) and is
deliberately generic so the future `comp-planning-cycle` change can
reference cycles instead of reinventing them. Grounded in Spectr
canonicalFeature `hrmq-canon-performance-reviews` (6/9 competitor
coverage). OKR/9-box/kalibratie (`performance-management-advanced` draft)
and the comp cycle (`comp-planning-cycle` draft) are explicitly out of
scope.

## Requirements

### Requirement: Review cycles are declarative register objects under the existing Personeel group (REQ-PRC-000)

The review-cycle capability MUST consist solely of the `ReviewCycle` schema
in `lib/Settings/register.d/hr-performance.json` (declarative
`x-openregister-lifecycle` concept→open→gesloten; no bespoke tables,
services or controllers — ADR-022/ADR-031) plus manifest sub-pages under
the pre-existing `EmployeesGroup` and seed data. Per ADR-001 Rule 6 the
capability MUST NOT introduce a new menu group or top-level menu entry.

#### Scenario: No module, no new menu

- GIVEN the humaniq codebase with this capability applied
- WHEN `src/manifest.json` and `lib/` are inspected
- THEN `ReviewCycles` is a child of the pre-existing `EmployeesGroup`, the top-level menu count is unchanged, and no PHP CRUD service/controller for cycles exists
- @e2e exclude structural manifest/register assertion with no user-observable flow of its own; covered by check:manifest and the delta spec's scenarios

### Requirement: A new `ReviewCycle` schema SHALL model the review cycle with a declarative lifecycle (REQ-PRC-001)

A new fragment `lib/Settings/register.d/hr-performance.json` (`x-humaniq-fragment: hr-performance`) SHALL declare `ReviewCycle` (version 0.1.0, icon `CalendarSyncOutline`, `x-schema-org: schema:Event`): `name` (string, required — e.g. "Jaargesprekken 2026"), `year` (integer, required — the cycle's calendar year), `type` (enum `jaargesprek`/`beoordeling`/`tussentijds`, required — enum append-only), `status` (enum `concept`/`open`/`gesloten`, default `concept`, required — governed by the lifecycle), `startDate` (string, format date, nullable), `endDate` (string, format date, nullable). Required: `name`, `year`, `type`, `status`. `configuration` declares `x-openregister-lifecycle`: `field: status`, `initial: concept`, transitions `openen` (concept→open) and `sluiten` (open→gesloten), terminal `gesloten` — no re-open edge (a closed cycle is history; corrections mean a new cycle, the Vacancy no-resurrection precedent). Every property carries title + description (gate-28). Register `lib/Settings/humaniq_register.json` `info.version` bumps 0.5.0 → 0.6.0.

#### Scenario: Cycle walks open and close
- **GIVEN** a ReviewCycle created with name "Jaargesprekken 2026", year 2026, type `jaargesprek` (status defaults to `concept`)
- **WHEN** `openen` is executed via the OpenRegister lifecycle endpoint
- **THEN** the object's `status` is `open`; **AND WHEN** `sluiten` is executed **THEN** `status` is `gesloten` and no further transition is offered (terminal)

#### Scenario: Illegal transition rejected
- **GIVEN** a ReviewCycle in status `concept`
- **WHEN** the `sluiten` action is attempted
- **THEN** OpenRegister rejects the transition (`sluiten` is only declared from `open`)

#### Scenario: Incomplete cycle rejected
- **WHEN** a ReviewCycle is written without `year` or `type`
- **THEN** OpenRegister schema validation rejects it (required-property violation)

### Requirement: `ReviewCycles` and `ReviewCycleDetail` SHALL live under the EXISTING Personeel group and drive exactly the declared lifecycle (REQ-PRC-002)

`src/manifest.json` SHALL add child `ReviewCycles` ("Beoordelingscycli", icon `CalendarSyncOutline`) to the **existing** `EmployeesGroup` — no new menu group and no new top-level entry is created (ADR-001 Rule 6: performance is dossier-anchored; sub-pages under Personeel are allowed, a 10th top-level menu is not). Pages: `ReviewCycles` (index over `ReviewCycle`, route `/review-cycles`: columns `name`, `year`, `type`, `status`, `startDate`, `endDate`; filters `status`, `type`; sort `year` desc) and `ReviewCycleDetail` (detail, route `/review-cycles/:id`) carrying: a "Cycle" data widget (all fields), an object-list "Beoordelingen in deze cyclus" (schema `PerformanceReview`, filter `cycleId: @objectId`, columns `employeeId`/`status`/`rating`, rowRoute `PerformanceReviewDetail`), `lifecycleActions` exposing **exactly** `openen` ("Openen", from `concept`) and `sluiten` ("Sluiten", from `open`) — no invented edges — and an audit-history sidebar tab. A deepLink `ReviewCycle` → `/apps/humaniq/review-cycles/{uuid}` is registered, and `src/icons.js` registers `CalendarSyncOutline`. The manifest MUST validate against app-manifest-v2 (`npm run check:manifest`).

#### Scenario: Manifest stays valid with no new menu group
- **WHEN** `npm run check:manifest` runs after this change
- **THEN** it exits 0; **AND** `src/manifest.json` contains no new menu group — `ReviewCycles` is a child of the pre-existing `EmployeesGroup` and the top-level menu count is unchanged

#### Scenario: Detail page walks the cycle workflow
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** a ReviewCycle in status `concept` opened on `ReviewCycleDetail`
- **WHEN** the user executes Openen
- **THEN** the page reflects status `open` and offers Sluiten; the "Beoordelingen in deze cyclus" list shows the reviews referencing this cycle

#### Scenario: No invented edges
- **WHEN** the manifest's `lifecycleActions` for `ReviewCycleDetail` are compared to the `x-openregister-lifecycle` in `lib/Settings/register.d/hr-performance.json`
- **THEN** they match action-for-action (`openen`/`sluiten`, same from/to) with no additional action

### Requirement: Seed data SHALL provide one open 2026 cycle (REQ-PRC-003)

`lib/Settings/register.d/hr-seed.json` SHALL gain `review-cycle-2026` (name "Jaargesprekken 2026", year 2026, type `jaargesprek`, status `open`, startDate `2026-01-01`, endDate `2026-12-31`) — the anchor object the two seeded reviews (spec'd in `performance-reviews`) reference via `cycleId`. The seed MUST stay an obvious placeholder and MUST import idempotently via the register Repair step.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the cycle exists exactly once, status `open`
