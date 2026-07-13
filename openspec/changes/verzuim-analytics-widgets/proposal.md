---
kind: config
---

# Verzuim Analytics Widgets (absence KPIs on the Dashboard + a WVP werkvoorraad page)

## Why

The Spectr canon insight `hrmq-canon-verzuim-analytics` scores absence analytics at **3/9 competitive coverage** — Dutch-SMB HR suites mostly stop at raw ziekmeldingen lists — while hrmq already *stores* everything an absence dashboard needs (leave-verzuim-mvp: `SickLeaveCase` with the four stored WVP milestone Due dates; `LeaveRequest` with status/hours) and *shows* none of it: the Dashboard has zero verzuim or verlof analytics, and the only sickness surface is the unfiltered `SickLeaveCases` index sorted by newest first. An HR user cannot answer the three questions the WVP clock forces every week — how many cases are open, which are approaching the 42-weken UWV-melding horizon, what leave is waiting — without opening three lists and counting by hand.

The investigation (design.md, "Widget capability (investigated)") established exactly which analytics shapes are demonstrably supported at HEAD, and this change ships **only** those: `stat` widgets over OpenRegister's `/value` aggregation endpoint (`metric: count|sum`, operator-aware filters like `firstSickDay[lte]`, fetch-time tokens `@today-294d` / `@monthStart` — every piece verified in the vendored nc-vue source and the OpenRegister checkout), plus a pre-filtered deadline-sorted index page (the round-1 `LoonaangifteFilings` pattern). Bradford factor and trend charts are named non-goals with the precise technical reason each is out.

## What Changes

- **Four analytics `stat` widgets on the existing Dashboard** (a new verzuim/verlof row; adjusts layout only, no existing widget changes):
  - `dash-verzuim-open` — open ziektegevallen: count `SickLeaveCase` where `status: "gemeld"`, deep-linking to the new `VerzuimOverzicht`;
  - `dash-verzuim-langdurig` — langdurig verzuim past the WVP 42-weken horizon: count `SickLeaveCase` where `status: "gemeld"` AND `firstSickDay` ≤ `@today-294d` (42 weeks = 294 days, day-granular relative token — the supported grammar), deep-linking to `VerzuimOverzicht`;
  - `dash-leave-pending` — verlofaanvragen in behandeling: count `LeaveRequest` where `status: "submitted"`, deep-linking to the existing `LeaveApproval`;
  - `dash-leave-hours-month` — goedgekeurde verlofuren deze maand: **sum** of `LeaveRequest.hours` where `status: "approved"` AND `startDate` ≥ `@monthStart` (sum metric verified supported), deep-linking to `LeaveRequests`.
- **New `VerzuimOverzicht` index page** (`/verzuim`, menu child in `Verlof & verzuim`): `SickLeaveCase` pre-filtered open (`filter: { "status": "gemeld" }`), columns including all four WVP milestone Due dates, sorted by `uwv42WeekMeldingDue` ascending — the deadline-sorted werkvoorraad, mirroring the round-1 `LoonaangifteFilings` deadline-queue pattern. The unfiltered `SickLeaveCases` index stays untouched as the full-history surface.
- **Seed data** — verify each widget renders non-empty against `hr-seed.json` (read fresh) and extend only where one would render empty: (a) the existing long-term case `sickcase-bakker-longterm` (firstSickDay 2025-09-29) only crosses the 42-weken horizon on 2026-07-20, so the `langdurig` widget shows 0 today → add one clearly-past-42-weeks compliant case (`sickcase-degroot-wvp42`, firstSickDay 2025-06-02, all four milestones derived AND done — no new WVP violations); (b) no approved `LeaveRequest` exists in the current month, so the sum widget shows 0 → add one approved July-2026 request for `employee-jansen` (16 hours). Open-cases and pending-leave widgets are already fed (2 open cases; 1 submitted request).

### Non-goals (each with the honest technical reason)

- **No Bradford factor** — no widget data source can compute it: Bradford is S²×D *per employee* (spells squared × days), a per-group computed expression; the aggregation surfaces (`/value` metrics count/sum/avg/min/max, `/grouped` group-by count/sum, chart `aggregate` DSL) evaluate one metric over stored numeric fields and cannot square, multiply, or combine two aggregates. Serving it would require a bespoke app REST endpoint consumed via `endpointSource` — new PHP, out of scope for this config change.
- **No frequency/duration trend charts** — *frequency* trends (new cases per month) are actually library-supported in principle (chart `dataSource.bucket` → OpenRegister `/timeseries`, verified at HEAD), BUT the bucket form requires a from/to range fed by a dashboard `dateRange` context or a hardcoded `staticRange` — the hrmq Dashboard has no dateRange wiring, and hardcoding dates into a chart rots immediately. *Duration* trends (average sickness duration) are genuinely unsupported: they need a date-difference (`recoveredDate - firstSickDay`) that no aggregation metric can express (metrics operate on stored numeric fields only). Both are recorded as the follow-up `verzuim-trend-charts`, gated on Dashboard dateRange adoption.
- **No new schemas, no PHP, no corpus changes** — pure manifest + seeds; the rule corpus is untouched (the existing WVP rules already police the milestone data these widgets read).
- **No verzuimpercentage KPI** — a rate needs a denominator (contracted hours/FTE per period) that no single-schema aggregation can produce; same follow-up family as the trends.

## Capabilities

### New Capabilities

- `verzuim-analytics-widgets`: the four absence/leave analytics stat widgets on the Dashboard, the `VerzuimOverzicht` deadline-sorted open-cases page, and the seed extensions that make both demonstrable.

### Modified Capabilities

<!-- none — verzuim-wvp's schema/rules/pages are untouched (SickLeaveCases index stays as-is; the new page is an additive scoped queue owned by this capability); mijn-hr-self-service's Dashboard widgets are untouched (this change only appends a row) -->

## Impact

- `src/manifest.json` — 4 Dashboard widgets + layout row (existing widgets untouched; the recent-hours table shifts down), `VerzuimOverzicht` index page, 1 menu child in `VerlofVerzuimGroup`.
- `lib/Settings/register.d/hr-seed.json` — 1 new SickLeaveCase seed (past-42-weeks, milestone-compliant), 1 new approved LeaveRequest seed (current month).
- No PHP, no schema changes, no register version bump, no corpus/RuleCatalogue changes (kind: config, manifest+seeds only).
- Related active changes: `mss-team-scope` (sibling; also appends Dashboard widgets — whichever lands second union-merges `config.widgets`/`config.layout` and re-flows `gridY`; widget-id namespaces are disjoint: this change uses `dash-verzuim-*`/`dash-leave-*`, the sibling `dash-team-*`/`dash-hr-*`, and the sibling deliberately adds no global leave widget so `dash-leave-pending` here is the only one), `hrmq-ia-navigation-alignment` (owns any later menu re-homing).
