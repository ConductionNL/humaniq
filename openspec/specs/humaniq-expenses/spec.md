# humaniq-expenses Specification

## Purpose
TBD - created by archiving change humaniq-expenses. Update Purpose after archive.

## Requirements

### Requirement: Expense claim with declarative reimbursement lifecycle

The system SHALL model an employee expense-reimbursement claim as an `Expense` object in the
OpenRegister `hrmq` register, whose workflow is governed by a declarative
`x-openregister-lifecycle` state machine on its `status` field (initial `draft`, terminal
`reimbursed`), with transitions `submit` (draft/rejected → submitted), `approve` (submitted →
approved), `reject` (submitted → rejected) and `reimburse` (approved → reimbursed). The object
SHALL carry at least `employeeId`, `title`, `amount`, `currency`, `category`, `expenseDate`,
`status`, `submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason` and `reimbursedAt`. The
lifecycle MUST be expressed declaratively in the register fragment — not in bespoke PHP transition
code or hand-written Vue views. The pipelinq-specific shillinq accounts-payable sync (fields,
service, listener, controller) MUST NOT be ported.

**Feature tier**: MVP

#### Scenario: Employee submits a draft expense claim

- GIVEN an `Expense` in state `draft`
- WHEN the employee applies the `submit` transition
- THEN the claim MUST move to state `submitted`
- AND `submittedAt` MUST record the submission timestamp

#### Scenario: An approved claim is reimbursed

- GIVEN an `Expense` in state `approved`
- WHEN the `reimburse` transition is applied
- THEN the claim MUST move to the terminal state `reimbursed`
- AND `reimbursedAt` MUST record the payout timestamp

#### Scenario: Reimburse is only reachable from approved

- GIVEN an `Expense` in state `submitted`
- WHEN the `reimburse` transition is attempted (which requires state `approved`)
- THEN the lifecycle MUST refuse the transition and leave the state unchanged

### Requirement: Separation of duties on expense approval

The system SHALL prevent an employee from approving or rejecting their own expense claim. The
`approve` and `reject` transitions SHALL each declare `requires:
OCA\Humaniq\Lifecycle\NoSelfApprovalGuard` — the same OpenRegister `LifecycleGuardInterface` the
timesheet feature uses — which denies the transition when the acting user equals the claim's
`employeeId` and fails closed when the actor or claimant is unknown.

**Feature tier**: MVP

#### Scenario: An employee cannot approve their own expense claim

- GIVEN an `Expense` in state `submitted` whose `employeeId` is employee A
- WHEN employee A applies the `approve` (or `reject`) transition
- THEN the guard MUST deny the transition with a separation-of-duties message
- AND the claim MUST remain in state `submitted`

#### Scenario: A manager approves another employee's expense claim

- GIVEN an `Expense` in state `submitted` whose `employeeId` is employee A
- WHEN a different user (manager B) applies the `approve` transition
- THEN the guard MUST allow the transition
- AND the claim MUST move to state `approved` with `approvedBy` = B and `approvedAt` set

### Requirement: Declarative expense pages

The system SHALL surface expense claims through declarative manifest pages rendered generically by
the `@conduction/nextcloud-vue` library — NOT bespoke Vue components (unlike pipelinq's
hand-written `ExpenseList.vue` / `ExpenseDetail.vue`). There SHALL be an `Expenses` `type:"index"`
list page, an `ExpenseApproval` `type:"index"` page whose default filter is `status == submitted`
(the pending-approval queue), and an `ExpenseDetail` `type:"detail"` page, each configured only
with `{ register: "hrmq", schema: "Expense", … }`, reached from an "Onkosten" menu group.

**Feature tier**: MVP

#### Scenario: The approval queue lists pending claims

- GIVEN submitted, approved and reimbursed claims exist in the `hrmq` register
- WHEN a manager opens the "Declaratiegoedkeuring" (ExpenseApproval) page
- THEN the page MUST default its filter to `status == submitted`
- AND MUST render the list generically from the `Expense` schema without a bespoke component

### Requirement: The employer SHALL choose which expense categories employees may claim

An admin SHALL switch each expense category on or off on the humaniq admin settings page. The
categories are travel, accommodation, meals, supplies, software, training and other. Every
category SHALL be on until an admin switches it off. The setting SHALL be stored as the list of
categories that are off (app config `expense_categories_disabled`), so a category added later
is on by default.

The server SHALL refuse an expense claim in a category that is off, with a message that names
the problem. It SHALL refuse when the claim is created, when its category is changed to one
that is off, and when it is submitted. It SHALL NOT refuse the approve, reject or reimburse of
a claim that was already submitted, or any other edit of an existing claim that keeps its
category. humaniq's own writes SHALL NOT be refused.

A category SHALL count as allowed only when it is one of the seven names, compared without case
or surrounding spaces, and is on. A claim without a category is not refused by this setting.

`GET /api/expense-categories` SHALL list every category and whether it is on, for any signed-in
user. `PUT /api/expense-categories` SHALL save the list of categories that are on. Only an admin
may save. A save without the list, with something that is not a list of names, with an unknown
category, or with no category on SHALL be refused with a 400 response and SHALL change nothing.

#### Scenario: Every category is on by default

@e2e exclude Settings read with no fixture of its own; covered by ExpenseCategoryPolicyTest::testEveryCategoryIsOnByDefault
- **GIVEN** a fresh install
- **WHEN** anyone reads the expense categories
- **THEN** all seven categories are on

#### Scenario: A claim in a switched-off category is refused

@e2e exclude Pre-save refusal with no screen of its own; covered by ExpenseCategoryGuardListenerTest::testACreateInASwitchedOffCategoryIsRefused
- **GIVEN** the admin switched off software
- **WHEN** an employee creates a software claim
- **THEN** the claim is not saved, and the employee reads that this category cannot be claimed

#### Scenario: A submit in a switched-off category is refused, a decision is not

@e2e exclude Pre-save refusal; covered by ExpenseCategoryGuardListenerTest::testASubmitIsRefusedAndADecisionIsNot
- **GIVEN** a draft meals claim and a submitted meals claim
- **AND** the admin then switches off meals
- **WHEN** the employee submits the draft, and the manager approves the submitted claim
- **THEN** the submit is refused and the approval is saved

#### Scenario: Only an admin saves the switches

@e2e exclude Endpoint authorization; covered by ExpenseCategoryControllerTest
- **WHEN** an admin saves meals and training as off
- **THEN** reading the categories shows meals and training off and the rest on
- **AND** saving an unknown category is refused with a 400 response

#### Scenario: A save that would switch everything off is refused

@e2e exclude Endpoint validation; covered by ExpenseCategoryControllerTest::testASaveWithoutAListIsRefused and ExpenseCategoryPolicyTest::testAtLeastOneCategoryStaysOn
- **WHEN** an admin saves without the list of categories, or with an empty list
- **THEN** the save is refused with a 400 response and every category keeps its switch

### Requirement: An approved expense claim SHALL keep the amounts it was approved for

Payroll pays an approved claim from its `amount`, `taxableAmount`, `approvedAt` and
`payrollRunId`. On any write of a claim whose stored status is `approved` or `reimbursed`, the
server SHALL save the stored `amount`, `taxFreeAmount`, `taxableAmount`, `currency`,
`approvedBy` and `approvedAt`, whatever the write sends. On the approve write (stored status
`submitted`, saved status `approved`), the server SHALL save the stored `amount`,
`taxFreeAmount`, `taxableAmount` and `currency`. The stored status decides this, never the
status the write sends.

`payrollRunId` and `paidInPeriod` SHALL change only through humaniq's own writes. Any other
write SHALL keep their stored values.

On any write of a claim whose stored status is `approved` or `reimbursed`, the server SHALL also
save the stored `reimbursementRoute`. Only humaniq's own writes MAY change it. This fails
closed: a route that was wrong is not corrected by an edit.

A claim created by any write other than humaniq's own SHALL start with status `draft` or
`submitted`; a create with any other status SHALL be refused with a message. Such a create
SHALL save `approvedBy`, `approvedAt`, `payrollRunId` and `paidInPeriod` empty, whatever it
sends. humaniq's own register and demo imports are humaniq's own writes for this rule,
so the example claims it ships in a decided status still import. No expense claim humaniq ships, in
the assembled register (`humaniq_register.json` and every `register.d` fragment) or the mock
register, SHALL be payable by a payroll run in any period: a
shipped claim that shows a paid example is `reimbursed`. An update during a shipped import
SHALL keep the stored payout fields as any other update does.

#### Scenario: An edit of an approved claim cannot change what is paid

@e2e exclude Pre-save behaviour with no screen of its own; covered by ExpensePayoutFieldsListenerTest::testADecidedClaimKeepsWhatIsPaid
- **GIVEN** Tim's approved claim of 34.50 EUR, approved on 1 October
- **WHEN** an edit that keeps status approved sends 3,450 EUR and approval date 30 November
- **THEN** the claim still says 34.50 EUR, approved on 1 October

#### Scenario: The approver approves what was submitted

@e2e exclude Pre-save behaviour; covered by ExpensePayoutFieldsListenerTest::testTheApproveWriteKeepsTheSubmittedAmounts
- **GIVEN** Tim's submitted claim of 34.50 EUR
- **WHEN** the approve write sends 50 EUR
- **THEN** the approved claim says 34.50 EUR

#### Scenario: Only the payroll run sets the run fields

@e2e exclude Pre-save behaviour; covered by ExpensePayoutFieldsListenerTest::testOnlyHumaniqSetsTheRunFields
- **GIVEN** an approved claim held by no payroll run
- **WHEN** an edit sends a `payrollRunId` and a `paidInPeriod`
- **THEN** the claim is still held by no run
- **AND** when the payroll run itself writes them, they are saved

#### Scenario: A claim cannot be created as approved

@e2e exclude Pre-save behaviour with no screen of its own; covered by ExpensePreSaveChainTest::testAClaimCannotBeCreatedAsApproved
- **WHEN** a create sends status approved, an approval date, an approver, the payroll route and
  9,999 EUR
- **THEN** the claim is not saved, the writer reads that a new claim starts as a draft or as
  submitted, and payroll pays nothing

#### Scenario: An approved claim keeps its route

@e2e exclude Pre-save behaviour; covered by ExpensePreSaveChainTest::testAnApprovedClaimKeepsItsRoute
- **GIVEN** Tim's approved claim on the direct route
- **WHEN** an edit sends the payroll route
- **THEN** the claim stays on the direct route, and payroll does not pay it

#### Scenario: No shipped claim is paid

@e2e exclude Shipped data checked against the payroll fold; covered by ShippedClaimsAreNotPayableTest::testNoShippedClaimIsPayable
- **GIVEN** every Expense in the register as RegisterConfigReader assembles it, and in
  `humaniq_mock_register.json`
- **WHEN** payroll folds each one for every month from 2024 to 2030
- **THEN** none of them is paid

### Requirement: An approved expense claim SHALL keep its employee

Payroll pays an approved claim to the employee its `employeeId` names. On the approve write
(stored status `submitted`, saved status `approved`), the server SHALL save the stored
`employeeId`, whatever the write sends. On any write of a claim whose stored status is
`approved` or `reimbursed`, the server SHALL do the same. The stored status decides this,
never the status the write sends. A claim that is still open may change its employee, and
its `userId`, `managerUserId` and `administrationId` then follow the new employee.

#### Scenario: The approve write cannot move a claim to another employee

@e2e exclude Stamping is a backend write-path behaviour with no screen of its own; covered by ExpenseManagerStampListenerTest::testAnApprovedClaimKeepsItsEmployee
- **GIVEN** Tim's submitted claim
- **WHEN** the approve write sends `employeeId` of Pieter
- **THEN** the approved claim still names Tim's employee

#### Scenario: A decided claim keeps its employee on a later write

@e2e exclude Stamping is a backend write-path behaviour; covered by ExpenseManagerStampListenerTest::testAnApprovedClaimKeepsItsEmployee
- **GIVEN** Tim's approved claim, or his reimbursed claim
- **WHEN** a later write sends `employeeId` of Pieter
- **THEN** the claim still names Tim's employee and Tim's account

#### Scenario: An open claim may change its employee

@e2e exclude Stamping is a backend write-path behaviour; covered by ExpenseManagerStampListenerTest::testAnOpenClaimMayChangeItsEmployee
- **GIVEN** a draft claim of Tim
- **WHEN** it is saved with Sanne's employee
- **THEN** the claim carries Sanne's account and her manager Pieter

### Requirement: HR SHALL be able to reopen an approved expense claim that no payroll run holds

The Expense lifecycle SHALL have a `reopen` transition from `approved` to `submitted`,
guarded by `HrReopenGuard`. OpenRegister hands the guard the incoming payload, so the guard
SHALL read the claim as stored, by its id, and judge that, never the payload's values. It
SHALL allow the reopen only when the acting account is a member of the HR group, is not the
stored claimant's account (`userId`), is not the stored approver (`approvedBy`), the stored
claim is `approved`, and it has no `payrollRunId`. It SHALL refuse it otherwise, when the
acting account is unknown, and when the stored claim cannot be read.

As a second check on the same rule, the server SHALL refuse any write that moves a claim from
`approved` to `submitted` when the stored claim has a `payrollRunId`, or the acting account is
unknown, is the stored claimant or is the stored approver.

The reopen write SHALL keep the stored `amount`, `taxFreeAmount`, `taxableAmount` and
`currency`, and the claim's employee and identity. It SHALL clear `approvedBy`, `approvedAt`
and `reimbursementRoute`, and stamp a new `submittedAt`, so the claim goes through approval
again and its route is chosen again. A switched-off category SHALL NOT refuse a reopen.

Each reopen SHALL be recorded in OpenRegister's audit trail with the account that made it and
the values before and after, including the approver it clears; humaniq SHALL NOT keep an audit
log of its own for it.

#### Scenario: HR reopens an approved claim

@e2e exclude The reopen runs through the lifecycle and pre-save listeners; covered by ExpensePreSaveChainTest::testHrReopensAnApprovedClaim and HrReopenGuardTest::testHrMayReopen
- **GIVEN** Tim's approved claim of 34.50 EUR on the payroll route, approved by Sanne
- **WHEN** Ilse from HR reopens it
- **THEN** the claim is submitted again with 34.50 EUR, without an approver, an approval date
  or a route
- **AND** OpenRegister's audit trail records that Ilse changed the claim, and that Sanne was
  the approver before

#### Scenario: Only HR, not the claimant or the approver, may reopen

@e2e exclude Lifecycle guard; covered by HrReopenGuardTest
- **WHEN** Tim, Sanne, or an employee outside HR tries to reopen the claim
- **THEN** the transition is refused
- **AND** when Sanne is also in HR, she still may not reopen a claim she approved

#### Scenario: A forged payload does not reopen a claim

@e2e exclude Lifecycle guard and pre-save refusal; covered by HrReopenGuardTest::testAForgedPayloadIsJudgedByTheStoredClaim and ExpensePreSaveChainTest::testAForgedReopenOfAClaimInARunIsRefused
- **GIVEN** an approved claim of Anna, who is in HR, held by payroll run `run-1`
- **WHEN** Anna reopens it and the write sends another claimant, no approver and no run
- **THEN** the guard refuses it, and the save is refused as well

#### Scenario: A claim held by a payroll run cannot be reopened

@e2e exclude Lifecycle guard; covered by HrReopenGuardTest::testAClaimInAPayrollRunCannotBeReopened
- **GIVEN** an approved claim that payroll run `run-1` holds
- **WHEN** HR tries to reopen it
- **THEN** the transition is refused

### Requirement: The shipped example claim SHALL be settled once on tenants that imported it as payable

A repair step SHALL run on upgrade and install, after the register import. It SHALL change
the Expense whose slug is `expense-devries-train` only when all of these hold:

- the claim still holds exactly what the old seed shipped: title, description, amount
  27.40, currency EUR, category travel, expense date 2026-05-20, status approved, submitted,
  approved by and approved at as shipped, and the payroll route;
- its employee is the Employee whose slug is `employee-devries`;
- it has no `payrollRunId`.

It SHALL then save the claim with status `reimbursed`, route `direct` and `reimbursedAt`
2026-05-26T10:00:00Z, as humaniq's own write, and log the claim's id. It SHALL NOT change any
other field or any other record. A run that finds nothing to change SHALL change nothing.

#### Scenario: The unchanged example claim is settled

@e2e exclude A repair step with no screen of its own; covered by SettleShippedExampleClaimTest::testTheUnchangedExampleClaimIsSettled
- **GIVEN** a tenant that imported the old seed and has not changed the example claim
- **WHEN** the repair step runs
- **THEN** the claim is reimbursed on the direct route, and payroll does not pay it
- **AND** a second run changes nothing

#### Scenario: A changed or paid example claim is left alone

@e2e exclude A repair step with no screen of its own; covered by SettleShippedExampleClaimTest::testAChangedOrPaidClaimIsLeftAlone
- **GIVEN** the example claim with another amount, another employee, or a payroll run
- **WHEN** the repair step runs
- **THEN** nothing is written
