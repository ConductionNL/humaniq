# Design — hrmq-dashboard-steering-indicators

## Context

**Verified against HEAD 2026-08-19, branch `spec/hrmq-refactor-wave-1`.**

The `Dashboard` page (`src/manifest.json`, `pages[].id: "Dashboard"`) is 15 widgets: 12 `stat`
tiles at `gridY` 0/2/4/6 (three self-scoped, three team-scoped, two org-scoped approval counts,
two verzuim counts, two leave counts) and 3 full-width `object-table` tiles at `gridY` 8/13/18
(recent timesheets, expiring contracts, expiring BHV certificates) — 23 grid rows total. Every
`stat` tile's `content.source` is `{register, schema, metric: count|sum, filter}` — a live queue
depth, never a trend.

Three mechanisms this design relies on, verified directly rather than assumed:

- **The `chart` widget is real and shipped**, not merely schema-reserved. `nextcloud-vue`'s
  `LIBRARY_BUILT_IN_WIDGET_KEYS` comment (`src/utils/validateManifest.js:58-67`) reads as if
  `chart`/`stats-block` are validation-only placeholders — but
  `src/components/CnWidgetGrid/registerDashboardWidgets.js:69-79` registers `chart` with a real
  `CnChartWidget.vue` renderer (`src/components/CnChartWidget/CnChartWidget.vue`, an ApexCharts
  wrapper) and a config form. `object-table` (`CnWidgetObjectTable`) is likewise real.
- **`dataSource` supports server-side period bucketing with zero new PHP.** Two independent
  mechanisms exist in `useDataSource.js`, both against OpenRegister's auto-generated GraphQL
  schema, no bespoke hrmq endpoint required:
  - `dataSource.bucket` (`useDataSource.js:264-360`, the OR `add-time-bucket-aggregation` spec):
    a **time-interval** group-by (`MINUTE|HOUR|DAY|WEEK|MONTH|QUARTER|YEAR`) over a date-typed
    field, metric `COUNT|SUM|AVG|MIN|MAX`. No `MEDIAN`.
  - `dataSource.aggregate` (object form, `app-manifest-v2.schema.json` `#/$defs/dataSource`): a
    **categorical** group-by over any field (via OR's `/grouped` REST facet), `metric: count|sum`.
  Both are single-metric, single-schema. Two series in one chart need the "raw GraphQL" `dataSource`
  form (`{graphql: {query, selectors}}`), which can alias two root fields — even across two
  *different* schemas — in one document, since GraphQL root fields are independent of each other.
- **`chart` and `object-table` both support `endpointSource` as the alternative to `dataSource`**
  (schema `#/$defs/chartEndpointSource`; `CnWidgetObjectTable.vue:265-280`) — "exactly one of
  `dataSource` | `endpointSource`" is validator-enforced on both. pipelinq's
  `AnalyticsController::trends()` (`pipelinq/lib/Controller/AnalyticsController.php:168-207`) is
  the shipped precedent this design follows for shape, params, and error envelope.

Two hrmq-specific facts close off the tempting shortcuts:

- **`x-openregister-calculations` cannot express what "approval lead time" needs.** hrmq's own
  schemas document this twice already: `hr-attendance.json`'s `workedHours` note ("that operator
  vocabulary is prop/+/− over numeric properties only and cannot express a date-time subtraction")
  and `hr-okr.json`'s `progress` note (same vocabulary, cannot express division). `approvedAt −
  submittedAt` is a date-time subtraction — not declarable, confirmed by hrmq's own precedent, not
  assumed.
- **hrmq has one real, unused authorization primitive: `AdministrationAccess.role`.**
  `lib/Settings/register.d/hr-administratie.json`'s `AdministrationAccess.role` enum is
  `accountant | hr | employee`, and its own description says: *"Purely descriptive in the MVP —
  the setter/switcher only check ROW PRESENCE, not this value... role-differentiated authorization
  is a named post-MVP fast-follow."* `AdministrationController::setActive()` /
  `AdministrationService::hasAccess()` already resolve per-user membership (never IDOR-able — the
  existing no-admin-idor resolve-first pattern). This is not the fleet-wide RBAC brief §4 finds
  absent; it is one narrower, already-shipped mechanism this change is the first to read past row
  presence.

## Goals / Non-Goals

**Goals:**
- Six widgets, one screen, no scrolling: five trend indicators plus one obligations list.
- Every new indicator computable from fields that exist today (verified per-field below), no
  schema version bump.
- Prefer OpenRegister's own aggregation (ADR-022) wherever it can answer the question; name why,
  concretely, wherever it cannot.
- The one indicator that already refuses to lie about missing data (`AbsenceRateService`'s
  `percentage: null`) must not be made to lie by the widget that renders it.
- The new endpoint returning organisation-wide payroll €/absence/lead-time is guarded by
  something real, not merely documented as needing a guard later.

**Non-Goals:**
- **Role-default layouts** (visibility, not data-guarding) — D6.
- **Fixing the `@workspace.activeAdministrationId?` sentinel-substitution defect** (brief §6) —
  out of scope; the two dataSource-native widgets inherit it exactly as every existing index page
  does today (D4). The two guarded endpoints avoid it structurally (D3) rather than fixing it.
- **A fleet-wide RBAC/permission system for hrmq** (brief §4) — this change enforces exactly one
  field (`AdministrationAccess.role`) on exactly one new surface. It does not add an
  `authorization` block to any schema, and does not change what the OpenRegister REST API returns
  to an unauthenticated-of-role caller for any *other* endpoint.
- **A compliance page surfacing every rule-engine violation** — the Obligations list attaches a
  best-effort mandatory-violation badge to rows it already has for another reason (an expiring
  contract, a WVP milestone); it is not a second `occ hrmq:rules:audit`.
- **`MijnGebruikelijkLoon`** — D7.
- **Menu-level role-lens deduplication** (the 18 index pages over 6 schemas, ADR-097 Decision 5) —
  a different change; this design only removes the Dashboard's own instance of the same pattern
  (rows 1–3 of the old widget grid).

## Decisions

### D1 — Per-indicator data binding: `dataSource` where OR's aggregation answers the question, `endpointSource` where it structurally cannot

| Indicator | Binding | Why |
|---|---|---|
| Billable ratio | `dataSource`, raw GraphQL, 2 aliased categorical group-bys on `Timesheet.period` (`billable: true` filtered vs unfiltered, both `metric: sum, sumField: hours`) | Single schema, single field, categorical (period is a `YYYY-MM` string, not a date type — `bucket`'s time-interval form does not apply; `aggregate`'s categorical form does). Two series → raw GraphQL, per the two-alias pattern above. |
| Headcount & turnover | `dataSource`, raw GraphQL, 2 aliased time-bucket group-bys on `Employee.startDate` / `Employee.endDate`, `interval: MONTH`, `metric: COUNT` | Both fields are real dates — `bucket`'s native form applies directly, one alias per field on the *same* schema. |
| Absence rate | `endpointSource` → `AnalyticsController::trends(metric: absence-rate)` | `AbsenceRateService` walks `absenceProgression` step functions against FTE-weighted `EmploymentContract` overlap per period — a cross-object, stateful calculation no `dataSource` form expresses. Already built (`absence-rate`, landed today); this change is purely its first caller. |
| Payroll cost per period | `endpointSource` → `AnalyticsController::trends(metric: payroll-cost)` | **Deliberately not `dataSource`**, even though `PayrollRun.period` categorical group-by with `metric: sum` could technically answer it. See D3 — the guard is the reason, not a data-shape limitation. |
| Approval lead time | `endpointSource` → `AnalyticsController::trends(metric: approval-lead-time)` | No `MEDIAN` in OR's `AggregationMetric` enum (`COUNT\|SUM\|AVG\|MIN\|MAX` only — `useDataSource.js:277`), and no stored duration field exists to `AVG` even if there were (see Context — declarative subtraction is not expressible). Computed server-side as a **mean**, documented as a mean. |
| Obligations list | `endpointSource` → `AnalyticsController::obligations()` | A cross-schema union (`SickLeaveCase` + `EmploymentContract` + `BhvCertificering`) plus a rule-engine badge; `object-table`'s `source` binds exactly one register+schema, no union primitive exists. |

**Alternative considered and rejected**: build all six through `dataSource` where even barely
possible (maximal ADR-022 compliance). Rejected for payroll cost specifically — see D3. Accepted
for the other two `dataSource` picks because nothing about them is more sensitive than what an
`Employee`/`Timesheet` index page already exposes to every authenticated user today.

### D2 — Absence rate renders `null` as a gap, never `0%`

`AbsenceRateService::absenceRate()` returns `percentage: null` when `availableDayEquivalents` is
zero — deliberately, per its own docblock: *"Returning 0.0 would render on a dashboard as '0% —
excellent', which is a measurement that never ran reported as a good result."* The one job this
widget's contract has is to not undo that.

`AnalyticsController::trends(metric: absence-rate)` MUST emit `null` (never `0`) for a period
bucket with no availability, and the chart widget's `endpointSource.series[].path` mapping MUST
carry that `null` through to ApexCharts unmodified (ApexCharts renders a `null` series point as a
break in the line, not a zero — ApexCharts' own documented null-handling, not new hrmq behaviour).
`tasks.md` includes an explicit before/after assertion for this (a period with zero contracts
renders a gap, verified by reading the series array the widget actually receives, not by "the
chart looks fine").

### D3 — Payroll cost per period is guarded, which is why it is NOT `dataSource`

An analytics endpoint returning organisation-wide payroll cost is not something every employee may
call (brief, Things to get right #2). OpenRegister's `PermissionHandler` returns `true` on an
empty `authorization` block — "default-OPEN" — and 0 of hrmq's 55 schemas declare one (brief §4).
A `dataSource`-bound chart reads straight through that open door: whatever guard this design adds
would not apply to it. Routing Payroll cost through `dataSource` would therefore ship the exact
defect the brief names, dressed as the ADR-022-preferred implementation.

So this indicator is the one place this design spends the "prefer the abstraction" default: it
goes through `AnalyticsController`, which can refuse a caller before returning a number, at the
cost of hrmq owning one more small endpoint. The other two `dataSource` picks (D1) are not
reconsidered on the same grounds — they expose no more than the `Employees`/`Timesheets` index
pages already do today, so guarding them here would be inconsistent (this change would be
tightening one door while every other door on the same corridor stays open) without closing
anything real.

### D4 — Tenant scope: dataSource-native widgets inherit the existing token; guarded endpoints resolve server-side and never trust a parameter

The two `dataSource` widgets filter with `administrationId: "@workspace.activeAdministrationId?"`
— the same token every index page uses. This inherits the live brief §6 defect (the sentinel
reaches the API unsubstituted, `total: 0`) **unfixed**; fixing the renderer/store wiring behind
that token is out of scope here and would silently expand this change's blast radius into every
existing index page. Named as a residual gap, not hidden.

The two guarded endpoints do **not** accept an `administrationId` request parameter at all —
resolving it from a client-controlled value (even a correctly-substituted one) would let a caller
ask for a tenant they merely typed, not one their `AdministrationAccess` rows grant. Instead
`AnalyticsController` resolves the caller's active administration server-side via the existing
`AdministrationService::getActiveAdministrationId($userId)` (the exact mechanism
`PageController::index()` already calls to stamp `IInitialState`), then scopes every OpenRegister
query it issues to that id. No caller-supplied tenant value is ever trusted — this is stricter than
the brief's "uses the same tenant filter the index pages use" ask, not weaker, because "the same
filter" would mean trusting the same broken parameter.

### D5 — Obligations list: three date-window queries plus a best-effort rule badge, not a second audit mechanism

`RuleAuditService::audit()` (the `occ hrmq:rules:audit` backing service) loads every object of
every engine-supported type (`LIMIT = 10000` per type, `lib/Service/RuleAuditService.php:50`) and
returns **aggregate** counts only — `violationsBySeverity`, `topViolatedRules` keyed by `ruleId` —
discarding per-object identity (`RuleAuditService.php:203-221`, confirmed by reading the loop: no
`objectId` is retained at the point a `Violation` is counted). It cannot feed "which employee, by
when" rows, and calling it fresh on every dashboard load would also be the wrong cost shape for a
landing page.

So `AnalyticsController::obligations()` does not call `audit()`. It runs the same three
already-shipped date-window queries the old Dashboard ran as three separate tables (unchanged
filters, unchanged windows — `EmploymentContract` 60 days per `hr-signals`, `BhvCertificering` 90
days per `bhv-organisatie`, plus `SickLeaveCase` WVP milestones `*Due` and not `*Done`), merges
them into one `{type, employeeId, subject, dueDate, route}` row shape sorted by nearest `dueDate`,
capped at a small N, and — for exactly those already-loaded rows, not the whole register — calls
`RuleEngine::evaluate($type, $object, $context)` (a public static, `RuleEngine.php:194`, already
called this way inside `RuleAuditService::audit()`'s own loop) to attach a `mandatory`-severity
badge when one applies. Predicates that need cross-object context degrade to a vacuous pass when
that context is absent (the same fail-safe direction `RuleAuditService`'s own comments document
throughout — "skipping degrades to vacuous pass, never to a false violation"), so a lighter context
here under-reports rather than fabricates.

**Alternative considered and rejected**: extend `RuleAuditService::audit()`'s return contract to
retain `objectId` per violation, then filter its output. Rejected — that is a change to the rule
engine's own reporting contract serving one caller, and the smallest version of the ask ("surfacing
violations... is in scope; building a whole compliance page is not") is met without touching it.

### D6 — Role-default layouts: the mechanism is real, this change does not use it

Investigated per the brief's ask, and the conclusion differs from a first read of OpenRegister's
generic mechanism:

- **OpenRegister's own `runtime.user` (`ManifestService::getEnrichedManifest()`) does not cleanly
  attach to hrmq.** It requires a top-level manifest `currentUserSchema` key (hrmq declares none —
  today's `runtime.user` in `src/manifest.json` is the static literal
  `{"administrationMode": "standard"}`, never enriched), and resolves the caller's profile by
  filtering the named schema on a hardcoded `ncUserId` field (`ManifestService.php:222`) — hrmq's
  equivalent field is `nextcloudUserId` (`hr-objects.json` `Employee.nextcloudUserId`), a name
  mismatch that would make `resolveUserProfile()` return `null` for every user, silently, the
  exact "filter on a non-property matches nothing" failure mode the brief warns about.
- **hrmq has its own, separate, already-working mechanism for the same `runtime.user` /
  `visibleIf` primitive**, unrelated to OpenRegister's: `PageController::index()` resolves
  `AdministrationService::getActiveAdministrationMode($userId)` and stamps it via
  `IInitialState::provideInitialState('activeAdministrationMode', …)`; `App.vue`'s
  `effectiveManifest` computed merges it into `manifest.runtime.user.administrationMode`
  (`src/App.vue:82-104`); `CnAppNav` evaluates menu-item `visibleIf` against it. This is real,
  shipped (`single-person-modes`), and the natural place to add a fourth stamped value —
  `activeAdministrationRole`, read from the same `AdministrationService` accessor D3 adds — mirrors
  `administrationMode` exactly.

So the *mechanism* is not the blocker. What is missing is a validated answer to "which of the six
widgets does an `employee`-role caller see instead, and what replaces them" — a product question,
not a technical one, and this change's evidence does not extend to it. Shipping `visibleIf` gates
now would mean guessing that answer and encoding the guess into the manifest.

This change therefore ships **one layout**. The D3 guard already stops an `employee`-role caller's
four HR-only widgets from returning real data (they render the widget's own empty/unavailable
state on a 403 — safe, if visually redundant for that caller). The fast-follow is named precisely
enough to build without re-deriving it: stamp `activeAdministrationRole` next to
`activeAdministrationMode` in `PageController::index()`, mirror the `App.vue` merge, add
`visibleIf: {"user.activeAdministrationRole": {"in": ["hr", "accountant"]}}` to the four HR-only
widgets once the product question above has an answer.

### D7 — `MijnGebruikelijkLoon` is untouched and out of scope

It is a second `type: "dashboard"` page (`src/manifest.json` line 8645), `visibleIf`-gated on
`administrationMode: dga_single_person`, carrying exactly two `banner` widgets bound to
`GET /apps/hrmq/api/payroll/dga-status` — a compliance-status notice, not a metric grid. It shares
no widget, no data source, and no menu entry with the `Dashboard` page this change redesigns. It
is not queue-depth (a banner has no count to be steerable or not), so the governing rule this
change applies does not bear on it. Left alone.

### D8 — Manifest placement: the `Dashboard` page id, route, and menu entry are unchanged

Only `pages[].widgets` under `id: "Dashboard"` changes. The menu entry, the route (`/dashboard`),
and the page `id` are untouched — ADR-096's landing-page norm and ADR-097's dashboard exemption
both already hold for hrmq today and need nothing from this change to keep holding.

## Risks / Trade-offs

- **The Obligations list's rule badge can under-report.** [Risk] A mandatory violation whose
  predicate needs cross-object context this design does not build (D5) is silently not shown on a
  row. → Mitigation: this is the same fail-safe direction every other context-light call in hrmq
  already accepts, documented as a known limitation, not fixed by widening context-building scope
  into this change.
- **`employee`-role callers see four widgets fail rather than four widgets absent.** [Risk] Without
  D6's `visibleIf` fast-follow, a non-HR caller's Dashboard shows an inline unavailable state four
  times. → Mitigation: no data leaks (D3 still holds); this is a UX rough edge, not a security gap,
  and is the honest cost of not guessing the product answer D6 defers.
- **The two `dataSource` widgets stay exposed to any authenticated user, and stay subject to the
  §6 sentinel defect.** [Risk] Neither is new exposure (D1/D4), but neither is this change's
  opportunity to close either gap — both are named, not silently carried.
- **Landing-order dependency on `absence-rate`.** Already landed on this branch, so this is
  historical rather than live, but the Absence rate widget has nothing to call if that change is
  reverted independently.

## Open Questions

- **Which widgets does an `employee`-role caller see instead of the four HR-only ones, once D6's
  fast-follow lands?** Genuinely deferrable — it changes a future `visibleIf` wiring, not this
  change's specs, endpoint contracts, or task breakdown. Tracked as the D6 fast-follow.
