## 1. Schemes

- [x] 1.1 Add `PensionScheme` and the three `PensionFiling` properties; seed the two schemes.
      Verify: `occ maintenance:repair` imports; `npm run check:seed-refs` exits 0.
      > `lib/Settings/register.d/hr-pension.json`, register 0.62.0, two unverified ADM-001 schemes
      > (`pfab` on UPA, `abp` on APG) in `hr-seed.json`. `check:seed-refs` passes; the live
      > `occ maintenance:repair` import is not run here.
- [x] 1.2 Add `PensionSchemes` pages. Verify: `npm run check:manifest` exits 0.
      > `PensionSchemes` and `PensionSchemeDetail` in `src/manifest.d/hr-pension.json`, a menu entry
      > beside Pension filings; `check:manifest`, `check:manifest-parity` and `check:l10n` pass.

## 2. Messages

- [ ] 2.1 Ship the UPA and APG format schemas for 2026 under `lib/Standards/pension/`. Verify: a
      test loads both.
      Blocked 2026-11-27: the schemas are third-party files (SIVI's UPA XSD, APG's delivery
      format for ABP). They have to be obtained from SIVI and APG with their licence terms recorded
      in a NOTICE, as `lib/Standards/loonaangifte/NOTICE` does; they are not invented here.
- [x] 2.2 Add `PensionMessageService` with the pension base of design D2. Verify: unit tests for
      a full-timer, a part-timer and a salary above the maximum.
      > `lib/Payroll/Pension/PensionBase.php` (pure; the scheme's yearly franchise and maximum taken
      > per month) and `PensionMessageService::lines()` (scheme of the period, approved run, one line
      > per payslip, a blocking finding naming an employee without a contract or hours per week).
      > PHPUnit: `PensionBaseTest`, `PensionMessageServiceTest`. Handing the lines to a builder
      > waits for 2.3.
- [ ] 2.3 Add `UpaMessageBuilder` and `ApgMessageBuilder`. Verify: golden-file tests that
      validate against the schemas.
- [ ] 2.4 Add `PensionMessageGuard` on `controleren`. Verify: tests for a stored file and a
      refusal listing an employee without a contract.
      Partly done, left open on 2.3: `lib/Lifecycle/PensionMessageGuard.php` is the `controleren`
      guard now. It runs `PayrollRunApprovedGuard` first, then for a fund with a scheme in the
      period refuses while an employee in the scheme has no contract or no hours per week, and
      names them (`PensionMessageGuardTest`). `PensionMessageService::lines()` counts only the
      employees with a `PensionParticipation` in the fund during the period, the spec's "employee
      in the scheme", so someone outside the fund cannot hold the filing up
      (`PensionMessageServiceTest`). A fund without a scheme passes as before, since its delivery
      is still made outside humaniq. Left open: the stored file. OpenRegister guards are read-only,
      so the file is rendered and stored by the render step (the `LoonaangifteMessageGuard`
      precedent: render stores, the guard reads), which waits for the builders of 2.3.
      e2e: `tests/e2e/spec-coverage/filings-pension-upa-message.spec.ts` (refusal and the
      outside-the-scheme pass; the two delivery scenarios are `test.fixme` until 2.3).

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live check of an ABP filing producing the APG file.
