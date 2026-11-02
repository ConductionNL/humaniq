# humaniq-expenses

## ADDED Requirements

### Requirement: HR SHALL be able to reopen an approved expense claim that no payroll run holds

The Expense lifecycle SHALL have a `reopen` transition from `approved` to `submitted`,
guarded by `HrReopenGuard`. The guard SHALL allow it only when the acting account is a member
of the HR group, is not the claimant's account (`userId`), is not the account that approved
the claim (`approvedBy`), and the claim has no `payrollRunId`. It SHALL refuse it otherwise,
and when the acting account is unknown.

The reopen write SHALL keep the stored `amount`, `taxFreeAmount`, `taxableAmount` and
`currency`, and the claim's employee and identity. It SHALL clear `approvedBy`, `approvedAt`
and `reimbursementRoute`, and stamp a new `submittedAt`, so the claim goes through approval
again and its route is chosen again. A switched-off category SHALL NOT refuse a reopen.

Each reopen SHALL be logged with the claim's id, the account that reopened it and the account
that had approved it.

#### Scenario: HR reopens an approved claim

@e2e exclude The reopen runs through the lifecycle and pre-save listeners; covered by ExpensePreSaveChainTest::testHrReopensAnApprovedClaim and HrReopenGuardTest::testHrMayReopen
- **GIVEN** Tim's approved claim of 34.50 EUR on the payroll route, approved by Sanne
- **WHEN** Ilse from HR reopens it
- **THEN** the claim is submitted again with 34.50 EUR, without an approver, an approval date
  or a route
- **AND** the reopen is logged with the claim, Ilse and Sanne

#### Scenario: Only HR, not the claimant or the approver, may reopen

@e2e exclude Lifecycle guard; covered by HrReopenGuardTest
- **WHEN** Tim, Sanne, or an employee outside HR tries to reopen the claim
- **THEN** the transition is refused
- **AND** when Sanne is also in HR, she still may not reopen a claim she approved

#### Scenario: A claim held by a payroll run cannot be reopened

@e2e exclude Lifecycle guard; covered by HrReopenGuardTest::testAClaimInAPayrollRunCannotBeReopened
- **GIVEN** an approved claim that payroll run `run-1` holds
- **WHEN** HR tries to reopen it
- **THEN** the transition is refused
