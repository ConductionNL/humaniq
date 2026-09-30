## 1. Corpus

- [x] 1.1 Document the component shape in `lib/Standards/cao/SCHEMA.md` and rewrite every
      `allowances` entry in it; bump `CaoRegistry::VERSION`. Verify: unit test that each
      rewritten file keeps its percentages and flags.
- [x] 1.2 Add `CaoRegistry::components()`. Verify: unit test that a placeholder leaf resolves
      to null and `cao-voorbeeld` resolves its components.

## 2. Resolution and calculation

- [x] 2.1 Add the contract fields and `EmploymentTermsResolver::resolveComponents()` with the
      reason and not-worse rules. Verify: unit tests for an override without a reason and an
      override below the agreement, both refused.
- [x] 2.2 Add `CaoComponentCalculator` (D3). Verify: unit tests for a 13.3% shift allowance, a
      night span crossing midnight split over two windows, a holiday date at `holidayPct`, and
      an entry without times listed as `no-times`.
- [x] 2.3 Fold the components into `PayrollRunService::generate()` and add the payslip fields.
      Verify: unit test that a contract without components gets a byte-identical payslip.
- [x] 2.4 Add `nl-cao-component-onbekend` to the corpus and `NlCaoChecks`. Verify:
      `occ humaniq:rules:audit` reports the seeded violation.

## 3. Pages and seed

- [x] 3.1 Show components on `EmploymentContractDetail`, `PayslipDetail` and `CaoDetail`.
      Verify: `npm run check:manifest` exits 0.
- [x] 3.2 Seed the example agreement's surcharge and a contract with night entries. Verify:
      `npm run check:seed-refs` exits 0.

## 4. Verification

- [x] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 4.2 One live check on a dev instance: calculate a run for the seeded night worker and
      read the premium lines; record screenshots in the PR.
      Left open: no live instance check in this lane; the reviewer runs it from the PR recipe.
