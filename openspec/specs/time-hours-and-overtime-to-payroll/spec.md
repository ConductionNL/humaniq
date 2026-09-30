# time-hours-and-overtime-to-payroll Specification

## Purpose
Approved hours of hourly-paid employees and approved overtime flow into the payroll run as pay, with the surcharge from the contract or collective agreement, or into a time-off-in-lieu balance. Built by time-hours-and-overtime-to-payroll (archived 2026-09-30).

## Requirements

### Requirement: The payroll run SHALL pay approved hours to hourly-paid employees (REQ-HTP-001)

For an employee without a monthly salary whose covering contract carries an hourly wage, the
payroll run SHALL compute the period's gross as the hours of that employee's approved, unpaid
timesheets times the hourly wage, and SHALL run that gross through the unchanged calculator.
The payslip SHALL record the hours paid and the timesheets used. Each timesheet SHALL be paid
by exactly one run that is not a draft.

Rows: `tim-hours-to-payroll` (humaniq matrix).

#### Scenario: An hourly employee is paid instead of skipped
- **GIVEN** an employee without a monthly salary, a contract at 16.00 an hour, and an
  approved timesheet for 2026-05 with 128 hours
- **WHEN** a payroll officer calculates the 2026-05 run on `PayrollRunDetail`
- **THEN** the employee has a payslip with a gross of 2048.00, 128 hours paid and that
  timesheet listed, and is no longer in the skipped list

@e2e exclude the run is server-side; covered by PayrollRunServiceTest::testAnHourlyEmployeeIsPaidTheApprovedHours and HoursPayServiceTest::testHourlyPay

#### Scenario: Hours approved late are paid in the next run
- **GIVEN** the 2026-05 run is approved and the employee's 2026-05 timesheet is approved a day
  later
- **WHEN** the payroll officer calculates the 2026-06 run
- **THEN** the 2026-06 payslip pays that timesheet and the 2026-05 run is unchanged

@e2e exclude covered by HoursPayServiceTest::testWhichTimesheetsARunPays and HoursPayServiceTest::testStamping

#### Scenario: No hours means a stated skip
- **GIVEN** an hourly employee with no approved timesheet up to 2026-05
- **WHEN** the 2026-05 run is calculated
- **THEN** the employee is skipped with the reason `no-approved-hours`

@e2e exclude covered by PayrollRunServiceTest::testAnHourlyEmployeeWithoutHoursIsSkippedWithAReason

### Requirement: Employees SHALL register overtime and managers SHALL approve it with the timesheet (REQ-HTP-002)

A time entry SHALL be markable as overtime with a choice between pay and time off, defaulting
to the collective agreement's compensation preference. The timesheet SHALL show its overtime
hours beside its total, and the existing approval SHALL approve both.

Rows: `tim-overtime` (humaniq matrix).

#### Scenario: An employee books Saturday overtime
- **GIVEN** an employee on a contract whose agreement prefers payment
- **WHEN** the employee adds a 4-hour entry on Saturday 16 May 2026 marked as overtime on
  their timesheet
- **THEN** the timesheet shows 4 overtime hours and the entry's compensation is `pay`

@e2e exclude the aggregate and the default are server-side; covered by HoursPayServiceTest::testTheTimesheetAddsUpItsOvertime and HoursPayServiceTest::testTheCaoDefaultIsTimeOff

### Requirement: Approved overtime SHALL be paid with its surcharge or credited as time off (REQ-HTP-003)

Approved overtime to be paid SHALL add hours times the hourly rate times (100 + surcharge) /
100 to the period's gross, with the surcharge resolved from the contract override or the
collective agreement for the day's category. Approved overtime to be taken off SHALL be
credited at the same factor to the employee's compensation leave balance once the run is
approved, once per timesheet. When no surcharge resolves, the hours SHALL be paid or credited
at the base rate and the payslip SHALL state that the surcharge was not resolved.

Rows: `tim-overtime` (humaniq matrix).

#### Scenario: Saturday overtime is paid at 150 percent
- **GIVEN** a salaried employee on 36 hours a week earning 3800.00 a month, a contract
  override of 50% for Saturdays, and 4 approved Saturday overtime hours to be paid
- **WHEN** the payroll officer calculates the run
- **THEN** the payslip shows 4 overtime hours and an overtime pay of 4 x (3800.00 / 156) x 1.5,
  rounded to the cent, included in the gross

@e2e exclude covered by HoursPayServiceTest::testSaturdayOvertimeUnderAFiftyPercentSurcharge and PayrollRunServiceTest::testASalariedEmployeeWithoutOvertimeKeepsAnIdenticalPayslip

#### Scenario: Overtime becomes time off
- **GIVEN** 3 approved weekday overtime hours to be taken off at a 25% surcharge
- **WHEN** the run that settles them is approved
- **THEN** the employee's compensation balance for the year rises by 3.75 hours, and approving
  the flow a second time adds nothing

@e2e exclude covered by HoursPayServiceTest::testTimeOffOnAFeestdag and OvertimeCreditServiceTest::testACreditLandsOnce, ::testTheListenerFiresOnApprovalOnly

#### Scenario: An unconfirmed agreement is shown, not guessed
- **GIVEN** a contract under an agreement whose overtime article is a placeholder and no
  contract override
- **WHEN** the run pays 2 approved overtime hours
- **THEN** they are paid at the base hourly rate and the payslip marks the surcharge as
  unresolved

@e2e exclude covered by HoursPayServiceTest::testAPlaceholderCaoIsFlagged
