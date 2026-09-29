## 1. Groups and the role check

- [x] 1.1 Add `EnsureRoleGroups` repair step. Verify: after `occ maintenance:repair` both
      groups exist; running it twice changes nothing (`EnsureRoleGroupsTest`). The group ids
      are fixed, not settings (design D1).
- [x] 1.2 Add `HumaniqRoles` and route the controller checks through it, leaving `AvgDsrController`
      and the jurisdiction pack upload administrator-only. Verify: unit tests for admin, HR,
      payroll and a plain user (`HumaniqRolesTest`, `LoonbeslagControllerTest`,
      `PayrollControllerContractTest`); existing controller tests still pass.

## 2. Field access

- [x] 2.1 Declare property `authorization` on the `Employee`, `EmploymentContract` and
      `Payslip` fields of design D3, stamp `EmploymentContract.userId`, and keep values a full
      save would wipe (D5). Verify: the declarations evaluated with OpenRegister's
      `PropertyRbacHandler` as HR, payroll, the subject, an administrator and a manager
      (design, Verification against OpenRegister); `FieldAuthorizationDeclarationTest`,
      `FieldAccessListenerTest`.
- [x] 2.2 Add `PerformanceReview.reviewerUserId` with its stamp and the content
      authorization of design D4. Verify: HR reads status but not `afspraken`, the employee
      and the reviewer read both (same OpenRegister evaluation);
      `FieldAccessListenerTest::testAReviewCarriesItsReviewersAccount`.
- [x] 2.3 Read aggregates and audits past the field strip (D6). Verify:
      `AnalyticsServiceTest::testUnitFiguresReadPayslipsPastTheFieldStrip`.

## 3. Settings and seed

- [x] 3.1 No group settings (D1) and no seeded accounts (design, Seed data); the seed's Jansen
      contracts carry `userId`. Verify: `npm run lint` and `npm run check:seed-refs` exit 0.

## 4. Verification

- [x] 4.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      `hydra-gate-semantic-auth` and `hydra-gate-no-admin-idor` pass on the changed controllers.
