## 1. Calculation

- [ ] 1.1 Extend `LeaveHoursCalculator::requestHours()` with patterns, non-working times,
      calendar dates and the `basis`. Verify: unit test for a Monday to Wednesday pattern
      (Monday and Tuesday cost 16, Thursday and Friday cost 0), a week with a calendar
      feestdag, an unread calendar (`pattern-only`), and no pattern (`contract-average`).
- [ ] 1.2 Load patterns, non-working times and the calendar once in
      `LeaveBalanceProjectionService`. Verify: unit test with a stubbed reader that the
      calendar is asked once per projection and an unread calendar is logged once.
- [ ] 1.3 Remove the "public holidays are NOT subtracted" docblock claim once it is false.
      Verify: `grep -n "NOT subtracted" lib/Service/LeaveHoursCalculator.php` finds nothing.

## 2. Cost on the request

- [ ] 2.1 Add `GET /api/leave/requests/{id}/cost`. Verify: controller test that an
      unreadable request answers 404; `hydra-gate-route-auth` and
      `hydra-gate-no-admin-idor` pass.
- [ ] 2.2 Add the cost section to `LeaveRequestDetail`. Verify: `npm run check:manifest` and
      `npm run lint` exit 0.
- [ ] 2.3 Seed the part-timer's requests. Verify: `npm run check:seed-refs` exits 0.

## 3. Verification

- [ ] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the changed methods (gate 16).
- [ ] 3.2 One live check: open the seeded part-timer's request and read 16 hours with the
      day breakdown; record the screenshot in the PR.
