## 0. Decisions first

- [ ] 0.1 Get Ruben's answers to O1 (schemes and where their parameters live), O2 (calendar
      or working days) and O3 (`sectorcode`), from `for-ruben/payroll-engine-move-comparison.md`.
      Record them in design.md under each choice before building.

## 1. Pension scheme and participation

- [ ] 1.1 O1 "table": add a `pensioen` block per scheme code to
      `lib/Standards/tables/nl-2026.json` (franchise per year, employer and employee percentage,
      source URL per scheme). O1 "schema": add `PensionScheme` (`schema:Thing`, code, name,
      year, franchise, employerPct, employeePct, source) in a new fragment
      `lib/Settings/register.d/hr-pension-scheme.json`, with index and detail pages under
      payroll. Verify: `occ maintenance:repair` imports it and `npm run check:manifest` exits 0.
- [ ] 1.2 Add `PensionParticipation` (D2) to the same fragment, with a guard that refuses an
      overlap (REQ-PPP-002), an index page and a tab on `EmployeeDetail`, en and nl labels and
      two mock objects. Verify: unit test of the guard with two overlapping and two adjacent
      participations.

## 2. Premium in the pack and the run

- [ ] 2.1 Add the steps `pensioengrondslag`, `pensionEmployee` (reduces net and the taxable
      wage) and `pensionEmployer` (employer charge) to `nl-2026.pack.json` (D1), plus golden
      vectors for the three REQ-PPP-001 scenarios. Verify: `PackValidator` passes and the nine
      existing fixtures are unchanged.
- [ ] 2.2 In `PayrollRunService`, read the participation in force and pass the scheme
      parameters; replace the constant `pensionContribution: 0.0` with the step results and add
      `pensionEmployer` to `Payslip` in `hr-objects.json`. Verify: unit tests for the three
      REQ-PPP-001 scenarios through the real run.
- [ ] 2.3 Add pension lines to `PayrollGLPostService::buildLines()` and the setting
      `gl_account_pension_payable` (D4, REQ-PPP-003). Verify: unit tests that the entry
      balances and that a missing account writes nothing and logs why.

## 3. Partial period

- [ ] 3.1 Add `periodFactor` to the run per O2 (D3) and store it on `Payslip`. Verify: unit
      tests for the three REQ-PPR-001 scenarios.
- [ ] 3.2 Show the factor and its dates on `PayslipDetail` when below 1 (REQ-PPR-002), en and
      nl. Verify: `npm run check:manifest` exits 0.

## 4. Sector code (only if O3 is yes)

- [ ] 4.1 Add `sectorcode` to `hrAdministration` and a sector table for the Whk sector fund
      part. Verify: unit test that two administrations in different sectors get different Whk
      premiums.

## 5. Hand-over

- [ ] 5.1 `@spec` tags on every changed method and an e2e test or a reason-bearing
      `@e2e exclude` per scenario (gates 19 and 46).
- [ ] 5.2 Live check on the dev instance: one run with a participant and a starter; note the
      payslips in the PR body.
- [ ] 5.3 Tell the shillinq lane that humaniq covers the engine, so shillinq can run the
      schema-retirement checklist and remove `bookkeeping-payroll-engine-nl` (row 65 b).
