# leave-expiry-and-carry-over Specification

## Purpose
Leave that draws from the hours that lapse first, carries over by the leave type's rule, lapses on its date unless HR waives it, and warns before it does. Built by leave-expiry-and-carry-over (archived 2026-09-29).

## Requirements

### Requirement: Leave taken SHALL draw from the hours that lapse first (REQ-LEX-001)

humaniq SHALL allocate the hours of every approved leave request, in date order, to the
employee's balances of that leave type, taking first the bucket that is valid on the leave
day and lapses soonest. Each balance SHALL record the statutory and bovenwettelijk hours used
from it, and `usedHours` SHALL be their sum. The allocation SHALL be recomputed from the
approved requests, so recomputing twice gives the same result.

Rows: `lve-year-end`, `lve-expiry` (humaniq matrix).

#### Scenario: Last year's hours go first
- **GIVEN** an employee with 16 unused statutory hours on last year's balance and a fresh
  balance this year
- **WHEN** a manager approves a 24-hour request for February
- **THEN** `LeaveBalances` shows 16 hours used on last year's balance and 8 on this year's

@e2e exclude the allocation is a backend recompute; covered by LeaveAllocationCalculatorTest::testAFebruaryRequestDrawsLastYearsStatutoryHoursFirst and LeaveBalanceProjectionServiceTest::testLastYearsHoursGoFirst

#### Scenario: A request after 1 July cannot use last year's statutory hours
- **GIVEN** the same employee with last year's statutory hours unused
- **WHEN** a request for August is approved
- **THEN** none of its hours are drawn from last year's statutory bucket

@e2e exclude the allocation is a backend recompute; covered by LeaveAllocationCalculatorTest::testARequestAfterFirstJulySkipsLastYearsStatutoryBucket

### Requirement: Unused hours SHALL carry over by the leave type's rule (REQ-LEX-002)

Statutory hours SHALL stay usable until their `expiryDate`. Bovenwettelijk hours SHALL stay
usable according to the leave type's carry-over rule: all of them, up to a cap, or none, and
until `bovenwettelijkExpiryDate`, which defaults to 31 December five years after the year.

Rows: `lve-year-end` (humaniq matrix).

#### Scenario: A capped carry-over
- **GIVEN** a holiday leave type with carry-over `capped` at 40 hours and an employee ending
  the year with 56 unused bovenwettelijk hours
- **WHEN** the year ends
- **THEN** 40 of those hours stay usable next year and 16 are recorded as lapsed

@e2e exclude the carry-over is a backend recompute; covered by LeaveAllocationCalculatorTest::testACappedCarryOverKeepsTheCapAndLapsesTheRest

### Requirement: Statutory hours SHALL lapse on their expiry date unless HR waives it (REQ-LEX-003)

When a bucket's expiry date has passed, humaniq SHALL record the hours left in it as
`expiredHours` and SHALL subtract them from `remainingHours`, unless the balance carries
`expiryWaived` with a reason. The lapse SHALL be recomputed from the balance and its
allocation each day, so a request approved later for a date before the expiry reduces it.

Rows: `lve-expiry` (humaniq matrix).

#### Scenario: Hours are gone on 2 July
- **GIVEN** a balance for last year with 8 statutory hours left and `expiryDate` 1 July
- **WHEN** the daily leave job runs on 2 July
- **THEN** the balance shows 8 expired hours and a remaining balance 8 hours lower

@e2e exclude the lapse is written by the daily job; covered by LeaveAllocationCalculatorTest::testHoursLapseTheDayAfterTheExpiryDate, LeaveBalanceProjectionServiceTest::testTheDailyRunLapsesLastYearsHoursUnlessWaived and LeaveAccrualJobTest::testTheDailyRunAppliesTheLapsesDueToday

#### Scenario: A long-term sick employee keeps the hours
- **GIVEN** the same balance with `expiryWaived` true and the reason "long-term sickness"
- **WHEN** the daily job runs on 2 July
- **THEN** no hours are recorded as expired

@e2e exclude the lapse is written by the daily job; covered by LeaveAllocationCalculatorTest::testAWaivedLapseKeepsTheHours and LeaveBalanceProjectionServiceTest::testTheDailyRunLapsesLastYearsHoursUnlessWaived

### Requirement: The employee and HR SHALL be warned before statutory hours lapse (REQ-LEX-004)

When a balance holds statutory hours that lapse within 60 days, humaniq SHALL notify the
employee and the HR group through the canonical notification dialect, naming the hours and
the date. `LeaveBalances` SHALL offer a view of the balances that lapse within 90 days.

Rows: `lve-expiry` (humaniq matrix).

#### Scenario: A reminder in May
- **GIVEN** an employee with 24 statutory hours that lapse on 1 July
- **WHEN** the date is 5 May
- **THEN** the employee receives a notification that 24 hours lapse on 1 July, and an HR
  adviser sees the balance in the "lapses soon" view

@e2e exclude the warning is sent by OpenRegister's notification engine on a date; the declaration is covered by LeaveExpiryDeclarationTest::testTheWarningIsDeclaredForTheEmployeeAndHr and the rule and calculations were evaluated with OpenRegister's NotificationAnnotationValidator, CalculationAnnotationValidator and CalculationEvaluator
