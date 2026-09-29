## 1. Schema

- [x] 1.1 Add the `Expense` fields, `CommuteArrangement` with its lifecycle, `WpmReport` and the
      `wpmBusinessKm` aggregation to `lib/Settings/register.d/hr-expense.json`. Verify:
      `occ app:update` imports the register and `npm run check:schema-l10n` exits 0.

## 2. Calculation

- [x] 2.1 Add `TravelAllowanceCalculator`. Verify: unit tests for 150 km at 0.23 (34.50, all
      tax free), 150 km at an employer rate of 0.30 (45.00, of which 10.50 taxable), and the
      seeded commute (118.13 a month).
- [x] 2.2 Add `TravelAmountListener` and register it. Verify: unit test that a claim without
      a distance keeps its typed amount and a travel claim with a distance is stamped.

## 3. Route lookup and report

- [x] 3.1 Add `RouteDistanceService` and `POST /api/travel/route-distance`. Verify: controller
      test for 404 on an unreadable arrangement and 409 when integriq is absent;
      `hydra-gate-no-admin-idor` passes.
- [x] 3.2 Add `WpmReportService` and `POST /api/travel/wpm-report`. Verify: unit test that an
      arrangement active for six months contributes half a year of commuting kilometres, and
      that a claim without a mode is listed.

## 4. Pages and seed

- [x] 4.1 Add arrangement index and detail pages, the WPM report pages and the claim fields in
      `src/manifest.d/hr-expense.json`, with menu entries. Verify: `npm run check:manifest`
      exits 0.
- [x] 4.2 Seed the arrangement and the claim's transport mode. Verify:
      `npm run check:seed-refs` exits 0.

## 5. Verification

- [x] 5.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 5.2 One live check on a dev instance: file a mileage claim with only a distance, and
      compile the 2026 WPM report for ADM-001; record screenshots in the PR.

5.2 is not ticked: the live check runs in the live-check pass after this lane, with the recipe in the PR body.
