---
kind: config
---

# Goals / OKR tracking — Objectives + measurable Key Results as a performance detail-tab

## Why

ADR-001 Rule 6 names it verbatim: `performance-management-advanced (OKR/9-box/kalibratie)` lives
as a **detail-tab on the personnel dossier**, not a 10th top-level menu. The existing
`performance-reviews-mvp` (archived 2026-07-13) deliberately records goals as a lightweight
free-text array *inside* a single `PerformanceReview` (`hr-performance.json`, design D2 there:
"no separate Goal entity … goals are not tracked across cycles in this MVP"). That is the right
shape for a jaargesprek afspraak — but it cannot express an **OKR**: an Objective with several
**measurable** Key Results, each carrying a numeric target/current value and a progress reading
that a manager tracks *between* reviews and rolls up. This change adds that measurable,
cross-review tracking layer as the same family (ADR-001 Rule 6 → detail-tab), anchored on
`EmployeeDetail` next to the Beoordelingen row and cross-linked from `ReviewCycleDetail` so an
OKR set can be tied to a beoordelingscyclus (review period).

This is a pure **config** change: two declarative OpenRegister schemas (`hr-okr.json`), the
manifest detail-tab/sub-page wiring, seed data, and icons/deep-links — no imperative engine. The
one substantive modelling decision (design D3) is that the OKR **progress %** is a
writer/UI-maintained number, **not** an `x-openregister-calculations` field: that operator
vocabulary is `prop`/`+`/`-` over numeric properties only (verified in `hr-leave.json`
`LeaveBalance.remainingHours` and the `hr-attendance.json` `workedHours` note), and a percentage
needs a division and a cross-row average it cannot express — the same honest limit the attendance
build documented for `workedHours`.

## What Changes

- **NEW register fragment `lib/Settings/register.d/hr-okr.json`** with two schemas:
  - **`Objective`** (slug `Objective`, `x-schema:Thing`/goal): an owned objective with a
    declarative `x-openregister-lifecycle` (`concept → actief → afgesloten`, terminal `afgesloten`,
    the `ReviewCycle` concept→open→gesloten precedent — closed OKR-sets are history, not reopened).
    Owner is either an Employee (`employeeId` `$ref Employee`) or a team (`orgUnitId` `$ref OrgUnit`)
    selected by `ownerType`; optionally tied to a review period via `cycleId` `$ref ReviewCycle`
    (nullable — an OKR set can also live under a plain `period` label). `uitkomst`
    (`behaald|deels-behaald|niet-behaald`, nullable) is the scored outcome stamped at `afsluiten`,
    kept separate from the lifecycle `status` exactly as `PerformanceReview.rating` is kept separate
    from its `status`. `progress` is the writer/UI-maintained rollup % (design D3). `userId`
    denormalises the owner's `nextcloudUserId` for the `@me` self-service page (the Timesheet /
    PerformanceReview convention).
  - **`KeyResult`** (slug `KeyResult`): a measurable result under exactly one Objective
    (`objectiveId` `$ref Objective`, required — the `PerformanceReview → ReviewCycle` parent-child
    precedent, chosen over embedding so each KR is independently updatable with its own audit trail
    and can be listed/updated between reviews; design D2). Carries `unit`
    (`percentage|aantal|euro|boolean`), `startValue`/`targetValue`/`currentValue` (numbers),
    `progress` (writer-maintained %, design D3), and a plain `status`
    (`open|behaald|vervallen`) mirroring the embedded goal-status enum of the reviews MVP.
- **Manifest (`src/manifest.json`) — detail-tab surfaces, ADR-001 Rule 6, no new top-level menu:**
  - `EmployeeDetail` gains an `emp-objectives` object-list widget row ("Doelen & OKR's", schema
    `Objective`, filter `employeeId: @objectId`, columns `title`/`status`/`progress`/`cycleId`,
    `rowRoute: ObjectiveDetail`) — the `emp-reviews` insertion pattern, the DETAIL_TAB realisation.
  - `ObjectiveDetail` (detail, route `/objectives/:id`, **not** a menu child — the
    `PerformanceReviewDetail`/`TimesheetDetail` convention): a data widget, a `related` widget
    (resolves `employeeId`/`orgUnitId`/`cycleId`), an `obj-keyresults` object-list ("Key results",
    schema `KeyResult`, filter `objectiveId: @objectId`, columns
    `title`/`currentValue`/`targetValue`/`unit`/`progress`/`status`, `rowRoute: KeyResultDetail`),
    `lifecycleActions` exposing **exactly** `activeren`/`afsluiten`, and an audit-history sidebar
    tab.
  - `KeyResultDetail` (detail, route `/key-results/:id`, not a menu child): a data widget + a
    `related` widget resolving `objectiveId`.
  - `ReviewCycleDetail` gains an `rc-objectives` object-list ("OKR's in deze cyclus", schema
    `Objective`, filter `cycleId: @objectId`, `rowRoute: ObjectiveDetail`) — the review-period link.
  - `MijnDoelen` (index over `Objective`, route `/mijn/doelen`, filter `userId: @me`) — mijn-hr
    self-service, the `MijnBeoordelingen` precedent.
  - `deepLinks` for `Objective` and `KeyResult`; `src/icons.js` registers the new icons
    (`BullseyeArrow` for Objective, `TargetVariant` for KeyResult).
- **Seed data (`lib/Settings/register.d/hr-seed.json`)** — one `actief` `Objective` for the seeded
  employee tied to the seeded open 2026 `ReviewCycle`, with 2–3 `KeyResult`s carrying hand-set
  target/current/progress, so the `EmployeeDetail` detail-tab and `ObjectiveDetail` render with
  real data on a clean install (ADR-001 seed convention).

### Non-goals (named, not silently dropped)

- **Server-computed progress rollup** — `progress` is writer/UI-maintained (design D3); an
  imperative recompute service (Objective progress = average of its KeyResults' progress, KR
  progress = `(current − start) / (target − start)`) is a named fast-follow the moment a `code`
  change is warranted. The measurable *truth* (`startValue`/`targetValue`/`currentValue`) is always
  stored, so a stale `progress` can be recomputed and never fabricates the underlying numbers.
- **9-box / kalibratie / calibration sessions** — the other half of `performance-management-advanced`
  (ADR-001 Rule 6); separate later spec.
- **Alignment / cascading** (parent-objective trees, company→team→individual laddering),
  check-ins/confidence-scoring history, and weighting — deferred; `objectiveId` and `cycleId` are
  the only relations in this MVP.
- **Write-time guards / who-may-close** — `afsluiten` is a plain declarative transition here; any
  authorization guard (e.g. only the owner's manager may close) is owned by the active
  `hrmq-rule-compliance-enforcement` change, not invented here.

## Capabilities

### New Capabilities

- `goals-okr`: the declarative `Objective` + `KeyResult` schemas (objective lifecycle, employee/team
  ownership, review-period tie), the writer-maintained progress model with its documented
  `x-openregister-calculations`-limit rationale, the ADR-001 Rule 6 detail-tab manifest surfaces
  (`EmployeeDetail` row, `ObjectiveDetail` with the Key-Results list, `ReviewCycleDetail` link,
  `KeyResultDetail`, `MijnDoelen` self-service), deep-links/icons, and the seed OKR set.

### Modified Capabilities

<!-- none — performance-reviews' in-review goals array is untouched; this is an additive family member -->

## Impact

- `lib/Settings/register.d/hr-okr.json` — NEW fragment (two schemas, declarative).
- `lib/Settings/register.d/hr-seed.json` — one seeded Objective + its KeyResults.
- `src/manifest.json` — `EmployeeDetail` + `ReviewCycleDetail` object-list rows; new
  `ObjectiveDetail`, `KeyResultDetail`, `MijnDoelen` pages; `Objective`/`KeyResult` deepLinks;
  `_note`s. `npm run check:manifest` MUST pass.
- `src/icons.js` — `BullseyeArrow`, `TargetVariant`.
- No PHP, no routes, no controllers — pure config (ADR-031 declarative default).
- Relates to (does not depend on): `performance-reviews`, `performance-review-cycles` (same
  ADR-001 Rule 6 family; `ReviewCycle` and `Employee`/`OrgUnit` already exist at HEAD).
