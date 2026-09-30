## 1. Document reading

- [ ] 1.1 Wait for filinq's contract and identity-document extraction contract
      (for-ruben/filinq-employee-document-extraction.md).
- [ ] 1.2 Add `EmployeeDocumentExtraction` to `hr-onboarding.json` with its own
      authorization (read, create, update: HR), en and nl keys, three mock objects.
- [ ] 1.3 Add `EmployeeDocumentExtractionService` with the filinq probe and the empty-field
      rule. Verify: unit test with the real filinq class that a filled field is not
      overwritten, that absent filinq records `skipped-no-docudesk`, and that a thrown filinq
      error records `failed`.
- [ ] 1.4 Add the read-document and contract-from-document routes and actions on
      `OnboardingDetail`. Verify: controller test that one extraction creates at most one
      contract.
- [ ] 1.5 Seed one extraction in status `extracted` on the seeded onboarding case.
- [ ] 1.6 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19).
