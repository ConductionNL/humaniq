## 1. Portal contribution

- [ ] 1.1 Add `candidate`, `new-hire` and `former-employee` to `getAudiences()` and return
      `null` for anything else. Verify: `PortalContributionProviderTest` asserts the six
      audiences and a `null` manifest for `visitor`.
- [ ] 1.2 Add the candidate manifest: the anonymous `openVacancies` collection filtered on
      `status` `gepubliceerd` and the anonymous `applyToVacancy` create action with the D2
      whitelist. Verify: unit test asserts both entries carry `anonymous: true` and that
      `status`, `retentionExpiryDate` and `administrationId` are absent from the whitelist.
- [ ] 1.3 Add the new-hire manifest: `myEmployeeRecord`, `myOnboarding`, and the
      `updateMyDetails` action at `minTrust: substantial`. Verify: unit test asserts the
      whitelist is exactly `iban`, `tenaamstelling`, `bsn` and no entry is anonymous.
- [ ] 1.4 Add the read-only former-employee manifest. Verify: unit test asserts three
      collections scoped on `employeeId` and an empty `actions` list.

## 2. Schemas

- [ ] 2.1 Add `Vacancy.questions` (0.3.0) and `job-application.answers` (0.4.0) and rewrite
      the two descriptions named in D6. Verify: `occ maintenance:repair` imports the register
      and `npm run check:schema-l10n` exits 0.
- [ ] 2.2 Seed the question list and one portal-style application. Verify:
      `npm run check:seed-refs` exits 0.

## 3. Pages

- [ ] 3.1 Register a host section that hands `Vacancy.questions` to `CnFormBuilder` and
      writes it back. Verify: `npm run lint` exits 0.
- [ ] 3.2 Add the questions section to `VacancyDetail` and show `answers` on
      `ApplicationDetail`. Verify: `npm run check:manifest` exits 0.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the changed provider methods (gate 16).
- [ ] 4.2 One live check with portaliq installed: open the careers page signed out, apply
      with an answer, and find the application at `nieuw` with the answer on
      `ApplicationDetail`; record the screenshot in the PR.
