## 1. Document reading

- [x] 1.1 Wait for filinq's contract and identity-document extraction contract
      (for-ruben/filinq-employee-document-extraction.md).
      Built instead of waited for (the draft did not exist): filinq#2323, change
      `extraction-employee-document`, `EmployeeDocumentExtractionService::extractEmployeeDocument()`
      (MRZ for the identity document, fixed rules for the contract, a confidence per value).
- [x] 1.2 Add `EmployeeDocumentExtraction` to `hr-onboarding.json` with its own
      authorization (read, create, update: HR), en and nl keys, three mock objects.
      Built: the document is a file property on the record (`documentFile`, the
      `Expense.receiptFile` pattern), listed on the register, exportable.
- [x] 1.3 Add `EmployeeDocumentExtractionService` with the filinq probe and the empty-field
      rule. Verify: unit test with the real filinq class that a filled field is not
      overwritten, that absent filinq records `skipped-no-docudesk`, and that a thrown filinq
      error records `failed`.
      Built: `EmployeeDocumentExtractionServiceTest` against filinq's classes vendored in
      `tests/stubs/Filinq`; a personal number is written as BSN only when it passes the elfproef.
- [x] 1.4 Add the read-document and contract-from-document routes and actions on
      `OnboardingDetail`. Verify: controller test that one extraction creates at most one
      contract.
      Built: `OnboardingDocumentController` (`POST /api/onboarding/{id}/read-document`,
      `POST /api/onboarding/{id}/contract-from-document`), the two header actions and the
      Documents read list on `OnboardingDetail`. `OnboardingDocumentControllerTest`.
- [x] 1.5 Seed one extraction in status `extracted` on the seeded onboarding case.
      Seeded `employeedocumentextraction-visser-contract` on `onboarding-visser`.
- [x] 1.6 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19).
      Done: a reason-bearing `@e2e exclude` under both scenarios.
