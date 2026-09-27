## 1. Series

- [ ] 1.1 Add the unit member set and the `orgUnitId` filter to every series in
      `AnalyticsService`. Verify: unit tests for a unit with children and an employee who moved
      mid-period.
- [ ] 1.2 Add the `absence-frequency` metric. Verify: unit test with a hand-counted frequency
      and a period with no members answering null.
- [ ] 1.3 Add wage cost per unit with the run's employer charge ratio. Verify: unit test that
      the units of an administration sum to the administration total.

## 2. Access

- [ ] 2.1 Extend `authorizeCaller()` with the manager branch and the small-unit rule. Verify:
      controller tests for hr, accountant, the unit's manager, another manager (403) and a unit
      of four for a manager (null with reason).

## 3. Pages

- [ ] 3.1 Add the unit comparison chart to `AbsenceReport` and `Dashboard`, and the
      `MijnAfdeling` page. Verify: `npm run check:manifest` and `npm run lint` exit 0.

## 4. Verification

- [ ] 4.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live look at `MijnAfdeling` as the seed manager.
