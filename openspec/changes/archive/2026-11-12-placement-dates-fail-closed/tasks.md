## 1. Rule

- [x] 1.1 `isActiveOn` treats a set but unreadable start or end date as not active. Verify: OrgResolutionServiceTest::testAnUnreadablePlacementDateCountsAsNotActive.
- [x] 1.2 Manager and cost-centre resolution route nothing through such a placement. Verify: OrgResolutionServiceTest::testAPlacementWithAnUnreadableDateRoutesNoApproval.

## 2. Verification

- [x] 2.1 Tests written first and red on the old rule.
- [x] 2.2 Capped mutants with a no-op control.
- [x] 2.3 `openspec validate placement-dates-fail-closed --strict`, `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16).
