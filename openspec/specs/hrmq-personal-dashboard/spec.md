---
capability: hrmq-personal-dashboard
status: done
built_by: openspec/changes/archive/2026-08-22-hrmq-personal-dashboard
---

# hrmq-personal-dashboard Specification

**Status**: done
**Scope**: hrmq
**OpenSpec changes**:
- [hrmq-personal-dashboard](../../changes/archive/2026-08-22-hrmq-personal-dashboard/) _(archived 2026-08-22, merged as humaniq#133, follow-up humaniq#137)_ — the caller-scoped `MijnHr` dashboard page at `/mijn` with six widgets that each carry a `userId: "@me"` or `managerUserId: "@me"` filter, and the `Mijn HR` menu group routing to it while keeping its children (kind: code+config)

## Purpose

Give hrmq's "Mijn HR" entry a personal landing surface: a `type: "dashboard"` page at `/mijn`
whose every widget binds only the caller's own records, so an employee opening "Mijn HR" sees
their own situation instead of a fold-out of nine links. Before this capability the group was a
route-less parent, which is also why hydra ADR-097 Decision 3 counted it against the six-entry
main-menu budget rather than granting it the personal-surface exemption — the exemption is
conditional on routing to a caller-scoped dashboard page that carries children. Every widget
reuses built-in widget types and the existing register API; the capability adds no backend
endpoint and no raw-GraphQL data source.

## Requirements

### Requirement: The app SHALL carry a caller-scoped personal dashboard page `MijnHr` at `/mijn` (REQ-PDB-001)

A new `type: "dashboard"` page `MijnHr` (route `/mijn`, title "Mijn HR"), authored in the
per-change fragment `src/manifest.d/personal-dashboard.json` (ADR-037), using the proven
dashboard grammar (`config.widgets[]` `{id, type, title, content}` + `config.layout[]` grid
entries) and only built-in widget types already resolving on this instance (`stat`,
`object-table` — via the registry override `src/registry.js` documents for `stat`). Every
widget's data binding SHALL carry a caller-scope filter — `userId: "@me"` for the employee's
own records, `managerUserId: "@me"` for the caller's own approval queue — plus the optional
administration token (`"administrationId": "@workspace.activeAdministrationId?"`, dropped when
unresolved). No widget SHALL bind without a caller-scope filter, and no widget SHALL introduce
a new backend endpoint or a raw-GraphQL `dataSource`. This page implements the personal landing
surface hydra ADR-097 Decision 3 requires: "route to a `type: "dashboard"` page scoped to the
caller … Not an index page, and not a bare parent with no route of its own."

#### Scenario: The page exists with the dashboard type and six caller-scoped widgets

@e2e exclude manifest-shape assertion, verified by reading the built page entry (type, widget count, per-widget filter keys); the rendered-page journey is covered by REQ-PDB-002's scenarios
- **GIVEN** the effective manifest after `buildManifest()`
- **WHEN** the `MijnHr` page entry is inspected
- **THEN** `type` is `"dashboard"`, `route` is `/mijn`, `config.widgets` contains exactly 6
  entries, and every widget's filter map — `content.source.filter` on a `stat`,
  `content.filter` on an `object-table` — carries `userId: "@me"` or `managerUserId: "@me"`

#### Scenario: Manifest stays valid

@e2e exclude Validated by `npm run check:manifest` in CI (tasks.md 1.5), a Node validator, not a browser journey
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

### Requirement: The six widgets SHALL bind the caller's own data exactly as specified (REQ-PDB-002)

The widget set, bindings and click-throughs (full configs in design.md D3):

1. **"Mijn uren deze maand"** — `stat`, sum of `TimeEntry.hours`,
   `filter: { userId: "@me", startedAt: { gte: "@monthStart" } }`, tile routes to `MijnUren`.
2. **"Mijn urenstaten"** — `object-table`, `Timesheet`, `userId: "@me"`, `period` desc,
   `limit: 3`, columns `period`/`hours`/`entryCount`/`status`, rows route to
   `TimesheetDetail`, footer to `MijnUrenstaten`. This is the "my current timesheet status"
   surface: status and entry count per row. `limit` is a fetch cap, not a render promise
   (ADR-062) — the visible row count fits the cell.
3. **"Mijn verlofsaldo"** — `object-table`, `LeaveBalance`,
   `filter: { userId: "@me", year: "@currentFiscalYear" }`, columns
   `leaveType`/`entitledHours`/`bovenwettelijkHours`/`usedHours`, no row route (no
   `LeaveBalanceDetail` page exists). Depends on REQ-MHS-002's LeaveBalance extension.
4. **"Mijn open declaraties"** — `stat`, count of `Expense`,
   `filter: { userId: "@me", status: "submitted" }`, tile routes to `MijnDeclaraties`.
5. **"Mijn recente loonstroken"** — `object-table`, `Payslip`, `userId: "@me"`, `period` desc,
   `limit: 3`, columns `period`/`grossPay`/`nettoPay`, rows route to `PayslipDetail`, footer to
   `MijnLoonstroken`.
6. **"Te beoordelen urenstaten"** — `stat`, count of `Timesheet`,
   `filter: { managerUserId: "@me", status: "submitted" }`, tile routes to
   `TeamUrengoedkeuring`. The widget grammar has no conditional-visibility primitive for stat
   tiles (design.md D4), so the tile SHALL always render — a caller who manages nobody sees 0.

All titles/captions are Dutch literals; `hrmq-i18n-locale-completeness` owns their later
conversion and this change SHALL add no English keys.

Two binding SPELLINGS are fixed by the render path rather than by preference (measured live —
design.md D3 addendum), and a config that gets them wrong renders without erroring, which is
why they are stated here: a `stat` tile's click-through SHALL be the vue-router location-object
form (`route: { name: "…" }`), because `<router-link :to>` reads a bare string as a PATH; and a
dashboard `object-table` SHALL use the FLAT content contract (`register` / `schema` / `filter` /
`sort: {field, dir}` / `limit` / `columns` / `rowRoute` / `viewAllRoute` / `emptyText`), because
`object-table` canonicalises to `table` → `CnObjectListWidget`, which reads no `source` key.
Each of the three tables SHALL additionally set `allowCreate: false` — the widget renders a
create affordance by default, and all three collections are read-only to the employee.

#### Scenario: Widgets show only the caller's rows

- **GIVEN** the seeded register where Jansen's records carry `userId: "admin"` and the
  De Vries / Bakker records (including their LeaveBalances) carry no `userId`
- **WHEN** the `admin` user opens `/mijn`
- **THEN** the "Mijn verlofsaldo" table lists exactly the Jansen balance row — the unstamped
  De Vries / Bakker rows never appear (fail-closed)

#### Scenario: The pending-approvals tile renders for every caller and routes to the queue

- **GIVEN** the seeded register with submitted timesheets carrying `managerUserId: "admin"`
- **WHEN** the `admin` user opens `/mijn` and activates "Te beoordelen urenstaten"
- **THEN** the tile shows the count of those submitted timesheets and the router navigates to
  `TeamUrengoedkeuring`

#### Scenario: The hours tile sums the current month through the operator filter

@e2e exclude value-level assertion is time-dependent (the seeded TimeEntry dates vs the test run's month); pinned instead by verification task V1 with its must-fail control, and the widget's presence/scoping is covered by the journeys above
- **GIVEN** seeded TimeEntry rows for the caller inside and outside the current month
- **WHEN** the "Mijn uren deze maand" stat resolves its aggregation
- **THEN** the value equals the sum of `hours` over only the caller's current-month entries
  (`filter[startedAt][gte]=@monthStart`-resolved, `filter[userId]=@me`-resolved)

### Requirement: The "Mijn HR" menu group SHALL route to `/mijn` while keeping its children (REQ-PDB-003)

The per-change fragment re-declares `{ "id": "MijnHrGroup", "route": "MijnHr" }`;
`mergeMenuItems` fills the base group's undefined `route` key (`buildManifest.js:111-116`). The
group keeps every existing child unchanged. Rendered behaviour (per `CnAppNav.vue`): the group
title is a router link to `/mijn` (`itemTo`, line 1150), the chevron still expands/collapses
the children (`allow-collapse`, line 108), and deep-linking to a child still auto-expands the
group (`hasActiveChild`). Together with REQ-PDB-001 this satisfies both conditions of hydra
ADR-097 Decision 3 — the entry "route[s] to a `type: "dashboard"` page scoped to the caller"
AND "carr[ies] children, which are the caller's own collections" — so gate-65's Decision 2/3
check verifies the exemption instead of counting the entry (Decision 8: "An entry claiming the
exemption and meeting neither is counted against the budget"). hrmq's counted main-menu total
drops 6 → 5.

#### Scenario: Group title navigates; chevron still folds

- **GIVEN** the rendered app navigation
- **WHEN** the user clicks the "Mijn HR" group TITLE
- **THEN** the router navigates to `/mijn` (the personal dashboard renders)
- **AND WHEN** the user clicks the group's collapse chevron instead
- **THEN** the nine children toggle visibility without navigation

#### Scenario: The exemption conditions hold on the effective manifest

@e2e exclude manifest-shape assertion over the merged menu (route present, children non-empty, target page type "dashboard"); gate-65 automates exactly this check when it lands — recorded in tasks.md 5.1
- **GIVEN** the effective manifest after fragment merge
- **WHEN** the `MijnHrGroup` entry is inspected
- **THEN** it carries `route: "MijnHr"`, its `children` are non-empty, and the `MijnHr` page's
  `type` is `"dashboard"`
