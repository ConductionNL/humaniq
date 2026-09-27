## 1. Chart read

- [ ] 1.1 Add `OrgChartService` (tree, manager, headcount on a date, optional people, orphan
      units as roots). Verify: unit tests for a three-level tree, an ended assignment on the
      date, and a unit whose parent is inactive.
- [ ] 1.2 Add the tidy-tree layout. Verify: unit test that no two units share a position
      and that children sit below their parent.
- [ ] 1.3 Add `GET /api/org/chart` in `OrgChartController`. Verify: controller test that
      `withPeople` lists only readable employees; `hydra-gate-route-auth` passes.

## 2. Page

- [ ] 2.1 Add the `Organogram` host view with tree and chart and the menu entry. Verify:
      `npm run lint` and `npm run check:manifest` exit 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate
      16), a keyboard walk through the tree view, and a live look at the seeded chart.
