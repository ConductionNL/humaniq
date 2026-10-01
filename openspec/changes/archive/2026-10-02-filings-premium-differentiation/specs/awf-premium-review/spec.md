# awf-premium-review

## ADDED Requirements

### Requirement: BBL apprentices and young part-timers SHALL get the low premium (REQ-AWF-101)

The unemployment premium SHALL be the low rate for a permanent written contract, for a BBL
contract whose praktijkovereenkomst is signed and carries no uitzendbeding, and for an employee
younger than 21 on the first day of the period who is paid at most 52 hours in that month (48
in a four-week period); otherwise the high rate. A rate set explicitly on the contract SHALL win,
except that the under-21 exception always applies. The payroll run, the retro recalculation and
the audit rule SHALL use the same resolution, and each payslip SHALL record the rate and the
reason for it.

Rows: `fil-premium-differentiation` (humaniq matrix).

#### Scenario: An apprentice is charged the low premium
- **GIVEN** an employee on a signed `bbl` contract
- **WHEN** the payroll officer calculates the June run
- **THEN** the payslip's employer charges use the low Awf rate, the payslip names `bbl` as the
  reason, and the audit rule passes the contract at low

@e2e exclude the rate is resolved server-side in the run; covered by PayrollRunServiceTest::testABblApprenticeIsChargedTheLowPremium, AwfTariffResolverTest::testASignedBblContractIsLowAndOneWithAnUitzendbedingIsHigh and NlPayrollChecksTest::testTheAwfRuleExpectsLowForASignedBblContract

#### Scenario: A 19-year-old on a weekend contract is charged the low premium
- **GIVEN** a 19-year-old on a temporary contract of 10 hours a week
- **WHEN** the June run is calculated
- **THEN** the payslip is charged the low rate with reason `young-part-time`, and at 16 hours a
  week it stays high

@e2e exclude the rate is resolved server-side in the run; covered by PayrollRunServiceTest::testAYoungPartTimerIsChargedTheLowPremiumWithinTheHoursNorm and AwfTariffResolverTest::testAYoungPartTimerUnderTheHoursNormIsLow

### Requirement: The low premium SHALL be reviewed on early ending and extra hours (REQ-AWF-102)

When an employment charged the low premium ends at most two months after it began (contracts
that follow each other without a day's gap count as one employment), humaniq SHALL charge the
high rate in its last period and recompute every earlier sealed period at the high rate. After a
calendar year, when an employee whose low-premium contracts averaged 30 contracted hours a week
or less was paid more than 30 percent above the contracted hours, humaniq SHALL recompute that
year's sealed low periods at the high rate. Each recomputed period SHALL become one adjustment of
type `awf-herziening` settled as an employer charge in the run being calculated, leaving net pay
unchanged, and SHALL be settled at most once. The BBL and under-21 exceptions SHALL NOT be
reviewed. While the year runs, each payslip SHALL carry the year-to-date paid and contracted hours
and the audit SHALL signal an overrun.

Rows: `fil-premium-differentiation` (humaniq matrix).

#### Scenario: A permanent contract ends after six weeks
- **GIVEN** a permanent written contract charged the low premium that ends six weeks after it
  started
- **WHEN** the next run is calculated
- **THEN** a payroll adjustment of type `awf-herziening` settles the high premium over those
  weeks, and the employee's net pay is unchanged

@e2e exclude the review is a server-side recomputation; covered by AwfReviewServiceTest::testAContractEndedAfterSixWeeksIsReviewedAtTheHighRate, PayrollRunServiceTest::testAContractEndingWithinTwoMonthsIsChargedHighInTheRun and PayrollRunServiceTest::testTheReviewSettlesInTheRunAndThePayslipCarriesTheYearToDateHours

#### Scenario: A part-timer works far more than agreed
- **GIVEN** a 24-hour contract paid 34 hours a week on average over the year
- **WHEN** the first run of the next year is calculated
- **THEN** the year is recomputed at the high rate and the difference is settled in that run,
  and the audit had signalled the overrun during the year

@e2e exclude the review and the signal are server-side; covered by AwfReviewServiceTest::testAPartTimerPaidFarAboveTheContractIsReviewedAfterTheYear, AwfReviewServiceTest::testThirtyPercentOrLessIsNotReviewed, AwfReviewServiceTest::testAboveThirtyContractedHoursAndTheExceptionsAreNotReviewed, AwfReviewServiceTest::testYearToDateFiguresForTheSignal and NlPayrollChecksTest::testTheExtraHoursSignalFlagsARunningOverrun
