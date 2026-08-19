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
- [hrmq-dashboard-steering-indicators](../../changes/hrmq-dashboard-steering-indicators/) — **Status**: in-progress — removes REQ-MHS-005 (the Dashboard self/approver KPI widgets); the menu group, the four self-service pages, and the account link are unchanged

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

### REQ-MHS-002: Timesheet, Expense, LeaveRequest and Payslip SHALL carry an optional denormalized `userId`

New property `userId` (string, nullable — the Nextcloud user id of the employee the record belongs to, a denormalized copy of the linked Employee's `nextcloudUserId`) on: `Timesheet` (`hr-timesheet.json`, 0.2.0 → 0.3.0), `Expense` (`hr-expense.json`, 0.1.0 → 0.2.0), `LeaveRequest` (`hr-leave.json`, 0.1.0 → 0.2.0), `Payslip` (`hr-objects.json`, 0.1.0 → 0.2.0). The property description documents (a) that it mirrors the plain-NC-user-id convention of `approvedBy` (never a `$ref`), and (b) that Payslip's `userId` is set by payroll alongside `employeeId` since employees never author payslips. No `required` change, no lifecycle change — every existing object stays valid.

#### Scenario: Additive property is backward compatible
- **GIVEN** an existing Timesheet object seeded before this change (no `userId`)
- **WHEN** the register re-imports the bumped fragments via the Repair step
- **THEN** the object still validates and is unchanged

#### Scenario: Records without userId never leak onto a Mijn page
- **GIVEN** a Timesheet with `userId: null`
- **WHEN** any user opens `MijnUren`
- **THEN** that timesheet is not listed (fail-closed)

### REQ-MHS-003: The manifest SHALL add the two frozen ADR-001 top menu slots without restructuring the rest

`src/manifest.json` menu gains: `Dashboard` (icon `view-dashboard`, order 10, route `Dashboard`) and group `MijnHrGroup` (label `Mijn HR`, icon `account`, order 20) with children `MijnUren`, `MijnDeclaraties`, `MijnVerlof`, `MijnLoonstroken`. The existing groups (`EmployeesGroup` 90, `TimesheetsGroup` 100, `VerlofVerzuimGroup` 105, `ExpensesGroup` 110, `PayrollGroup` 120) keep their ids, labels, orders and children exactly as they are — the full IA realignment is owned by the active change `hrmq-ia-navigation-alignment`. Icon values are registered as the PascalCase MDI component names the app's icon registry (`src/icons.js`) actually resolves (`ViewDashboardOutline`, `Account`) — the ADR-001 kebab-case labels (`view-dashboard`, `account`) are the conceptual names, not literal manifest values, matching every other existing menu icon in this manifest.

#### Scenario: Menu order matches ADR-001
- **WHEN** the app shell renders the manifest menu
- **THEN** Dashboard is the first entry and Mijn HR the second, before all pre-existing groups

### REQ-MHS-004: The four Mijn pages SHALL list only the current user's records via an `@me` base filter

Four new `type: index` pages, each with `config.filter: { "userId": "@me" }` (resolved to the signed-in Nextcloud uid at fetch time by the renderer's shared token grammar) and columns that omit `employeeId`/`userId`:
- `MijnUren` — route `/mijn/uren`, schema `Timesheet`; columns `period`, `hours`, `billable`, `status`; sort `period` desc.
- `MijnDeclaraties` — route `/mijn/declaraties`, schema `Expense`; columns `title`, `amount`, `category`, `expenseDate`, `status`; sort `expenseDate` desc.
- `MijnVerlof` — route `/mijn/verlof`, schema `LeaveRequest`; columns `leaveType`, `startDate`, `endDate`, `status`; sort `startDate` desc.
- `MijnLoonstroken` — route `/mijn/loonstroken`, schema `Payslip`; columns `period`, `jurisdiction`, `grossPay`, `nettoPay`; sort `period` desc; **read-only**: `actionToggles` disable Add and the edit/delete/copy row actions.

The manifest MUST validate against app-manifest-v2 (`npm run check:manifest`).

#### Scenario: Employee sees only their own records
- **GIVEN** the seeded register where Jansen's timesheet carries `userId: "admin"` and the De Vries / Bakker timesheets carry no `userId`
- **WHEN** the `admin` user opens `MijnUren`
- **THEN** exactly the Jansen timesheet is listed

#### Scenario: Payslip page offers no authoring
- **WHEN** the `admin` user opens `MijnLoonstroken`
- **THEN** their payslip rows render read-only — no Add button and no edit/delete row actions

#### Scenario: Manifest stays valid
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
