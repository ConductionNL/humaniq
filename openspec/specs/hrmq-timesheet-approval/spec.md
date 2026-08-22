# hrmq-timesheet-approval Specification

## Purpose
TBD - created by archiving change hrmq-timesheet-approval. Update Purpose after archive.

## Requirements

### Requirement: Timesheet object with declarative approval lifecycle

The system SHALL model the period aggregate as a `Timesheet` object ("urenstaat") in the
OpenRegister `hrmq` register, whose approval workflow is governed by a declarative
`x-openregister-lifecycle` state machine on its `status` field (initial `draft`), with
transitions `submit` (draft/rejected → submitted), `approve` (submitted → approved), `reject`
(submitted → rejected) and `reopen` (approved → draft) — unchanged. The object SHALL carry at
least `employeeId`, `period`, `hours`, `entryCount`, `status`, `submittedAt`, `approvedBy`,
`approvedAt`, and `rejectionReason`. The lifecycle MUST be expressed declaratively in the
register fragment — not in bespoke PHP transition code. Additionally: `hours` and `entryCount`
are server-maintained aggregates of the timesheet's TimeEntry bookings (time-entry-capture
REQ-TEC-004), the `submit` transition SHALL declare
`requires: OCA\Hrmq\Lifecycle\TimesheetNotEmptyGuard` (an empty timesheet cannot be submitted),
and `submittedAt`/`approvedBy`/`approvedAt`/`rejectionReason` are lifecycle-stamped process
fields per the process-field requirement below — never hand-entered data.

#### Scenario: Employee submits a draft timesheet

- GIVEN a `Timesheet` in state `draft` with at least one booking (`entryCount` ≥ 1)
- WHEN the employee applies the `submit` transition
- THEN the timesheet MUST move to state `submitted`
- AND `submittedAt` MUST record the submission timestamp, stamped by the lifecycle machinery on
  the carrying write — not supplied by the client

#### Scenario: A rejected timesheet can be corrected and re-submitted

- GIVEN a `Timesheet` in state `rejected`
- WHEN the employee corrects its bookings and applies the `submit` transition
- THEN the timesheet MUST move to state `submitted`
- AND the previous `approvedBy`/`approvedAt`/`rejectionReason` MUST be cleared by the stamping

#### Scenario: An invalid transition is refused

- GIVEN a `Timesheet` in state `draft`
- WHEN the `approve` transition is attempted (which requires state `submitted`)
- THEN the lifecycle MUST refuse the transition and leave the state unchanged

### Requirement: Separation of duties on approval

The system SHALL prevent an employee from approving or rejecting their own timesheet. The
`approve` and `reject` transitions SHALL each declare `requires:
OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`, an OpenRegister `LifecycleGuardInterface` that denies the
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

The system SHALL surface the hours process through declarative manifest pages rendered
generically by the `@conduction/nextcloud-vue` library — NOT bespoke Vue components — authored
as manifest fragments (`src/manifest.d/`). The page set SHALL comprise: `MijnUren`
(`/mijn/uren`, TimeEntry booking, Add enabled), `MijnUrenstaten` (`/mijn/urenstaten`,
read-only own Timesheets), `TimeEntries` (`/time-entries`, HR booking index),
`TimeEntryDetail` (`/time-entries/:id`), `Timesheets` (`/timesheets`, HR aggregate index with
Add disabled — timesheets are server-created), `TimesheetApproval` (`/timesheets/approval`,
default filter `status == submitted`), `TeamUrengoedkeuring` (`/timesheets/team-approval`,
additionally `managerUserId: @me`), and `TimesheetDetail` (`/timesheets/:id`, carrying the
lifecycle actions, the read-only process panels and the bookings list). Every page that offers a
create or edit form SHALL declare an explicit `config.includeFields` allowlist (with
`fieldOverrides` as needed) so the form shows exactly the process-relevant fields; no hours form
may rely on the schema's full property set. HRMQ SHALL appear in the Nextcloud app menu via an
`<navigations>` entry routing to the SPA shell. The exact per-page configs are fixed by this
change's design.md Decision 8.

**Feature tier**: MVP

#### Scenario: The approval queue lists pending timesheets

- GIVEN submitted, approved and rejected timesheets exist in the `hrmq` register
- WHEN a manager opens the "Urengoedkeuring" (TimesheetApproval) page
- THEN the page MUST default its filter to `status == submitted`
- AND MUST render the list generically from the `Timesheet` schema without a bespoke component
- AND opening a row MUST land on `TimesheetDetail`, where the allowed transitions render as
  lifecycle actions

#### Scenario: HRMQ is reachable from the app menu

- GIVEN hrmq is installed and enabled
- WHEN the user opens the Nextcloud app menu
- THEN HRMQ MUST appear and open its SPA shell at the timesheets list

#### Scenario: The booking form is an allowlist

- GIVEN the `MijnUren` page
- WHEN the employee opens the create dialog
- THEN the form MUST show exactly `startedAt`, `endedAt`, `breakMinutes`, `description`,
  `projectId`, `billable`
- AND MUST NOT show `status`, `submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason`,
  `employeeId`, `userId`, `managerUserId`, `administrationId`, `costCenter`, `hours` or
  `allocationKey`

### Requirement: Process fields are server-stamped and inert to client input

`status`, `submittedAt`, `approvedBy`, `approvedAt` and `rejectionReason` SHALL never appear on
any create or edit form, and SHALL be inert to client input on every write path (UI form, direct
API): a write that supplies them without the corresponding lifecycle edge SHALL have those
values replaced by the stored values before persistence. On the lifecycle edges the system SHALL
stamp them on the carrying write: `submit` stamps `submittedAt` and clears the approval fields;
`approve` and `reject` stamp `approvedBy` (the acting session user) and `approvedAt`; `reopen`
clears all four. The stamping SHALL complete within the same persisted write that changes
`status`, so post-save consumers (including the approved-time-entry event) observe stamped
provenance. On CREATE the object SHALL always start at `status: "draft"` with all process fields
empty, whatever the client sent.

#### Scenario: Hand-writing an approval does not approve anything

@e2e exclude API-tamper path has no UI surface; verified by the TimesheetProcessStampListener unit tests (client-supplied approvedBy with no status edge is restored to the stored value)
- **GIVEN** a Timesheet in state `submitted`
- **WHEN** a client writes the object with `approvedBy: "attacker"` and `approvedAt` set but
  `status` unchanged
- **THEN** the persisted object carries the stored (empty) approval fields — the input is inert

#### Scenario: Approving stamps provenance on the carrying write

- **GIVEN** a submitted Timesheet of another employee
- **WHEN** a manager applies the `approve` transition from `TimesheetDetail`
- **THEN** the timesheet renders `approved` with `approvedBy` = the manager's uid and
  `approvedAt` set — with no separate follow-up write

### Requirement: Rejection reason is captured when the approver rejects

The `reject` transition SHALL capture a `rejectionReason` from the approver at rejection time —
never via the object's edit form. The Timesheet schema SHALL declare the reason as a transition
input (`x-openregister-lifecycle.transitions.reject.inputs`), the transition endpoint SHALL
merge only such allowlisted inputs into the carrying write (OpenRegister dependency work item
D1), and the lifecycle actions UI SHALL prompt for the declared inputs before posting the
transition (nextcloud-vue dependency work item D2). The reject-edge stamping accepts
`rejectionReason` as the single allowlisted client-supplied process value. Until D1 and D2 ship,
reject proceeds without a reason (matching the pre-redesign behaviour on the sanctioned path);
this requirement is satisfied only when the dependency items land.

#### Scenario: Rejecting prompts for and records the reason

@e2e exclude Blocked on external dependency work items D1 (openregister transition inputs) and D2 (nextcloud-vue prompt dialog); the e2e journey is added by tasks.md 4.5 when they land — shipping an e2e reference now would be a check that cannot run
- **GIVEN** a submitted Timesheet and a manager on `TimesheetDetail`
- **WHEN** the manager chooses Reject and enters "Uren komen niet overeen met aanwezigheid"
- **THEN** the timesheet moves to `rejected` with that `rejectionReason` persisted on the
  carrying write
- **AND** the employee sees the reason on their read-only Goedkeuring panel

#### Scenario: The reason cannot ride any other write

@e2e exclude API-tamper path has no UI surface; verified by the stamping listener unit tests
- **WHEN** a client supplies `rejectionReason` on a write that is not a submitted→rejected edge
- **THEN** the supplied value is discarded and the stored value persists
