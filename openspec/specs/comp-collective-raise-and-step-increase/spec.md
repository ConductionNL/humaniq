---
capability: comp-collective-raise-and-step-increase
status: done
built_by: openspec/changes/archive/2026-09-28-comp-collective-raise-and-step-increase
---

# comp-collective-raise-and-step-increase Specification

**Status**: done
**Scope**: humaniq

**OpenSpec changes**: [comp-collective-raise-and-step-increase](../../changes/archive/2026-09-28-comp-collective-raise-and-step-increase/) _(archived 2026-09-28)_

## Purpose

Raise many salaries in one compensation cycle: a collective raise for everyone in scope or a
hand-picked selection, the yearly step in the employer's own pay scale proposed when it falls
due, approval that stays four eyes per adjustment, effectuation from the cycle page after a
preview, and a refusal that carries a reason the employee receives.

## Requirements

### Requirement: A collective cycle SHALL propose one adjustment per employee in scope (REQ-CRS-001)

A `CompReviewCycle` of kind `collective` SHALL carry a raise (a percentage or an amount in
cents) and a scope. The propose action SHALL create one `proposed` `CompAdjustment` per
employee in scope whose contract covers the cycle's effective date, with `currentSalary` taken
from the employee's gross monthly salary and `proposedSalary` computed from the raise and
rounded to the cent. A second run SHALL create no duplicate for the same cycle and employee. A
dry run SHALL report the count and write nothing.

Rows: `ppl-bulk-mutations` (humaniq matrix).

#### Scenario: A payroll officer raises a whole administration by 2 percent
@e2e exclude the proposal is computed and written server side; covered by CompCollectiveServiceTest::testATwoPercentRaiseProposesOnePerEmployeeInScope and ::testADryRunCountsAndWritesNothing, which validate every payload against the CompAdjustment schema
- **GIVEN** an open collective cycle with a 2% raise, scope administration ADM-001 and three
  employees in it earning 3800.00, 2912.00 and 2600.00
- **WHEN** the payroll officer runs "Propose for everyone in scope" on the cycle page and
  confirms after the preview shows 3 employees
- **THEN** three proposed adjustments exist with proposed salaries 3876.00, 2970.24 and
  2652.00, each linked to the cycle

#### Scenario: Running the proposal twice changes nothing
@e2e exclude covered by CompCollectiveServiceTest::testRunningTheProposalTwiceCreatesNothing
- **GIVEN** the same cycle after the first run
- **WHEN** the payroll officer runs the propose action again
- **THEN** the response reports 0 created and 3 already present

### Requirement: Bulk approval SHALL keep separation of duties per adjustment (REQ-CRS-002)

The approve-all action SHALL approve each proposed adjustment in the cycle through its own
guarded transition. An adjustment the caller proposed SHALL NOT be approved and SHALL be
reported back as refused for self-approval.

Rows: `ppl-bulk-mutations` (humaniq matrix).

#### Scenario: The proposer cannot approve their own batch
@e2e exclude needs a second Nextcloud account on the test instance; covered by CompCollectiveServiceTest::testTheProposerCannotApproveTheirOwnBatch and DecisionReasonGuardTest::testTheProposerCannotApprove
- **GIVEN** a collective cycle whose proposals were all created by an HR adviser
- **WHEN** the same HR adviser runs "Approve all proposed"
- **THEN** no adjustment changes status and every row is reported as `refused-self-approval`

#### Scenario: A second person approves the batch
@e2e exclude needs a second Nextcloud account on the test instance; covered by CompCollectiveServiceTest::testASecondPersonApprovesEachThroughItsOwnTransition
- **GIVEN** the same cycle
- **WHEN** a payroll officer who proposed none of them runs "Approve all proposed"
- **THEN** every adjustment is `approved` with that officer as `approvedBy`

### Requirement: A cycle SHALL be effectuated from its page after a preview (REQ-CRS-003)

The cycle page SHALL offer to effectuate every approved, due adjustment of the cycle in one
action, SHALL first show a dry-run preview of the salaries it will write, and SHALL then write
through the existing effectuation, which also sets the contract's hourly wage when the
adjustment carries one.

Rows: `ppl-bulk-mutations` (humaniq matrix).

#### Scenario: Effectuating a collective raise
@e2e exclude the salary write is server side; covered by CompAdjustmentServiceTest::testApprovedDueWithinBandWritesSalaryAndBecomesEffective, ::testAnHourlyRaiseIsWrittenOntoTheContract and CompControllerCycleTest::testAnAdminApprovesAndPreviewsTheEffectuation
- **GIVEN** three approved adjustments with an effective date of today
- **WHEN** the payroll officer runs "Effectuate due adjustments" and confirms the preview
- **THEN** the three employees' gross monthly salaries carry the proposed figures and the
  adjustments are `effective`

#### Scenario: An adjustment that is not due stays untouched
@e2e exclude covered by CompAdjustmentServiceTest::testNotYetDueAdjustmentRefusedWritesNothing and CompControllerCycleTest::testAnAdminApprovesAndPreviewsTheEffectuation, whose preview counts it as refused-not-due
- **GIVEN** one approved adjustment with an effective date next month
- **WHEN** the cycle is effectuated today
- **THEN** that adjustment stays `approved` and the preview lists it as not due

### Requirement: A step-increase cycle SHALL propose the next step when it falls due (REQ-CRS-004)

A salary band SHALL be able to carry steps with a monthly salary per step, and a contract
SHALL be able to carry its band, current step and step date. A cycle of kind `step-increase`
SHALL propose the next step, at that step's salary, for every contract whose step date falls
in the cycle period and whose step is below the band's highest step.

Rows: `td-step-increase` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: A due step is proposed
@e2e exclude covered by CompCollectiveServiceTest::testADueStepIsProposedAndTheTopAndNotDueAreNot and CompAdjustmentServiceTest::testAStepEffectuationMovesTheStepAndTheStepDate
- **GIVEN** an employee on step 2 of band A, step date in March 2026, and a 2026
  step-increase cycle
- **WHEN** an HR adviser runs "Propose due steps" on the cycle
- **THEN** one proposal from step 2 to step 3 exists at step 3's salary

#### Scenario: An employee at the top of the band gets no proposal
@e2e exclude covered by CompCollectiveServiceTest::testADueStepIsProposedAndTheTopAndNotDueAreNot
- **GIVEN** an employee on the highest step of band A with a step date in 2026
- **WHEN** the same action runs
- **THEN** no proposal is created for that employee

### Requirement: A refusal SHALL carry a reason and the employee SHALL receive the decision (REQ-CRS-005)

A proposed adjustment SHALL be refusable only with a non-empty decision reason and never by
its own proposer. When an adjustment is approved or refused, the employee SHALL receive a
Nextcloud notification that states the decision and, when given, the reason.

Rows: `td-step-increase` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: A manager refuses a step without a reason
@e2e exclude the refusal is decided by the lifecycle guard; covered by DecisionReasonGuardTest::testARefusalWithoutAReasonIsDenied, and the transition's required decisionReason input by CompDecisionNotificationTest
- **GIVEN** a proposed step increase
- **WHEN** a manager chooses "Refuse" on `CompAdjustmentDetail` with an empty reason
- **THEN** the transition is refused and the proposal stays `proposed`

#### Scenario: The employee reads why their step was refused
@e2e exclude needs a second Nextcloud account and the notifications app on the test instance; covered by DecisionReasonGuardTest::testARefusalWithAReasonByAnotherPersonIsAllowed and CompDecisionNotificationTest::testApprovalAndRefusalReachTheEmployeeWithTheReason
- **GIVEN** a proposed step increase for an employee with a Nextcloud account
- **WHEN** a manager refuses it with the reason "Beoordeling onvoldoende, zie gesprek 12 mei"
- **THEN** the proposal is `refused` and the employee receives a notification carrying that
  reason
