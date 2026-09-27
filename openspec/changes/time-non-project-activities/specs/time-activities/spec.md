# time-activities

## ADDED Requirements

### Requirement: Non-project work SHALL be bookable as an activity (REQ-TAC-001)

humaniq SHALL hold administered time activities of kind project, non-project or absence, and a
time entry SHALL be able to carry a non-project activity such as training or an internal meeting
instead of a project. Absence activities SHALL NOT be booked by hand.

Rows: `tim-non-project` (planninq matrix, owned by humaniq), tender
https://www.tenderned.nl/aankondigingen/overzicht/365739.

#### Scenario: An employee books a training afternoon
- **GIVEN** an employee on `MijnUren`
- **WHEN** they book four hours on Wednesday with activity "Opleiding"
- **THEN** the entry is saved without a project, and the week's timesheet counts four
  non-project hours

#### Scenario: Leave cannot be booked twice
- **GIVEN** the same employee
- **WHEN** they try to book eight hours with activity "Verlof"
- **THEN** the booking is refused with a message that leave comes from the leave request

### Requirement: Leave and sickness SHALL show on the weekly timesheet from their own records (REQ-TAC-002)

The week view of a timesheet SHALL show read-only lines for approved leave and open sickness in
that week, in hours from the employee's working pattern, and the timesheet SHALL show project,
non-project and absence hours beside the contract hours.

Rows: `tim-non-project` (planninq matrix, owned by humaniq).

#### Scenario: A week that adds up
- **GIVEN** a 36-hour employee with 26 project hours, 2 training hours and one approved leave day
  of 8 hours in a week
- **WHEN** they open the week's `TimesheetDetail`
- **THEN** it shows 26 project, 2 non-project and 8 absence hours against 36 contract hours
