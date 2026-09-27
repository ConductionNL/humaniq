## 1. History service and endpoints

- [ ] 1.1 Add `lib/Service/EmployeeHistoryService.php` composing contract, placement,
      applied pay change, approved leave, sickness and finalised review events per
      employee, newest first. Verify: unit test with one employee holding each event kind
      asserts the order and that a rejected leave request and an unapplied
      `CompAdjustment` are absent.
- [ ] 1.2 Add `activeEmploymentsOn(employeeId, date)` with summed hours and FTE, reusing
      the overlap helper from `AbsenceRateService`. Verify: unit test with two overlapping
      contracts and one ended contract returns two rows and the right sums, and the FTE
      equals what `AbsenceRateService` computes for the same contracts.
- [ ] 1.3 Add `EmployeeHistoryController` with `GET /api/employees/{id}/history` and
      `GET /api/employees/{id}/employments`, `#[NoAdminRequired]`, visibility through
      `RbacObjectReader`. Verify: controller test that an unreadable employee answers 404
      and an unreadable source row is dropped; `hydra-gate-route-auth` and
      `hydra-gate-no-admin-idor` pass.

## 2. Employee page

- [ ] 2.1 Register `EmployeeHistorySection` in `src/registry.js` handing events to
      `CnTimelineView`. Verify: `npm run lint` exits 0.
- [ ] 2.2 Add two `bodyWidgets` sections to `EmployeeDetail` in
      `src/manifest.d/hr-objects.json`: the history and the employments block with the
      leave balances. Verify: `npm run check:manifest` exits 0.
- [ ] 2.3 Seed a second active contract on one seed employee. Verify: after
      `occ app:update`, the page shows two contracts and their summed hours.

## 3. Verification

- [ ] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and
      `@spec` tags on the new methods (gate 16).
- [ ] 3.2 One live check on a dev instance: open a seeded employee and read the history
      and the combined block; record the screenshot in the PR.
