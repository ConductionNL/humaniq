# hr-reports Specification

## Purpose
humaniq's Reports page offers three ready-made reports (workforce, leave and absence, performance). A payroll officer or a controller finds nothing about pay: no wage cost report, no payroll run overview, no turnover over the year, no leave balances. And no one can make a report of their own: the index pages cannot save a chosen set of filters and columns as a report for colleagues, and most of them cannot export. Six of the seven compared systems ship payroll and absence reports as standard, and five let users build and share their own and export them to a spreadsheet.

## Requirements

### Requirement: The Reports page SHALL offer payroll and leave reports ready-made (REQ-HRP-001)

Beside the workforce, absence and performance reports, the `Reports` page SHALL offer ready-made
reports for wage costs, payroll runs, turnover and leave balances, each opening without any
configuration.

Rows: `rep-standard-reports` (humaniq matrix).

#### Scenario: A controller opens the wage cost report
- **GIVEN** payroll runs for the last twelve months
- **WHEN** a controller opens `Reports` and chooses "Wage costs"
- **THEN** the report shows the wage cost per month with its components, without any setup

#### Scenario: HR sees whose leave expires
- **GIVEN** leave balances with hours expiring on 1 July
- **WHEN** an HR adviser opens the "Leave balances" report
- **THEN** it lists the balances per employee and leave type with their expiry date

### Requirement: Users SHALL build, share and export their own reports (REQ-HRP-002)

On the main index pages a user SHALL be able to save the current filters, sort and columns as a
named report, share it with other users, reopen it from the `Reports` page, and export it to CSV
or Excel. Exports SHALL contain only the fields the user may read.

Rows: `rep-report-builder` (humaniq matrix).

#### Scenario: HR builds a report of contracts ending this quarter
- **GIVEN** an HR adviser on `EmploymentContracts`
- **WHEN** they filter on end dates in this quarter, choose the columns, save the view as a
  shared report and export it to Excel
- **THEN** the file holds those contracts and columns, and a colleague finds the report under
  "My reports" on `Reports`

#### Scenario: An export leaves out fields the user may not read
- **GIVEN** a manager allowed to export their team's employees but not their salaries
- **WHEN** they export the employees view
- **THEN** the file has no salary column
