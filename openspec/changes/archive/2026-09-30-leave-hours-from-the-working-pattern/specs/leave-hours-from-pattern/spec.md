# leave-hours-from-pattern

## ADDED Requirements

### Requirement: A leave day SHALL cost the hours the person was contracted to work that day (REQ-LHP-001)

For a leave request without an explicit `hours` value, humaniq SHALL compute the cost per
day from `WorkingHoursService`: the `WorkingPattern` in force, minus the employee's
`NonWorkingTime`, and zero on a day openregister's working calendar marks non-working. The
cost SHALL be the sum over the request's days in the balance year.

Rows: `lve-hours-from-pattern` (humaniq matrix).

#### Scenario: A part-timer's two days off
- **GIVEN** an employee whose pattern is eight hours on Monday, Tuesday and Wednesday
- **WHEN** a manager approves leave for a Monday and Tuesday
- **THEN** the leave balance shows 16 hours used for it, not 9.6

@e2e exclude the cost is computed server-side; covered by LeaveHoursFromPatternTest::testThePatternDecidesTheHours and LeaveBalanceProjectionServiceTest::testTheCalendarIsReadOncePerProjection

#### Scenario: Days the person never works cost nothing
- **GIVEN** the same employee
- **WHEN** leave for a Thursday and Friday is approved
- **THEN** it costs 0 hours

@e2e exclude covered by LeaveHoursFromPatternTest::testThePatternDecidesTheHours

### Requirement: Public holidays SHALL come from openregister's working calendar and nowhere else (REQ-LHP-002)

Leave calculations SHALL treat as non-working exactly the dates openregister's working
calendar marks non-working, read through `WorkingCalendarReader`. humaniq SHALL NOT hold a
public-holiday list or schema. When the calendar cannot be read, the cost SHALL be computed
from the pattern alone and marked `pattern-only`, and SHALL NOT assume any holiday.

Rows: `lve-public-holidays` (humaniq matrix).

#### Scenario: Easter Monday costs no leave
- **GIVEN** an employee contracted for eight hours every weekday and an openregister working
  calendar marking Easter Monday non-working
- **WHEN** leave for the week of Easter Monday is approved
- **THEN** it costs 32 hours

@e2e exclude needs openregister's working calendar; covered by LeaveHoursFromPatternTest::testACalendarFeestdagCostsNothing and LeaveCostServiceTest::testTheCostNamesEachDay

#### Scenario: An instance without the calendar says so
- **GIVEN** an instance where openregister publishes no working calendar
- **WHEN** the cost of the same week is read
- **THEN** it is 40 hours with basis `pattern-only`

@e2e exclude covered by LeaveHoursFromPatternTest::testAnUnreadCalendarIsPatternOnly and LeaveCostServiceTest::testAnUnreadCalendarSaysPatternOnly

### Requirement: Every leave cost SHALL state its basis and be visible before approval (REQ-LHP-003)

The computed cost SHALL carry a basis of `explicit`, `pattern`, `pattern-only` or
`contract-average`. `LeaveRequestDetail` SHALL show the cost per year and a day-by-day
breakdown naming each public holiday and free day, read through
`GET /api/leave/requests/{id}/cost`, which SHALL answer 404 for a request the caller may not
read.

Rows: `lve-hours-from-pattern`, `lve-public-holidays` (humaniq matrix).

#### Scenario: The approver sees why the week costs 32 hours
- **GIVEN** a submitted request for the week of Easter Monday
- **WHEN** the manager opens `LeaveRequestDetail`
- **THEN** the cost section shows 32 hours with basis `pattern`, and Easter Monday listed
  as `feestdag` with 0 hours

@e2e exclude the widget renders the endpoint's rows; the rows are covered by LeaveCostServiceTest::testTheCostNamesEachDay and the 404 by LeaveCostServiceTest::testAnUnreadableRequestIs404
