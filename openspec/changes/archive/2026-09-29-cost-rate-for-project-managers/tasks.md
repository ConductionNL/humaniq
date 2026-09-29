## 1. Contract lookup

- [x] 1.1 Call `findAll` with its real signature in `EmployerCostRateController::activeContract()`.
      Verify: a red test with an ObjectService double that has the real `findAll(array, bool, bool)` signature.

## 2. Project manager access

- [x] 2.1 Add `ProjectManagerAccess::managesProjectOf(string $uid, array $employee): bool` reading
      planninq projects owned by the caller and the employee's time entries (system reads).
      Verify: unit tests for member, time entry, neither, another owner, planninq absent.
- [x] 2.2 In the controller, fall back to the project-manager path on 404 or 409 and answer the
      reduced rate. Verify: controller tests (PM gets rate without salary fields; non-PM keeps 404/409).

## 3. Spec

- [x] 3.1 Fold the requirement into `openspec/specs/employer-hourly-cost-rate/spec.md` on archive.

Notes from the build (2026-09-29): the employee and contract lookups under the caller's access moved
from the controller into `CostRateAccess` next to the project-manager reads (phpmd coupling of the
controller); the OpenRegister availability guard moved with them and `OpenRegisterGuardContractTest`
lists `CostRateAccess` instead of the controller. The contract filter also named fields the schema
does not have (`employee`, `status`); the contract is now the one running in the period
(`startDate`/`endDate`), matched on `employeeId`.
