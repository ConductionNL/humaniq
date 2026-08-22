---
capability: mijn-hr-self-service
status: done
built_by: openspec/changes/archive/2026-07-12-mijn-hr-self-service
---

# mijn-hr-self-service Specification

**Status**: done
**Scope**: hrmq
**OpenSpec changes**:
- [mijn-hr-self-service](../../changes/archive/2026-07-12-mijn-hr-self-service/) _(archived 2026-07-12)_ — `Mijn HR` menu group (ADR-001 menu 2) with four `@me`-scoped employee index pages (uren / declaraties / verlof / loonstroken), a `Dashboard` page (ADR-001 menu 1) with self-service + approver KPI widgets, `Employee.nextcloudUserId` account link, and the denormalized `userId` scoping property on Timesheet/Expense/LeaveRequest/Payslip (kind: config)
- [hrmq-dashboard-steering-indicators](../../changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/) _(archived 2026-08-20)_ — removes REQ-MHS-005 (the Dashboard self/approver KPI widgets); the menu group, the four self-service pages, and the account link are unchanged
- [hrmq-hours-process-redesign](../../changes/archive/2026-08-22-hrmq-hours-process-redesign/) _(archived 2026-08-22, merged as humaniq#128)_ — extends REQ-MHS-002 to `TimeEntry` and makes `userId` a server-stamped denormalization that client input can never set; rebuilds REQ-MHS-004's `MijnUren` onto the `TimeEntry` booking schema with an explicit `includeFields` allowlist and adds the read-only `MijnUrenstaten` aggregate list (kind: code+config)
- [hrmq-personal-dashboard](../../changes/archive/2026-08-22-hrmq-personal-dashboard/) _(archived 2026-08-22, merged as humaniq#133, follow-up humaniq#137)_ — adds `LeaveBalance` to REQ-MHS-002's denormalized-`userId` set (stamped by `LeaveAccrualJob`), amends REQ-MHS-003 so the `Mijn HR` group entry itself routes to the `/mijn` personal dashboard, and adds REQ-MHS-007 moving the last stray Mijn page under the `/mijn/...` prefix with a redirect from the legacy path (kind: code+config)

## Purpose

Give hrmq's logged-in employees an in-app self-service surface per ADR-001
Rule 2 (role-filtered wrapper, never a sibling portal app): `Mijn HR` index
pages that show only the current user's records — scoped by a denormalized
`userId` property filtered with the renderer's `@me` token, the one mechanism
verified to work with today's renderer + OpenRegister — plus a Dashboard with
"mine" and approver KPIs. Employee self-service is a baseline market
expectation per the 2026-07-12 deep research (Spectr insights
`hrmq-insight-afas-baseline`, `hrmq-insight-ranked-buildlist`). External
(no-NC-account) self-service stays with portaliq and is out of scope.

## Requirements

### REQ-MHS-001: The `Employee` schema SHALL carry an optional `nextcloudUserId` account link

`lib/Settings/register.d/hr-objects.json`: `Employee` gains `nextcloudUserId` (string, nullable) — the Nextcloud user id of this employee's account, when they have one. HR maintains it on the employee record; it stays null for portal-only external employees (portaliq scoping remains UUID-claim based per ADR-046 A4 and is untouched). Employee schema `version` bumps 0.1.0 → 0.2.0. `required` list unchanged.

#### Scenario: Linked employee validates
- **GIVEN** the imported hrmq register
- **WHEN** an Employee is written with `nextcloudUserId: "admin"`
- **THEN** creation succeeds; **AND** an Employee written without the property remains valid (optional, nullable)

### REQ-MHS-002: Timesheet, TimeEntry, Expense, LeaveRequest, Payslip **and LeaveBalance** SHALL carry an optional denormalized `userId`

Property `userId` (string, nullable — the Nextcloud user id of the employee the record belongs
to, a denormalized copy of the linked Employee's `nextcloudUserId`) on: `Timesheet` and
`TimeEntry` (`hr-timesheet.json`), `Expense` (`hr-expense.json`), `LeaveRequest` **and
`LeaveBalance`** (`hr-leave.json`), `Payslip` (`hr-objects.json`). It mirrors the
plain-NC-user-id convention of `approvedBy` (never a `$ref` — rationale in `x-notes`). Custody
is tightened: `userId` is a pure denormalization of `employeeId` and SHALL never be a form field
on any surface. For `Timesheet` and `TimeEntry` it SHALL be stamped server-side on every write
(re-derived from `employeeId` → `Employee.nextcloudUserId`, so it cannot drift); for `Payslip`
it remains stamped by payroll generation (`PayrollRunService`); for `Expense` and `LeaveRequest`
the population mechanism is unchanged (their own process redesigns follow). Records whose
employee has no linked account keep `userId: null` and never appear on a Mijn page
(fail-closed). No `required` change, no lifecycle change — every existing object stays valid; a
repair step backfills existing Timesheet rows idempotently with a single warn-once summary for
unresolvable links.

`LeaveBalance` (`hr-leave.json`, 0.2.0 → 0.3.0) joins the denormalized-`userId` set under the
established convention: string, nullable, never a `$ref` (mirrors `approvedBy` — rationale in
`x-notes`), never a form field on any surface, no `required` change — every existing object
stays valid. Population custody: `LeaveAccrualJob` — the schema's sole systematic writer —
stamps `userId` from the resolved Employee's `nextcloudUserId` on both its create and its
monthly update path (see the `leave-accrual-job` delta), so pre-existing rows self-heal on the
next accrual run; no dedicated repair step. A balance whose employee has no linked account
keeps `userId: null` and never appears on a personal surface (fail-closed).

#### Scenario: Additive property is backward compatible

@e2e exclude Repair-step behaviour with no UI surface; verified by the MigrateHoursProcess unit tests (tasks.md 3.2), including the run-twice idempotency proof
- **GIVEN** an existing Timesheet object seeded before this change (no `userId`)
- **WHEN** the register re-imports the bumped fragments via the Repair step
- **THEN** the object still validates, and the migration backfills `userId` where the employee
  link resolves

#### Scenario: Records without userId never leak onto a Mijn page

- **GIVEN** a Timesheet with `userId: null`
- **WHEN** any user opens `MijnUrenstaten`
- **THEN** that timesheet is not listed (fail-closed)

#### Scenario: The stamp cannot drift from the employee link

@e2e exclude Backend write-path invariant; verified by the stamping listeners' unit tests (re-derivation on every write)
- **GIVEN** a TimeEntry whose `employeeId` names employee Jansen (linked account "admin")
- **WHEN** any write supplies `userId: "someone-else"`
- **THEN** the persisted `userId` is "admin" — re-derived from the employee link, client input
  inert

#### Scenario: Additive LeaveBalance property is backward compatible

@e2e exclude Repair-step/import behaviour with no UI surface; verified by re-importing the bumped fragment and revalidating existing seed balances (tasks.md 2.1, 3.2)
- **GIVEN** an existing LeaveBalance object seeded before this change (no `userId`)
- **WHEN** the register re-imports the bumped fragment via the Repair step
- **THEN** the object still validates and is unchanged

#### Scenario: Balances without userId never leak onto the personal dashboard

- **GIVEN** the De Vries LeaveBalance with no `userId`
- **WHEN** the `admin` user opens `/mijn`
- **THEN** that balance is not listed in "Mijn verlofsaldo" (fail-closed)

### REQ-MHS-003: The manifest SHALL add the two frozen ADR-001 top menu slots without restructuring the rest

**Amended (this change): the `Mijn HR` group entry routes to the personal dashboard.** The
menu contract of the original requirement stands — `Dashboard` (order 10) first, group
`MijnHrGroup` (label `Mijn HR`, icon `Account`, order 20) second, all pre-existing groups
unchanged — with one addition: `MijnHrGroup` carries `route: "MijnHr"` (supplied by the
`personal-dashboard.json` fragment through `mergeMenuItems`' fill-undefined-key semantics), so
the group title is the entry point to the caller's personal dashboard while its children
remain the caller's own collections. This implements hydra ADR-097 Decision 3's exemption
conditions for the personal surface (see `hrmq-personal-dashboard` REQ-PDB-003).

#### Scenario: Menu order matches ADR-001

- **WHEN** the app shell renders the manifest menu
- **THEN** Dashboard is the first entry and Mijn HR the second, before all pre-existing groups

#### Scenario: Mijn HR is a routed group, not a bare parent

- **WHEN** the app shell renders the manifest menu
- **THEN** the `Mijn HR` entry navigates to `/mijn` on title click and still exposes its
  children under the collapse chevron

### REQ-MHS-004: The four Mijn pages SHALL list only the current user's records via an `@me` base filter

The Mijn pages each carry `config.filter: { "userId": "@me" }` (resolved to the signed-in
Nextcloud uid at fetch time by the renderer's shared token grammar) plus the administration
scope filter, and columns that omit `employeeId`/`userId`:

- `MijnUren` — route `/mijn/uren`, schema **`TimeEntry`** (redesigned: this is the booking
  surface, "uren boeken"); columns `startedAt`, `endedAt`, `hours`, `description`, `projectId`,
  `billable`; sort `startedAt` desc; Add enabled (`actionToggles.showAdd` — never the dead
  `allowCreate` key) with the explicit
  `includeFields: ["startedAt","endedAt","breakMinutes","description","projectId","billable"]`
  allowlist — the employee's own Employee record is resolved server-side from the signed-in
  account, so the form carries no employee, user, administration or process fields.
- `MijnUrenstaten` — route `/mijn/urenstaten`, schema `Timesheet` (new); columns `period`,
  `hours`, `entryCount`, `status`, `submittedAt`; sort `period` desc; **read-only** list
  (`actionToggles` disable Add and the edit/delete/copy row actions — timesheets are
  server-created aggregates; submission happens on `TimesheetDetail`).
- `MijnDeclaraties` — route `/mijn/declaraties`, schema `Expense`; columns `title`, `amount`,
  `category`, `expenseDate`, `status`; sort `expenseDate` desc (unchanged).
- `MijnVerlof` — route `/mijn/verlof`, schema `LeaveRequest`; columns `leaveType`, `startDate`,
  `endDate`, `status`; sort `startDate` desc (unchanged).
- `MijnLoonstroken` — route `/mijn/loonstroken`, schema `Payslip`; columns `period`,
  `jurisdiction`, `grossPay`, `nettoPay`; sort `period` desc; **read-only** (unchanged).

The manifest MUST validate against app-manifest-v2 (`npm run check:manifest`), with the pages
authored in their manifest fragments.

#### Scenario: Employee sees only their own records

- **GIVEN** the seeded register where Jansen's records carry `userId: "admin"` and the
  De Vries / Bakker records carry no `userId`
- **WHEN** the `admin` user opens `MijnUren`
- **THEN** exactly Jansen's time entries are listed

#### Scenario: Booking hours needs no identity fields

- **WHEN** the `admin` user creates a booking from `MijnUren`
- **THEN** the persisted TimeEntry carries `employeeId` (Jansen), `userId: "admin"` and the
  derived `administrationId` — none of which appeared on the form

#### Scenario: Payslip page offers no authoring

- **WHEN** the `admin` user opens `MijnLoonstroken`
- **THEN** their payslip rows render read-only — no Add button and no edit/delete row actions

#### Scenario: Manifest stays valid

@e2e exclude Validated by `npm run check:manifest` in CI (tasks.md 4.3), a Node validator, not a browser journey
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

### REQ-MHS-005: The Dashboard page SHALL surface self-service and approver KPIs from the built-in widget set

New `type: dashboard` page `Dashboard` (route `/dashboard`) using `config.widgets` + `config.layout` with exactly this widget set (all from the schema's built-in widget enum — no custom widgets):
1. `stat` "Mijn ingediende uren" — Timesheet count, `filter: { "userId": "@me", "status": "submitted" }`, route `MijnUren`.
2. `stat` "Mijn declaraties in behandeling" — Expense count, `filter: { "userId": "@me", "status": "submitted" }`, route `MijnDeclaraties`.
3. `stat` "Mijn verlofaanvragen" — LeaveRequest count, `filter: { "userId": "@me", "status": "submitted" }`, route `MijnVerlof`.
4. `stat` "Te beoordelen uren" — Timesheet count, `filter: { "status": "submitted" }`, route `TimesheetApproval` (the approver surface per ADR-001 Rule 2).
5. `stat` "Te beoordelen declaraties" — Expense count, `filter: { "status": "submitted" }`, route `ExpenseApproval`.
6. `object-table` "Mijn recente uren" — `source` Timesheet, `filter: { "userId": "@me" }`, order `period` desc, limit 5, rows navigating to `TimesheetDetail`.

#### Scenario: My KPIs are user-scoped, approver KPIs are not
- **GIVEN** the seeded register (Jansen/admin submitted timesheet + two other submitted/rejected timesheets without `userId`)
- **WHEN** the `admin` user opens the Dashboard
- **THEN** "Mijn ingediende uren" counts 1 while "Te beoordelen uren" counts every submitted timesheet

#### Scenario: KPI deep-links land on the matching page
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer/CnDashboardPage library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **WHEN** the user activates the "Te beoordelen declaraties" stat
- **THEN** the router navigates to `ExpenseApproval`

### REQ-MHS-006: Seed data SHALL make the dev-instance `admin` login land on populated Mijn HR pages

`lib/Settings/register.d/hr-seed.json` is extended (existing objects extended, not duplicated): (1) a new seed `Employee` with slug `employee-jansen` — the Employee the existing seeds already reference — carrying `nextcloudUserId: "admin"` and placeholder person/payroll values; (2) `userId: "admin"` added to `timesheet-jansen-2026-05` and `expense-jansen-hotel`; (3) one new `LeaveRequest` (`leave-jansen-zomer`, holiday 2026-08-03 → 2026-08-14, status submitted) and one new `Payslip` (`payslip-jansen-2026-05`, period 2026-05, placeholder gross/net) both with `employeeId: "employee-jansen"` and `userId: "admin"`. The De Vries and Bakker objects deliberately stay unstamped so the `@me` filter's exclusion is demonstrable.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the new Employee, LeaveRequest and Payslip exist exactly once and the stamped objects carry `userId: "admin"` exactly once

#### Scenario: Fresh dev login is not empty
- **GIVEN** a clean dev instance seeded by the Repair step
- **WHEN** `admin` opens each of the four Mijn pages
- **THEN** each lists at least one record

### REQ-MHS-007: Every Mijn surface SHALL live under the `/mijn/...` route prefix, with a redirect from the legacy path

`MijnGebruikelijkLoon` — the only Mijn page outside the prefix — moves from
`/mijn-hr/gebruikelijk-loon` to `/mijn/gebruikelijk-loon` (route value edited in the base
`src/manifest.json`, where the fragment pipeline's Decision 2 homes this app-shell page).
Compatibility: the menu entry references the page by route **name** and is untouched; no
`deepLinks[]` entry references `/mijn-hr` (all 37 are schema `urlTemplate`s — verified);
`src/main.js` gains a hand-written redirect route
(`{ path: '/mijn-hr/gebruikelijk-loon', redirect: '/mijn/gebruikelijk-loon' }`) before the
catch-all, per the `/vehicles/:id` precedent (`main.js:150-151`), so stale bookmarks resolve
instead of falling through to the catch-all default. The page's `visibleIf`
(`dga_single_person`) gating and content are unchanged.

#### Scenario: The legacy path redirects

- **WHEN** a user opens `/mijn-hr/gebruikelijk-loon` directly by URL
- **THEN** the router lands on `/mijn/gebruikelijk-loon` and the gebruikelijk-loon dashboard
  renders

#### Scenario: The DGA menu entry still resolves

@e2e exclude the entry is `visibleIf`-gated on `administrationMode: dga_single_person`, which the CI seed's default active administration does not select; the route-name reference is asserted structurally via the manifest and the redirect scenario covers the path change
- **GIVEN** a caller whose active administration mode is `dga_single_person`
- **WHEN** they activate the "Mijn gebruikelijk loon" menu entry
- **THEN** the router resolves the `MijnGebruikelijkLoon` route name to
  `/mijn/gebruikelijk-loon`
