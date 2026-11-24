# time-activities Specification

## Purpose
A municipality running projects wants one weekly timesheet per employee: hours on projects, hours on training, internal meetings and other non-project work, and the leave and sick days of that week, so the week adds up to the contract. In humaniq a time entry has a free project reference or nothing; there is no way to say "training" or "overhead", and leave and sickness live in their own records, invisible on the timesheet. planninq's lane decided this capability belongs to humaniq (planninq `docs/ARCHITECTURE.md` section 5, resolved question 4: time is logged per task there, and leave and sickness live in humaniq, whose `TimeEntry` planninq's hours are moving to).

## Requirements

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
- @e2e tests/e2e/spec-coverage/time-non-project-activities.spec.ts

#### Scenario: Leave cannot be booked twice
- **GIVEN** the same employee
- **WHEN** they try to book eight hours with activity "Verlof"
- **THEN** the booking is refused with a message that leave comes from the leave request
- @e2e tests/e2e/spec-coverage/time-non-project-activities.spec.ts

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

@e2e exclude Needs a working pattern, an approved leave day and a full week of bookings on one employee; the arithmetic is TimesheetWeekServiceTest's and the timesheet aggregate test's, and the week view is read in the live week of task 3.1
