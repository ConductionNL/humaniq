# payroll-partial-period

## ADDED Requirements

### Requirement: An employee employed for part of a period SHALL be paid pro rata (REQ-PPR-001)

For an employee whose contract starts after the first day or ends before the last day of the
period, the payroll calculation SHALL multiply the periodic salary components by a period
factor between 0 and 1 and SHALL store that factor on the payslip. Components paid per day,
per hour or per trip SHALL NOT be scaled. An employee employed for the whole period SHALL get
factor 1 and the same payslip as before this change. The method that counts the days is set
by design O2.

Rows: `pay-partial-period` (humaniq matrix). Source: `for-ruben/payroll-engine-move-comparison.md`, PayrollCalculator `proRataBruto`.

#### Scenario: A starter on the 16th of a 30-day month (calendar days)
- **GIVEN** the calendar-day method and an employee with a monthly salary of 3,000 whose
  contract starts on 16 June
- **WHEN** the June payroll run is calculated
- **THEN** the payslip's period factor is 0.5 and its gross salary is 1,500.00

#### Scenario: A leaver keeps per-trip travel allowances whole
- **GIVEN** an employee whose contract ends on 10 March and who made eight commuting trips
  before that day
- **WHEN** the March payroll run is calculated
- **THEN** the salary is scaled by the period factor and the travel allowance for eight trips
  is paid in full

#### Scenario: A full month is unchanged
- **GIVEN** an employee employed for the whole of May
- **WHEN** the May payroll run is calculated
- **THEN** the period factor is 1 and every amount equals the amount without this change

### Requirement: The payslip SHALL show why a partial period pays less (REQ-PPR-002)

The payslip detail page SHALL show the period factor and the dates it was computed from
whenever the factor is below 1.

#### Scenario: HR reads a starter's payslip
- **WHEN** an HR adviser opens the June payslip of an employee who started on 16 June
- **THEN** the page shows "Period factor 0.5 (16 June to 30 June)"
