## 1. Transition

- [x] 1.1 `reopen` (approved to submitted) on the Expense lifecycle, requiring `HrReopenGuard`. Verify: ExpenseReopenLifecycleTest.
- [x] 1.2 `HrReopenGuard` judges the stored claim: HR only, not the claimant, not the approver, not with a payroll run, refused when unreadable. Verify: HrReopenGuardTest.
- [x] 1.3 `ExpensePayoutFieldsListener` refuses an approved-to-submitted write the guard should have refused. Verify: ExpensePayoutFieldsListenerTest::testAReopenThatTheGuardShouldHaveRefusedIsStopped, ExpensePreSaveChainTest::testAForgedReopenOfAClaimInARunIsRefused.

## 2. The reopen write

- [x] 2.1 `ExpensePayoutFieldsListener` keeps the amounts and clears the approval and the route on the reopen write. Verify: ExpensePayoutFieldsListenerTest, ExpensePreSaveChainTest::testHrReopensAnApprovedClaim.
- [x] 2.2 `ExpenseCategoryGuardListener` does not refuse a reopen. Verify: ExpenseCategoryGuardListenerTest.
- [x] 2.3 The reopen is recorded by OpenRegister's audit trail; no app-local audit listener (gate 23, ADR-022).

## 3. Verification

- [x] 3.1 Failing-first tests, capped mutants with a no-op control, the full suite once under pcov.
- [x] 3.2 `openspec validate hr-reopens-an-approved-claim --strict`.
