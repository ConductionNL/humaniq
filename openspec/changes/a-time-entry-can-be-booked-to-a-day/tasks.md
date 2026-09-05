# Tasks

## 1. The schema

- [x] 1.1 `TimeEntry` gains `date` (format `date`), in both descriptors.
- [x] 1.2 `startedAt`/`endedAt` are no longer required, in both descriptors.
- [x] 1.3 `date` is NOT required either: the either/or invariant is not
      expressible in `required`, and making it required would have needed a
      backfill for every existing row.

## 2. The listener

- [x] 2.1 `deriveHours()` branches on whether clock times are present.
- [x] 2.2 The clocked path is byte-for-byte what it was, refusals included.
- [x] 2.3 The day path refuses a missing/invalid date, a missing or
      non-numeric hours, a non-positive hours, and more than 24 hours.
- [x] 2.4 `date` is stamped on every write from the reference timestamp, so a
      pre-existing clocked entry gains one without a migration.

## 3. Verification

- [x] 3.1 A day booking is accepted, totalled, and filed on the same month grain.
- [x] 3.2 A clocked booking still derives 8.00 from 09:00-17:30 minus 30, and
      now also carries `2026-05-04`.
- [x] 3.3 An entry with neither shape is refused.
- [x] 3.4 Each impossible day booking is refused FOR THE RIGHT REASON, asserted
      on the message rather than only on the refusal.
- [x] 3.5 Verified by disabling the branch: exactly the three day-booking tests
      go red and every clocked test stays green.
- [x] 3.6 1,393 tests green.

## 4. Not in this change

- [ ] 4.1 pipelinq's migration onto this schema, plus a `billingTimeEntry`
      satellite for its 11 billing/WIP fields.
- [ ] 4.2 planninq's migration, plus a satellite for `contractorRef` and
      `hourlyRate`, and `task` mapped onto `domainObjectType`/`domainObjectRef`.
- [ ] 4.3 A `user` (Nextcloud uid) to `employeeId` (Employee uuid) resolution
      both consumers need, and the degradation when humaniq is not installed.
