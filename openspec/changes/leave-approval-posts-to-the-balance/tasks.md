## 1. Projection service

- [x] 1.1 Add `LeaveBalanceProjectionService` with a static, object-store-free arithmetic core (`workingDaysBetween`, `requestHours`, `usedHoursFor`).
- [x] 1.2 Recompute rather than increment, and skip the write when the value is unchanged.
- [x] 1.3 Resolve the balance by employee, year and leave type, and never create one.
- [x] 1.4 Name underivable requests in a warning instead of silently counting them as zero.
- [x] 1.5 Reject a date that is not a literal `YYYY-MM-DD`, so an empty range cannot parse as today.

## 2. Listener

- [x] 2.1 Add `LeaveApprovalListener` as a thin adapter over `ObjectCreatedEvent` and `ObjectUpdatedEvent`.
- [x] 2.2 Filter to the `leaverequest` slug at registration time and again in the handler.
- [x] 2.3 Swallow and log every failure so the save path is never broken.
- [x] 2.4 Register both events in `Application::boot()`.

## 3. Schema and spec text

- [x] 3.1 Drop the "not yet auto-posted" caveat from the `LeaveBalance` description and say what maintains `usedHours`.
- [x] 3.2 Record the public-holiday overstatement in the `usedHours` notes.
- [x] 3.3 Bump `LeaveBalance` to 0.3.1.
- [x] 3.4 Correct `leave-accrual-job/spec.md`, which said the buy/sell path mutates `usedHours` when it writes only `bovenwettelijkHours`.

## 4. Tests

- [x] 4.1 Cover projection, non-approved exclusion, idempotent no-write, scoping, and a missing balance.
- [x] 4.2 Cover derivation: full week, weekend, part-time, explicit hours, no contract snapshot, New Year split, edge cases.
- [ ] 4.3 Assert the projected balance through the LeaveBalances page in the leave e2e spec.

## 5. Verification

- [ ] 5.1 `composer check:strict` green.
- [ ] 5.2 Hydra gates green.
- [ ] 5.3 Playwright e2e green.
