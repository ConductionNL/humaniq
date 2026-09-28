## 1. Release and mark

- [x] 1.1 Add `RetentionExpiryService::releaseLapsedFloorHold()`: release a lapsed
      humaniq floor hold and mark the record. Verify: red tests that a lapsed humaniq hold is
      released and marked, a future one is kept, and a hold with another reason is kept.
- [x] 1.2 Add `RetentionExpiryService` walking the payroll family and ended employees.
      Verify: unit tests with the real OpenRegister `RetentionService` hold methods.

## 2. Job and switch

- [x] 2.1 Add `RetentionExpiryJob` (daily) and register it in `appinfo/info.xml`. Verify: a
      test that the job changes nothing while `retention_expiry_enabled` is off.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario, `@spec` tags. The live run on a
      payslip with a lapsed hold is owed to the live-check pass (recipe in the PR body).
