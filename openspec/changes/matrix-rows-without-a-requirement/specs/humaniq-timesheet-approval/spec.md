# humaniq-timesheet-approval

## ADDED Requirements

### Requirement: An empty timesheet SHALL NOT be submitted

The `submit` transition SHALL be refused for a timesheet with no bookings (`entryCount` 0). The
refusal SHALL say that the timesheet has no hours.

Rows: `tim-timesheet-not-empty` (humaniq matrix).

#### Scenario: An employee submits a timesheet with no bookings
- **GIVEN** a `Timesheet` in state `draft` with `entryCount` 0
- **WHEN** the employee applies the `submit` transition
- **THEN** the transition is refused, the state stays `draft` and the refusal names the missing hours

### Requirement: An approved timesheet SHALL be reopenable until payroll has used it

The `reopen` transition SHALL move an approved timesheet back to `draft` and clear its approval
fields. It SHALL be refused once a payroll run that is approved or later has paid hours from the
timesheet.

Rows: `tim-reopen` (humaniq matrix).

#### Scenario: HR reopens an approved timesheet for a correction
- **GIVEN** an approved timesheet that no payroll run has used
- **WHEN** HR applies `reopen`
- **THEN** the timesheet is `draft`, its entries are editable and `approvedBy` and `approvedAt` are empty

#### Scenario: Payroll already paid the hours
- **GIVEN** an approved timesheet whose hours an approved payroll run has paid
- **WHEN** HR applies `reopen`
- **THEN** the transition is refused and the refusal names the payroll run
