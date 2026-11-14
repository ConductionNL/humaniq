## 1. Setting

- [x] 1.1 `ExpenseCategoryPolicy` lists the categories, says whether one is on, and saves the switches (unknown categories refused). Verify: ExpenseCategoryPolicyTest.
- [x] 1.2 `ExpenseCategoryController` reads the switches for every signed-in user and saves them for admins. Verify: ExpenseCategoryControllerTest.
- [x] 1.3 The admin settings page shows one switch per category and saves it. Verify: AdminSettings test.

## 2. Refusal

- [x] 2.1 `ExpenseCategoryGuardListener` refuses a create, a category change and a submit in a switched-off category; other writes and humaniq's own writes pass. Verify: ExpenseCategoryGuardListenerTest.
- [x] 2.2 It is subscribed for both pre-save events. Verify: TravelListenerWiringTest.

## 3. Verification

- [x] 3.1 Failing-first tests, capped mutants with a no-op control, pcov without risky tests.
- [x] 3.2 `openspec validate allowed-expense-categories --strict`, `@e2e exclude` per scenario, `@spec` tags.
