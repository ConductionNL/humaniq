## 1. Schema

- [x] 1.1 Extend `CompReviewCycle`, `SalaryBand` and `CompAdjustment` in
      `lib/Settings/register.d/hr-comp.json` and `EmploymentContract` in `hr-objects.json` with
      the fields of design.md D2, D5 and D6, plus the `refused` status and `refuse` transition.
      Verify: `occ app:update` imports the register and `npm run check:schema-l10n` exits 0.
- [x] 1.2 Add `lib/Lifecycle/DecisionReasonGuard.php` and reference it from `refuse`.
      Verify: unit test that an empty `decisionReason` and a self-refusal are both refused.
- [x] 1.3 Declare the two `x-openregister-notifications` rules on `CompAdjustment`.
      Verify: `hydra-gate-notification-dialect` passes.

## 2. Services and endpoints

- [x] 2.1 Add `CompCollectiveService::proposeForCycle()` with dry run and idempotency per
      `(cycleId, employeeId)`. Verify: unit test that a second run creates nothing and that a
      2% raise on 3800.00 proposes 3876.00.
- [x] 2.2 Add step proposal for `step-increase` cycles. Verify: unit test that a contract on
      the top step and a contract with a step date outside the period get no proposal.
- [x] 2.3 Add `CompCollectiveService::approveCycle()` writing one transition per adjustment.
      Verify: unit test that the caller's own proposals come back `refused-self-approval`.
- [x] 2.4 Extend `CompAdjustmentService::effectuate()` with the hourly wage and step writes.
      Verify: unit test that a step effectuation sets `salaryStep` and moves `stepDate` a year.
- [x] 2.5 Add the three `CompController` endpoints and routes. Verify: controller tests for
      404 on an unreadable cycle and 403 for an employee role; `hydra-gate-route-auth` and
      `hydra-gate-no-admin-idor` pass.

## 3. Pages

- [x] 3.1 Add the cycle actions (propose with preview, approve all, effectuate with preview)
      to `CompReviewCycleDetail` in `src/manifest.d/hr-comp.json`. Verify:
      `npm run check:manifest` exits 0.
- [x] 3.2 Add the "Propose a raise" bulk action on `Employees` and register its host modal in
      `src/registry.js`. Verify: `npm run lint` exits 0.
- [x] 3.3 Seed the collective cycle and the stepped band. Verify: `npm run check:seed-refs`
      exits 0.

## 4. Verification

- [x] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on every new method (gate 16).
- [ ] 4.2 One live check on a dev instance: run the seeded collective cycle from propose to
      effectuate and refuse one step with a reason; record screenshots in the PR.
      Not run in the build lane (no dev instance in use by this lane); the recipe is in the
      PR body and STATE.md for the live-check pass.
