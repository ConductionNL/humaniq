## 1. Contract lookup

- [ ] 1.1 Call `findAll` with its real signature in `EmployerCostRateController::activeContract()`.
      Verify: a red test with an ObjectService double that has the real `findAll(array, bool, bool)` signature.

## 2. Project manager access

- [ ] 2.1 Add `ProjectManagerAccess::managesProjectOf(string $uid, array $employee): bool` reading
      planninq projects owned by the caller and the employee's time entries (system reads).
      Verify: unit tests for member, time entry, neither, another owner, planninq absent.
- [ ] 2.2 In the controller, fall back to the project-manager path on 404 or 409 and answer the
      reduced rate. Verify: controller tests (PM gets rate without salary fields; non-PM keeps 404/409).

## 3. Spec

- [ ] 3.1 Fold the requirement into `openspec/specs/employer-hourly-cost-rate/spec.md` on archive.
