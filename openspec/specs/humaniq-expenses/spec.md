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

