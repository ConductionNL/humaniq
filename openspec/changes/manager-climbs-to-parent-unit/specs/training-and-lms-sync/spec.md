# training-and-lms-sync

## MODIFIED Requirements

### Requirement: humaniq SHALL publish one minimal people feed for learning platforms (REQ-TRN-002)

`GET /api/learning/people` SHALL return active employees with their name, Nextcloud account,
current org unit and role, managers and employment dates, and SHALL return no other employee
field. The managers are resolved as in mss-team-scope REQ-MSS-001: a unit manager placed in
their own unit gets the manager of a unit above them, never themself. It SHALL accept
`modifiedSince` and return only employees whose record, placement or unit changed after it. It
SHALL return only employees the caller may read.

#### Scenario: A caller only receives the people they may read
- **GIVEN** an integration account that may read the employees of one team
- **WHEN** it reads the feed
- **THEN** it receives those employees and nobody else

@e2e exclude an API feed for an integration account, no page; covered by LearningControllerTest::testACallerOnlyReceivesEmployeesTheyMayRead

Rows: `td-lms-sync` (humaniq matrix), tender https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: A move to another team reaches the learning platform
- **GIVEN** an employee moved from Finance to HR yesterday
- **WHEN** the learning platform's account calls the feed with `modifiedSince` two days ago
- **THEN** the employee is returned with org unit HR and HR's manager, and without BSN or
  salary

@e2e exclude an API feed for an integration account, no page; covered by LearningPeopleFeedTest::testAMoveToAnotherTeamReachesThePlatform and LearningPeopleFeedTest::testARowHoldsExactlyTheFeedFields

#### Scenario: A leaver drops out
- **GIVEN** an employee whose `endDate` passed last week
- **WHEN** the feed is read
- **THEN** the employee is not in it

@e2e exclude an API feed for an integration account, no page; covered by LearningPeopleFeedTest::testALeaverDropsOut

#### Scenario: A unit manager's row names the manager above them
- **GIVEN** Sanne manages HR and is placed in HR, and HR sits under Directie, managed by Pieter
  with account `pieter`
- **WHEN** the feed is read
- **THEN** Sanne's row lists `pieter` as her manager, not her own account

@e2e exclude an API feed for an integration account, no page; covered by LearningPeopleFeedTest::testAUnitManagerRowNamesTheManagerAboveThem
