# payroll-expenses-and-allowances Specification

## Purpose
Approved expense claims and recurring allowances are paid through the payslip with their tax treatment: claims untaxed on top of net pay, allowances split into a taxed part in the gross and an untaxed part on top of net, the WKR ledger written by the run, and reimbursements booked in the payroll journal. Built by payroll-expenses-and-allowances (archived 2026-09-30).

## Requirements

### Requirement: An approved payroll-route claim SHALL be paid once on the payslip (REQ-PEA-001)

An approved claim with the payroll route SHALL be added to the employee's net pay in the first
draft run whose period ends on or after its approval, SHALL be listed on the payslip, and SHALL
be marked reimbursed with the paying run when that run is approved. It SHALL NOT be taxed or
insured, and SHALL NOT be paid by a second run. A claim with a taxable part SHALL be refused
for the payroll route.

Rows: `exp-reimburse-in-pay` (humaniq matrix).

#### Scenario: A train ticket on the payslip
- **GIVEN** an approved 27.40 train claim with the payroll route, approved on 12 May 2026
- **WHEN** a payroll officer calculates the 2026-05 run
- **THEN** the employee's payslip shows a reimbursement of 27.40 and a net pay 27.40 higher,
  and the claim names that run

@e2e exclude the run is server-side; covered by PayrollRunServiceTest::testClaimsAndAllowancesArePaidThroughThePayslip and PayrollExpenseFoldServiceTest::testAnApprovedPayrollClaimIsFoldedIntoNet

#### Scenario: The next run does not pay it again
- **GIVEN** the 2026-05 run approved with that claim
- **WHEN** the 2026-06 run is calculated
- **THEN** the claim is not on the 2026-06 payslip and its status is `reimbursed`

@e2e exclude covered by PayrollExpenseFoldServiceTest::testOnlyTheRightClaimsAreSelected, PayrollExpenseFoldServiceTest::testApprovingTheRunMarksItsClaimsReimbursedOnce and PayrollExpenseFoldServiceTest::testTheApprovalListenerMarksTheRunsClaims

#### Scenario: A claim above the tax-free rate keeps the direct route
- **GIVEN** an approved mileage claim with a taxable part of 10.50
- **WHEN** an HR adviser sets its route to payroll
- **THEN** the change is refused with a message that the taxable part cannot yet be paid
  through payroll

@e2e exclude the refusal is a server-side listener; covered by ExpensePayrollListenersTest::testATaxableClaimIsRefusedThePayrollRoute

### Requirement: A recurring allowance SHALL pay itself every period with its tax treatment (REQ-PEA-002)

An active recurring allowance SHALL be paid on every payslip of a period it covers. A taxed
allowance SHALL be added to the gross before the calculation. An untaxed allowance under a
targeted exemption SHALL be added to net up to its numeric norm where one exists, with any
excess added to the gross. An allowance charged to the WKR free margin SHALL be added to net.
An allowance whose norm is unverified SHALL NOT be paid, and the payslip SHALL say so.

Rows: `exp-fixed-allowances` (humaniq matrix).

#### Scenario: A home-working allowance within the norm
- **GIVEN** an active home-working allowance of 8 days a month at the day norm, untaxed under
  a targeted exemption
- **WHEN** the payroll officer calculates the month's run
- **THEN** the payslip shows the allowance as untaxed and the net pay rises by exactly that
  amount

@e2e exclude the run is server-side; covered by AllowanceSplitterTest::testAHomeWorkingAllowanceAtTheNormIsUntaxed and PayrollRunServiceTest::testClaimsAndAllowancesArePaidThroughThePayslip

#### Scenario: A taxed telephone allowance
- **GIVEN** an active telephone allowance of 20.00 a month declared as taxed
- **WHEN** the run is calculated
- **THEN** the payslip's gross is 20.00 higher and the wage tax is computed over it

@e2e exclude the run is server-side; covered by AllowanceSplitterTest::testTaxedFreeMarginAndDeclaredTreatments and PayrollRunServiceTest::testClaimsAndAllowancesArePaidThroughThePayslip

#### Scenario: An allowance cannot be activated by the person who drafted it
- **GIVEN** a draft allowance created by an HR adviser
- **WHEN** the same HR adviser tries to activate it
- **THEN** the transition is refused

@e2e exclude the guard is server-side; covered by PayrollExpensesDeclarationTest::testActivationIsRefusedToTheDrafter and ExpensePayrollListenersTest::testANewAllowanceIsStampedWithItsDrafterAndEmployee

### Requirement: Allowance payments SHALL write the WKR ledger themselves (REQ-PEA-003)

For every untaxed allowance payment the run SHALL write one WKR declaration row for the
allowance and period, with the allowance's tax treatment as its category, and SHALL rewrite
the same row on recalculation.

Rows: `exp-fixed-allowances` (humaniq matrix).

#### Scenario: The WKR assessment sees the allowance
- **GIVEN** a free-margin allowance of 50.00 paid in 2026-05
- **WHEN** an HR adviser opens the WKR declarations for 2026
- **THEN** one row of 50.00 with category `vrije-ruimte` references that allowance and period,
  and recalculating the draft run leaves exactly one such row

@e2e exclude covered by PayrollExpenseFoldServiceTest::testAllowancesAreSplitListedAndProduceWkrRows and PayrollExpenseFoldServiceTest::testWkrRowsAreUpsertedOnTheirSourceReference

### Requirement: Reimbursements SHALL reach the ledger in the payroll journal (REQ-PEA-004)

A payroll run SHALL carry the total of reimbursements and untaxed allowances it paid, and the
payroll journal SHALL carry that total as a debit line on the configured reimbursement account,
so the journal balances and the reimbursements post to the ledger with the run.

Rows: `plt-accounting-link` (humaniq matrix).

#### Scenario: A run with reimbursements posts balanced
- **GIVEN** an approved run with 27.40 of reimbursements
- **WHEN** the journal is posted to shillinq
- **THEN** the journal carries a 27.40 debit line on the reimbursement account and debits
  equal credits

@e2e exclude the journal is built server-side; covered by PayrollRunServiceTest::testReimbursementsReachTheJournalBalanced

#### Scenario: A run without reimbursements is unchanged
- **GIVEN** an approved run without claims or untaxed allowances
- **WHEN** the journal is built
- **THEN** it carries the same four lines as before this change

@e2e exclude covered by PayrollRunServiceTest::testReimbursementsReachTheJournalBalanced and PayrollRunServiceTest::testWithoutClaimsOrAllowancesThePayslipIsIdentical
