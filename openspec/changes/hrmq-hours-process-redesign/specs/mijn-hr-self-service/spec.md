# mijn-hr-self-service — delta for hrmq-hours-process-redesign

## MODIFIED Requirements

### REQ-MHS-002: Timesheet, Expense, LeaveRequest and Payslip SHALL carry an optional denormalized `userId`

Property `userId` (string, nullable — the Nextcloud user id of the employee the record belongs
to, a denormalized copy of the linked Employee's `nextcloudUserId`) on: `Timesheet` and
`TimeEntry` (`hr-timesheet.json`), `Expense` (`hr-expense.json`), `LeaveRequest`
(`hr-leave.json`), `Payslip` (`hr-objects.json`). It mirrors the plain-NC-user-id convention of
`approvedBy` (never a `$ref` — rationale in `x-notes`). Custody is tightened: `userId` is a pure
denormalization of `employeeId` and SHALL never be a form field on any surface. For `Timesheet`
and `TimeEntry` it SHALL be stamped server-side on every write (re-derived from
`employeeId` → `Employee.nextcloudUserId`, so it cannot drift); for `Payslip` it remains stamped
by payroll generation (`PayrollRunService`); for `Expense` and `LeaveRequest` the population
mechanism is unchanged by this change (their own process redesigns follow). Records whose
employee has no linked account keep `userId: null` and never appear on a Mijn page
(fail-closed). No `required` change, no lifecycle change — every existing object stays valid; a
repair step backfills existing Timesheet rows idempotently with a single warn-once summary for
unresolvable links.

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
