## 1. Listener

- [x] 1.1 `ExpenseManagerStampListener` keeps the stored `employeeId` whenever it keeps the stored identity. Verify: ExpenseManagerStampListenerTest::testAnApprovedClaimKeepsItsEmployee, testAnOpenClaimMayChangeItsEmployee.

## 2. Verification

- [x] 2.1 Failing-first tests, capped mutants with a no-op control, pcov without risky tests.
- [x] 2.2 `openspec validate approved-claim-keeps-its-employee --strict`.
