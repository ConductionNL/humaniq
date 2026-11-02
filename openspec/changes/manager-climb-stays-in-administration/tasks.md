## 1. Resolution

- [x] 1.1 `OrgResolutionService::resolveManagerUserIds()` ends the climb at a unit outside the placement unit's administration. Verify: OrgResolutionServiceTest cases for a parent in another administration, a parent without one, a start unit without one, and a middle unit in another administration.
- [x] 1.2 The stamp follows. Verify: HoursRegisterGatewayTest::testTheStampDoesNotClimbIntoAnotherAdministration.

## 2. Audit

- [x] 2.1 `RelatedContextIndexer` carries `administrationId` in the OrgUnit index. Verify: RuleAuditRelatedContextGoldenTest and RuleAuditServiceTest::testTheAuditClimbStopsAtTheAdministrationBoundary.
- [x] 2.2 The audit passes vacuously when the only manager above is in another administration. Verify: NlOrgChecksTest::testAParentManagerInAnotherAdministrationResolvesNoManager.

## 3. Verification

- [x] 3.1 The new tests fail on the base and pass on the branch. Capped mutants with a no-op control.
- [x] 3.2 `openspec validate manager-climb-stays-in-administration --strict`, `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16).
