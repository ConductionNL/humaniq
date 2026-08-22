# mss-team-scope — delta for humaniq-hours-process-redesign

## MODIFIED Requirements

### Requirement: The approval-carrying schemas SHALL gain an optional denormalized `managerUserId` scoping property (REQ-MSS-001)

`Timesheet` (`lib/Settings/register.d/hr-timesheet.json`), `Expense` (`hr-expense.json`) and
`LeaveRequest` (`hr-leave.json`) SHALL each declare `managerUserId`: string, nullable, NOT in
`required`, NEVER a `$ref` (it names a Nextcloud *account*, mirroring the `userId`/`approvedBy`
convention). Manager assignment truth lives ONLY on the org structure (`OrgAssignment` →
`OrgUnit.managerId`); `managerUserId` is a **server-maintained cache** of that chain, existing
solely because the manifest filter grammar cannot join across schemas for the `@me` team-queue
filters. For `Timesheet`, the cache SHALL be stamped server-side on every write by the process
stamping listener via the shared `OrgResolutionService` — the same code path the
`nl-mss-manager-consistency` audit (`NlOrgChecks`) evaluates, so stamp and audit cannot
disagree; it SHALL never appear on any form, and client input to it is inert. For `Expense` and
`LeaveRequest` the property, its filter role and its audit are unchanged by this change
(server-stamping those two records is follow-up work, not regressed here — they remain
HR/back-office-populated until their own process redesigns). Each property description SHALL be
user-oriented ("The team manager who reviews this record — kept up to date automatically from
the organisation structure" for Timesheet), with the denormalization rationale in `x-notes`.

#### Scenario: Record without a manager stamp stays valid

@e2e exclude Register-level validation of an optional nullable property; no UI journey exists for creating an org-managerless employee's timesheet — verified by the stamping listener unit tests (null path) and the register import
- **GIVEN** the imported hrmq register after the schema bumps
- **WHEN** a Timesheet is created for an employee with no resolvable org manager
- **THEN** creation succeeds and `managerUserId` is `null` (fail-closed: it appears in no team
  queue)

#### Scenario: Stamped record round-trips

@e2e exclude Plain-string persistence contract on a schema this change does not redesign (Expense); backend round-trip verified by the existing register import tests
- **WHEN** an Expense is written with `managerUserId: "admin"`
- **THEN** the value persists and is returned as a plain string (no reference resolution)

#### Scenario: The Timesheet cache follows the org structure, not the client

@e2e exclude Stamping is a backend write-path behaviour; verified by TimesheetProcessStampListener + OrgResolutionService unit tests (the team-queue UI consequence is covered by the existing TeamUrengoedkeuring filter scenario)
- **GIVEN** an employee whose active `OrgAssignment` unit's manager resolves to
  `nextcloudUserId` "admin"
- **WHEN** any write persists that employee's Timesheet, even one supplying
  `managerUserId: "someone-else"`
- **THEN** the persisted `managerUserId` is "admin" — the org chain wins, client input is inert
