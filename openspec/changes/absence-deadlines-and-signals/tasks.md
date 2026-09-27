## 1. Reminders

- [ ] 1.1 Add the four days-left calculations to `SickLeaveCase`. Verify:
      `occ maintenance:repair` imports the register and a seeded case reads the expected
      days left through the objects API.
- [ ] 1.2 Add `CaseManagerResolver` and the four reminder rules in the canonical dialect.
      Verify: unit test for the resolver; `hydra-gate-notification-dialect` passes; on a dev
      instance the seeded case's reminder arrives for the HR group.
- [ ] 1.3 Show the days left on `SickLeaveCaseDetail`. Verify: `npm run check:manifest`
      exits 0.

## 2. The 42-week notification

- [ ] 2.1 Add `uwv-melding-42-weken` and `sickLeaveCaseId` to `HrGeneratedDocument`.
      Verify: import as 1.1 and `npm run check:schema-l10n` exits 0.
- [ ] 2.2 Add the data assembly to `HrDocumentService`. Verify: unit test that the payload
      holds the D3 fields and no field outside them, and that a `hersteld` case is refused.
- [ ] 2.3 Add the guarded route and the action on `SickLeaveCaseDetail`. Verify: controller
      test for 404 on an unreadable case and 403 for a non-HR caller;
      `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` pass.

## 3. Frequent absence

- [ ] 3.1 Add the threshold fields to `hrAdministration` and the signal fields to
      `SickLeaveCase`. Verify: import as 1.1.
- [ ] 3.2 Add `FrequentAbsenceService` and `FrequentAbsenceListener`. Verify: unit test that
      a reopened case counts once, the third case in twelve months sets the signal, and the
      listener's own write does not trigger it again.
- [ ] 3.3 Add the notification rule and the count on `EmployeeDetail`, and the seed. Verify:
      `npm run check:manifest` and `npm run check:seed-refs` exit 0.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new classes (gate 16).
- [ ] 4.2 One live check: generate the 42-week notification for the seeded case and open the
      PDF; record the screenshot in the PR.
