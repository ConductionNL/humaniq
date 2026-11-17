## 1. Resolution

- [x] 1.1 `OrgResolutionService::resolveManagerUserIds()` resolves no manager for a placement in a unit of another administration than the employee's. Verify: OrgResolutionServiceTest::testAPlacementInAnotherAdministrationsUnitGivesNoManager.
- [x] 1.2 A manager whose own Employee record is outside the boundary administration resolves no manager, at the placement unit and above it. Verify: OrgResolutionServiceTest::testAManagerAboveInAnotherAdministrationGivesNoManager and testADirectManagerInAnotherAdministrationGivesNoManager.
- [x] 1.3 The stamp follows. Verify: HoursRegisterGatewayTest::testTheStampNamesOnlyAManagerInTheEmployeeAdministration.

## 2. Audit

- [x] 2.1 The audit reads the manager's administration from its Employee index. Verify: NlOrgChecksTest::testAManagerOutsideTheEmployeeAdministrationResolvesNoManager and RuleAuditServiceTest::testTheAuditChecksTheManagersOwnAdministration.

## 3. Verification

- [x] 3.1 The new tests fail on the base and pass on the branch. Capped mutants with a no-op control.
- [x] 3.2 `openspec validate approver-in-employee-administration --strict`, `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16).
