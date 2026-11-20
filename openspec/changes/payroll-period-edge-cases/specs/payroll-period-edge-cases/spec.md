# payroll-period-edge-cases

## ADDED Requirements

### Requirement: An administration SHALL run payroll monthly, four-weekly or weekly (REQ-PPE-001)

An administration SHALL have a pay frequency of month, four weeks or week, changeable only from
the first period of a year. A payroll run SHALL accept only period ids of that frequency:
`YYYY-MM`, `YYYY-Pnn` on ISO weeks, or `YYYY-Wnn`. The engine SHALL compute each period with
its own tijdvak factor and its own maximum premium wage. A monthly run SHALL compute exactly
what it computes before this change.

Rows: `dm-week-53` (humaniq matrix), changelog https://loket.nl/roadmap/.

#### Scenario: A four-weekly employer runs period 5
- **GIVEN** an administration with pay frequency four weeks
- **WHEN** a payroll officer calculates the run for `2026-P05`
- **THEN** the payslips are computed with the four-weekly tijdvak factor 13 and the
  four-weekly maximum premium wage

@e2e exclude A run is calculated server-side and its payslip is read back through the API; there is no page step a browser test adds. `PayrollRunServiceTest::testAFourWeeklyPayslipScalesTheMonthlyFigures` and the pack's inline `2026-P05` vectors (gate 5) assert the four-weekly factor and cap.
#### Scenario: The wrong grain is refused
- **GIVEN** an administration with pay frequency month
- **WHEN** a payroll officer asks for a run for `2026-P05`
- **THEN** the run is refused because the administration pays monthly

@e2e exclude The refusal is the run's answer to a period id, not something a page renders differently. `PayrollRunServiceTest::testARunTakesOnlyPeriodsOfTheAdministrationsFrequency` asserts it.

### Requirement: The 53rd week SHALL be paid and taxed as a one-week tijdvak (REQ-PPE-002)

In a year with ISO week 53, a weekly administration SHALL run period W53, and a four-weekly
administration SHALL pay week 53 either as a separate period 14 or on the payslip of period 13,
per its setting. In every case the wage for week 53 SHALL be taxed and insured with the weekly
tijdvak, separately from the four weeks of period 13.

Rows: `dm-week-53` (humaniq matrix), changelog https://loket.nl/roadmap/.

#### Scenario: Period 13 extended with week 53
- **GIVEN** a four-weekly administration set to extend period 13, and 2026, which has ISO
  week 53
- **WHEN** the payroll officer calculates `2026-P13`
- **THEN** each payslip shows one calculation for weeks 49 to 52 with the four-weekly tijdvak
  and one for week 53 with the weekly tijdvak, and their sum

@e2e exclude The two calculations are written by the run and land as the payslip's `week53` block; the payslip page renders stored fields only. `PayrollRunServiceTest::testAnExtendedPeriod13AddsWeek53AsAOneWeekCalculation` asserts both parts and their sum.
#### Scenario: Week 53 as its own period
- **GIVEN** the same administration set to a separate period 14
- **WHEN** the payroll officer calculates `2026-P14`
- **THEN** the run covers week 53 only, with the weekly tijdvak

@e2e exclude Server-side calculation only. `PayrollRunServiceTest::testASeparatePeriod14IsWeek53TaxedAsOneWeek` asserts the weekly tijdvak for `2026-P14`.
#### Scenario: A year without week 53 has no period 14
- **GIVEN** 2025, which has 52 ISO weeks
- **WHEN** the payroll officer asks for `2025-P14`
- **THEN** the run is refused

@e2e exclude A refused period id, asserted by `PayPeriodTest` and `PayrollRunServiceTest::testASeparatePeriod14IsWeek53TaxedAsOneWeek` (`2025-P14`).

### Requirement: Opening balances SHALL carry a previous package's year-to-date figures into annual totals (REQ-PPE-003)

humaniq SHALL hold, per employee, administration and year, the year-to-date figures of the
previous payroll package up to its last period, entered by import. The annual statement SHALL
add them to humaniq's payslips and SHALL state that it includes them; the WKR assessment SHALL
add their wage to the fiscal wage bill. A humaniq payslip in a period the opening balance
already covers SHALL be flagged, and an employee who starts in humaniq after the first period of
a year without an opening balance SHALL be flagged.

Rows: `dm-midyear-payroll-start` (humaniq matrix), changelog
https://klant.afas.nl/update/profit-7/hrm.

#### Scenario: A July switch gives a full-year statement
- **GIVEN** an employee with an opening balance for January to June 2026 of 22800.00 wage and
  humaniq payslips for July to December of 3800.00 each
- **WHEN** a payroll officer generates the 2026 annual statement
- **THEN** it shows a total wage of 45600.00 over twelve periods and says it includes the
  opening balance from the previous package

@e2e exclude The statement is aggregated server-side before the PDF is rendered by docudesk. `HrDocumentServiceTest::testGenerateJaaropgaafAddsTheOpeningBalanceOfTheYear` asserts the twelve periods; the import itself is the library's mass import on the OpeningBalances page, covered by the library's own tests.
#### Scenario: A double-counted month is flagged
- **GIVEN** an opening balance up to 2026-06 and a humaniq payslip for 2026-06 for the same
  employee
- **WHEN** an HR adviser runs the rule audit
- **THEN** the audit flags the overlap for that employee and period

@e2e exclude A rule audit finding, produced by `occ humaniq:rules:audit` and the audit service. `RuleAuditServiceTest::testTheAuditReportsAnOpeningBalanceOverlapAndAMissingBalance` asserts it.
#### Scenario: A forgotten balance is flagged
- **GIVEN** an employee whose first humaniq payslip of 2026 is for 2026-07 and who has no 2026
  opening balance
- **WHEN** the rule audit runs
- **THEN** the audit flags the missing opening balance

@e2e exclude A rule audit finding, as above. `RuleAuditServiceTest::testTheAuditReportsAnOpeningBalanceOverlapAndAMissingBalance` and `NlOpeningBalanceChecksTest` assert it.
