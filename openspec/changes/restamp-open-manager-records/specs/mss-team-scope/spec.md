# mss-team-scope

## ADDED Requirements

### Requirement: Open records SHALL be restamped with the manager the shared resolution gives today (REQ-MSS-007)

A repair pass SHALL rewrite the `managerUserId` stamp of open records whose stamp differs
from what `OrgResolutionService` resolves for the record's own date, reduced to one manager
or null exactly as a new stamp is. Open records are change requests with status `ingediend`,
relations cases whose status is not `afgesloten`, side activities with status `gemeld`, and
commuting arrangements and travel claims (`category: travel`) with status `submitted`. A
record without a status takes its schema's default status.

The record's own date is the relations case's `openedOn`, the arrangement's `startDate`, the
claim's `expenseDate`, and otherwise the record's creation date. A record without a usable
date is resolved for today.

The pass SHALL write only when the stamp differs, so a second run changes nothing. It SHALL
leave a record without a stamp, or without an employee, as it is. It SHALL save outside the
pre-save listeners, so nothing else on the record is recalculated, and log the record id,
the old stamp and the new one for each change.

The pass SHALL run as a repair step after `MigrateHoursProcess`, on upgrade, install and
`occ maintenance:repair`. `occ humaniq:manager:restamp` SHALL run the same pass, and with
`--dry-run` count per schema what it would change without writing.

#### Scenario: A self stamp on an open record goes to the manager above

@e2e exclude A repair step and an occ command with no screen of their own; covered by ManagerStampRepairTest::testASelfStampOnAnOpenRecordIsRestamped
- **GIVEN** Sanne manages Consultancy and is placed in it, and Pieter manages Directie above it
- **AND** Sanne's change request awaiting a decision carries `managerUserId: "sanne"`
- **WHEN** the repair pass runs
- **THEN** the request carries `managerUserId: "pieter"`, and the change is logged with the
  request id, `sanne` and `pieter`

#### Scenario: Decided and closed records keep their stamp

@e2e exclude A repair step with no screen of its own; covered by ManagerStampRepairTest::testClosedAndDecidedRecordsKeepTheirStamp
- **GIVEN** an approved change request, a closed relations case, an approved arrangement and a
  hotel claim, each stamped with the employee's own account
- **WHEN** the repair pass runs
- **THEN** none of them is written

#### Scenario: A stamp that no longer resolves is cleared

@e2e exclude A repair step with no screen of its own; covered by ManagerStampRepairTest::testAStampThatNoLongerResolvesIsCleared
- **GIVEN** Sanne's open change request carries `managerUserId: "kees"`
- **AND** the manager above Sanne is in another administration, so no manager resolves
- **WHEN** the repair pass runs
- **THEN** the request carries `managerUserId: null`

#### Scenario: Each record is resolved for its own date

@e2e exclude A repair step with no screen of its own; covered by ManagerStampRepairTest::testEachRecordIsResolvedForItsOwnDate
- **GIVEN** Tim sat in Support, managed by Kees, until 31 May, and in Consultancy since 1 June
- **AND** Tim's relations case was opened on 15 May and carries his own account
- **WHEN** the repair pass runs
- **THEN** the case carries `managerUserId: "kees"`

#### Scenario: A dry run writes nothing and a second run changes nothing

@e2e exclude An occ command with no screen of its own; covered by ManagerStampRepairTest::testADryRunWritesNothing, ManagerStampRepairTest::testASecondRunChangesNothing and ManagerRestampCommandTest
- **GIVEN** five open records with a self stamp
- **WHEN** `occ humaniq:manager:restamp --dry-run` runs
- **THEN** it prints per schema that one record would change, and nothing is written
- **AND** after a run without `--dry-run`, a second run changes nothing
