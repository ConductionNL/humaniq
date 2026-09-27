## 1. Announcements

- [ ] 1.1 Add `hr-announcements.json` with `Announcement` (lifecycle) and
      `AnnouncementConfirmation` (one per employee). Verify: import succeeds; unit test that a
      second confirmation is refused.
- [ ] 1.2 Add `GET /api/announcements/mine` with the audience match. Verify: controller tests for
      an employee in and out of the audience units.
- [ ] 1.3 Add the HR pages with a confirmation overview, `MijnMededelingen` and the `MijnHr`
      widget. Verify: `npm run check:manifest` exits 0.

## 2. Daily message

- [ ] 2.1 Add `Employee.shareBirthday`. Verify: import succeeds; default false.
- [ ] 2.2 Add the `humaniq.team-digest` node. Verify: unit tests that the message holds names and
      return dates, never a leave type, and only consenting birthdays without a year.
- [ ] 2.3 Declare the "Dagbericht" flow, disabled. Verify: it shows on `Flows` as disabled.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16), and
      a live run of the enabled flow posting to a test Talk conversation.
