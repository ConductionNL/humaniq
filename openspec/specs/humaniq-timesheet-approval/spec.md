# humaniq-timesheet-approval Specification

## Purpose
TBD - created by archiving change humaniq-timesheet-approval. Update Purpose after archive.
## Requirements
### Requirement: Timesheet object with declarative approval lifecycle

The system SHALL model a submitted timesheet as a `Timesheet` object in the OpenRegister `humaniq`
register, whose approval workflow is governed by a declarative `x-openregister-lifecycle` state
machine on its `status` field (initial `draft`), with transitions `submit` (draft/rejected →
submitted), `approve` (submitted → approved), `reject` (submitted → rejected) and `reopen`
(approved → draft). The object SHALL carry at least `employeeId`, `period`, `hours`, `status`,
`submittedAt`, `approvedBy`, `approvedAt`, and `rejectionReason`. The lifecycle MUST be expressed
declaratively in the register fragment — not in bespoke PHP transition code.

**Feature tier**: MVP

#### Scenario: Employee submits a draft timesheet

- GIVEN a `Timesheet` in state `draft`
- WHEN the employee applies the `submit` transition
- THEN the timesheet MUST move to state `submitted`
- AND `submittedAt` MUST record the submission timestamp

#### Scenario: A rejected timesheet can be corrected and re-submitted

- GIVEN a `Timesheet` in state `rejected`
- WHEN the employee applies the `submit` transition
- THEN the timesheet MUST move to state `submitted`

#### Scenario: An invalid transition is refused

- GIVEN a `Timesheet` in state `draft`
- WHEN the `approve` transition is attempted (which requires state `submitted`)
- THEN the lifecycle MUST refuse the transition and leave the state unchanged

### Requirement: Separation of duties on approval

The system SHALL prevent an employee from approving or rejecting their own timesheet. The
`approve` and `reject` transitions SHALL each declare `requires:
OCA\Humaniq\Lifecycle\NoSelfApprovalGuard`, an OpenRegister `LifecycleGuardInterface` that denies the
transition when the acting user equals the timesheet's `employeeId`. The guard MUST fail closed:
when the acting user or the claiming employee cannot be identified, the transition is denied.

**Feature tier**: MVP

#### Scenario: A manager approves another employee's timesheet

- GIVEN a `Timesheet` in state `submitted` whose `employeeId` is employee A
- WHEN a different user (manager B) applies the `approve` transition
- THEN the guard MUST allow the transition
- AND the timesheet MUST move to state `approved` with `approvedBy` = B and `approvedAt` set

#### Scenario: An employee cannot approve their own timesheet

- GIVEN a `Timesheet` in state `submitted` whose `employeeId` is employee A
- WHEN employee A applies the `approve` (or `reject`) transition
- THEN the guard MUST deny the transition with a separation-of-duties message
- AND the timesheet MUST remain in state `submitted`

#### Scenario: The actor cannot be identified

- GIVEN a transition is attempted with no resolvable acting user
- WHEN the guard runs
- THEN it MUST deny the transition (fail closed)

### Requirement: Declarative timesheet pages

The system SHALL surface timesheets through declarative manifest pages rendered generically by the
`@conduction/nextcloud-vue` library — NOT bespoke Vue components. There SHALL be a `Timesheets`
`type:"index"` list page, a `TimesheetApproval` `type:"index"` page whose default filter is
`status == submitted` (the pending-approval queue), and a `TimesheetDetail` `type:"detail"` page,
each configured only with `{ register: "hrmq", schema: "Timesheet", … }`. Humaniq SHALL appear in the
Nextcloud app menu via an `<navigations>` entry routing to the SPA shell.

**Feature tier**: MVP

#### Scenario: The approval queue lists pending timesheets

- GIVEN submitted, approved and rejected timesheets exist in the `hrmq` register
- WHEN a manager opens the "Urengoedkeuring" (TimesheetApproval) page
- THEN the page MUST default its filter to `status == submitted`
- AND MUST render the list generically from the `Timesheet` schema without a bespoke component

#### Scenario: Humaniq is reachable from the app menu

- GIVEN humaniq is installed and enabled
- WHEN the user opens the Nextcloud app menu
- THEN Humaniq MUST appear and open its SPA shell at the timesheets list

