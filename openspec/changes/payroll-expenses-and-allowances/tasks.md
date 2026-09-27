## 1. Schema and tables

- [ ] 1.1 Add the `Expense`, `Payslip` and `PayrollRun` fields and the `RecurringAllowance`
      schema with its lifecycle. Verify: `occ app:update` imports the register and
      `npm run check:schema-l10n` exits 0.
- [ ] 1.2 Add the sourced home-working norm leaf to `lib/Standards/tables/nl-2026.json`.
      Verify: `TaxTables::load('nl-2026')` loads and the leaf carries `source` and `verified`.

## 2. Folds

- [ ] 2.1 Add `PayrollExpenseFoldService` claim selection and the route refusal for taxable
      claims. Verify: unit test that a claim with a taxable part is refused for the payroll
      route and a paid claim is not selected twice.
- [ ] 2.2 Add the allowance split (D4). Verify: unit tests for a taxed allowance, a home-working
      allowance under and over the norm, and an unverified norm that pays nothing.
- [ ] 2.3 Wire both folds into `PayrollRunService::generate()` and the run totals, and mark
      paid claims reimbursed from `PayrollRunApprovedListener`. Verify: unit test that a payslip
      without claims or allowances is byte-identical to before, and that approving the run
      marks the claim `reimbursed` once.
- [ ] 2.4 Upsert the `WkrDeclaration` rows per allowance and period. Verify: unit test that a
      recalculation leaves one row.

## 3. Journal

- [ ] 3.1 Add the reimbursement debit line and its account setting to `PayrollGLPostService`.
      Verify: unit test that a run with 27.40 reimbursements balances and one without keeps
      its four lines.

## 4. Pages and seed

- [ ] 4.1 Add the allowance pages, the route on `ExpenseDetail` and the payslip fields. Verify:
      `npm run check:manifest` exits 0.
- [ ] 4.2 Seed the payroll-route claim and the home-working allowance. Verify:
      `npm run check:seed-refs` exits 0.

## 5. Verification

- [ ] 5.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 5.2 One live check on a dev instance: recalculate a draft run and read the reimbursement
      and the allowance on a payslip; record screenshots in the PR.
