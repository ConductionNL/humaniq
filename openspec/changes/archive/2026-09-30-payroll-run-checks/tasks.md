## 1. Schema

- [x] 1.1 Add `PayrollRunFinding` with its `acknowledge` transition and the three `PayrollRun`
      fields. Verify: `occ app:update` imports the register and `npm run check:schema-l10n`
      exits 0.

## 2. Check

- [x] 2.1 Add `PayAnomalyDetector` (D3, D4). Verify: unit tests for a 2x net deviation flagged
      as warning, the same deviation with a `retroAdjustment` explained as info, and two prior
      payslips giving "not enough history".
- [x] 2.2 Add `PayrollRunCheckService` with the skipped, unpaid-input and rule sources and the
      replace-but-keep-acknowledged rule (D1, D2). Verify: unit test that an acknowledged
      finding survives a recheck and a resolved one disappears.
- [x] 2.3 Call the check from `PayrollRunService::generate()` after a successful save. Verify:
      unit test that a calculation writes findings and the run's counts.
- [x] 2.4 Add `POST /api/payroll/check` and the threshold settings. Verify: controller tests for
      404 on an unreadable run and 403 for an employee role; `hydra-gate-route-auth` and
      `hydra-gate-no-admin-idor` pass.

## 3. Page and seed

- [x] 3.1 Add the "Check run" action, the findings list and two stat tiles to
      `PayrollRunDetail`. Verify: `npm run check:manifest` exits 0.
- [x] 3.2 Seed the three history payslips. Verify: `npm run check:seed-refs` exits 0.

## 4. Verification

- [x] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 4.2 One live check on a dev instance: calculate a draft run and read its findings on the
      Left open: no live instance check in this lane (the fleet builds without a local instance run); the reviewer runs it from the PR recipe.
      run page; record screenshots in the PR.
