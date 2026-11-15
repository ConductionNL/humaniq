# mss-team-scope

## ADDED Requirements

### Requirement: A placement date that cannot be read SHALL count as not active (REQ-MSS-008)

`OrgResolutionService::isActiveOn` SHALL treat an `OrgAssignment` whose `startDate` or
`endDate` is set but cannot be read as not active. A missing or empty date SHALL stay
no bound. Every manager, cost centre and approval resolved through the shared
resolution SHALL follow from this, so no approval is routed through a placement whose
period nobody can read.

#### Scenario: An unreadable start date routes no approval
@e2e exclude needs an OrgAssignment row a form would not save; covered by OrgResolutionServiceTest::testAPlacementWithAnUnreadableDateRoutesNoApproval
- **GIVEN** an employee whose only placement has the start date "sinds kort"
- **WHEN** their manager or cost centre is resolved
- **THEN** none is resolved, and the record waits for HR instead of reaching a manager

#### Scenario: A missing date is still no bound
@e2e exclude a pure date rule; covered by OrgResolutionServiceTest::testAnUnreadablePlacementDateCountsAsNotActive
- **GIVEN** a placement without an end date
- **WHEN** it is checked for today
- **THEN** it is active
