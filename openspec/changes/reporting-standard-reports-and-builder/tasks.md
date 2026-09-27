## 1. Ready-made reports

- [ ] 1.1 Add `WageCostReport` and `PayrollRunsReport` with their cards. Verify:
      `npm run check:manifest` exits 0; the seeded runs show on both.
- [ ] 1.2 Add `TurnoverReport` and `LeaveBalanceReport` with their cards. Verify: as 1.1.

## 2. Own reports

- [ ] 2.1 Switch on `allowSavedViews` and `allowExport` on the seven index pages and
      `exportable` on their schemas. Verify: `npm run check:manifest` exits 0; the register
      imports.
- [ ] 2.2 Add the "My reports" category on `Reports`. Verify: the seeded public view shows as a
      card and opens its page with the view applied.
- [ ] 2.3 Seed the public view. Verify: `npm run check:seed-refs` exits 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live save, share and
      export of a view by one user and its use by another.
