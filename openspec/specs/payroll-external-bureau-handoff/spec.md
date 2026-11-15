# payroll-external-bureau-handoff Specification

## Purpose
An administration whose payroll an outside bureau runs gets one handoff per period: humaniq compiles the period's payroll mutations as differences from what earlier handoffs sent (employee facts and period items), a second person sets it ready, integriq delivers it and writes the bureau's payslips back, and humaniq checks those payslips for completeness before the period can be closed. Built by payroll-external-bureau-handoff (archived 2026-10-01).

## Requirements

### Requirement: An administration SHALL be markable as processed by an outside bureau (REQ-PXB-001)

An administration SHALL carry whether its payroll is processed by humaniq's engine or by an
outside bureau. The engine SHALL refuse to calculate a run for an administration processed by
an outside bureau and SHALL say so.

Rows: `pay-outsourced` (humaniq matrix).

#### Scenario: The engine stays out of an outsourced administration
- **GIVEN** administration ADM-006 set to external bureau
- **WHEN** a payroll officer tries to calculate a 2026-05 run for ADM-006
- **THEN** the run is refused with the reason that a bureau processes this administration

@e2e exclude the refusal is server-side in the payroll engine; covered by PayrollRunServiceTest::testTheEngineStaysOutOfAnOutsourcedAdministration

### Requirement: humaniq SHALL compile each period's payroll mutations as differences from the previous handoff (REQ-PXB-002)

For an externally processed administration and a period, humaniq SHALL compile one handoff
holding one mutation per change to an employee's payroll facts since the previous handoff:
starters and leavers, contract, salary, approved hours, payroll-route claims, allowances, leave
transactions, sickness, garnishments, bank account and tax settings, each with its effective
date, old and new values, and the object it came from. A change undone before compiling SHALL
NOT be sent. The first handoff SHALL send every employee as a starter.

Rows: `pay-outsourced` (humaniq matrix).

#### Scenario: A raise becomes one mutation
- **GIVEN** a previous handoff that sent an employee's salary as 3800.00 and an applied raise to
  3876.00 since then
- **WHEN** an HR adviser compiles the handoff for the next period
- **THEN** the handoff holds one salary mutation for that employee from 3800.00 to 3876.00,
  linked to the raise

@e2e exclude the comparison is server-side; covered by SalaryBureauExchangeServiceTest::testARaiseBecomesOneMutation, the period items by PayrollHandoffPeriodItemsTest::testThePeriodItemsTravelAsTheirOwnMutations and PayrollHandoffPeriodItemsTest::testASentItemIsNotSentAgainButItsChangeIs

#### Scenario: A reverted edit is not sent
- **GIVEN** an HR adviser who changed an employee's bank account and changed it back before
  compiling
- **WHEN** the handoff is compiled
- **THEN** it holds no bank-account mutation for that employee

@e2e exclude the comparison is server-side; covered by SalaryBureauExchangeServiceTest::testARevertedEditIsNotSentAndARecompileReplaces

### Requirement: A handoff SHALL be reviewed by a second person and delivered through integriq (REQ-PXB-003)

A compiled handoff SHALL be set ready only by someone other than the person who compiled it.
integriq SHALL deliver ready handoffs to the bureau and SHALL record them as sent with a
delivery reference; humaniq SHALL hold no bureau credentials. A handoff the bureau rejects
SHALL be reopenable to concept.

Rows: `pay-outsourced` (humaniq matrix).

#### Scenario: Four eyes before anything leaves
- **GIVEN** a handoff compiled by an HR adviser
- **WHEN** the same HR adviser tries to set it ready
- **THEN** the transition is refused

@e2e exclude a second Nextcloud account is needed to show the allowed path; the refusal is the lifecycle guard, covered by HandoffGuardsTest::testFourEyesBeforeAnythingLeaves and LifecycleGuardRegistrationTest

#### Scenario: integriq marks the handoff sent
- **GIVEN** a handoff set ready by a second HR adviser and an integriq synchronisation for the
  bureau
- **WHEN** integriq delivers it
- **THEN** the handoff shows status `verzonden` with the delivery reference

@e2e exclude the delivery is integriq's synchronisation (cross-app, drafted for Ruben in for-ruben/integriq-humaniq-payroll-handoff-sync.md); humaniq declares the verzenden transition and the deliveryReference field in hr-handoff.json

### Requirement: The bureau's results SHALL be recorded as payslips and checked for completeness (REQ-PXB-004)

Payslips returned by the bureau SHALL be stored as payslips linked to the handoff, marked as
externally calculated and stamped with the employee's account, so they appear in the employee's
self-service payslips and on the annual statement. humaniq SHALL check that every employee in
the handoff's period has exactly one returned payslip and that no returned payslip names an
employee outside the administration, and SHALL refuse to close the handoff while such a finding
is open.

Rows: `pay-outsourced` (humaniq matrix).

#### Scenario: An employee sees the bureau's payslip
- **GIVEN** a returned payslip for an employee with a Nextcloud account
- **WHEN** the employee opens "Mijn loonstroken"
- **THEN** the payslip is listed with its gross, net and period

@e2e exclude needs a payslip written back by integriq on a live instance; the account stamp that puts it on "Mijn loonstroken" is covered by SalaryBureauExchangeServiceTest::testTheIntakeListsMissingAndUnknownEmployees

#### Scenario: A missing payslip blocks closing
- **GIVEN** a handoff for three employees and returned payslips for two
- **WHEN** an HR adviser runs the intake check and then tries to close the handoff
- **THEN** the check lists the third employee as missing and closing is refused

@e2e exclude needs returned payslips on a live instance; covered by SalaryBureauExchangeServiceTest::testTheIntakeListsMissingAndUnknownEmployees, HandoffIntakeListenerTest::testReceivingRunsTheIntakeCheck and HandoffGuardsTest::testAMissingPayslipBlocksClosing
