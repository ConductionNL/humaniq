# humaniq-expenses

## ADDED Requirements

### Requirement: An approved expense claim SHALL keep the amounts it was approved for

Payroll pays an approved claim from its `amount`, `taxableAmount`, `approvedAt` and
`payrollRunId`. On any write of a claim whose stored status is `approved` or `reimbursed`, the
server SHALL save the stored `amount`, `taxFreeAmount`, `taxableAmount`, `currency`,
`approvedBy` and `approvedAt`, whatever the write sends. On the approve write (stored status
`submitted`, saved status `approved`), the server SHALL save the stored `amount`,
`taxFreeAmount`, `taxableAmount` and `currency`. The stored status decides this, never the
status the write sends.

`payrollRunId` and `paidInPeriod` SHALL change only through humaniq's own writes. Any other
write SHALL keep their stored values, and a claim created by any other write SHALL start
without them.

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
