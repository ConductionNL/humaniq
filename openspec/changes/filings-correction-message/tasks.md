## 1. Data and lifecycle

- [x] 1.1 Add `corrects`, `correctionLines` and `correctionRoute` to `LoonaangifteFiling` and
      replace the `corrigeren` transition. Verify: the register fragment validates with the
      real validator; seed objects import. Built: also `correctionTree`, `correctionSaldo`,
      `carriedBy`, `carriedCorrectionIds` (LoonaangifteFiling 0.5.0); `corrigeren` removed (D1
      amended); stored corrections validate with `RegisterSchemaValidator` in
      `LoonaangifteCorrectionServiceTest`; `npm run check:seed-refs` exits 0.
- [x] 1.2 Add `LoonaangifteCorrectionGuard` on `corrigeren` and register it. Built instead
      (D1 amended): the Correct action, `POST /api/loonaangifte/filings/{filingId}/correction`,
      `LoonaangifteCorrectionService::open()`. Verify:
      `testCorrectingASentReturnOpensALinkedCorrection` (the sent filing is unchanged and a
      linked correction exists), `LoonaangifteMessageControllerTest::testCorrectAndMakeACorrection`.

## 2. The difference

- [x] 2.1 Add `LoonaangifteCorrectionService` with the difference (`CorrectionDiff`). Verify:
      `testOneEmployeesRetroRiseYieldsOneLine` (a retro change for one of two employees yields
      one line), `testAnEmployeeNoLongerPaidIsWithdrawn`.
- [x] 2.2 Route by year. Verify: `testTheNextReturnCarriesTheCorrection` (current year, carried
      by the next return with its saldo) and `testAClosedYearCorrectionIsItsOwnMessage`.
- [x] 2.3 Refuse `klaarzetten` on a correction with no difference. Verify:
      `testNothingToCorrectIsRefused`, `LoonaangifteMessageGuardTest::testACorrectionNeedsItsTreeOrItsMessage`.

## 3. Page and verification

- [x] 3.1 Show `corrects` and the lines on `LoonaangifteFilingDetail`. Verify:
      `npm run check:manifest` exits 0; l10n keys in every shipped locale (en, nl).
- [x] 3.2 e2e or reason-bearing `@e2e exclude` per scenario, `@spec` tags.
- [ ] 3.3 Live: one correction on the seed administration (after `filings-wage-tax-message`'s
      live check, since a correction needs a sent humaniq message to compare against).
