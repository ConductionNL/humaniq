## 1. Retention periods

- [ ] 1.1 Add the `archive` configuration to the six schemas. Verify: register fragments
      validate with the real validator; a saved seed payslip carries `archiefactiedatum`.

## 2. Hold release

- [ ] 2.1 Add `PayrollRetentionGuardService::releaseLapsedFloorHolds()`. Verify: red tests
      that a lapsed humaniq hold is released, a future one is kept, and a hold with another
      reason is kept.
- [ ] 2.2 Add `RetentionHoldReleaseJob` and the admin switch, off by default. Verify: a test
      that the job releases nothing while the switch is off.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario, `@spec` tags, and one live run
      on a seed payslip dated eight years back that lands on a destruction list.
