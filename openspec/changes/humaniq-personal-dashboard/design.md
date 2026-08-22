# Design — humaniq personal dashboard ("Mijn HR" → `/mijn`)

## Context (verified against this checkout, 2026-08-21)

- **ADR-097** (`hydra/openspec/architecture/adr-097-navigation-budget.md`, status **Proposed**):
  Decision 1 caps `main`-section top-level entries at 6; Decision 2 exempts the landing dashboard
  and "**One personal surface** — 'My HR', 'Mijn zaken', 'My work' — the caller's own records";
  Decision 3 conditions the personal exemption on the entry "**rout[ing] to a
  `type: "dashboard"` page scoped to the caller** … Not an index page, and not a bare parent
  with no route of its own" AND "**carry[ing] children**, which are the caller's own
  collections"; Decision 8's gate "never grants an exemption it could not verify" — an entry
  claiming it and meeting neither condition is **counted against the budget**. ADR-079
  Decision 5 (configuration out of the main menu) is already satisfied: `ConfiguratieGroup`
  carries `section: "settings"` in the base manifest.
- **Current count**: the effective `main` menu has 7 top-level entries (Dashboard, MijnHrGroup,
  EmployeesGroup, VerlofVerzuimGroup, PlanningGroup, ExpensesGroup, PayrollGroup). Dashboard is
  exempt; `MijnHrGroup` has children (nine, `src/manifest.d/05-menu.json:4-65`) but **no
  `route`** (`src/manifest.json` menu shell), so it fails Decision 3's first condition and
  counts: **6 counted, at the ceiling**. After this change: 5.
- **Menu rendering** (`node_modules/@conduction/nextcloud-vue/src/components/CnAppNav/
  CnAppNav.vue`): one template branch renders every non-caption `main` item as an
  `NcAppNavigationItem` with `:to="itemTo(item)"` (line 102) and
  `:allow-collapse="visibleChildren(item).length > 0"` (line 108), nesting child
  `NcAppNavigationItem`s inside it (lines 134-156). `itemTo()` (lines 1150-1157) returns
  `{ name: item.route }` for **any** item with a `route` — group or leaf. `onItemClick()`
  (lines 1234-1266) toggles open/closed **only** "route-less items with visible children";
  routed items navigate via `:to` and the chevron still toggles via `@update:open`.
  `isItemOpen()`/`hasActiveChild()` (lines 1186-1203) auto-expand a group whose child route is
  active. So **a group with both `route` and `children` is a clickable landing link AND a
  collapsible parent** — the exact shape Decision 3 requires.
- **Menu merge** (`node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js`,
  `mergeMenuItems`, lines 105-123): fragments merge by `id`; for the keys
  `['label','icon','route','order','section','featureFlag','permission','visibleIf','href',
  'action']` the **first definition wins** and a later fragment fills a key only when it is
  still `undefined`. The base declares `MijnHrGroup` without `route` and `05-menu.json`
  re-declares it with only `id` + `children`, so a per-change fragment re-declaring
  `{ "id": "MijnHrGroup", "route": "MijnHr" }` sets the route without touching anything else.
- **Schema**: `$defs/menuItem` in `app-manifest-v2.schema.json` declares `route` and `children`
  as sibling properties with no mutual exclusion (`additionalProperties: false`, both listed) —
  a routed group validates.
- **Dashboard page grammar** (proven live by the two shipped `type: "dashboard"` pages,
  `Dashboard` and `MijnGebruikelijkLoon`, both in `src/manifest.json`): `config.widgets[]`
  entries `{ id, type, title, content }` plus `config.layout[]`
  `{ id, widgetId, gridX, gridY, gridWidth, gridHeight }` on a 12-column grid, rendered by
  `CnDashboardPage`. A registry-resolved widget receives its `content` both as the `content`
  prop and spread via `v-bind` (`CnDashboardPage.vue:561-563`), which is how flat-prop widgets
  (`object-table`) and content-prop widgets (`stat`, `banner`) both work from the same manifest
  shape. `stat` resolves through humaniq's own registry override (`src/registry.js`, the
  `CnStatWidget` re-registration — the library's self-registration is tree-shaken out of the
  production bundle; that override is load-bearing for this page).
- **`stat` widget data binding** (`CnStatWidget.vue`): `content.source = { register, schema,
  metric: count|sum|avg|min|max, field, filter }` fetches
  `GET /apps/openregister/api/objects/aggregations/{register}/{schema}/value`
  (`fetchAggregate`, lines 717-727). `flattenFilter` (lines 688-705) resolves filter tokens via
  the shared `resolveFilterTokens` and emits operator-aware params:
  `{ startedAt: { gte: … } }` → `filter[startedAt][gte]=…`. `content.route` makes the whole
  tile a click-through. OpenRegister's `AggregationController::value()` reads the same
  operator-aware `filter[...]` vocabulary (`AggregationController.php:124,145`), and `gte`
  maps to `>=` in `MariaDbSearchHandler.php:617`.
- **Token grammar** (`resolveFilterTokens.js`): `@me` (line 115, signed-in uid), `@monthStart`
  (line 132, `YYYY-MM-01` of the current month), `@currentFiscalYear` (current year as a
  **string**), `@today±Nd`; `@workspace.<key>?` optional tokens are dropped when unresolved
  (`dropOptionalUnresolved`). This is the identical grammar the Mijn index pages already use
  (`src/manifest.d/hr-timesheet.json`, `MijnUren` instantiation:
  `{"userId": "@me", "administrationId": "@workspace.activeAdministrationId?"}`).
- **`object-table` widget** (`CnWidgetObjectTable.vue`): declarative
  `source = { register, schema, filter, order, limit }` self-fetches from OpenRegister with the
  same token resolution (lines 239-250, 435-441); `rowRoute` is a **single static route name**
  forwarded as `{ name: rowRoute, params: { id } }` (lines 306-309); `columns` in the object
  form; `limit` shows a footer when rows are a limited subset.
- **Conditional visibility**: the dashboard widget grammar has **no** per-widget
  visibility predicate. `visibleWhen` exists only inside `CnBannerWidget`'s own `content`
  (endpoint-bound, `CnBannerWidget.vue`) and in the dashboard header-actions config
  (`CnDashboardPage.vue:1086`); `CnStatWidget`'s `variantWhen` switches the tile's *variant*,
  never its presence.
- **Data model after `humaniq-hours-process-redesign`** (this change lands directly after it):
  `TimeEntry` (individual booking; `hours` server-derived, `userId` server-stamped,
  `startedAt` date-time), `Timesheet` (period aggregate; server-maintained `hours`,
  `entryCount`, lifecycle `draft|submitted|approved|rejected`, caches
  `userId`/`managerUserId`/`administrationId`), `MijnUren` = TimeEntry booking page
  (`/mijn/uren`), `MijnUrenstaten` = read-only Timesheet list (`/mijn/urenstaten`).
- **Schemas for the remaining widgets**: `Expense` (`hr-expense.json`): `userId`, `status` enum
  `draft|submitted|approved|rejected|reimbursed`. `Payslip` (`hr-objects.json`): `userId`,
  `period`, `grossPay`, `nettoPay`; detail page `PayslipDetail` exists
  (`src/manifest.d/hr-objects.json:642`). `LeaveBalance` (`hr-leave.json`, 0.2.0):
  `employeeId`, `year` (**integer**), `leaveType`, `entitledHours`, `bovenwettelijkHours`,
  `usedHours` — **no `userId`** (REQ-MHS-002's set covered Timesheet/Expense/LeaveRequest/
  Payslip only), and **no LeaveBalance detail page** exists (only the `LeaveBalances` index).
  `LeaveBalance` rows are written by `lib/BackgroundJob/LeaveAccrualJob.php` (create at
  line ~261, update at ~221).
- **Route normalization target**: `MijnGebruikelijkLoon` is a base-manifest page
  (`src/manifest.json`, per the fragment pipeline's Decision 2: app-shell pages stay in the
  base) at `/mijn-hr/gebruikelijk-loon` — the only Mijn surface outside `/mijn/...`. Its menu
  entry references it by route **name**, not path (`05-menu.json:55-64`), so a path change
  does not touch the menu. All 37 `deepLinks[]` are schema `urlTemplate`s
  (`/apps/humaniq/<collection>/{uuid}`); none references `/mijn-hr` — verified by dumping the
  array. `src/main.js:150-151` is the standing precedent for hand-written redirect routes
  (`/vehicles/:id` → `/assets/:id`), added before the catch-all.
- **e2e**: specs live in `tests/e2e/spec-coverage/*.spec.ts`; CI seeds via
  `tests/e2e/ci-seed.sh` (runs against the CI `php -S` instance and refuses :8080 by design);
  the router base must be resolved via `OC.generateUrl` per `core-journeys.spec.ts`'s header.
- **Seeds**: `hr-seed.json` carries `leavebalance-jansen-2026-holiday` (employee-jansen,
  entitled 160 + 40, used 56 — no `userId`), plus devries/bakker balances; Jansen's
  timesheet/expense/leave/payslip rows carry `userId: "admin"`, and submitted rows carry
  `managerUserId: "admin"` (three occurrences), which feeds the pending-approvals widget.

## Decision 1 — The group itself routes; no synthetic first child

**Chosen**: `MijnHrGroup` gains `route: "MijnHr"`. The mechanism supports it end-to-end
(Context: `itemTo()` routes any item with `route`; `allow-collapse` and child rendering are
independent of `route`; `mergeMenuItems` fills the group's undefined `route` from a fragment;
the v2 `menuItem` schema allows `route` + `children` together). The title click lands on
`/mijn`; the chevron still expands the children; deep-linking to a child still auto-expands the
group (`hasActiveChild`).

**Rejected**: a first child "Mijn overzicht" routing to the dashboard while the group stays
route-less. It would satisfy ADR-097 Decision 3's *letter* only by inserting a tenth child and
would leave the group header itself a dead click — "a dashboard with an extra click in front of
it", the exact shape Decision 3 warns turns a personal surface into clutter. The mechanism makes
the direct route strictly better, and the gate (Decision 8) verifies the *entry*'s route, not a
child's.

One interaction note, accepted: with a routed group, a user who wants to *collapse* "Mijn HR"
must use the chevron — the title no longer toggles. That is exactly how every routed group in
`CnAppNav` behaves and how NcAppNavigationItem is designed to compose; not a new pattern.

## Decision 2 — Placement: one per-change fragment; two unavoidable base/main.js touches

Per ADR-037 and the fragment pipeline's standing convention, everything new lands in one
per-change fragment `src/manifest.d/personal-dashboard.json`:

- `pages[]`: the `MijnHr` page (D3).
- `menu[]`: `{ "id": "MijnHrGroup", "route": "MijnHr" }` — the re-declare-group-by-id
  mechanism; `mergeMenuItems` fills the base's undefined `route` key (Context). No children are
  re-declared here (they live in `05-menu.json` and the hours-redesign fragment; re-listing
  them would duplicate custody for no benefit — union-by-id makes it harmless but noisy).
- **No** `deepLinks`/`runtime`/`dependencies` keys — `buildManifest()` silently drops them from
  fragments (its documented four-key read); tasks.md carries the grep check.

Two edits cannot live in the fragment, by mechanism rather than choice:

- `src/manifest.json`: `MijnGebruikelijkLoon`'s `route` value — the page is one of the three
  app-shell pages the fragment pipeline's Decision 2 assigns to the base, and a fragment
  re-declaring the same page id would collide (pages merge is union-by-id, not deep-merge).
- `src/main.js`: the redirect route (D6) — manifest v2's page `type` enum has no redirect
  variant; hand-written router entries are the documented precedent (`main.js:144-151`).

## Decision 3 — The `MijnHr` page and its six widgets

`type: "dashboard"`, route `/mijn`, title `"Mijn HR"`, description
`"Jouw persoonlijke overzicht: uren, verlof, declaraties en loonstroken."` — Dutch literals,
matching the app's current state; `humaniq-i18n-locale-completeness` converts them later and this
change deliberately adds no English keys.

All widgets use the **proven** dashboard grammar (`{id, type, title, content}` + `layout[]`,
Context) and the built-in `stat` / `object-table` types already resolving on this instance —
no custom widget, no new backend endpoint, no raw-GraphQL `dataSource` (the steering change's
`_note` documents why that form is a trap). Every widget carries the caller scope; the
administration scope rides along as the optional token the Mijn index pages already use
(`"administrationId": "@workspace.activeAdministrationId?"` — dropped when unresolved, so the
widget degrades to user-scoped-only, never to another user's data).

| # | Widget (Dutch title) | Type | Binding | Filter / config | Click-through |
|---|---|---|---|---|---|
| 1 | "Mijn uren deze maand" | `stat` | `source`: register `hrmq`, schema `TimeEntry`, `metric: "sum"`, `field: "hours"` | `{ "userId": "@me", "startedAt": { "gte": "@monthStart" }, "administrationId": "@workspace.activeAdministrationId?" }`; `format: { style: "number", decimals: 2 }`, caption "geboekt deze maand" | `route: "MijnUren"` |
| 2 | "Mijn urenstaten" | `object-table` | `source`: schema `Timesheet`, `order: { period: "desc" }`, `limit: 3` | `{ "userId": "@me", "administrationId": "@workspace.activeAdministrationId?" }`; columns `period`, `hours`, `entryCount`, `status` | `rowRoute: "TimesheetDetail"` |
| 3 | "Mijn verlofsaldo" | `object-table` | `source`: schema `LeaveBalance`, `order: { leaveType: "asc" }` | `{ "userId": "@me", "year": "@currentFiscalYear", "administrationId": "@workspace.activeAdministrationId?" }`; columns `leaveType`, `entitledHours`, `bovenwettelijkHours`, `usedHours`; `emptyText` "Nog geen verlofsaldo" | none — no `LeaveBalanceDetail` page exists (Context), and `rowRoute` to an index would break its `{ params: { id } }` contract |
| 4 | "Mijn open declaraties" | `stat` | `source`: schema `Expense`, `metric: "count"` | `{ "userId": "@me", "status": "submitted", "administrationId": "@workspace.activeAdministrationId?" }`; caption "in behandeling" | `route: "MijnDeclaraties"` |
| 5 | "Mijn recente loonstroken" | `object-table` | `source`: schema `Payslip`, `order: { period: "desc" }`, `limit: 3` | `{ "userId": "@me", "administrationId": "@workspace.activeAdministrationId?" }`; columns `period`, `grossPay`, `nettoPay` | `rowRoute: "PayslipDetail"` |
| 6 | "Te beoordelen urenstaten" | `stat` | `source`: schema `Timesheet`, `metric: "count"` | `{ "managerUserId": "@me", "status": "submitted", "administrationId": "@workspace.activeAdministrationId?" }`; caption "wachten op jouw goedkeuring" | `route: "TeamUrengoedkeuring"` |

Layout (12-column, no scrolling on the reference viewport — total grid height 11, below the
steering Dashboard's 13):

| widgetId | gridX | gridY | gridWidth | gridHeight |
|---|---:|---:|---:|---:|
| 1 (uren-maand) | 0 | 0 | 4 | 3 |
| 4 (declaraties) | 4 | 0 | 4 | 3 |
| 6 (te-beoordelen) | 8 | 0 | 4 | 3 |
| 2 (urenstaten) | 0 | 3 | 6 | 4 |
| 3 (verlofsaldo) | 6 | 3 | 6 | 4 |
| 5 (loonstroken) | 0 | 7 | 12 | 4 |

### D3 addendum — three spellings the live render path forced (measured 2026-08-22)

The table above describes the widgets' *intent*; three of its spellings did not survive contact
with the shipping render path and were corrected in `personal-dashboard.json`. Each was measured
in the browser, not inferred, and each is recorded in the page's `_note`.

1. **`route` on a `stat` tile is a route OBJECT, not a bare name.** `widgetLink` passes
   `content.route` straight to `<router-link :to>`, where vue-router 4 reads a *string* as a
   PATH — `"MijnUren"` would have resolved to the path `MijnUren`, missed every route and landed
   on the catch-all. The manifest uses `{ "name": "MijnUren" }`, the same spelling the
   `hr-objects` nav-card entries already use. Verified live: the three tiles render as
   `<a href="/apps/humaniq/mijn/uren">`, `/apps/humaniq/mijn/declaraties` and
   `/apps/humaniq/timesheets/team-approval`.
2. **`object-table` on a `type:"dashboard"` page is `CnObjectListWidget`, with the FLAT content
   contract** — `{ register, schema, filter, sort: { field, dir }, limit, columns, rowRoute,
   viewAllRoute, emptyText, allowCreate }` — not the nested `source` shape this design assumed.
   `CnDashboardPage.registryRenderer` resolves `getWidgetTypeEntry(canonicalWidgetType(type))`,
   and `utils/widgetTypeAliases.js` maps `object-table` → `table`, which
   `registerDashboardWidgets.js` registers INLINE to `CnObjectListWidget`. The `object-table`
   key that `CnWidgetObjectTable/dashboardRegistration.js` registers is therefore unreachable
   from a dashboard: the canonicaliser has already rewritten the name away, and that module is a
   bare side-effect import ADR-061 tree-shaking drops from the production bundle anyway. With
   the `source` shape the widgets rendered their `emptyText` and fired **zero** fetches. Two
   consequences are accepted: `limit` is a FETCH CAP (ADR-062) rather than a render promise, and
   `sort: {field, dir}` replaces `order: {field: dir}`. **This also means the steering
   Dashboard's `Open obligations` widget — an `object-table` bound via `endpointSource`, a key
   `CnObjectListWidget` does not read — cannot be rendering its rows either. Out of scope here;
   flagged to the orchestrator.**
3. **`allowCreate: false` on all three tables.** `CnObjectListWidget` renders a create
   affordance by DEFAULT, which put a "+ Toevoegen" button under the caller's timesheets, leave
   balance and payslips. All three are read-only to the employee (timesheets are server-created,
   `LeaveBalance` is the accrual job's, payslips are payroll's, and `MijnLoonstroken` already
   declares the read-only posture in REQ-MHS-004), so the default had to be switched off
   explicitly.

Notes on adjustments from the initial widget brief, driven by what the data supports:

- **"My current timesheet status"** is widget 2's rows (`period`/`status`/`entryCount` on the
  latest timesheets), not a separate stat — a stat can render one number, and "draft/submitted
  + entryCount" is two facts per row. The `limit: 3` table with `rowRoute: "TimesheetDetail"`
  also gives the employee the submit path (submission happens on the detail page, per the hours
  redesign).
- **"My leave balance"** cannot be one `stat`: the usable remainder is
  `entitledHours + bovenwettelijkHours − usedHours`, and `CnStatWidget`'s metrics are single
  aggregates (count/sum/avg/min/max) plus ratio/weighted — none computes a difference of sums.
  A per-`leaveType` table of the three components is honest and matches how `LeaveBalances`
  (HR index) presents the data. Requires `LeaveBalance.userId` — D5.
- **"Open expense claims"** counts `status: "submitted"` — the same definition the removed
  REQ-MHS-005 widget used ("Mijn declaraties in behandeling"). `draft` claims are the
  employee's own unfinished input, not "open" toward the organisation, and a multi-status
  filter (`status in [submitted, …]`) would need an `in`-operator array through
  `flattenFilter`, whose query-string serialization for arrays is unverified — not worth the
  risk for a definitional nicety.
- **"Recent payslips"** is `limit: 3` per the brief; rows route to `PayslipDetail` (exists,
  Context). The read-only posture of `MijnLoonstroken` is a page-level `actionToggles` concern
  and does not apply to a table widget (no add/edit affordances exist on `object-table`).

## Decision 4 — Pending approvals: always rendered, zero shown as zero

The brief asked for the approvals tile to be "visible only when nonzero if the widget grammar
supports conditional visibility". It does not (Context: `visibleWhen` exists only inside
`CnBannerWidget` content and dashboard header-actions; `variantWhen` restyles, never hides).
So widget 6 is **always shown** and renders `0` for a caller who manages nobody — the
documented fallback in the brief. Two mitigations considered and rejected:

- A `banner` with `visibleWhen` — its predicate is **endpoint-bound** (`{endpoint, field, op,
  value}`), which would require a new guarded endpoint for a count OpenRegister already serves,
  violating this change's no-new-endpoint stance for a cosmetic gain.
- `visibleIf` on the widget — that key exists on **menu items**, not widgets; a config key no
  component reads is a comment.

If nc-vue later grows widget-level visibility, hiding the zero tile is a one-key follow-up.
Until then the tile's caption ("wachten op jouw goedkeuring") keeps a zero legible.

## Decision 5 — `LeaveBalance.userId`: additive schema property, stamped by the accrual job

`LeaveBalance` joins the REQ-MHS-002 denormalized-`userId` convention (string, nullable, never a
form field, never a `$ref`; fail-closed when null): `hr-leave.json` bumps `LeaveBalance`
0.2.0 → 0.3.0, `required` unchanged.

**Stamping**: `LeaveAccrualJob` is the schema's sole systematic writer (Context). It already
resolves each balance's Employee; it stamps `userId` from `Employee.nextcloudUserId` on **both**
its create path (~line 261) and its monthly update path (~line 221), so every pre-existing row
self-heals on the next accrual run without a dedicated repair step. **Trade-off, accepted**: for
up to one accrual cycle after deploy, a pre-existing balance may still carry `userId: null` and
the widget shows the empty state ("Nog geen verlofsaldo") — the specified fail-closed behaviour,
never another user's rows. The dev/CI instance doesn't wait: the seed stamps
`leavebalance-jansen-2026-holiday` with `userId: "admin"` directly (devries/bakker deliberately
stay unstamped, keeping the exclusion demonstrable — the REQ-MHS-006 pattern). A repair-step
backfill was rejected as machinery duplicating what the job's next run does anyway, for a
surface whose empty state is honest.

The buy/sell settlement path (`LeaveBuySellSettlementService`) mutates `usedHours` on existing
rows and never creates balances; it neither needs nor gets stamping logic (the row it touches
was stamped at creation/accrual).

## Decision 6 — Route normalization with a redirect, not a break

`MijnGebruikelijkLoon.route` changes `/mijn-hr/gebruikelijk-loon` → `/mijn/gebruikelijk-loon` in
the base manifest (D2). Compatibility, in order of what could break:

- **Menu**: references the route by name (`"route": "MijnGebruikelijkLoon"`) — unaffected.
- **deepLinks[]**: all 37 are schema `urlTemplate`s; none names `/mijn-hr` (Context, verified
  by dumping the array) — unaffected.
- **Bookmarks / external links**: `src/main.js` gains
  `routes.push({ path: '/mijn-hr/gebruikelijk-loon', redirect: '/mijn/gebruikelijk-loon' })`
  before the catch-all, exactly the `/vehicles/:id` precedent at `main.js:150-151` (manifest v2
  has no redirect page type, so hand-written routes are the sanctioned escape hatch — the
  precedent's own comment says so).
- **Router conflicts**: `/mijn` (new, static) vs `/mijn/uren` etc. — vue-router 4 ranks by
  static-segment score, and all Mijn routes are fully static; no ambiguity. The fragment
  pipeline's route-order analysis (its design.md, Risks) covers the same ground.

The `visibleIf` gate on the menu entry (`dga_single_person` mode) is untouched — the page
remains reachable by URL in other modes exactly as today (its own endpoint fail-safes cover
that, per the page's `_note`).

## Decision 7 — ADR-097 / steering-spec / i18n non-collision

- **ADR-097 Decision 3 is satisfied literally**: the personal entry routes to a
  `type: "dashboard"` page (`MijnHr`) whose every widget is caller-scoped
  (`@me`/`managerUserId: "@me"`), and it carries children (nine — "six views of one person's
  own data, not six domains"; children are unbudgeted per Decision 3). Widget 6
  (pending approvals) is still caller-scoped — it is *the caller's own* work queue
  (`managerUserId: "@me"`), the same lens `TeamUrengoedkeuring` already applies, not an
  unscoped management surface.
- **`humaniq-dashboard-steering-indicators`**: its REQ-DSI-001 scenarios ("no stat widget SHALL
  remain **on the page**", pairwise-duplication check over "the six widgets") are scoped to the
  `Dashboard` page's `widgets` array and are untouched. The steering page keeps zero
  `@me`-filtered widgets; this page keeps zero management trends — the two surfaces partition
  cleanly along ADR-097's dashboard-vs-personal exemption line. Widget 1 here and the
  Billable-ratio chart there both read TimeEntry-derived hours, but through different bindings
  for different callers — not the row-1–3 role-filter duplication REQ-DSI-001's scenario
  polices, which is again page-scoped.
- **i18n**: Dutch literals throughout, zero new keys, zero conversions —
  `humaniq-i18n-locale-completeness` lands after this change and owns the sweep. Its scope
  includes every manifest label; the new page's strings will be converted there with the rest.

## Risks / Trade-offs

- **[RESOLVED — V2, 2026-08-22] `@currentFiscalYear` (string) vs `LeaveBalance.year` (integer).**
  Proven through a rendered `object-table` on a temporary dev dashboard page: the token resolved
  to `"2026"`, travelled as `…/objects/hrmq/LeaveBalance?_limit=25&_order[leaveType]=asc&year=2026`
  and returned the seeded 2026 balances; the must-fail control (`"year": 1999`, same widget,
  same page, same render) returned the empty state. OpenRegister's comparison matches a string
  year against the integer column. **The documented fallback (drop the year filter, sort `year`
  desc) is NOT needed and was not applied**; the widget keeps its year filter.
  One caveat carried forward, not blocking: `@currentFiscalYear` is a **deprecated** sentinel
  (`SENTINEL_DEPRECATIONS` in nc-vue's `sentinelTokens.js` — a single-app shillinq invention,
  replacement `@config.fiscalYear`, removal **2026-10-01**). It validates today via the schema's
  deprecated-during-window overlay, so the gate warns rather than fails, but this change adds a
  new use roughly six weeks before removal. Migrating it to `@config.fiscalYear` needs an
  IAppConfig key humaniq does not have; flagged to the orchestrator as a follow-up.
- **[RESOLVED — V1, 2026-08-22] operator filter (`gte`) through the stat widget's aggregation
  endpoint.** Proven through the shipping render path (a rendered `stat` tile, not a curl
  approximation): the widget emitted
  `…/aggregations/hrmq/<TimeEntry>/value?metric=sum&field=hours&filter[userId]=admin&filter[startedAt][gte]=2026-08-01`
  and displayed `8.00` — the single current-month booking — against `168.00` for the identical
  widget with the `gte` clause removed. The must-fail control (`gte: "@today+300d"`, a future
  date) rendered `—`. The `@monthStart` and `@me` tokens both resolved on the wire.
- **[NEW RISK, measured — the aggregation endpoint resolves a schema by slug GLOBALLY, not
  within the named register.]** `AggregationController::value()` → `runAdhocByRef()` →
  `SchemaMapper::find($slug)`, which matches `LOWER(slug)` across every register on the
  instance and tie-breaks on app-ownership-then-lowest-id; the `{register}` path segment is
  never used to disambiguate. On this shared dev box that is not theoretical: `TimeEntry`
  resolves to **planninq's** schema #161 (humaniq's is #9466) and `Expense` to **pipelinq's** #507
  (humaniq's is #5026), so widget 1 renders `0.00` while the data exists, and widget 4 renders a
  count of *another app's* expenses. Both were measured live; V1's probe therefore carried the
  humaniq schema id alongside the shipping slug so the operator-filter question stayed isolated
  from the resolution question. Nothing in this manifest can fix it — a slug is the only
  portable spelling, and humaniq's own schema slugs are not renameable — so the fix belongs in
  OpenRegister (resolve the schema within the named register's `schemas[]` before falling back
  to the global lookup) or in an `occ openregister:schemas:dedup` pass on the dev instance. It
  does not affect a single-app instance or CI, where no competing slug exists. **The three
  object-list widgets are unaffected**: `/api/objects/{register}/{schema}` resolves the schema
  within the register (measured — the same `Timesheet`/`LeaveBalance`/`Payslip` slugs return
  humaniq's rows). Flagged to the orchestrator.
- **[CONFIRMED, benign] `@workspace.activeAdministrationId?` does NOT resolve inside dashboard
  widget context.** Measured 2026-08-22 on an instance where the active-administration pointer
  IS set (the Timesheets *index* page sent `administrationId=ADM-001` in the same session):
  every one of the six widgets on `/mijn` omitted the parameter entirely —
  `CnDashboardPage`'s workspace context does not publish that key. The token is **optional** by
  grammar, so unresolved means dropped, not sent as a literal: the widgets are
  administration-unscoped but still strictly `@me`-scoped, the same posture every Mijn index
  page has today. Never fails open across users. Worth noting that
  `scripts/check-manifest-sentinels.mjs` would not have caught a leak here either — it collects
  clauses from `config.filter` and `config.quickFilters[].filter` only, never from a dashboard
  widget's `content.filter` / `content.source.filter`, so the six clauses this change adds are
  outside what that gate inspects. Flagged to the orchestrator as a gate-widening follow-up.
- **[Trade-off] zero-tile for non-managers** (D4) — accepted; grammar limit, documented,
  one-key fix if the library grows widget visibility.
- **[Trade-off] up to one accrual cycle of `userId: null` on pre-existing LeaveBalances**
  (D5) — fail-closed empty state, self-healing, seeds cover dev/CI.
- **[Trade-off] widget 6 duplicates `TeamUrengoedkeuring`'s count** — deliberate: ADR-097
  routes exactly this kind of pressure into dashboards instead of menu entries; the tile is
  the approver's entry point, the page is the work surface.

## Open Questions

1. **Should `/mijn` become the app's default landing for the catch-all redirect** (`main.js:157`
   currently falls back to `/timesheets`)? Arguably `/dashboard` or `/mijn` is the better
   default post-ADR-096, but changing the catch-all affects every stale URL and belongs to a
   navigation change, not this one. Left as-is; flagged to the orchestrator.
2. Whether `MijnGebruikelijkLoon` should eventually merge INTO `MijnHr` as widgets (one
   personal dashboard, mode-gated widgets) rather than staying a sibling dashboard. ADR-097
   Decision 2 says "an app with two personal surfaces has one personal surface and one domain"
   — but MijnGebruikelijkLoon is a menu *child* of the personal group, not a second top-level
   personal entry, so it does not violate the exemption. Deferred; would need `visibleIf` at
   widget level, which today's grammar lacks (D4).
