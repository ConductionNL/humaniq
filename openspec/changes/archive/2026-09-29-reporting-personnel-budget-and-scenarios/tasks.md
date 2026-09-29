## 1. Data

- [x] 1.1 Add `FormationScenario` (with its `vaststellen` lifecycle) and
      `ScenarioMutation` to `hr-formation.json`. Verify: `occ maintenance:repair` imports
      both.
- [x] 1.2 Add the pre-save listener refusing mutations on a `vastgesteld` scenario.
      Verify: unit test for a refused and an accepted write.
- [x] 1.3 Seed the two 2027 scenarios. Verify: `npm run check:seed-refs` exits 0.

## 2. Budget

- [x] 2.1 Add `PersonnelBudgetService` with the cost line of design D2 and the roll-ups
      per unit, function and cost centre. Verify: unit tests with a hand-computed budget
      for one occupied and one vacant place, a contract ending mid-year, an approved
      raise, and an unpriced place.
- [x] 2.2 Add the scenario comparison. Verify: unit test that the growth scenario differs
      from the baseline by 2.0 FTE from March and the matching cost.
- [x] 2.3 Add `GET /api/formation/budget` and `GET /api/formation/compare` with the role
      check. Verify: controller tests for an hr user, an accountant and a manager (refused).

## 3. Pages

- [x] 3.1 Add `FormationScenarios` and `FormationScenarioDetail` with a budget section and
      CSV export. Verify: `npm run check:manifest` exits 0.

## 4. Verification

- [x] 4.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate
      16), and one live comparison of the two seeded scenarios.

3.1 has no CSV button (design, build-time changes). The live comparison in 4.1 runs in the live-check pass after this lane, with the recipe in the PR body.
