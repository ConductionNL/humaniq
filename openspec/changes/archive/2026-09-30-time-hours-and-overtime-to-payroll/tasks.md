## 1. Schema

- [x] 1.1 Add the timesheet, time entry and payslip fields of design.md D2, D3 and D6, and the
      `compensation` leave type. Verify: `occ app:update` imports the register and
      `npm run check:schema-l10n` exits 0.
- [x] 1.2 Aggregate `overtimeHours` in `TimesheetAggregationService::computeAggregates()`.
      Verify: unit test with two regular and one overtime entry.

## 2. Pay

- [x] 2.1 Add `HoursPayService` with timesheet selection and stamping rules (D2). Verify: unit
      test that a timesheet stamped by an approved run is not selected and one stamped by a
      deleted run is.
- [x] 2.2 Compute overtime pay per day category through `EmploymentTermsResolver` and
      `WorkingCalendarReader` (D4). Verify: unit test that a Saturday entry under a 50%
      surcharge pays 150% and a placeholder CAO sets `overtimeSurchargeUnresolved`.
- [x] 2.3 Fold hours and overtime into `PayrollRunService::generate()` before
      `CalculationInput`. Verify: unit test that the hourly seed employee gets 2048.00 gross and
      that a salaried employee without overtime keeps a byte-identical payslip.
- [x] 2.4 Add `OvertimeCreditService::creditForRun()` and `PayrollRunApprovedListener`. Verify:
      unit test that a draft-to-approved update credits once and a replayed event credits
      nothing.

## 3. Pages and seed

- [x] 3.1 Show overtime on `TimesheetDetail`, the entry form and `PayslipDetail`. Verify:
      `npm run check:manifest` exits 0.
- [x] 3.2 Seed the hourly timesheet and the overtime entry. Verify: `npm run check:seed-refs`
      exits 0.

## 4. Verification

- [x] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 4.2 One live check on a dev instance (recipe in the PR body; not run here, no live instance in this lane): calculate 2026-05 and read the hourly payslip and
      the overtime line; record screenshots in the PR.
