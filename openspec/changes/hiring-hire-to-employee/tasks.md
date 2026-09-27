## 1. Schemas

- [ ] 1.1 Add `job-application.employeeId`, `Employee.privateEmail` and `Employee.phone`,
      rewrite the `aannemen` description, and add `EmployeeDocumentExtraction`. Verify:
      `occ maintenance:repair` imports the register and `npm run check:schema-l10n` exits 0.

## 2. Hire

- [ ] 2.1 Add `HireMatchService` with the three ordered keys. Verify: unit test with a BSN
      match, a name and birth date match, an e-mail match and two same-name people with
      different birth dates (no match).
- [ ] 2.2 Add `HireService::hire()` creating the employee, the onboarding case and the link,
      idempotent on `employeeId`. Verify: unit test that a second call creates nothing and
      that the application's status is unchanged in the save payload.
- [ ] 2.3 Add attach-to-existing for a former and an active employee. Verify: unit test
      that `endDate` clears only for a former employee and old contracts are untouched.
- [ ] 2.4 Add `HireController` hire-matches and hire routes, `#[NoAdminRequired]`, resolve
      first, 409 on unresolved matches. Verify: controller test; `hydra-gate-route-auth` and
      `hydra-gate-no-admin-idor` pass.
- [ ] 2.5 Add `HireApplicationDialog` and the `Create employee` action on
      `ApplicationDetail`. Verify: `npm run lint` and `npm run check:manifest` exit 0.

## 3. Document reading

- [ ] 3.1 Add `EmployeeDocumentExtractionService` with the filinq probe and the empty-field
      rule. Verify: unit test that a filled field is not overwritten, that absent filinq
      records `skipped-no-docudesk`, and that a thrown filinq error records `failed`.
- [ ] 3.2 Add the read-document and contract-from-document routes and actions on
      `OnboardingDetail`. Verify: controller test that one extraction creates at most one
      contract.
- [ ] 3.3 Seed the application link, the look-alike former employee and one extraction.
      Verify: `npm run check:seed-refs` exits 0.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [ ] 4.2 One live check: hire the seeded `aanbod` application, see the look-alike match,
      attach, and open the employee with its onboarding case; record the screenshot in the PR.
