## 1. Activities

- [ ] 1.1 Add `TimeActivity` and `TimeEntry.activityCode` to `hr-timesheet.json`; seed six
      activities. Verify: `occ maintenance:repair` imports; `npm run check:seed-refs` exits 0.
- [ ] 1.2 Refuse absence-kind bookings in `TimeEntryStampListener`. Verify: unit test for a
      refused `verlof` booking and an accepted `opleiding` booking.
- [ ] 1.3 Add `activityCode` to the `MijnUren` form allowlist. Verify: `npm run check:manifest`
      exits 0.

## 2. Week and totals

- [ ] 2.1 Add `TimesheetWeekService` with leave and sickness lines. Verify: unit tests for a leave
      day, a half-day sickness and a week without absence.
- [ ] 2.2 Add `projectHours`, `nonProjectHours`, `absenceHours` to `Timesheet` and to
      `computeAggregates()`. Verify: unit test that the three add up with the booked hours.
- [ ] 2.3 Show the week view and totals on `TimesheetDetail`. Verify: `npm run lint` exits 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16), and
      a live week with training and a leave day.
