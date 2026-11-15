# payroll-run-checks Specification

## Purpose
Every calculated payroll run carries a stored check a reviewer reads on the run page before approving it: who will not be paid, which approved input no run pays, which payslip breaks a rule, and which amount is far from the employee's own paid history, with the known cause named. Built by payroll-run-checks (archived 2026-09-30).

## Requirements

### Requirement: Every calculated draft run SHALL carry a stored check on its page (REQ-PRK-001)

After each calculation of a draft payroll run, and on demand, humaniq SHALL store the run's
findings as objects linked to the run and SHALL show them with their counts per severity on the
run's page. A new check SHALL replace the previous findings, except that an acknowledged
finding that recurs SHALL keep its acknowledgement.

Rows: `pay-run-check` (humaniq matrix).

#### Scenario: A reviewer sees the check after calculating
- **GIVEN** a draft run for 2026-05
- **WHEN** a payroll officer chooses "(Re)calculate" on `PayrollRunDetail`
- **THEN** the page lists the run's findings with a count of blocking and warning findings

@e2e exclude the check runs server-side after the calculation and the page is manifest-driven; covered by PayrollRunServiceTest::testACalculationRunsTheCheckWithTheSkippedList, PayrollRunCheckServiceTest::testTheFourSourcesBecomeFindingsAndCounts and the PayrollRunDetail manifest (check:manifest)

#### Scenario: An acknowledged finding stays acknowledged
- **GIVEN** a warning a reviewer acknowledged with the note "bonus agreed with the manager"
- **WHEN** the run is recalculated and the same deviation is found again
- **THEN** the finding is still acknowledged with that note and shows the new values

@e2e exclude covered by PayrollRunCheckServiceTest::testAnAcknowledgedFindingSurvivesARecheck, PayrollRunFindingStampListenerTest::testALaterWriteKeepsTheReviewer and PayrollRunChecksDeclarationTest::testAFindingIsAcknowledgedThroughATransition

### Requirement: The check SHALL list who will not be paid and which payslip breaks a rule (REQ-PRK-002)

The check SHALL record every employee the calculation skipped as a blocking finding with the
reason in plain words, and SHALL record the rule audit of the run's period and administration:
a mandatory violation as blocking, any other violation as a warning. The check SHALL inform
the approval and SHALL NOT change the run's status.

Rows: `pay-run-check` (humaniq matrix).

#### Scenario: An employee without a salary is flagged
- **GIVEN** an employee in the administration with a covering contract and no salary or hours
- **WHEN** the run is calculated
- **THEN** a blocking finding names that employee and says no salary or approved hours were
  found, and the run stays a draft

@e2e exclude covered by PayrollRunCheckServiceTest::testTheFourSourcesBecomeFindingsAndCounts and PayrollRunServiceTest::testACalculationRunsTheCheckWithTheSkippedList

#### Scenario: A mandatory rule violation is blocking
- **GIVEN** a payslip in the run that violates a mandatory payroll rule
- **WHEN** the check runs
- **THEN** a blocking finding names the payslip, the rule id and the rule's statement

@e2e exclude the rule audit is server-side; covered by PayrollRunCheckServiceTest::testTheFourSourcesBecomeFindingsAndCounts

### Requirement: The check SHALL compare each payslip with the employee's own history (REQ-PRK-003)

For each payslip in the run the check SHALL compare gross, net, wage tax, employer cost and
hours paid with the median of the employee's last six paid payslips, and SHALL flag a
deviation larger than the component's configured relative threshold and absolute floor. When
the payslip carries a known cause the baseline lacks (sick pay, a retro correction, leave
bought or sold, a wage garnishment, a bijtelling, or a raise applied in the period), the
finding SHALL name the cause and SHALL be informational. An employee with fewer than three
prior payslips SHALL get an informational finding that the history is too short.

Rows: `dm-pay-anomaly` (humaniq matrix), changelog
https://data.maglr.com/1697/issues/68666/820322/index.html.

#### Scenario: An unexplained doubling is flagged
- **GIVEN** an employee whose last six net pays were all 2750.00 and whose new payslip shows
  5500.00 with no retro, leave, sick pay, garnishment, bijtelling or raise
- **WHEN** the check runs with the default thresholds
- **THEN** a warning shows component net, current 5500.00, baseline 2750.00, and no
  explanation

@e2e exclude pure calculation; covered by PayAnomalyDetectorTest::testATwiceTheUsualNetIsAWarning and PayrollRunCheckServiceTest::testADeviationFromPaidHistoryIsFound

#### Scenario: A retro correction explains the jump
- **GIVEN** the same employee with a retro correction of 2750.00 on the new payslip
- **WHEN** the check runs
- **THEN** the finding is informational and names the retro correction as the cause

@e2e exclude pure calculation; covered by PayAnomalyDetectorTest::testAKnownCauseExplainsTheDeviation

#### Scenario: A new employee has no baseline
- **GIVEN** an employee with two earlier payslips
- **WHEN** the check runs
- **THEN** the only finding for that employee says the history is too short

@e2e exclude pure calculation; covered by PayAnomalyDetectorTest::testTwoPriorPayslipsAreNotEnoughHistory
