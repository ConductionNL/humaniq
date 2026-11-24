## 1. Read CV

- [x] 1.1 Wait for filinq's profile extraction contract (for-ruben/filinq-cv-profile-extraction.md).
      Built instead of waited for (the draft did not exist): filinq#2320, change
      `extraction-cv-profile`, `ProfileExtractionService::extractProfile()`.
- [x] 1.2 Add `Read CV` duck-typed to filinq, filling only an empty profile. Verify: unit test
      for absent filinq and for a filled profile left alone, with the real filinq class.
      Built: `CvProfileReader`, `POST /api/applications/{id}/read-cv`, the Read CV action,
      `profileSource`. `CvProfileReaderTest` (filinq's classes vendored in `tests/stubs/Filinq`),
      `ApplicationCvControllerTest`.
- [x] 1.3 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19).
      Done: a reason-bearing `@e2e exclude` under both scenarios.
