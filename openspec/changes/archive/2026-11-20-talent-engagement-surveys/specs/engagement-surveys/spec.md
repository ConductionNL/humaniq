# engagement-surveys

## ADDED Requirements

### Requirement: HR SHALL build a survey and send it to a group of employees (REQ-SRV-001)

humaniq SHALL provide a `Survey` with its own questions in the manifest field shape, a
scope (everyone, org units or a contract type), open and close dates and a minimum group
size. Opening it SHALL create one invitation per active employee in scope with a Nextcloud
account, notify each through the canonical notification dialect, and remind those who have
not answered in the three days before it closes.

Rows: `tal-surveys` (humaniq matrix).

#### Scenario: A survey goes to two teams
- **GIVEN** a survey scoped to the units Finance and HR with nine employees between them
- **WHEN** an HR adviser opens it
- **THEN** nine invitations exist, each employee is notified, and the survey page shows 0 of
  9 answered

@e2e exclude opening is a server-side step that invites from the register; covered by SurveyServiceTest::testASurveyGoesToTwoTeams and ::testOpeningRules, SurveyControllerTest::testOnlyHrOpensASurvey and SurveyOpenGuardTest::testOnlyOpenSurveyOpensASurvey

### Requirement: An answer SHALL be stored without anything that identifies the employee (REQ-SRV-002)

When an employee answers, humaniq SHALL mark their invitation answered and SHALL store the
response with the survey, the answers, the employee's org unit and contract type and the
date only. The response SHALL NOT hold the employee, their account, the invitation or a time
of day. An employee SHALL answer a survey once.

Rows: `tal-surveys` (humaniq matrix).

#### Scenario: HR cannot find out who wrote what
- **GIVEN** an employee who answered a survey on `Mijn enquetes`
- **WHEN** an HR adviser reads that survey's responses through the objects API
- **THEN** no response names an employee or account, and the invitation shows only that the
  employee answered

@e2e exclude the separation happens server-side in the stored records; covered by SurveyServiceTest::testAnAnswerNamesNobody, which validates the stored response against the real SurveyResponse schema

#### Scenario: One answer per person
- **GIVEN** an employee whose invitation is answered
- **WHEN** they submit again
- **THEN** the second answer is refused

@e2e exclude server-side refusal; covered by SurveyServiceTest::testOneAnswerPerPerson and SurveyControllerTest::testAnsweringPassesTheServiceStatusOn

### Requirement: Results SHALL never show a group smaller than the minimum (REQ-SRV-003)

The results SHALL show per question the distribution, the average for scales and the eNPS
for the recommend question, overall and per org unit. A unit with fewer responses than the
survey's minimum group size SHALL be combined into "other", and "other" SHALL be left out
when it is itself below the minimum. Free-text answers SHALL be shown overall only.

Rows: `tal-surveys` (humaniq matrix).

#### Scenario: A small team stays anonymous
- **GIVEN** a closed survey with minimum group size 5 and responses from Finance (7), HR (5)
  and Legal (2)
- **WHEN** an HR adviser opens the results
- **THEN** Finance and HR are shown on their own, Legal does not appear, and the overall
  figures include all 14 responses

@e2e exclude results are computed server-side on read; covered by SurveyServiceTest::testASmallTeamStaysAnonymous and ::testSmallDepartmentsFoldIntoOther, SurveyControllerTest::testOnlyHrReadsTheResults
