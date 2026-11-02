## 1. Resolution

- [ ] 1.1 `OrgResolutionService::resolveManagerUserIds()` climbs `parentUnitId` past a self-managed unit, with a visited set against loops. Verify: OrgResolutionServiceTest cases for one level, several levels, no other manager up to the root, a loop, and the same account on another Employee record.
- [ ] 1.2 Every caller that resolves an employee's manager goes through that method. Verify: grep `managerId` across `lib/`; only `OrgResolutionService` reads a unit's manager for an employee.

## 2. Callers

- [ ] 2.1 The `nl-mss-manager-consistency` audit flags a stamp that names the employee when a manager above exists. Verify: NlOrgChecksTest.
- [ ] 2.2 The learning people feed names the manager above a unit manager. Verify: LearningPeopleFeedTest.
- [ ] 2.3 The stamp (`HoursRegisterGateway::uniqueManagerUserIdFor`) and the relations case notification follow. Verify: HoursRegisterGatewayTest and CaseManagerResolverTest.

## 3. Verification

- [ ] 3.1 The new tests fail on development and pass on the branch. Capped mutants with a no-op control.
- [ ] 3.2 `openspec validate manager-climbs-to-parent-unit --strict`, `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16), `hydra/scripts/diff-check.sh` green.
