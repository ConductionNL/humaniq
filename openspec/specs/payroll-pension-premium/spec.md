# payroll-pension-premium

## Purpose

Requirements first specified in the change `payroll-engine-port-from-shillinq` (archived 2026-11-25).

## Requirements

### Requirement: The payroll SHALL withhold the employee pension premium and record the employer premium (REQ-PPP-001)

For an employee with a `PensionParticipation` in force in the period, the payroll calculation
SHALL compute the pension base as the pensionable salary times the participation factor,
capped at the scheme's maximum pensionable salary for the period, minus the scheme's
franchise for the period times the employee's part-time factor, and never below zero. It
SHALL use the same base the pension filing reports (REQ-PUM-001). It SHALL compute the
employee premium and the employer premium as the scheme's percentages of that base, rounded
per cent. The employee premium SHALL reduce net pay and the taxable wage. The employer premium
SHALL count as an employer charge. Both SHALL be stored on the payslip.

Rows: `pay-pension-premium` (humaniq matrix). Source: `for-ruben/payroll-engine-move-comparison.md`, PayrollCalculator `pensioen`.

#### Scenario: A full-time participant pays the employee premium
- **GIVEN** a scheme with a yearly franchise of 18,000, no cap below the salary, an employee
  premium of 7.5% and an employer premium of 15%
- **AND** a full-time employee with a monthly pensionable salary of 4,000 and a
  participation factor of 1
- **WHEN** the April payroll run is calculated
- **THEN** the pension base is 2,500.00, the payslip's employee pension premium is 187.50 and
  its employer pension premium is 375.00
- **AND** net pay is 187.50 lower than without the participation, before the wage tax effect

@e2e exclude The premiums are the run's server-side calculation, stored on the payslip and rendered as stored fields. `PensionPremiumCalculationTest::testAFullTimeParticipantPaysTheEmployeePremium` and `PayrollRunServiceTest::testAParticipantsPayslipWithholdsThePensionPremium` assert 2,500.00, 187.50 and 375.00.

#### Scenario: A salary below the franchise pays no premium
- **GIVEN** the same scheme and an employee with a monthly pensionable salary of 1,200
- **WHEN** the payroll run is calculated
- **THEN** the pension base is 0 and both premiums are 0.00

@e2e exclude Server-side calculation only. `PensionPremiumCalculationTest::testASalaryBelowTheFranchisePaysNoPremium` and `PensionPremiumLookupTest::testASalaryBelowTheFranchiseHasAZeroBase` assert it.

#### Scenario: No participation, no premium
- **GIVEN** an employee without a `PensionParticipation` in the period
- **WHEN** the payroll run is calculated
- **THEN** both premiums are 0.00, as before this change

@e2e exclude Server-side calculation only; such a payslip is unchanged. `PensionPremiumCalculationTest::testNoParticipationNoPremium` and `PensionPremiumLookupTest::testNoParticipationOrNoSchemeMeansNoPremium` assert it.

### Requirement: A participation SHALL link an employee to one scheme for a date range (REQ-PPP-002)

HR SHALL record a `PensionParticipation` with an employee, a pension fund, a start date, an
optional end date and a participation factor. The payroll SHALL use the participation in force
on the last day of the period, and the administration's `PensionScheme` of that fund valid on
that day. Two participations of one employee SHALL NOT overlap.

#### Scenario: HR enrols a new employee
- **WHEN** an HR adviser adds a participation for an employee in fund `pfab` from 1 May
- **THEN** the May run computes the employee's premiums and the April run does not

@e2e exclude Adding a participation is the generic index page's create form; what this scenario asserts is which run reads it. `PensionPremiumLookupTest::testAParticipationCountsFromTheMonthItStarts` and `PensionPremiumLookupTest::testTheRowsInForceOnTheLastDayApply` assert it.

#### Scenario: An overlapping participation is refused
- **GIVEN** an employee in fund `pfab` from 1 January with no end date
- **WHEN** HR adds a second participation from 1 June
- **THEN** the save is refused with a message that names the existing participation

### Requirement: The pension premiums SHALL reach the payroll journal on their own lines (REQ-PPP-003)

The journal entry humaniq writes into shillinq for a payroll run SHALL debit the employer
pension premiums as an employer charge and SHALL credit both premiums to the pension payable
account set in the app settings. The net wages credit SHALL NOT include any pension premium.

#### Scenario: The journal splits the pension payable
- **GIVEN** an approved run whose payslips hold 187.50 employee and 375.00 employer pension
  premium
- **WHEN** the journal entry is posted
- **THEN** it holds a credit of 562.50 on the pension payable account and stays balanced

@e2e exclude The journal is written into shillinq by the server; no page of humaniq renders it. `PayrollGLPostServiceTest::testTheJournalSplitsThePensionPayable` and `PayrollGLPostServiceTest::testARunWithPremiumsPostsThePensionLine` assert 562.50 and the balance.

#### Scenario: No pension payable account is set
- **GIVEN** a run with pension premiums and no pension payable account in the settings
- **WHEN** the journal entry is posted
- **THEN** no entry is written and the post log records that the account is missing

@e2e exclude A server-side refusal recorded on the GL post. `PayrollGLPostServiceTest::testAMissingPensionPayableAccountWritesNoEntry` asserts that no entry is written and the reason is recorded.
