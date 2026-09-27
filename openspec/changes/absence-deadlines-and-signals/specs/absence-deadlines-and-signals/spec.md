# absence-deadlines-and-signals

## ADDED Requirements

### Requirement: HR and the manager SHALL be reminded before each Poortwachter deadline (REQ-ADS-001)

For every open sickness case, humaniq SHALL notify the HR group and the employee's manager
when an undone Poortwachter milestone (problem analysis, action plan, 42-week notification,
first-year evaluation) comes within 14 days of its due date, and again when it passes it.
Reminders SHALL use the canonical notification dialect, SHALL stop once the milestone's done
date is filled or the case is recovered, and SHALL NOT carry any medical detail.

Rows: `abs-gatekeeper-reminders` (humaniq matrix).

#### Scenario: Week 42 does not slip
- **GIVEN** an open case whose 42-week notification is due in 14 days and not done
- **WHEN** the days left reach 14
- **THEN** the HR adviser and the employee's manager receive a notification naming the
  employee, the 42-week notification and its due date

#### Scenario: A done milestone is quiet
- **GIVEN** the same case with `uwv42WeekMeldingDone` filled
- **WHEN** the due date passes
- **THEN** no overdue reminder is sent

### Requirement: The 42-week notification SHALL be generated from the case (REQ-ADS-002)

`SickLeaveCaseDetail` SHALL offer `Generate 42-week notification` for an open case whose
notification is not yet done. It SHALL render, through filinq, a document with the
employer's name, payroll tax number and KvK number, the employee's name, BSN and date of
birth, the first sick day, the work-resumption steps and the contract's hours, type and end
date, and SHALL store it as an `HrGeneratedDocument` linked to the case. It SHALL hold no
medical data and SHALL NOT transmit anything. It SHALL be available to admins and HR only.

Rows: `abs-uwv-reporting` (humaniq matrix).

#### Scenario: The adviser files from a generated form
- **GIVEN** an open case in week 40 for an employee on a 32-hour contract, 50 percent back
  at work
- **WHEN** an HR adviser presses `Generate 42-week notification`
- **THEN** a PDF with the first sick day, the 50 percent resumption step and the 32 contract
  hours is attached to the case, and the adviser records the filing date after submitting it

#### Scenario: A recovered case has nothing to notify
- **GIVEN** a case with status `hersteld`
- **WHEN** the notification is requested
- **THEN** it is refused and no document is created

### Requirement: Frequent absence SHALL be signalled at a threshold each administration sets (REQ-ADS-003)

Each administration SHALL set a number of sickness cases and a window in months. When a new
or reopened case brings an employee to that number within the window, humaniq SHALL mark
the case, SHALL notify the HR group and the manager once, and SHALL show the count on
`EmployeeDetail`. A case reopened as a relapse SHALL count once.

Rows: `td-frequent-absence` (humaniq matrix), tender https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: The third absence in a year
- **GIVEN** an administration with threshold 3 in 12 months and an employee with two cases
  since January
- **WHEN** an HR adviser records a third case in October
- **THEN** the case is marked as frequent absence, the adviser and the manager are notified
  once, and `EmployeeDetail` shows 3 cases in 12 months

#### Scenario: A relapse is not a new episode
- **GIVEN** the employee's second case recovered two weeks ago
- **WHEN** the case is reopened
- **THEN** the count stays 2 and no signal is raised
