## 1. Schemas

- [x] 1.1 Add the `hr-survey.json` fragment with `Survey`, `SurveyInvitation` and
      `SurveyResponse`, and list them in `humaniq_register.json`. Verify:
      `occ maintenance:repair` imports them and `npm run check:schema-l10n` exits 0.
- [x] 1.2 Add the invitation and reminder rules in the canonical dialect. Verify:
      `hydra-gate-notification-dialect` passes.

## 2. Service and endpoints

- [x] 2.1 Add `SurveyService::open()` creating invitations for the scope. Verify: unit test
      that an out-of-scope employee and an employee without an account get no invitation and
      the count of the latter is returned.
- [x] 2.2 Add `SurveyService::respond()`. Verify: unit test that the stored response holds no
      employee, account, invitation id or time, that a second answer is refused, and that a
      caller without an invitation is refused.
- [x] 2.3 Add `SurveyService::results()` with the folding rule and eNPS. Verify: unit test
      with units of seven, five and two responses at minimum five.
- [x] 2.4 Add `SurveyController` and the three routes. Verify: controller test that results
      and open require admin or HR; `hydra-gate-route-auth` and `hydra-gate-no-admin-idor`
      pass.

## 3. Pages and seed

- [x] 3.1 Add `Enquetes`, `SurveyDetail` with `CnFormBuilder` and the results charts, and
      `MijnEnquetes` under Mijn HR. Verify: `npm run check:manifest` and `npm run lint`
      exit 0.
- [x] 3.2 Seed the two surveys. Verify: `npm run check:seed-refs` exits 0.

## 4. Verification

- [x] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [x] 4.2 One live check: answer the open seeded survey as an employee and read the results
      page as HR with the small unit folded; record the screenshot in the PR.
      Done in the live pass (2026-11-17, clean install): admin (employee Jansen) answered the
      pulse survey on its invitation page (201, invitation `beantwoord`); hr-sanne read
      "Medewerkersonderzoek najaar 2026": Overall 14, Backoffice 5, Consultancy 7, the unit
      with two responses folded into Overall only.
- [ ] 4.3 Decide how a response stops pointing at its writer. Found live: HR reading
      `SurveyResponse` through the objects API sees `@self.owner` = the respondent's account,
      and the response's `@self.created` equals the invitation's answered time to the second,
      so REQ-SRV-002 ("no response names an employee or account") does not hold. Owner can be
      set to the system; the shared timestamp needs a design choice (for example storing
      responses in batches).
