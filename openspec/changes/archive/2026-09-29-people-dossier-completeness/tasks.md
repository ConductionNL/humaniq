## 1. Schemas

- [x] 1.1 Add the `hr-dossier.json` fragment with `DossierRequirement`, `PersonnelDocument`
      and `RightToWorkCheck`, and list them in `humaniq_register.json`. Verify:
      `occ maintenance:repair` imports the register and `npm run check:schema-l10n` exits 0.
- [x] 1.2 Add the expiry notification rule on `PersonnelDocument` in the canonical dialect.
      Verify: `hydra-gate-notification-dialect` passes.

## 2. Completeness

- [x] 2.1 Add `DossierCompletenessService` with the five statuses. Verify: unit test per
      status, including a VOG issued 200 days before the start date (`te-oud-bij-start`) and
      a BIG met by a current competence.
- [x] 2.2 Add `DossierController` status and incomplete routes, read through
      `RbacObjectReader`. Verify: controller test that an unreadable employee answers 404;
      `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` pass.
- [x] 2.3 Add the status section to `EmployeeDetail`, the `Onvolledige dossiers` page and
      the requirement and document index pages. Verify: `npm run check:manifest` exits 0.

## 3. Right to work

- [x] 3.1 Add `RightToWorkService` with the D4 rule, the EEA table and the ICAO check
      digits. Verify: unit test for a Dutch passport, an expired passport, a residence
      document with and without work endorsement, a current TWV and a wrong check digit.
- [x] 3.2 Add the right-to-work route, duck-typed filinq reading, and the stamp of
      `widCheckDone`. Verify: controller test for absent filinq (manual path) and a pass.
- [x] 3.3 Add `RightToWorkGuard` on `gereed_melden` and `starten`. Verify: unit test that a
      missing or failing check refuses and a passing check dated before `startDate` allows.
- [x] 3.4 Seed requirements, documents and the two checks. Verify:
      `npm run check:seed-refs` exits 0.

## 4. Verification

- [x] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [ ] 4.2 One live check: try to mark the seeded failing onboarding ready and read the
      refusal, then open `Onvolledige dossiers`; record the screenshot in the PR.

3.2 was built as a save listener on `RightToWorkCheck` rather than a POST route, and filinq has no identity reader to call (design.md, build-time changes). 4.2 is not ticked: the live check runs in the live-check pass after this lane, with the recipe in the PR body.
