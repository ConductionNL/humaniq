# awf-premium-review

## ADDED Requirements

### Requirement: BBL apprentices and young part-timers SHALL get the low premium (REQ-AWF-101)

The unemployment premium SHALL be the low rate for a permanent written contract, for a BBL
contract, and for an employee under 21 whose paid hours in the period average twelve a week or
less; otherwise the high rate. The payroll run and the audit rule SHALL use the same resolution.

Rows: `fil-premium-differentiation` (humaniq matrix).

#### Scenario: An apprentice is charged the low premium
- **GIVEN** an employee on a `bbl` contract
- **WHEN** the payroll officer calculates the June run
- **THEN** the payslip's employer charges use the low Awf rate and the audit rule passes

### Requirement: The low premium SHALL be reviewed on early ending and extra hours (REQ-AWF-102)

When a contract charged the low premium ends within two months of its start, or when a contract
under 35 hours a week charged the low premium is paid more than 30 percent above its contracted
hours in a calendar year, humaniq SHALL recompute the premium at the high rate over the affected
periods and settle the difference as an employer charge adjustment in the current run, leaving
net pay unchanged.

Rows: `fil-premium-differentiation` (humaniq matrix).

#### Scenario: A permanent contract ends after six weeks
- **GIVEN** a permanent written contract charged the low premium that ends six weeks after it
  started
- **WHEN** the next run is calculated
- **THEN** a payroll adjustment of type `awf-herziening` settles the high premium over those
  weeks, and the employee's net pay is unchanged

#### Scenario: A part-timer works far more than agreed
- **GIVEN** a 24-hour contract paid 34 hours a week on average over the year
- **WHEN** the December run is calculated
- **THEN** the year is recomputed at the high rate and the difference is settled in December, and
  the audit had signalled the overrun during the year
