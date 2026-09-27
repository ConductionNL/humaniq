# pension-upa-message

## ADDED Requirements

### Requirement: A checked pension filing SHALL carry its delivery message (REQ-PUM-001)

When a `PensionFiling` is checked, humaniq SHALL render the pension delivery for its fund and
period from the approved payroll run, using the fund's `PensionScheme`: per employee the
pensionable wage, the pension base after franchise and cap, the part-time factor and the
contributions. The message SHALL be in the UPA format or APG's delivery format as the scheme
says, SHALL validate against that format's schema, and SHALL be attached to the filing.

Rows: `fil-pension` (humaniq matrix).

#### Scenario: A payroll officer produces the UPA delivery
- **GIVEN** an approved June run and a UPA scheme for fund `pfab`
- **WHEN** the payroll officer checks the June `pfab` filing on `PensionFilingDetail`
- **THEN** the filing carries a UPA file that validates, with one entry per employee in the
  scheme

#### Scenario: A filing that cannot be delivered stays open
- **GIVEN** an approved run with an employee whose contract has no hours per week
- **WHEN** the filing is checked
- **THEN** the check is refused and the filing names that employee and the missing part-time
  factor

### Requirement: ABP SHALL be delivered through the same mechanism (REQ-PUM-002)

An administration obliged to ABP SHALL be able to hold an `abp` scheme on APG's delivery
format, and checking its `abp` filing SHALL produce that delivery.

Rows: `fil-abp` (humaniq matrix).

#### Scenario: A municipality's ABP delivery
- **GIVEN** an ABP-obliged administration with an `abp` scheme on the APG format
- **WHEN** the payroll officer checks the June `abp` filing
- **THEN** the filing carries an APG delivery file, and `nl-abp-fund-required` passes for June
