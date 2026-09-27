# loonaangifte-message

## ADDED Requirements

### Requirement: A filing made ready SHALL carry its wage tax return message (REQ-LAM-001)

When a `LoonaangifteFiling` is made ready, humaniq SHALL render the loonaangifte message for
its administration and period from the approved payroll run's payslips, validate it against
the year's XML schema, and attach it to the filing with its collective totals. The message
SHALL NOT be rendered from a draft run.

Rows: `fil-wage-tax-return` (humaniq matrix).

#### Scenario: A payroll officer downloads the June return
- **GIVEN** an approved payroll run for 2026-06
- **WHEN** the payroll officer makes the June filing ready on `LoonaangifteFilingDetail`
- **THEN** the filing carries a message file that validates against the 2026 schema, with
  collective totals equal to the run's totals, ready to upload

#### Scenario: A draft run is not filed
- **GIVEN** only a draft run for the period
- **WHEN** the filing is made ready
- **THEN** the transition is refused with the reason that the run is not approved

### Requirement: Missing data SHALL stop the filing with a named finding (REQ-LAM-002)

When the data cannot produce a valid message, making the filing ready SHALL be refused and the
filing SHALL list, per employee, the element that is missing or invalid.

Rows: `fil-wage-tax-return` (humaniq matrix).

#### Scenario: An employee without a BSN
- **GIVEN** an approved run containing an employee with no BSN
- **WHEN** the filing is made ready
- **THEN** it stays in concept and lists that employee with the missing citizen service number
