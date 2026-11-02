## 1. Transition

- [x] 1.1 `reopen` (approved to submitted) on the Expense lifecycle, requiring `HrReopenGuard`. Verify: ExpenseReopenLifecycleTest.
- [x] 1.2 `HrReopenGuard` allows HR only, not the claimant, not the approver, and not with a payroll run. Verify: HrReopenGuardTest.

## 2. The reopen write

- [x] 2.1 `ExpensePayoutFieldsListener` keeps the amounts and clears the approval and the route on the reopen write. Verify: ExpensePayoutFieldsListenerTest, ExpensePreSaveChainTest::testHrReopensAnApprovedClaim.
- [x] 2.2 `ExpenseCategoryGuardListener` does not refuse a reopen. Verify: ExpenseCategoryGuardListenerTest.
- [x] 2.3 The reopen is recorded by OpenRegister's audit trail; no app-local audit listener (gate 23, ADR-022).

## 3. Verification

- [x] 3.1 Failing-first tests, capped mutants with a no-op control, the full suite once under pcov.
- [x] 3.2 `openspec validate hr-reopens-an-approved-claim --strict`.
