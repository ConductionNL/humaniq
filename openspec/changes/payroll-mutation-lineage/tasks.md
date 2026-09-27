## 1. Schema

- [ ] 1.1 Add `PayslipLine`, `PayrollAdjustment.correctsPayslipLineId` and the `causes` item
      field on `PayrollMutationReport.lines`. Verify: `occ app:update` imports the register and
      `npm run check:schema-l10n` exits 0.

## 2. Lineage

- [ ] 2.1 Return per-source amounts from the retro and leave fold helpers without changing the
      totals. Verify: unit test that two corrections of 100.00 and 50.00 give a total of 150.00
      and two sources.
- [ ] 2.2 Add `PayslipLineageService::replaceLines()` with the salary source rule (D3). Verify:
      unit tests for a salary set by an applied raise, one set by a direct edit, and an
      unavailable audit read leaving `sourceAuditTrailId` null.
- [ ] 2.3 Call it from `PayrollRunService::generate()` after each payslip save. Verify: unit
      test that a recalculated draft keeps only the current lines.
- [ ] 2.4 Validate `correctsPayslipLineId` against `originalPayslipId`. Verify: unit test that a
      line of another payslip is refused.
- [ ] 2.5 Add `causes` in `PayrollMutationService::buildReport()`. Verify: unit test that a new
      raise shows as one cause and an old payslip yields `causes: null`.

## 3. Page

- [ ] 3.1 Register `PayslipLinesSection` and add it to `PayslipDetail`. Verify:
      `npm run check:manifest` and `npm run lint` exit 0.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 4.2 One live check on a dev instance: calculate a draft run, open a payslip and follow
      the salary line to its source; record screenshots in the PR.
