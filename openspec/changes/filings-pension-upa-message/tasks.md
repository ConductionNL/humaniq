## 1. Schemes

- [ ] 1.1 Add `PensionScheme` and the three `PensionFiling` properties; seed the two schemes.
      Verify: `occ maintenance:repair` imports; `npm run check:seed-refs` exits 0.
- [ ] 1.2 Add `PensionSchemes` pages. Verify: `npm run check:manifest` exits 0.

## 2. Messages

- [ ] 2.1 Ship the UPA and APG format schemas for 2026 under `lib/Standards/pension/`. Verify: a
      test loads both.
- [ ] 2.2 Add `PensionMessageService` with the pension base of design D2. Verify: unit tests for
      a full-timer, a part-timer and a salary above the maximum.
- [ ] 2.3 Add `UpaMessageBuilder` and `ApgMessageBuilder`. Verify: golden-file tests that
      validate against the schemas.
- [ ] 2.4 Add `PensionMessageGuard` on `controleren`. Verify: tests for a stored file and a
      refusal listing an employee without a contract.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live check of an ABP filing producing the APG file.
