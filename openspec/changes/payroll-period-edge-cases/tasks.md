## 1. Periods

- [ ] 1.1 Add `PayPeriod` (D1). Verify: unit tests for `2026-05`, `2026-P13` holding ISO week
      53, `2026-P14`, `2026-W53`, and `2025-P14` refused because 2025 has no week 53.
- [ ] 1.2 Add `payFrequency` and `week53Handling` to `hrAdministration` and switch the four month
      patterns to `PayPeriod`. Verify: unit tests that a monthly administration refuses
      `2026-P05` and a four-weekly one accepts it.

## 2. Engine

- [ ] 2.1 Teach `RefResolver` `@period.tijdvak` and `@period.lastDay` for every id. Verify:
      unit tests for each grain.
- [ ] 2.2 Replace the monthly factor and caps in the pack with tijdvak bindings, add
      `maximumpremieloon.week` and new golden vectors. Verify: the nine fixtures pass unchanged
      and the four-weekly and weekly vectors pass through `PackValidator` gate 5.
- [ ] 2.3 Switch the run's monthly conversions to `shareOfYear` (D4). Verify: unit test that a
      monthly payslip is byte-identical to before.
- [ ] 2.4 Add week-53 handling for both settings (D3). Verify: unit tests that an extended
      `2026-P13` payslip carries a four-week and a one-week calculation, and a `2026-P14` run
      covers week 53 only.

## 3. Opening balances

- [ ] 3.1 Add `PayrollOpeningBalance` with its uniqueness rule and the `OpeningBalances` page
      with mass import. Verify: `occ app:update` imports it and `npm run check:manifest` exits
      0.
- [ ] 3.2 Add opening balances to `upsertJaaropgaaf()` and `WkrService`. Verify: unit tests
      that six months of opening balance plus six humaniq payslips give a twelve-period
      statement.
- [ ] 3.3 Add `nl-openingsbalans-overlap` and `nl-openingsbalans-ontbreekt`. Verify:
      `occ humaniq:rules:audit` reports a seeded overlap.

## 4. Seed

- [ ] 4.1 Seed `ADM-005` and the opening balance. Verify: `npm run check:seed-refs` exits 0.

## 5. Verification

- [ ] 5.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 5.2 One live check on a dev instance: calculate `2026-P13` for `ADM-005` and generate the
      2026 statement for the employee with an opening balance; record screenshots in the PR.
