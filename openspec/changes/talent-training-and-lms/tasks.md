## 1. Training record

- [ ] 1.1 Add the `hr-training.json` fragment with `TrainingRecord` and its lifecycle, and
      list it in `humaniq_register.json`. Verify: `occ maintenance:repair` imports it and
      `npm run check:schema-l10n` exits 0.
- [ ] 1.2 Add `TrainingCompetenceWriter`. Verify: unit test that a `gevolgd` record with a
      code creates a competence, a later validity extends it, and an earlier one leaves it.
- [ ] 1.3 Add `Trainingen`, `TrainingRecordDetail`, the list on `EmployeeDetail` and the menu
      entry. Verify: `npm run check:manifest` exits 0.

## 2. People feed

- [ ] 2.1 Add `LearningPeopleFeed` and `GET /api/learning/people`. Verify: unit test that the
      payload holds exactly the D3 fields, a leaver after `endDate` is excluded, and
      `modifiedSince` returns an employee whose placement changed.
- [ ] 2.2 Guard the route through `RbacObjectReader`. Verify: controller test that a caller
      who may not read an employee does not receive them; `hydra-gate-route-auth` passes.

## 3. Credentials from learniq

- [ ] 3.1 Add `LearniqCredentialListener`. Verify: unit test with a stubbed event that a
      credential creates one record, a repeat creates none, and an unknown account is
      skipped and logged.
- [ ] 3.2 Seed the training records. Verify: `npm run check:seed-refs` exits 0.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new classes (gate 16).
- [ ] 4.2 One live check with learniq installed: complete a course as an employee and read
      the training record and competence on `EmployeeDetail`; record the screenshot in the PR.
