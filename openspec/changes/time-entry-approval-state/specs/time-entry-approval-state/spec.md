# time-entry-approval-state

## ADDED Requirements

### Requirement: A time entry SHALL carry the approval state of its timesheet (REQ-TAS-001)

Every `TimeEntry` SHALL carry `approvalState`, equal to its timesheet's
status, and `approvedAt` when that status is `approved`. humaniq SHALL stamp
both on the entry's own write and on every status edge of its timesheet, and
SHALL ignore any client value for either.

Requested by shillinq `sales-time-and-expense-billing` through
`hours-to-humaniq`.

#### Scenario: Approving a timesheet approves its entries
- **GIVEN** employee Visser's submitted timesheet for 2026-09 with three entries
- **WHEN** her manager approves the timesheet in humaniq
- **THEN** all three entries carry `approvalState: approved` and the approval time
- @e2e exclude a listener fan-out with no screen of its own; pinned by the TimesheetEntryStateListener unit tests

#### Scenario: Reopening takes the approval back
- **GIVEN** the same approved timesheet
- **WHEN** HR reopens it for a correction
- **THEN** its entries carry `approvalState: draft` and no approval time
- @e2e exclude pinned by the TimesheetEntryStateListener unit tests

#### Scenario: A client cannot approve its own entry
- **GIVEN** an employee booking hours on a draft timesheet
- **WHEN** the booking is sent with `approvalState: approved`
- **THEN** the saved entry carries `approvalState: draft`
- @e2e exclude a server-side stamp; pinned by the TimeEntryStampListener unit test

### Requirement: Approved billable hours SHALL be readable per project and period in one query (REQ-TAS-002)

A caller with read access to time entries SHALL be able to list the entries of
one project in a date range that are billable and approved by filtering
`TimeEntry` on `projectId`, `date`, `billable` and `approvalState`.

#### Scenario: Shillinq lists the hours to invoice
- **GIVEN** approved entries of 8 and 4.5 billable hours and 2 non-billable hours on project "Renovatie Kade 12" in September 2026
- **WHEN** shillinq's hours source asks for the approved billable hours of that project for September
- **THEN** it receives the two billable entries, 12.5 hours, with person, date and description
- @e2e exclude a data read between apps with no humaniq screen; covered by the live check in tasks.md 3.1
