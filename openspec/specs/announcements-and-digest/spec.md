# announcements-and-digest Specification

## Purpose
Announcements and policies HR publishes to an audience, with read confirmation, and a daily away-and-birthday message to a team's Talk conversation. Built by self-service-announcements-and-digest (archived 2026-09-30).

## Requirements

### Requirement: HR SHALL publish announcements and policies to an audience (REQ-AND-001)

HR SHALL be able to publish an announcement with an optional policy document to the whole
administration or to chosen org units for a period. Employees in the audience SHALL see it on
`MijnHr` and on `MijnMededelingen`; others SHALL NOT.

Rows: `ess-announcements` (humaniq matrix).

#### Scenario: A new expense policy reaches everyone
- **GIVEN** HR publishes "Nieuwe declaratieregeling 2027" to the whole administration with the
  policy attached
- **WHEN** an employee opens `MijnHr`
- **THEN** the announcement and its policy are shown

@e2e exclude the audience match is a backend read; covered by AnnouncementServiceTest::testAnEmployeeSeesWhatIsPublishedToThemToday and AnnouncementControllerTest::testMineListsTheCallersAnnouncements

#### Scenario: A team announcement stays in the team
- **GIVEN** an announcement for Team Burgerzaken only
- **WHEN** an employee of another team opens `MijnMededelingen`
- **THEN** it is not listed

@e2e exclude the audience match is a backend read; covered by AnnouncementServiceTest::testAnEmployeeSeesWhatIsPublishedToThemToday (Financien does not see the Burgerzaken announcement)

### Requirement: Policies SHALL be confirmable and HR SHALL see who confirmed (REQ-AND-002)

For an announcement that requires it, each employee in the audience SHALL be able to confirm once
that they read it, and HR SHALL see per announcement who confirmed and who did not.

Rows: `ess-announcements` (humaniq matrix).

#### Scenario: HR follows up on unread policies
- **GIVEN** a policy that requires confirmation, confirmed by 40 of 55 employees
- **WHEN** HR opens the announcement
- **THEN** it lists the 15 who have not confirmed

@e2e exclude the overview is a backend read; covered by AnnouncementServiceTest::testHrSeesWhoHasNotConfirmed, AnnouncementServiceTest::testAnEmployeeConfirmsOnce and AnnouncementControllerTest::testOnlyHrSeesTheConfirmations

### Requirement: A team SHALL be able to receive a daily away-and-birthday message in Talk (REQ-AND-003)

humaniq SHALL ship a flow, disabled until adopted, that each working morning posts to a chosen
Talk conversation who in a chosen org unit is away today and until when, without the reason, and
whose birthday it is, only for employees who agreed to share it and without their age.

Rows: `dm-chat-channel-digest` (humaniq matrix).

#### Scenario: The morning message
- **GIVEN** the enabled flow for Team Burgerzaken, one member on approved leave until Friday, one
  off sick, and one with a shared birthday today
- **WHEN** the flow runs at 08:00
- **THEN** the team's Talk conversation receives one message naming the two who are away with
  their return dates where known, and the birthday, and no leave type, reason or age

@e2e exclude the message is composed by a flow node and posted to Talk; covered by TeamDigestNodeTest::testTheMorningMessage and TeamDigestNodeTest::testTheNodeHandsTheMessageToTheTalkStep
