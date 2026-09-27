## 1. Schema

- [ ] 1.1 Add `CostAllocation` and `WageCostAllocation` with the `wageCostByCostCenter`
      aggregation. Verify: `occ app:update` imports them and `npm run check:schema-l10n`
      exits 0.
- [ ] 1.2 Add the sum-to-100 guard and the overlap listener. Verify: unit tests for splits of
      90% and for two overlapping allocations, both refused.

## 2. Allocation

- [ ] 2.1 Add `CostAllocationService::splitFor()` with the order of D2. Verify: unit tests for
      fixed, hours, two placements split equally, and unallocated.
- [ ] 2.2 Write the allocation lines after each payslip save, with cent rounding on the largest
      share. Verify: unit test that three shares of a 1000.01 gross add up to 1000.01.

## 3. Journal

- [ ] 3.1 Split the debit lines in `PayrollGLPostService::buildLines()`. Verify: unit tests that
      a split run balances, carries the codes, and a run without allocation rows keeps its
      four lines.

## 4. Pages and seed

- [ ] 4.1 Add the allocation pages, the payslip lines and the wage costs page. Verify:
      `npm run check:manifest` exits 0.
- [ ] 4.2 Seed the fixed split. Verify: `npm run check:seed-refs` exits 0.

## 5. Verification

- [ ] 5.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 5.2 One live check on a dev instance: calculate a run and read the wage costs per cost
      centre; record screenshots in the PR.
