---
kind: design
---

## Why

`src/manifest.json`'s `Dashboard` page (`pages[].id: "Dashboard"`, from line 722) carries 15
widgets across 23 grid rows: 12 `stat` tiles (`gridY` 0/2/4/6, three-then-three-then-two-then-two
per row) and 3 full-width `object-table` widgets (`gridY` 8/13/18, height 5 each). Every one of the
12 stat tiles is a queue-depth count (`metric: count` or a `sum` of pending hours) — "how much
work is waiting," never "is it getting better or worse." As measured live on 2026-08-19 (brief
§6), ten of the twelve read `0`, one (`"Goedgekeurde verlofuren deze maand"`, a `sum` with no
matching rows) renders a literal em-dash, and all three tables read "No items found" — but that
emptiness is the pre-existing §6 sentinel-substitution defect (`@workspace.activeAdministrationId?`
reaching the API unsubstituted), not the design fault this change fixes. The design fault survives
even when the data is populated: a queue depth is not steerable, and rows 1–3 (`gridY` 0/2)
duplicate the same three counts three times — mine / my team's / everyone's — which is exactly the
role-lens pattern ADR-097 Decision 5 names and asks to be collapsed into a preset or a scope, not a
tile.

A dashboard tile earns its place by showing a number someone can move, and how it has been moving.
An inbox is one list, not twelve tiles. This change replaces the 15 widgets with six: five trend
indicators an HR/payroll reader can act on, and one list of obligations that have a deadline —
plus the backend the two indicators that cannot be served by OpenRegister's own aggregation need.

## What Changes

- **The `Dashboard` page's 15 widgets are replaced by 6**, fitting one screen with no scrolling:
  Billable ratio (declarabiliteit), Absence rate (verzuim, from the `absence-rate` capability
  landed today on this branch), Headcount & turnover, Payroll cost per period, Approval lead time,
  and one full-width **Obligations** list (replaces the 3 former tables plus the verzuim/leave
  queue-depth tiles).
- **Two indicators bind to OpenRegister's own aggregation** (`chart` widget `dataSource`, no new
  endpoint): Billable ratio (`Timesheet`, categorical group-by on `period`) and Headcount & turnover
  (`Employee`, time-bucket group-by on `startDate` / `endDate`). Both keep today's exposure — read
  through the unguarded OpenRegister REST API, same as every existing index page.
- **Three indicators need a new, guarded endpoint** (`chart` / `object-table` `endpointSource`):
  Absence rate (wraps the already-landed `AbsenceRateService`, which OpenRegister's aggregation
  cannot express), Payroll cost per period (deliberately routed off the OR-abstraction path — see
  design.md D3 — because it is organisation-wide payroll €, not because OR cannot sum a field), and
  Approval lead time (no stored duration field exists, so the durations are derived per record in
  PHP — and once they are an array there, the endpoint returns a **median and a p90**, the two
  statistics this metric is actually read with; a mean would let one 200-day outlier describe a
  two-day process as a three-week one).
- **New `AnalyticsController`** (`lib/Controller/AnalyticsController.php`, mirroring pipelinq's
  `AnalyticsController` shape): `GET /api/analytics/trends?metric=…&period=…` for the three
  endpoint-bound trends, `GET /api/analytics/obligations` for the list. Every action resolves the
  caller's tenant server-side via the existing `AdministrationService` (never a request parameter)
  and requires an `AdministrationAccess` row with `role` `hr` or `accountant` — the first real
  enforcement of that field, which today is documented on the schema itself as "purely descriptive."
- **The Obligations list** merges `SickLeaveCase` WVP milestones due-and-not-done, expiring
  `EmploymentContract`s (the existing 60-day `hr-signals` window, unchanged), expiring
  `BhvCertificering` (the existing 90-day `bhv-organisatie` window, unchanged), and a best-effort
  mandatory-violation badge per row from `RuleEngine::evaluate()` — not a second alerting mechanism,
  the same one `hr-signals`/`bhv-organisatie` already use, called against the small already-loaded
  row set rather than the full corpus `occ hrmq:rules:audit` walks.
- **`mijn-hr-self-service`'s self/approver KPI row and `verzuim-analytics-widgets`'s four absence
  stat tiles are removed from the Dashboard, not relocated.** The self-service index pages
  (`MijnUren`, `MijnDeclaraties`, `MijnVerlof`, …) and the approval queues (`TimesheetApproval`,
  `TeamUrengoedkeuring`, `VerzuimOverzicht`, …) are unchanged and stay one click away from the main
  menu; this change removes their Dashboard mirror, it does not remove the pages.
- **BREAKING (manifest, not API):** every widget id under the old `Dashboard` page's `widgets[]`
  array is removed. Nothing outside `src/manifest.json` references a Dashboard widget by id, so
  this has no other blast radius.
- **`MijnGebruikelijkLoon` (the second `type: "dashboard"` page) is untouched.** It is a
  `visibleIf`-gated, two-`banner`-widget compliance-status surface for `dga_single_person` mode —
  not a queue-depth page and not part of this change's scope (design.md D7).
- **Role-default layouts are investigated and NOT built.** A real mechanism exists — hrmq's own
  `PageController::index()` → `IInitialState` → `App.vue`'s `effectiveManifest` → `visibleIf`
  pipeline, the exact one `single-person-modes` already ships for `administrationMode` — but this
  change ships one layout for every caller who can reach the Dashboard. The new endpoint guard
  (above) already stops an `employee`-role caller's four HR-only widgets from returning data;
  which widgets to *hide* rather than gray out per role is a product decision this change does not
  have the UX evidence to make (design.md D6, DEFERRED_QUESTIONS).

## Capabilities

### New Capabilities
- `hrmq-dashboard-steering-indicators`: the redesigned Dashboard (six steering-indicator widgets)
  and the `AnalyticsController` backend serving `payroll-cost`, `approval-lead-time`, and
  `obligations` — the guard, the tenant resolution, and the null-vs-zero contract.

### Modified Capabilities
- `absence-rate`: adds the analytics-endpoint exposure its own spec explicitly deferred
  ("wiring it to an analytics endpoint is a separate change" — this one). The calculation
  contract (`AbsenceRateService`, the null-not-zero rule) is unchanged.
- `mijn-hr-self-service`: REQ-MHS-005 ("The Dashboard page SHALL surface self-service and approver
  KPIs from the built-in widget set") is removed. REQ-MHS-001–004 and REQ-MHS-006 (the account
  link, the denormalized `userId`, the menu slots, the `@me` self-service pages, seed data) are
  unchanged.
- `verzuim-analytics-widgets`: REQ-VZA-001 (the four Dashboard absence stat widgets) is removed,
  superseded by the Absence rate trend widget. REQ-VZA-002/003 (`VerzuimOverzicht`, seed data) are
  unchanged.
- `hr-signals`: REQ-SIG-005 ("The dashboard shows 'Aflopende contracten'") is modified — the
  60-day expiring-contract signal now renders as rows in the Obligations list instead of a
  dedicated full-width table. The underlying rule (`nl-signaal-contract-verloopt`), the window, and
  REQ-SIG-001–004/006 are unchanged.
- `bhv-organisatie`: REQ-BHV-005 ("BHV pages SHALL live under the existing Verlof & verzuim menu
  group... and SHALL add the 'Aflopende BHV-certificaten' widget to the existing Dashboard page")
  is modified — the menu-placement clause is unchanged, the dashboard-widget clause is replaced:
  the 90-day BHV-expiry signal folds into the Obligations list instead of a dedicated widget.
  REQ-BHV-001/002/003/004 are unchanged.

## Impact

- **`src/manifest.json`** — the `Dashboard` page's `widgets[]` array is fully replaced (15 → 6);
  no other page is touched.
- **`lib/Controller/AnalyticsController.php`** (new) — `trends()`, `obligations()`.
- **`lib/Service/AnalyticsService.php`** (new) — payroll-cost aggregation, approval-lead-time percentiles (median + p90),
  obligations merge; wraps the existing `AbsenceRateService` for the `absence-rate` metric.
- **`appinfo/routes.php`** — two new `GET` routes.
- **`AdministrationService`** — gains a small read accessor for the active administration's
  `AdministrationAccess.role` (no schema change; the field already exists).
- No schema version bump, no new register fragment, no data migration.
- Depends on `absence-rate` (landed today on this branch) for the Absence rate metric's
  calculation.
