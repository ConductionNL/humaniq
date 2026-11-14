# offboarding-completion

## ADDED Requirements

### Requirement: The open leave balance SHALL be paid on the leaver's final payslip (REQ-OFC-005)

When an employee's `endDate` falls in a payroll period, the run for that period SHALL pay their
open leave hours (entitled plus extra-statutory minus used, at least zero) at the hourly wage of
the last contract. The payslip SHALL show the hours and the amount as one line. The offboarding
case SHALL then read `verlofsaldoUitbetaald: true`.

Rows: `lve-leave-in-pay` (humaniq matrix).

#### Scenario: A leaver with 16 open hours
- **GIVEN** an employee leaving on 31 March with 16 open leave hours and an hourly wage of 25 euros
- **WHEN** the March payroll run is calculated
- **THEN** their payslip carries a leave payout line of 16 hours and 400 euros
- **AND** their offboarding case reads `verlofsaldoUitbetaald: true`

#### Scenario: A leaver with no open hours
- **GIVEN** an employee leaving with every leave hour used
- **WHEN** the run is calculated
- **THEN** the payslip has no leave payout line
