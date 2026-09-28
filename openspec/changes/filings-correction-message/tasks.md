## 1. Data and lifecycle

- [ ] 1.1 Add `corrects`, `correctionLines` and `correctionRoute` to `LoonaangifteFiling` and
      replace the `corrigeren` transition. Verify: the register fragment validates with the
      real validator; seed objects import.
- [ ] 1.2 Add `LoonaangifteCorrectionGuard` on `corrigeren` and register it. Verify: a red
      test that a sent filing stays `verzonden` and a linked correction exists; the register
      walk test counts the new guard.

## 2. The difference

- [ ] 2.1 Add `LoonaangifteCorrectionService::diff()`. Verify: unit test with a retro change
      for one of two employees yields one line.
- [ ] 2.2 Route by year. Verify: unit tests for a period in the current year and one in a
      closed year.
- [ ] 2.3 Refuse `klaarzetten` on a correction with no difference. Verify: guard test.

## 3. Page and verification

- [ ] 3.1 Show `corrects` and the lines on `LoonaangifteFilingDetail`. Verify:
      `npm run check:manifest` exits 0; l10n keys in every shipped locale.
- [ ] 3.2 e2e or reason-bearing `@e2e exclude` per scenario, `@spec` tags, and one live
      correction on the seed administration.
