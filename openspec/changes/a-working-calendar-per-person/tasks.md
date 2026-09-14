## 1. The working pattern

- [ ] 1.1 Add the `WorkingPattern` schema in `lib/Settings/register.d/`: employee,
      hours per weekday, `validFrom`, optional `validUntil`.
- [ ] 1.2 Refuse two patterns for one employee whose periods overlap.
- [ ] 1.3 Manifest page for patterns, under the existing employee surface.

## 2. Non-working time

- [ ] 2.1 Add the `NonWorkingTime` schema: employee, period or recurring weekday,
      reason from an administered list.
- [ ] 2.2 Confirm it draws down no leave balance and opens no sick-leave case.
- [ ] 2.3 Manifest page for non-working times.

## 3. The resolution query

- [ ] 3.1 Add `contractedHoursOn(employee, date)` and its range form, applying
      pattern, then non-working time, then openregister's working calendar.
- [ ] 3.2 Point `absence-rate` at the same query, so one definition of a working day
      serves every figure.
- [ ] 3.3 Expose the query for `agenda-rostering-and-resource-booking` to consume.

## 4. Reading openregister's calendar

- [ ] 4.1 Resolve the working calendar duck-typed, container-resolved, behind a
      `class_exists()` guard.
- [ ] 4.2 Mark a pattern-only answer and record a degradation when the calendar is
      unreachable.
- [ ] 4.3 Confirm no humaniq schema holds a national holiday, a freeze period or a
      week-numbering setting.

## 5. Verification

- [ ] 5.1 Unit tests for the dated resolution, the overlap refusal and the
      pattern-only degradation.
- [ ] 5.2 e2e coverage or a reason-bearing exclusion per scenario, per gate 19.
- [ ] 5.3 `npm run check:manifest` exits 0.
