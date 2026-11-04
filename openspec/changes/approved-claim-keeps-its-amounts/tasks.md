## 1. Payout fields

- [x] 1.1 `ExpensePayoutFieldsListener` keeps the paid amounts and the decision of a decided claim, the submitted amounts on the approve write, and the run fields on every non-internal write. Verify: ExpensePayoutFieldsListenerTest.
- [x] 1.3 A create starts as draft or submitted, without decision or run fields; a decided claim keeps its route. Verify: ExpensePayoutFieldsListenerTest, ExpensePreSaveChainTest::testAClaimCannotBeCreatedAsApproved, testAnApprovedClaimKeepsItsRoute.
- [x] 1.2 It is subscribed for both pre-save events, after the other Expense listeners. Verify: TravelListenerWiringTest.

- [x] 1.4 No shipped claim is payable: the seed's paid example is reimbursed directly; an update during a shipped import keeps the payout fields. Verify: ShippedClaimsAreNotPayableTest, ExpensePayoutFieldsListenerTest::testAnUpdateDuringAShippedImportKeepsWhatIsPaid.

## 2. Category setting

- [x] 2.1 A save without a list of names is refused; at least one category stays on. Verify: ExpenseCategoryControllerTest, ExpenseCategoryPolicyTest.
- [x] 2.2 `isAllowed` accepts only the seven known names, without case or spaces, that are on. Verify: ExpenseCategoryPolicyTest, ExpenseCategoryGuardListenerTest.

## 3. Verification

- [x] 3.1 Failing-first tests, capped mutants with a no-op control, pcov without risky tests.
- [x] 3.2 `openspec validate approved-claim-keeps-its-amounts --strict`.
