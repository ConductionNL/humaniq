## 1. Groups and the role check

- [ ] 1.1 Add `EnsureRoleGroups` repair step and the two group settings. Verify: after
      `occ maintenance:repair` both groups exist; running it twice changes nothing.
- [ ] 1.2 Add `HumaniqRoles` and route the controller checks through it, leaving `AvgDsrController`
      administrator-only. Verify: unit
      tests for admin, HR, payroll and a plain user; existing controller tests still pass.

## 2. Field access

- [ ] 2.1 Declare property `authorization` on the `Employee`, `EmploymentContract` and
      `Payslip` fields of design D3. Verify: integration test reading an employee as HR,
      payroll, the subject and a manager; only the first three see `bsn` and
      `grossMonthlySalary`.
- [ ] 2.2 Add `PerformanceReview.reviewerUserId` with its stamp listener and the content
      authorization of design D4. Verify: integration test that HR sees status but not
      `afspraken`, and the employee and the reviewer see both.

## 3. Settings and seed

- [ ] 3.1 Add the two group ids to the admin settings page and seed the four demo users.
      Verify: `npm run lint` and `npm run check:seed-refs` exit 0.

## 4. Verification

- [ ] 4.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      `hydra-gate-semantic-auth` and `hydra-gate-no-admin-idor` pass on the changed controllers.
