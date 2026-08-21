---
kind: code+config
---

## Why

"Mijn HR" is hrmq's personal surface — and today it is a dead group header. The base manifest
declares `MijnHrGroup` (label "Mijn HR", icon `Account`, order 20) with **no `route`**
(`src/manifest.json`, menu shell), so `CnAppNav` renders it as a route-less parent whose title
click only toggles its children open (`CnAppNav.vue:1266`, the route-less-group branch of
`onItemClick`). There is no personal landing page: an employee who opens "Mijn HR" gets a fold-out
of nine links and no overview of their own situation.

That has a budget consequence under hydra ADR-097 (`hydra/openspec/architecture/
adr-097-navigation-budget.md`, status Proposed). Decision 2 exempts "**One personal surface** —
'My HR', 'Mijn zaken', 'My work' — the caller's own records" from the six-entry main-menu budget,
but Decision 3 makes the exemption conditional:

> A `main`-section personal entry MUST:
>
> - **route to a `type: "dashboard"` page scoped to the caller** — the personal landing surface,
>   standing in the same relation to the personal entry as the app dashboard does to the app. Not
>   an index page, and not a bare parent with no route of its own.
> - **carry children**, which are the caller's own collections.

and Decision 8 closes the loophole fail-safe:

> An entry claiming the exemption and meeting neither is **counted against the budget** — the
> gate never grants an exemption it could not verify.

hrmq's effective `main` menu today carries **7** top-level entries (Dashboard, MijnHrGroup,
EmployeesGroup, VerlofVerzuimGroup, PlanningGroup, ExpensesGroup, PayrollGroup;
ConfiguratieGroup is already `section: "settings"` per ADR-079 Decision 5). The Dashboard is
exempt (ADR-096 Decision 1 via ADR-097 Decision 2), but `MijnHrGroup` — "a bare parent with no
route of its own" — fails Decision 3's first condition, so gate-65 counts it: **6 counted
entries, exactly at the ceiling**, with zero headroom. Giving the personal entry its dashboard
moves hrmq to 5 counted entries and makes the exemption real rather than claimed. This is also
ADR-097's stated intent: "Decision 3 makes the personal-surface exemption cost something. An app
that wants it builds a personal dashboard, which is a thing users want anyway."

The product-owner decision is settled: every "Mijn/My" surface routes through **one personal,
caller-scoped dashboard** as its landing. The main `/dashboard` page is no longer that surface —
`hrmq-dashboard-steering-indicators` rebuilt it as a management steering page and **removed** the
old REQ-MHS-005 self-service KPI widgets, so the personal dashboard is a **new page**, not a
modification of `/dashboard`.

## What Changes

- **New `type: "dashboard"` page `MijnHr` (route `/mijn`)** in a per-change manifest fragment
  (`src/manifest.d/personal-dashboard.json`, ADR-037), every widget filtered to the caller
  (`userId: "@me"` / `managerUserId: "@me"` — the same token grammar the Mijn index pages use).
  Six widgets: my hours this month (sum of `TimeEntry.hours`), my timesheets with status and
  entry count, my leave balance, my open expense claims, my recent payslips, and my pending
  approvals count (shown always — the dashboard widget grammar has no conditional-visibility
  primitive for stat tiles; see design.md D4).
- **`MijnHrGroup` itself routes to `/mijn`** while keeping all its children. Verified mechanism:
  `CnAppNav` renders a group with both `route` and `children` as a router-link title plus a
  collapse chevron (`itemTo()` returns `{name: item.route}` for any routed item,
  `CnAppNav.vue:1150-1157`; `:allow-collapse` derives from `visibleChildren`,
  `CnAppNav.vue:108`), and `buildManifest()`'s `mergeMenuItems` lets the fragment supply the
  group's missing `route` key (`buildManifest.js:111-116`). No "Mijn overzicht" first child is
  needed.
- **Route-prefix cleanup**: `MijnGebruikelijkLoon` moves from `/mijn-hr/gebruikelijk-loon` to
  `/mijn/gebruikelijk-loon` (the only Mijn page outside the `/mijn/...` prefix), with a
  hand-written redirect route in `src/main.js` per the existing `/vehicles/:id` precedent
  (`src/main.js:150-151`) so stale bookmarks resolve. No `deepLinks[]` change — all 37 entries
  are schema `urlTemplate`s; none references `/mijn-hr` (verified).
- **`LeaveBalance` gains the denormalized `userId`** (the one @me-relevant schema REQ-MHS-002's
  set missed), stamped by `LeaveAccrualJob` on every balance write and added to the Jansen seed
  row, so the leave-balance widget can be caller-scoped like everything else.
- **e2e**: new `tests/e2e/spec-coverage/personal-dashboard.spec.ts` (gate-19 traceability;
  CI-seeded via `tests/e2e/ci-seed.sh`, which refuses :8080 by design).

Labels stay **Dutch literals** — `hrmq-i18n-locale-completeness` lands after this change and owns
the key conversion; this change adds no English keys and converts nothing.

## Capabilities

- **Added**: `hrmq-personal-dashboard` — the caller-scoped personal landing dashboard and the
  ADR-097 Decision 3 exemption conditions it satisfies.
- **Modified**: `mijn-hr-self-service` (menu group routes to the personal dashboard;
  `LeaveBalance` joins the denormalized-`userId` set; `MijnGebruikelijkLoon` route normalized),
  `leave-accrual-job` (the job stamps `userId` on the balances it creates and updates).

## Impact

- `src/manifest.d/personal-dashboard.json` (new fragment: `MijnHr` page + `MijnHrGroup` route
  key via the re-declare-group-by-id mechanism). No `deepLinks`/`runtime`/`dependencies` keys in
  the fragment (silently dropped by `buildManifest()` — grep-checked).
- `src/manifest.json` (base): `MijnGebruikelijkLoon.route` only — the page lives in the base per
  the fragment pipeline's Decision 2 (app-shell pages), so this one key cannot move to a
  fragment.
- `src/main.js`: one redirect route (`/mijn-hr/gebruikelijk-loon` → `/mijn/gebruikelijk-loon`).
- `lib/Settings/register.d/hr-leave.json` (`LeaveBalance` + `userId`, 0.2.0 → 0.3.0),
  `hr-seed.json` (stamp Jansen's balance), `lib/BackgroundJob/LeaveAccrualJob.php` (stamp on
  create/update) + unit tests.
- `tests/e2e/spec-coverage/personal-dashboard.spec.ts` (new).
- **Ordering**: lands **after** `hrmq-hours-process-redesign` — the hours widgets bind to its
  `TimeEntry` schema and `Timesheet.entryCount` aggregate, and the spec deltas here are written
  against that change's post-state of REQ-MHS-002/004. Lands **before**
  `hrmq-i18n-locale-completeness`.

## Non-Goals

- **No change to the management `/dashboard`** — `hrmq-dashboard-steering-indicators` owns it;
  its "no stat widget SHALL remain" and pairwise-duplication scenarios are scoped to the
  `Dashboard` page and are untouched by a new page.
- **No menu-count reduction work** beyond the exemption itself — collapsing role-lens duplicates
  (ADR-097 Decision 5) and any regrouping are separate changes.
- **No RBAC** — widgets are `@me`-filtered the same way the Mijn index pages are; row-level
  authorization remains the fleet-wide programme it already is.
- **No label/i18n conversion** (owned by `hrmq-i18n-locale-completeness`).
- **No new backend endpoints** — every widget binds to OpenRegister's existing aggregation/object
  APIs; the guarded analytics endpoints stay management-only.
