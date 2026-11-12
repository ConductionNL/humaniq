# time-entry-capture

## ADDED Requirements

### Requirement: A billable booking SHALL carry the rate it is billed at (REQ-TEC-009)

A `TimeEntry` with `billable: true` SHALL carry an `hourlyRate` in integer cents, taken from the
project's rate when the entry is written and editable by the project manager. The approved-time
event SHALL carry the hours and the amount, hours times rate, per project.

Rows: `tim-billable-rates` (humaniq matrix).

#### Scenario: A billable booking takes the project's rate
- **GIVEN** a project billed at 95 euros an hour
- **WHEN** an employee books 3 billable hours on it
- **THEN** the entry carries an `hourlyRate` of 9500 cents
- **AND** once its timesheet is approved, the event carries 3 hours and 285 euros for that project

#### Scenario: A non-billable booking has no rate
- **WHEN** an employee books hours with `billable: false`
- **THEN** the entry carries no `hourlyRate`
