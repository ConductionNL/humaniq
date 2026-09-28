## 1. Release and mark

- [ ] 1.1 Add `PayrollRetentionGuardService::releaseLapsedFloorHold()`: release a lapsed
      humaniq floor hold and mark the record. Verify: red tests that a lapsed humaniq hold is
      released and marked, a future one is kept, and a hold with another reason is kept.
- [ ] 1.2 Add `RetentionExpiryService` walking the payroll family and ended employees.
      Verify: unit tests with the real OpenRegister `RetentionService` hold methods.

## 2. Job and switch

- [ ] 2.1 Add `RetentionExpiryJob` (daily) and register it in `appinfo/info.xml`. Verify: a
      test that the job changes nothing while `retention_expiry_enabled` is off.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario, `@spec` tags, and one live run
      on a payslip with a lapsed hold that lands on a destruction list.
