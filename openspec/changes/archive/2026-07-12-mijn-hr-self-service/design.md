# Design — mijn-hr-self-service

## Context

hrmq's manifest (`src/manifest.json`) ships 7 menu entries across 4 groups (orders 90–120) and 13 pages — all back-office views over everyone's records. There is no Dashboard page and no employee-scoped surface, even though ADR-001 freezes `Dashboard` (menu 1, `view-dashboard`) and `Mijn HR` (menu 2, `account`) as the first two top-level entries and Rule 2 mandates in-app role-filtered self-service. The self-service *lifecycles* already exist and are fully declarative (Timesheet/Expense/LeaveRequest `x-openregister-lifecycle` + `NoSelfApprovalGuard`); what is missing is any way to show a logged-in employee **only their own** records.

For external people without NC accounts, `lib/Portal/PortalContributionProvider.php` already scopes every collection by UUID claim (`scopeField: employeeId` == `claims.hrmq.employeeId`, stamped server-side by portaliq) — deliberately never Nextcloud user ids (ADR-046 A4). That mechanism is portaliq-only; the in-app renderer has no claim map, so this change needs its own scoping key.

## Scoping mechanism (investigated)

Verified against nc-vue HEAD and openregister HEAD on 2026-07-12:

1. **`@me` in an index page's base filter WORKS.** The manifest v2 schema documents `@me` ("current user via @nextcloud/auth") in the shared filter-token grammar (`app-manifest-v2.schema.json`, `$defs/sentinelFilterToken` + the objectTableSource/statsBlockEntry filter descriptions), and `CnIndexPage`'s self-fetch path resolves the page's `config.filter` map through `resolveFilterTokens` at fetch time (`src/components/CnIndexPage/useSelfFetchList.js` — "an index base filter can scope to the signed-in user (e.g. `{ assignee: '@me' }`) without a bespoke wrapper"; `resolveFilterTokens.js` maps `@me` → `getCurrentUser().uid`). Widget filters (stat / stats-block / object-table) run the same grammar. Sibling proof: procest's dashboard ships `"filter": { "assignee": "@me" }` on stat and object-table widgets (its `_note` about needing a wrapper for index pages is stale — the stock resolver landed since).
2. **OpenRegister `owner` metadata is NOT reachable from a manifest filter.** OR does support metadata filtering server-side, but only via a **nested** `@self` bag (`MagicSearchHandler.php` reads `$query['@self']` and maps fields to `_owner` etc.; `applyMetadataFilters()`). The renderer's filter map serializes flat keys through `buildQueryString` (`utils/headers.js`), which JSON-stringifies object values (`@self={"owner":…}` — a string, not a PHP array) and a flat `@self.owner=x` key is mangled by PHP's `$_GET` dot-to-underscore rewriting. Owner scoping would also be semantically wrong: HR/payroll create records on behalf of employees (every Payslip, many Timesheets), so `owner` == creator ≠ the employee.
3. **Two-hop filtering does not exist.** The token grammar has no lookup form — nothing can express `Timesheet.employeeId → Employee.nextcloudUserId == @me`. Tokens resolve to local context only (`@me`, dates, `@objectId`/`@object.<field>`, `@workspace.*`, `@config.*`).
4. **Create-form token defaults do NOT exist.** `open-form` actions have no prefill/defaults key; `object-op` create `values` are spread raw into `saveObject` without token resolution (`utils/actionsDispatcher.js` — only `api-call` `params` run the grammar); the built-in CnIndexPage create dialog does not inherit the page's base filter.

**Conclusion:** the only scoping that demonstrably works with today's renderer + OpenRegister is a **denormalized Nextcloud-user-id property on the record itself**, filtered with `@me`. That is what this change specifies.

## Goals / Non-Goals

**Goals:** ADR-001 menus 1+2 real; four `@me`-scoped employee index pages; a small role-relevant Dashboard; the Employee↔NC-account link as durable data; seed data that makes a dev login (`admin`) land on populated pages.

**Non-Goals:** manager portal (Rule 2), notifications, mobile, automatic `userId` stamping (renderer gap — see Open Questions), leave back-office pages (`leave-verzuim-mvp`), IA restructuring of the existing groups (`hrmq-ia-navigation-alignment`).

## Decisions

### D1 — Scope by a denormalized `userId` property + `@me` base filter

`Timesheet`, `Expense`, `LeaveRequest` and `Payslip` each gain an **optional** `userId` (string, nullable — the Nextcloud user id of the employee the record belongs to). Every `Mijn*` index page sets `config.filter: { "userId": "@me" }`; the self-fetch path resolves `@me` to the signed-in uid and OpenRegister filters on the object field. Records without `userId` simply never appear on a Mijn page — fail-closed, no over-sharing.

Rejected alternatives (per the investigation above): OR `owner` metadata (unreachable from the manifest + semantically the creator, not the employee) and two-hop via `employeeId` (no such token). `userId` deliberately mirrors the existing plain-NC-user-id convention already on these schemas (`approvedBy` — "an NC user id, not an object FK, so it stays plain per ADR-046").

### D2 — `Employee.nextcloudUserId` is the durable link; `userId` is its per-record denormalization

The canonical Employee↔account link lives ONCE, on the Employee record (`nextcloudUserId`, optional/nullable — external payroll employees keep it null and use portaliq). The per-record `userId` is a denormalized copy of that link, carried because the manifest cannot join (finding 3). Drift is possible and accepted for the MVP: a future corpus rule (`xc-self-service-userid-consistency`: `record.userId` must equal the referenced Employee's `nextcloudUserId`) can make drift auditable via `occ hrmq:rules:audit` — deliberately NOT in this change (kind: config, no check code).

### D3 — Payslip scoping: same one-hop `userId`, populated by payroll (deviation, documented)

The intent was to scope payslips via the Employee link only (employees never create their own payslips), but two-hop filtering is impossible (finding 3) and an unfiltered fallback is unacceptable. Deferring payslips would drop the single most-demanded self-service artifact (loonstrook is the #1 AFAS Pocket use case per `hrmq-insight-afas-baseline`). So `Payslip` gets the same optional `userId`, set by whoever generates the payslip — payroll already sets `employeeId` on every payslip, so setting `userId` alongside it is the same authoring step, not a new burden. `MijnLoonstroken` is read-only (`actionToggles`: no Add, no edit/delete row actions) since `Payslip` carries no lifecycle and employees must never author one.

### D4 — Declarative-vs-imperative (ADR-031 decision)

| behaviour | path | rationale |
|---|---|---|
| Page scoping (`userId == @me`) | **declarative** manifest base filter, resolved by the shared renderer | the verified mechanism; zero app code |
| Dashboard KPIs / lists | declarative `stat` / `object-table` widgets with `@me` filter tokens | procest-proven widget shapes from the closed schema enum |
| Employee↔account link | schema property (data) | plain data; no behaviour |
| `userId` population | **manual/seeded data** in this change | no create-form token defaults exist (finding 4); an imperative write-hook would be PHP and out of kind: config — recorded as the honest trade-off, not hidden behind a stub |
| `userId`↔Employee consistency | deferred corpus rule (follow-up) | check code is imperative; this change ships no PHP |
| RBAC / who may approve | unchanged — OpenRegister RBAC + `NoSelfApprovalGuard` | the Mijn filter is a *view* scope, NOT an authorization boundary; server-side authority stays where it is |

**Security note:** `{ userId: "@me" }` is client-resolved convenience filtering — a user who hand-crafts a URL can still query the register within whatever OpenRegister RBAC allows them. This change does not weaken or replace RBAC; tightening read RBAC per schema is existing OR configuration, out of scope here.

### D5 — Dashboard widget set stays small and procest-shaped

`type: dashboard` page using `config.widgets` + `config.layout` (the exact shape procest's live Dashboard uses and hrmq's own detail pages already use). Six widgets, all from the schema's built-in widget enum:

- 3 × `stat` (mine): submitted timesheets / submitted expenses / submitted leave requests, each `filter: { "userId": "@me", "status": "submitted" }`, deep-linking to the matching Mijn page.
- 2 × `stat` (approver): all submitted timesheets / expenses, `filter: { "status": "submitted" }`, deep-linking to `TimesheetApproval` / `ExpenseApproval`. Per ADR-001 Rule 2 this **is** the manager self-service surface; the approval index pages already exist.
- 1 × `object-table` (mine): my recent timesheets (`source.filter: { "userId": "@me" }`, order period desc, limit 5, rowRoute `TimesheetDetail`).

No dateRange picker, no charts, no custom widgets — smallest useful set; growth belongs to a later dashboard change.

### D6 — Menu placement: add the two frozen slots, touch nothing else

`Dashboard` menu entry order 10, `Mijn HR` group order 20 — both land BEFORE the existing groups (90–120), matching ADR-001's frozen order without renumbering anything. The `hrmq-ia-navigation-alignment` change owns re-homing Uren/Onkosten and the fragment pipeline; this change's additions are position-stable under that realignment (Dashboard and Mijn HR are ADR-001 entries the IA change also targets — coordination note added to both).

## Schema delta

| fragment | schema | delta | version |
|---|---|---|---|
| `hr-objects.json` | `Employee` | + `nextcloudUserId` (string, nullable): the NC user id of this employee's account, when they have one; HR-maintained; null for portal-only externals (ADR-046 A4 keeps portaliq on UUID claims) | 0.1.0 → 0.2.0 |
| `hr-objects.json` | `Payslip` | + `userId` (string, nullable): denormalized NC user id (D3) | 0.1.0 → 0.2.0 |
| `hr-timesheet.json` | `Timesheet` | + `userId` (string, nullable): denormalized NC user id (D1/D2) | 0.2.0 → 0.3.0 |
| `hr-expense.json` | `Expense` | + `userId` (string, nullable) | 0.1.0 → 0.2.0 |
| `hr-leave.json` | `LeaveRequest` | + `userId` (string, nullable) | 0.1.0 → 0.2.0 |

All additive + optional: no `required` change, no lifecycle change, existing objects stay valid.

## Manifest delta

- Menu: `Dashboard` (icon `view-dashboard`, order 10, route `Dashboard`); group `MijnHrGroup` (label `Mijn HR`, icon `account`, order 20) with children `MijnUren`, `MijnDeclaraties`, `MijnVerlof`, `MijnLoonstroken`.
- Pages (all `config.filter: { "userId": "@me" }`, columns exclude `employeeId`/`userId` — the page is already "mine"):
  - `MijnUren` — `/mijn/uren`, index, Timesheet; columns period/hours/billable/status; sort period desc.
  - `MijnDeclaraties` — `/mijn/declaraties`, index, Expense; columns title/amount/category/expenseDate/status; sort expenseDate desc.
  - `MijnVerlof` — `/mijn/verlof`, index, LeaveRequest; columns leaveType/startDate/endDate/status; sort startDate desc.
  - `MijnLoonstroken` — `/mijn/loonstroken`, index, Payslip; columns period/jurisdiction/grossPay/nettoPay; sort period desc; `actionToggles: { showAdd: false, showEditAction: false, showDeleteAction: false, showCopyAction: false }` (read-only view; Payslip creation is payroll's).
- `Dashboard` page — `/`… no: hrmq has no root page today and CnPageRenderer matches by route; use `/dashboard` and menu order puts it first (taking over `/` is a navigation-default decision that belongs to `hrmq-ia-navigation-alignment`).
- Existing pages untouched.

## Seed Data (ADR-001)

Extend `lib/Settings/register.d/hr-seed.json` (extend existing objects, do not duplicate):

1. **Add** the seed `Employee` the existing objects already reference by slug: `employee-jansen` (lastName Jansen, startDate, plausible placeholder payroll fields) with `nextcloudUserId: "admin"` — today NO Employee object is seeded at all, so the fragment gains its first one.
2. **Stamp** `userId: "admin"` onto the two existing Jansen objects: `timesheet-jansen-2026-05` and `expense-jansen-hotel`.
3. **Add** one `LeaveRequest` (`leave-jansen-zomer`: holiday, 2026-08-03 → 2026-08-14, status submitted, `employeeId: "employee-jansen"`, `userId: "admin"`) — the fragment's first LeaveRequest seed.
4. **Add** one `Payslip` (`payslip-jansen-2026-05`: period 2026-05, NL, placeholder gross/net, `employeeId: "employee-jansen"`, `userId: "admin"`).

Result: a dev-instance `admin` login sees 1 timesheet, 1 expense, 1 leave request and 1 payslip on the Mijn pages, and non-zero "mine" KPIs on the Dashboard. De Vries/Bakker objects stay unstamped — proving the `@me` filter actually excludes other people's records. All values are obvious placeholders.

## Risks / Trade-offs

- **`userId` drift / omission**: a record whose `userId` was never set is invisible on Mijn pages even though it belongs to the employee. Accepted for the MVP (fail-closed beats over-sharing); the consistency corpus rule (D2) and a create-default renderer feature (Open Questions) are the follow-ups.
- **View-scope ≠ authorization** (D4 security note): documented so nobody mistakes the Mijn filter for RBAC.
- **Dashboard route not `/`**: the app's landing page stays whatever the router defaults to until `hrmq-ia-navigation-alignment` decides the root; menu order 10 still surfaces Dashboard first in navigation.
- **Coordination with `hrmq-ia-navigation-alignment`**: both changes edit `src/manifest.json` menus. This change is additive-only (two new top slots); whichever lands second rebases mechanically.

## Open Questions

- **Renderer feature request** (non-blocking): create-form token defaults (e.g. `open-form`/built-in dialog field default `"@me"`, or "stamp the page's resolved base filter onto created objects") would let employee-created records self-scope without back-office intervention. To be filed against nextcloud-vue; until then population is seed/back-office data (D4).
