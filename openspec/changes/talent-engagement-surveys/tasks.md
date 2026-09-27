## 1. Schemas

- [ ] 1.1 Add the `hr-survey.json` fragment with `Survey`, `SurveyInvitation` and
      `SurveyResponse`, and list them in `humaniq_register.json`. Verify:
      `occ maintenance:repair` imports them and `npm run check:schema-l10n` exits 0.
- [ ] 1.2 Add the invitation and reminder rules in the canonical dialect. Verify:
      `hydra-gate-notification-dialect` passes.

## 2. Service and endpoints

- [ ] 2.1 Add `SurveyService::open()` creating invitations for the scope. Verify: unit test
      that an out-of-scope employee and an employee without an account get no invitation and
      the count of the latter is returned.
- [ ] 2.2 Add `SurveyService::respond()`. Verify: unit test that the stored response holds no
      employee, account, invitation id or time, that a second answer is refused, and that a
      caller without an invitation is refused.
- [ ] 2.3 Add `SurveyService::results()` with the folding rule and eNPS. Verify: unit test
      with units of seven, five and two responses at minimum five.
- [ ] 2.4 Add `SurveyController` and the three routes. Verify: controller test that results
      and open require admin or HR; `hydra-gate-route-auth` and `hydra-gate-no-admin-idor`
      pass.

## 3. Pages and seed

- [ ] 3.1 Add `Enquetes`, `SurveyDetail` with `CnFormBuilder` and the results charts, and
      `MijnEnquetes` under Mijn HR. Verify: `npm run check:manifest` and `npm run lint`
      exit 0.
- [ ] 3.2 Seed the two surveys. Verify: `npm run check:seed-refs` exits 0.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [ ] 4.2 One live check: answer the open seeded survey as an employee and read the results
      page as HR with the small unit folded; record the screenshot in the PR.
