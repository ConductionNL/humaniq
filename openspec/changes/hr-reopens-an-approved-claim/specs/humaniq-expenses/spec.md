# humaniq-expenses

## ADDED Requirements

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
