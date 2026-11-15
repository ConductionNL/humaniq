# awf-premium-review

## ADDED Requirements

### Requirement: A permanent written contract SHALL get the low unemployment premium and a flexible contract the high one (REQ-AWF-103)

The payroll run SHALL charge the low Awf rate for a contract of type permanent that is in
writing, and the high rate for every other contract unless an exception in REQ-AWF-101 applies.
The payslip SHALL name the rate it charged and the basis for it. One resolver,
`lib/Payroll/AwfTariffResolver.php`, SHALL decide the rate for the run, for a retro
recalculation and for the rules audit, so the three cannot disagree.

Rows: `fil-premium-differentiation` (humaniq matrix).

#### Scenario: A permanent contract in writing is charged the low premium
- **GIVEN** an employee with a permanent contract that is in writing
- **WHEN** a payroll officer calculates the June run
- **THEN** the payslip's employer charges use the low Awf rate and name `permanent-written` as
  the basis

#### Scenario: A fixed-term contract is charged the high premium
- **GIVEN** an employee with a fixed-term contract of 32 hours a week, aged 30
- **WHEN** the June run is calculated
- **THEN** the payslip's employer charges use the high Awf rate and name `flex` as the basis

#### Scenario: A permanent contract that is not in writing is charged the high premium
- **GIVEN** an employee with a permanent contract whose `writtenContract` is false
- **WHEN** the June run is calculated
- **THEN** the payslip uses the high Awf rate, and the rules audit reports no Awf violation for
  that payslip
