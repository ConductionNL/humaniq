## 0. Decisions first

- [x] 0.1 Get Ruben's answers to O1 (schemes and where their parameters live), O2 (calendar
      or working days) and O3 (`sectorcode`), from `for-ruben/payroll-engine-move-comparison.md`.
      Record them in design.md under each choice before building.
      Decided under the 22 Nov 2026 delegation and recorded in design.md: O1 the schema (the
      existing `PensionScheme` of filings-pension-upa-message), O2 calendar days, O3 no.

## 1. Pension scheme and participation

- [x] 1.1 O1 "table": add a `pensioen` block per scheme code to
      `lib/Standards/tables/nl-2026.json` (franchise per year, employer and employee percentage,
      source URL per scheme). O1 "schema": add `PensionScheme` (`schema:Thing`, code, name,
      year, franchise, employerPct, employeePct, source) in a new fragment
      `lib/Settings/register.d/hr-pension-scheme.json`, with index and detail pages under
      payroll. Verify: `occ maintenance:repair` imports it and `npm run check:manifest` exits 0.
      Done by reuse: `PensionScheme` already sits in `hr-pension.json` with its pages
      (filings-pension-upa-message D1); no second record. `check:manifest` exits 0.
- [x] 1.2 Add `PensionParticipation` (D2) to the same fragment, with a guard that refuses an
      overlap (REQ-PPP-002), an index page and a tab on `EmployeeDetail`, en and nl labels and
      two mock objects. Verify: unit test of the guard with two overlapping and two adjacent
      participations.
      Built in `hr-pension.json` (keyed on `fund`, per O1), the guard
      `PensionParticipationOverlapListener`, pages `PensionParticipations` and
      `PensionParticipationDetail`, a Pension list on `EmployeeDetail`, en and nl, two seeded
      participations; `tests/Unit/Listener/PensionParticipationOverlapListenerTest.php` and
      `tests/Unit/Settings/PensionParticipationDeclarationTest.php`.

## 2. Premium in the pack and the run

- [x] 2.1 Add the steps `pensioengrondslag`, `pensionEmployee` (reduces net and the taxable
      wage) and `pensionEmployer` (employer charge) to `nl-2026.pack.json` (D1), plus golden
      vectors for the three REQ-PPP-001 scenarios. Verify: `PackValidator` passes and the nine
      existing fixtures are unchanged.
      Built: inputs `pensionBase` and the two percentages, bindings `pensionBase`,
      `pensionEmployeePremium` (deducted in `belastbaarLoon`) and `loonNaVrijstelling` (the
      reserves' unchanged base), the three steps, three inline vectors; pack 1.3.0. The base is
      an input computed by the run with `PensionBase` (O1). `NlPackTest` (every fixture
      unchanged), `PensionPremiumCalculationTest`.
- [x] 2.2 In `PayrollRunService`, read the participation in force and pass the scheme
      parameters; replace the constant `pensionContribution: 0.0` with the step results and add
      `pensionEmployer` to `Payslip` in `hr-objects.json`. Verify: unit tests for the three
      REQ-PPP-001 scenarios through the real run.
      Built: `PensionPremiumService` (participation in force and the fund's scheme on the last
      day of the period), `PensionPremium` on `CalculationInput`, `pensionEmployer` and
      `pensionBase` on `Payslip` (stamped for participants only). `PensionPremiumServiceTest`,
      `PayrollRunServiceTest::testAParticipantsPayslipWithholdsThePensionPremium`.
- [x] 2.3 Add pension lines to `PayrollGLPostService::buildLines()` and the setting
      `gl_account_pension_payable` (D4, REQ-PPP-003). Verify: unit tests that the entry
      balances and that a missing account writes nothing and logs why.
      Built: the run stores `totalPensionEmployee` and `totalPensionEmployer`; the journal
      credits both on the app config key `glpost_account_pension_payable` (named like the other
      `glpost_account_*` keys, no default) and takes them out of the net wages credit; the
      employer premium stays debited inside the employer charges, per cost centre. A run with
      premiums and no account is not posted (GL post `failed` with the reason).
      `PayrollGLPostServiceTest` (the five pension tests).

## 3. Partial period

- [ ] 3.1 Add `periodFactor` to the run per O2 (D3) and store it on `Payslip`. Verify: unit
      tests for the three REQ-PPR-001 scenarios.
- [ ] 3.2 Show the factor and its dates on `PayslipDetail` when below 1 (REQ-PPR-002), en and
      nl. Verify: `npm run check:manifest` exits 0.

## 4. Sector code (only if O3 is yes)

- [x] 4.1 Add `sectorcode` to `hrAdministration` and a sector table for the Whk sector fund
      part. Verify: unit test that two administrations in different sectors get different Whk
      premiums.
      Not built: O3 is decided no (design.md), so this task does not apply.

## 5. Hand-over

- [ ] 5.1 `@spec` tags on every changed method and an e2e test or a reason-bearing
      `@e2e exclude` per scenario (gates 19 and 46).
- [ ] 5.2 Live check on the dev instance: one run with a participant and a starter; note the
      payslips in the PR body.
- [ ] 5.3 Tell the shillinq lane that humaniq covers the engine, so shillinq can run the
      schema-retirement checklist and remove `bookkeeping-payroll-engine-nl` (row 65 b).
