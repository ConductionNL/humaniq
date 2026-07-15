# Design — goals-okr

## Context

**Verified against HEAD 2026-07-15.** This change adds measurable OKR tracking as a new member of
the performance-review family (ADR-001 Rule 6). Everything it hangs off already exists at HEAD:

- **`hr-performance.json`** — `ReviewCycle` (declarative `x-openregister-lifecycle`
  `concept → open → gesloten`, terminal `gesloten`, closed = history-not-reopened) and
  `PerformanceReview` (`concept → ingediend → besproken → vastgesteld` with a `heropenen` correction
  edge). `PerformanceReview` already carries a lightweight **embedded** `goals` array
  (`{titel, status: open|behaald|vervallen, toelichting}`) and its design D2 states, deliberately,
  "no separate Goal entity … goals are not tracked across cycles". That is the jaargesprek-afspraak
  shape; it cannot carry a numeric target/current pair or a tracked progress reading — which is what
  an OKR needs. This change adds that measurable layer alongside, without touching the reviews MVP.
- **`hr-objects.json`** `Employee` (slug `Employee`, has `nextcloudUserId`) and **`hr-org.json`**
  `OrgUnit` (slug `OrgUnit`, teams/departments) — the two owner targets ("Employee/team goals").
- **`src/manifest.json`** — the realised ADR-001 Rule 6 pattern (verified on `EmployeeDetail`
  page 15 / `PerformanceReviewDetail` page 62 / `ReviewCycleDetail` page 61): a DETAIL_TAB is an
  **object-list widget row** on the dossier (`emp-reviews`: schema `PerformanceReview`,
  `filter: {employeeId: "@objectId"}`, `rowRoute: PerformanceReviewDetail`) plus a routed detail
  page that is **not** a menu child, a `related` widget resolving the `$ref`s, `lifecycleActions`
  mapping the schema transitions, and an audit-history sidebar tab. `MijnBeoordelingen` (page 63,
  route `/mijn/beoordelingen`, `filter: {userId: "@me"}`) is the mijn-hr self-service precedent.
- **`x-openregister-calculations` vocabulary** (read fresh): the only materialised declarative
  calc in the repo is `hr-leave.json` `LeaveBalance.remainingHours` —
  `{"-": [{"+": [{"prop": "entitledHours"}, {"prop": "bovenwettelijkHours"}]}, {"prop": "usedHours"}]}`
  with `"materialise": true`. The `hr-attendance.json` `workedHours` note states the limit
  explicitly: *"that operator vocabulary is prop/+/- over numeric properties only"* — no division,
  no multiplication, no cross-row aggregation. This is the pivot for design D3.

## Goals / Non-Goals

**Goals:** a declarative `Objective` + measurable `KeyResult` model with a review-period tie and
employee/team ownership; the OKR set surfaced as an ADR-001 Rule 6 detail-tab on the personnel
dossier and cross-linked from the review cycle; an honest progress model that stores the measurable
truth and is upfront about what the declarative calc vocabulary cannot express; seed data proving
the surfaces render. Pure config — no imperative code.

**Non-Goals (from the proposal, binding):** server-computed progress rollup (named fast-follow);
9-box/kalibratie (the other Rule 6 half); alignment/cascading trees, weighting, confidence-score
check-in history; write-time authorization guards on `afsluiten` (owned by
`hrmq-rule-compliance-enforcement`).

## Decisions

### D1 — Objective lifecycle mirrors ReviewCycle; the scored outcome is a field, not a status

`Objective.status` is a declarative `x-openregister-lifecycle`: initial `concept`, terminal
`afgesloten`, transitions `activeren` (`concept → actief`) and `afsluiten` (`actief → afgesloten`).
This is the `ReviewCycle` concept→open→gesloten shape (a closed OKR set is history and is not
reopened — the Vacancy/ReviewCycle no-resurrection precedent; a correction means a new set). The
**scored result** is a *separate* nullable field `uitkomst` (`behaald|deels-behaald|niet-behaald`),
stamped on the carrying write of `afsluiten` — exactly the pattern where `PerformanceReview` keeps
`rating` separate from its lifecycle `status`, so "how it ended" and "where it is in the flow" never
collide. No guard is declared on `afsluiten` (D-non-goal: guard wiring is
`hrmq-rule-compliance-enforcement`'s scope; the reviews MVP's `NoSelfApprovalGuard` on `vaststellen`
is *not* copied here — closing an OKR set has no self-approval hazard).

### D2 — KeyResult is a separate `$ref` child, not an embedded array

An OKR's Key Results each carry a numeric `targetValue`/`currentValue` and are tracked and updated
**between** reviews. Two shapes were available:

- **(A) Embed** a KeyResult array inside `Objective` (the `PerformanceReview.goals` precedent).
- **(B) Separate `KeyResult` schema** with `objectiveId` `$ref Objective` (the
  `PerformanceReview → ReviewCycle` parent-child precedent).

**Chosen: (B).** Each Key Result needs to be updated independently (a manager bumps
`currentValue` on one KR mid-quarter), wants its **own audit trail** of those numeric moves, and is
listed/filtered as rows (`obj-keyresults` object-list on `ObjectiveDetail`, `KeyResultDetail` for a
single KR). Embedding would freeze all KRs into one object write and one audit entry — the right
call for a frozen jaargesprek afspraak (why the reviews MVP embeds `goals`), the wrong call for a
tracked measurable. `objectiveId` is `required`: a KeyResult without an Objective is meaningless.
KeyResult keeps a **plain** `status` enum (`open|behaald|vervallen`) — no lifecycle machine, mirroring
the embedded goal-status of the reviews MVP; its state is trivial and needs no guarded transitions.

### D3 — Progress % is a writer/UI-maintained number, NOT an x-openregister-calculations field

The whole point of an OKR is the **computed progress %**: for a KeyResult,
`(currentValue − startValue) / (targetValue − startValue) × 100`; for the Objective, the **average**
of its KeyResults' progress. Neither is expressible in the declarative calc vocabulary — which is
`prop`/`+`/`-` over numeric properties of the **same object** only (Context: `LeaveBalance` proves
`+`/`-`; the `workedHours` note names the ceiling). A percentage needs a **division**; the rollup
needs a **cross-row average** over child objects. Both are outside the vocabulary.

So `progress` on both schemas is a **stored, writer/UI-maintained** `number` (percent 0–100),
declared exactly the way `hr-attendance.json` `workedHours` is: a presentation/aggregation
convenience, honestly documented as *not* a declarative calc. The **measurable truth**
(`startValue`, `targetValue`, `currentValue`, and each KR's `status`) is always stored, so
`progress` never fabricates a number — it can be recomputed at any time. `uitkomst` on the Objective
is likewise the human scored verdict, not a derived field. This keeps the change pure-config: the
imperative recompute service (KR progress from the three values, Objective progress as the KR
average) is the named fast-follow the moment a `code` change is justified.

*(One declarative crumb IS expressible and deliberately declined: a KeyResult
`attainmentDelta = currentValue − startValue` via `prop`/`-` would validate — but it is a raw delta,
not a percentage, and duplicates data already visible in the two source fields, so it is left out to
avoid a materialised field that reads like progress but is not. Named here so the omission is a
choice, not an oversight.)*

### D4 — Detail-tab surfaces per ADR-001 Rule 6; no new top-level menu

The manifest realisation copies the `emp-reviews` / `PerformanceReviewDetail` build exactly:

- **`EmployeeDetail`** gains an `emp-objectives` object-list row ("Doelen & OKR's", icon
  `BullseyeArrow`; schema `Objective`, `filter: {employeeId: "@objectId"}`, columns
  `title`/`status`/`progress`/`cycleId`, `rowRoute: ObjectiveDetail`) — inserted as a full-width
  layout row the way `emp-reviews` was; no `viewAllRoute` (dossier-anchored, ADR-001 Rule 6).
- **`ObjectiveDetail`** (route `/objectives/:id`, **not** a menu child): a `data` widget (excluding
  `employeeId`/`orgUnitId`/`cycleId` — `related` resolves them — and `userId`, the internal `@me`
  field), a `related` widget, an `obj-keyresults` object-list ("Key results", schema `KeyResult`,
  `filter: {objectiveId: "@objectId"}`, columns
  `title`/`currentValue`/`targetValue`/`unit`/`progress`/`status`, `rowRoute: KeyResultDetail`),
  `lifecycleActions` mapping **exactly** `activeren`/`afsluiten` (Dutch labels "Activeren"/"Afsluiten"),
  and an audit-history sidebar tab.
- **`KeyResultDetail`** (route `/key-results/:id`, not a menu child): a `data` widget + a `related`
  widget resolving `objectiveId`.
- **`ReviewCycleDetail`** gains an `rc-objectives` object-list ("OKR's in deze cyclus", schema
  `Objective`, `filter: {cycleId: "@objectId"}`, columns `title`/`status`/`progress`/`employeeId`,
  `rowRoute: ObjectiveDetail`) — the review-period tie, alongside the existing "Beoordelingen in
  deze cyclus" list.
- **`MijnDoelen`** (index over `Objective`, route `/mijn/doelen`, `filter: {userId: "@me"}`, columns
  `title`/`status`/`progress`/`cycleId`) — the `MijnBeoordelingen` self-service precedent.
- `deepLinks` for `Objective` (`/apps/hrmq/objectives/{uuid}`) and `KeyResult`
  (`/apps/hrmq/key-results/{uuid}`); `src/icons.js` registers `BullseyeArrow` + `TargetVariant`.

**No new menu group and no 10th top-level entry** anywhere (ADR-001 Rule 6, the 9-item cap). Every
surface is a detail-tab row, a routed detail reached from a row/deepLink, or a self-service index.
`npm run check:manifest` gates it against app-manifest-v2.

### D5 — Ownership is employee-or-team, selected by ownerType

`Objective.ownerType` (`employee|team`, required) declares which relation is meaningful:
`employeeId` `$ref Employee` for an individual OKR (drives the `EmployeeDetail` row and, via the
denormalised `userId = Employee.nextcloudUserId`, the `MijnDoelen @me` filter — the manifest filter
grammar cannot join across schemas, so `userId` is copied here exactly as `PerformanceReview.userId`
and `Timesheet.userId` are), `orgUnitId` `$ref OrgUnit` for a team OKR. Both relations are nullable;
`ownerType` says which is authoritative. A team OKR does not appear on any single `EmployeeDetail`
row (correct — it belongs to the unit), and both kinds appear on `ReviewCycleDetail` when tied to a
cycle. `cycleId` `$ref ReviewCycle` is nullable: an OKR set can run under a plain `period` string
label (e.g. `2026-Q1`) with no formal beoordelingscyclus.

## Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Objective / KeyResult data model | **declarative schemas** (`hr-okr.json`) | ADR-031 default; plain object shapes with `$ref` relations |
| Objective lifecycle (`concept → actief → afgesloten`) | **declarative** `x-openregister-lifecycle` | the `ReviewCycle` precedent; OpenRegister enforces the state machine |
| KeyResult status (`open/behaald/vervallen`) | declarative plain enum | trivial state, no guarded transitions (the embedded-goal precedent) |
| Progress % (KR and Objective rollup) | **stored, writer/UI-maintained number** | division + cross-row average are outside the `prop`/`+`/`-` calc vocabulary (D3) — the `workedHours` exception class; imperative recompute is a named fast-follow |
| Scored outcome (`uitkomst`) | stored nullable field, stamped at `afsluiten` | a human verdict, not derived — the `PerformanceReview.rating` precedent |
| Ownership routing | declarative `$ref` + denormalised `userId` | manifest filter grammar cannot join schemas (the `Timesheet.userId` precedent) |
| Detail-tab / cycle / self-service surfaces | **declarative manifest** (object-list rows, routed details, `lifecycleActions`) | ADR-001 Rule 6 realised exactly as `emp-reviews`/`PerformanceReviewDetail` |
| Close authorization guard | **not in this change** | owned by `hrmq-rule-compliance-enforcement` — no guard invented on `afsluiten` |

## Seed Data (ADR-001)

Add to `lib/Settings/register.d/hr-seed.json`: one `Objective` in status `actief`
(`ownerType: employee`, `employeeId` = the seeded employee, `cycleId` = the seeded open 2026
`ReviewCycle`, `userId` = that employee's `nextcloudUserId`, `progress` hand-set, e.g. 55) with
2–3 `KeyResult`s referencing it (`objectiveId` = the seed Objective) carrying hand-set
`unit`/`startValue`/`targetValue`/`currentValue`/`progress`/`status` — e.g. a `percentage` KR
(start 0, target 100, current 60, progress 60, open), an `aantal` KR (start 0, target 12, current 7,
progress 58, open), and a `boolean`-unit KR (target 1, current 1, progress 100, behaald). This makes
the `EmployeeDetail` "Doelen & OKR's" row, `ObjectiveDetail` with its Key-Results list, the
`ReviewCycleDetail` "OKR's in deze cyclus" row and `MijnDoelen` all render with real data on a clean
install. The seeded `progress` values are hand-consistent with `(current − start)/(target − start)`
so the manual number matches the measurable truth (the fast-follow recompute will reproduce them).
No lifecycle transition is seeded beyond creating the `actief` objective.

## Risks / Trade-offs

- **Writer-maintained `progress` can drift** from the measurable truth if a `currentValue` is edited
  without updating `progress`. Mitigated: the three source values are always stored and displayed
  next to `progress` on the KR list, so drift is visible; the recompute service is a named
  fast-follow; and `progress` never affects `uitkomst` (the human close verdict) or any downstream
  booked data — it is presentation only, exactly the `workedHours` risk profile.
- **Two goal shapes coexist** (embedded `PerformanceReview.goals` vs the new `Objective`/`KeyResult`).
  Mitigated: they are different jobs — a frozen jaargesprek afspraak vs a tracked measurable — and
  the proposal/design name the distinction so a future author does not "unify" them by accident. The
  reviews MVP is untouched (no Modified Capability).
- **Team OKRs have no personnel-dossier home** (they belong to an `OrgUnit`, which has no detail
  page in this MVP): mitigated by `ReviewCycleDetail` + `MijnDoelen` + deepLink reachability; an
  `OrgUnitDetail` OKR row is a trivial follow-up when org-chart detail pages land.

## Open Questions

- None blocking. Server-side progress recompute, 9-box/kalibratie, alignment trees and a close-guard
  are all named fast-follows / other-change scope above.
