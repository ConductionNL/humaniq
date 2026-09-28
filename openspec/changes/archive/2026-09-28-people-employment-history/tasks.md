## 1. History service and endpoints

- [x] 1.1 Add `lib/Service/EmployeeHistoryService.php` composing contract, placement,
      applied pay change, approved leave, sickness and finalised review events per
      employee, newest first. Verify: unit test with one employee holding each event kind
      asserts the order and that a rejected leave request and an unapplied
      `CompAdjustment` are absent.
- [x] 1.2 Add `activeEmploymentsOn(employeeId, date)` with summed hours and FTE, reusing
      the overlap helper from `AbsenceRateService`. Verify: unit test with two overlapping
      contracts and one ended contract returns two rows and the right sums, and the FTE
      equals what `AbsenceRateService` computes for the same contracts.
- [x] 1.3 Add `EmployeeHistoryController` with `GET /api/employees/{id}/history` and
      `GET /api/employees/{id}/employments`, `#[NoAdminRequired]`, visibility through
      `RbacObjectReader`. Verify: controller test that an unreadable employee answers 404
      and an unreadable source row is dropped; `hydra-gate-route-auth` and
      `hydra-gate-no-admin-idor` pass.

## 2. Employee page

- [x] 2.1 Register the `employee-history` widget (`src/widgets/EmployeeHistoryWidget.vue`) in `src/registry.js` handing events to
      `CnTimelineView`. Verify: `npm run lint` exits 0.
- [x] 2.2 Add two `employee-history` widgets (views `employments` and `history`) to `EmployeeDetail` in
      `src/manifest.d/hr-objects.json`: the history and the employments block with the
      leave balances. Verify: `npm run check:manifest` exits 0.
- [x] 2.3 Seed a second active contract on one seed employee. Already true: the seed
      gives employee-jansen two open-ended contracts from 2024 (contract-jansen-vast and
      contract-hr21-jansen-schaal-mismatch), so no seed change was needed.

## 3. Verification

- [x] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and
      `@spec` tags on the new methods (gate 16).
- [x] 3.2 One live check on a dev instance: owed to the live-check pass (recipe in the PR
      body); the lane builds without touching the shared dev instance.
