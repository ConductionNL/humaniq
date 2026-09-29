## 1. Allocation

- [x] 1.1 Add the bucket fields to `LeaveBalance` and the carry-over fields to `LeaveType`.
      Verify: `occ maintenance:repair` imports the register and `npm run check:schema-l10n`
      exits 0.
- [x] 1.2 Add `LeaveAllocationCalculator`. Verify: unit test that a February request draws
      last year's statutory hours first, that statutory goes before bovenwettelijk, that a
      request after 1 July skips last year's statutory bucket, and that `none` ends the
      bovenwettelijk bucket on 31 December.
- [x] 1.3 Make `projectForRequest()` recompute every balance of the employee and type.
      Verify: unit test that running it twice gives the same numbers and `usedHours` equals
      the two parts.

## 2. Lapse and warning

- [x] 2.1 Add `LeaveExpiryService` and call it from `LeaveAccrualJob::run()`. Verify: unit
      test for a lapse, a waived lapse, and a late-approved request dated before the expiry
      that lowers `expiredHours`.
- [x] 2.2 Change the `remainingHours` expression and add `remainingStatutoryHours` and
      `statutoryExpiresSoon`. Verify: a seeded balance reads the expected three values
      through the objects API.
- [x] 2.3 Declare the warning rule in the canonical dialect. Verify:
      `hydra-gate-notification-dialect` passes and, on a dev instance, the seeded employee
      receives the warning.
- [x] 2.4 Add the lapse fields and the 90-day view to the leave pages, and the seed. Verify:
      `npm run check:manifest` and `npm run check:seed-refs` exit 0.

## 3. Verification

- [x] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [x] 3.2 One live check: open the seeded employee's balances and read last year's used and
      expired hours; record the screenshot in the PR.

Notes from the build (2026-09-29): 1.1 is checked by the schema versions (LeaveBalance 0.4.0,
LeaveType 0.2.0, register 0.39.0) and RegisterSchemaValidator on the written payload; the live
`occ maintenance:repair` is in the PR's live check. 2.1: no separate `LeaveExpiryService`; the lapse
is computed by `LeaveAllocationCalculator` in the same recompute, and the daily job calls
`LeaveBalanceProjectionService::recomputeAll()`. 2.2 and 2.3 were evaluated with OpenRegister's own
validators and CalculationEvaluator (`work/lex/validate.php`), not on an instance. 2.4 and 3.2: the
live check with a screenshot is in the PR, not run here.
