## 1. Evaluations

- [ ] 1.1 Add `Vacancy.evaluationCriteria` and the `CandidateEvaluation` schema with the
      aggregation and the cascade. Verify: `occ maintenance:repair` imports the register and
      `npm run check:schema-l10n` exits 0.
- [ ] 1.2 Add the evaluator stamp listener. Verify: unit test that a create stamps the
      session user and that a client-supplied `evaluatorUserId` is replaced.
- [ ] 1.3 Show evaluations and averages on `ApplicationDetail`. Verify:
      `npm run check:manifest` exits 0.

## 2. Matching

- [ ] 2.1 Add `Vacancy.requirements` and `job-application.profile`. Verify: import as 1.1.
- [ ] 2.2 Add `VacancyMatchService` with the D2 points. Verify: unit test with a full match
      (100), a missing required competence, an expired employee competence (not counted)
      and a rejected applicant without talent-pool consent (excluded).
- [ ] 2.3 Add `GET /api/vacancies/{id}/matches`, resolve first, admin or HR. Verify:
      controller test; `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` pass.
- [ ] 2.4 Add `Read CV` duck-typed to filinq, filling only an empty profile. Verify: unit
      test for absent filinq and for a filled profile left alone.
- [ ] 2.5 Show the ranked matches with their breakdown on `VacancyDetail`. Verify:
      `npm run check:manifest` exits 0.

## 3. Referrals

- [ ] 3.1 Add `ReferralService` and the two routes. Verify: unit test that a missing consent
      and a closed vacancy are refused and that `mine` returns only name, title and status.
- [ ] 3.2 Add `MijnVacatures` and `MijnAanbevelingen` under Mijn HR. Verify:
      `npm run check:manifest` and `npm run lint` exit 0.
- [ ] 3.3 Seed criteria, requirements, evaluations, a profile, a competence and a referral.
      Verify: `npm run check:seed-refs` exits 0.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [ ] 4.2 One live check: refer a candidate as an employee, score them as two
      interviewers, and read the averages and the match list; record the screenshot in the PR.
