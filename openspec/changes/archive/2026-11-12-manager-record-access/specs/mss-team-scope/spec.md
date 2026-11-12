# mss-team-scope

## ADDED Requirements

### Requirement: A manager and their deputies SHALL read their team's records through stamped access fields (REQ-MSS-009)

`Employee`, `SickLeaveCase`, `LeaveTransaction` and `CompAdjustment` SHALL carry
`managerUserId` and `deputyUserIds`. Each schema's authorization SHALL let the account in
`managerUserId`, and every account in `deputyUserIds`, read the record. On `LeaveTransaction`
they SHALL also update it, so the manager or the deputy can decide the trade. No other verb
SHALL open to them.

The fields SHALL be stamped on every create and update: `managerUserId` is the one manager
`OrgResolutionService` resolves today for the record's employee (an `Employee` record is its
own employee), or null; `deputyUserIds` holds the deputies of the `ManagerDeputy` records
active today for that manager, without the employee themself. Values sent with the record SHALL
be replaced. When the resolution fails, an update SHALL keep the stored stamps.

A pass SHALL restamp every stored record whose stamps differ, and write nothing else. It SHALL
run as a repair step after the register import until one pass finishes without failures, every
six hours, and once after an `OrgAssignment`, `OrgUnit` or `ManagerDeputy` is saved or deleted.

#### Scenario: The team manager reads a team member's sickness case

@e2e exclude Access is enforced by OpenRegister on stamped fields with no screen of its own; covered by ManagerAccessStampsTest::testTheTeamManagerIsStampedAndReadsEveryKindOfRecord
- **GIVEN** Sam is placed in a unit Mila manages
- **WHEN** a sickness case for Sam is saved
- **THEN** it carries `managerUserId` mila
- **AND** Mila may read it

#### Scenario: The manager of another team is refused

@e2e exclude Access is enforced by OpenRegister on stamped fields with no screen of its own; covered by ManagerAccessStampsTest::testTheManagerOfAnotherTeamIsRefused
- **GIVEN** Kees manages another unit
- **WHEN** Kees reads Sam's sickness case or employee record
- **THEN** no rule admits him

#### Scenario: The deputy reads and decides the queue during their period

@e2e exclude Access is enforced by OpenRegister on stamped fields with no screen of its own; covered by ManagerAccessStampsTest::testTheDeputyReadsAndDecidesTheQueueOnlyDuringTheirPeriod
- **GIVEN** Dirk stands in for Mila from 14 July to 1 August
- **WHEN** Sam's leave trade is stamped on 20 July
- **THEN** `deputyUserIds` holds dirk, and Dirk may read and update it
- **AND** after the pass on 2 August Dirk is no longer stamped

#### Scenario: A forged stamp is replaced

@e2e exclude Access is enforced by OpenRegister on stamped fields with no screen of its own; covered by ManagerAccessStampListenerTest::testACreatedRecordIsStampedAndAForgedStampIsReplaced
- **GIVEN** an employee sends their own account as `managerUserId`
- **WHEN** the record is saved
- **THEN** the stamp is the manager the org resolution gives
