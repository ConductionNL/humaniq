## 1. Data

- [ ] 1.1 Add `Employee.gender` with field authorization and `Normfunctie.payCategory`; bump
      versions; seed. Verify: `occ maintenance:repair` imports; a manager cannot read `gender`.

## 2. Report

- [ ] 2.1 Add `PayTransparencyService` with hourly pay, mean and median gap, variable pay and
      quartiles. Verify: unit tests against a hand-computed small administration.
- [ ] 2.2 Add suppression below the threshold. Verify: unit test that a category of four women
      reports `tooSmall`.
- [ ] 2.3 Add `GET /api/reports/pay-transparency` for hr and accountant roles. Verify:
      controller tests for an allowed and a refused caller.
- [ ] 2.4 Add the report card and page with CSV export. Verify: `npm run check:manifest` exits 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16), and
      a live report for the seeded year.
