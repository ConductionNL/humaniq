## 1. Announcements

- [x] 1.1 Add `hr-announcements.json` with `Announcement` (lifecycle) and
      `AnnouncementConfirmation` (one per employee). Verify: import succeeds; unit test that a
      second confirmation is refused.
- [x] 1.2 Add `GET /api/announcements/mine` with the audience match. Verify: controller tests for
      an employee in and out of the audience units.
- [x] 1.3 Add the HR pages with a confirmation overview, `MijnMededelingen` and the `MijnHr`
      widget. Verify: `npm run check:manifest` exits 0.

## 2. Daily message

- [x] 2.1 Add `Employee.shareBirthday`. Verify: import succeeds; default false.
- [x] 2.2 Add the `humaniq.team-digest` node. Verify: unit tests that the message holds names and
      return dates, never a leave type, and only consenting birthdays without a year.
- [x] 2.3 Declare the "Dagbericht" flow, disabled. Verify: it shows on `Flows` as disabled.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16), and
      a live run of the enabled flow posting to a test Talk conversation.

Built 2026-09-30 (lanes 14 and 15). 3.1: @e2e excludes and @spec tags done; the live run of the enabled flow against a Talk conversation not done here (no instance in the lane); it is the live check in the PR body.
