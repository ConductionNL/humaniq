## 1. Data

- [ ] 1.1 Add `payrollProcessing` and `payrollBureauName` to `hrAdministration`, the new
      `hr-handoff.json` fragment with `PayrollHandoff` and `PayrollHandoffMutation`, and the two
      `Payslip` fields. Verify: `occ app:update` imports the register and
      `npm run check:schema-l10n` exits 0.

## 2. Engine guard

- [ ] 2.1 Refuse externally processed administrations in `runFor()` and the calculate node.
      Verify: unit test for the `refused-external-bureau` outcome.

## 3. Handoff

- [ ] 3.1 Add `PayrollHandoffService::compile()` (D2). Verify: unit tests that a first handoff
      sends `start` for everyone, a raise becomes one `salary` mutation, and an edit undone
      before compiling sends nothing.
- [ ] 3.2 Add `checkIntake()` (D4) and the listener on `ontvangen`. Verify: unit tests for a
      missing employee (blocking) and an unknown employee (blocking), and `userId` stamping.
- [ ] 3.3 Add the two endpoints. Verify: controller tests for 404 on an unreadable
      administration and 403 for an employee role; `hydra-gate-route-auth` and
      `hydra-gate-no-admin-idor` pass.

## 4. Pages and seed

- [ ] 4.1 Add handoff index and detail pages with the mutations list and lifecycle actions.
      Verify: `npm run check:manifest` exits 0.
- [ ] 4.2 Seed `ADM-006`. Verify: `npm run check:seed-refs` exits 0.

## 5. Verification

- [ ] 5.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 5.2 One live check on a dev instance: compile a handoff for `ADM-006`, set it ready, post a
      returned payslip through the object API and run the intake check; record screenshots in
      the PR.
