# mss-team-scope

## ADDED Requirements

### Requirement: Open records SHALL be restamped with the manager the shared resolution gives today (REQ-MSS-007)

A repair pass SHALL rewrite the `managerUserId` stamp of open records whose stamp differs
from what `OrgResolutionService` resolves for the record's own date, reduced to one manager
or null exactly as a new stamp is. Open records are change requests with status `ingediend`,
relations cases whose status is not `afgesloten`, side activities with status `gemeld`, and
commuting arrangements and expense claims of every category with status `submitted`. A
record without a status takes its schema's default status.

The record's own date is the relations case's `openedOn`, the arrangement's `startDate`, the
claim's `expenseDate`, and otherwise the record's creation date. A record without a usable
date is resolved for today.

The pass SHALL write only when the stamp differs, so a second run changes nothing. It SHALL
leave a record without a stamp, or without an employee, as it is. It SHALL save outside the
pre-save listeners, so nothing else on the record is recalculated, and log the record id,
the old stamp and the new one for each change. A dry run SHALL log what would change without
saying it was restamped.

The pass SHALL read every page of every schema it restamps and of the org lookups, and read
the org lookups once per run. When a read stopped before the end, it SHALL count that schema
as failed. When an org lookup read stopped before the end, it SHALL NOT rewrite any stamp:
every record whose stamp would change keeps it and counts as failed.

A record that cannot be resolved or saved SHALL be logged with its schema, id and error, and
counted as failed. The pass SHALL carry on with the next record.

The pass SHALL run as a repair step after `MigrateHoursProcess`, on upgrade, install and
`occ maintenance:repair`, until one pass finishes without failures. It then stores the done
marker `manager_restamp_done` in the app config, and the step skips every later run.
`occ humaniq:manager:restamp` SHALL run the same pass whatever the marker says, and with
`--dry-run` count per schema what it would change without writing. It SHALL exit 1 when a
record failed.

#### Scenario: A self stamp on an open record goes to the manager above

@e2e exclude A repair step and an occ command with no screen of their own; covered by ManagerStampRepairTest::testASelfStampOnAnOpenRecordIsRestamped
- **GIVEN** Sanne manages Consultancy and is placed in it, and Pieter manages Directie above it
- **AND** Sanne's change request awaiting a decision carries `managerUserId: "sanne"`
- **WHEN** the repair pass runs
- **THEN** the request carries `managerUserId: "pieter"`, and the change is logged with the
  request id, `sanne` and `pieter`

#### Scenario: Decided and closed records keep their stamp

@e2e exclude A repair step with no screen of its own; covered by ManagerStampRepairTest::testClosedAndDecidedRecordsKeepTheirStamp
- **GIVEN** an approved change request, a closed relations case, an approved arrangement, a
  draft meals claim and a reimbursed training claim, each stamped with the employee's own account
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

#### Scenario: Open expense claims of every category are restamped

@e2e exclude A repair step with no screen of its own; covered by ManagerStampRepairTest::testOpenClaimsOfEveryCategoryAreRestamped
- **GIVEN** Sanne's submitted claims for travel, accommodation, meals, supplies, software,
  training and other costs, each stamped with her own account
- **WHEN** the repair pass runs
- **THEN** each claim carries `managerUserId: "pieter"`

#### Scenario: One failing record does not stop the pass

@e2e exclude A repair step and an occ command with no screen of their own; covered by ManagerStampRepairTest::testAFailingRecordIsCountedAndThePassCarriesOn and ManagerRestampCommandTest::testAFailedRecordExitsOne
- **GIVEN** two open change requests with a self stamp, and the first cannot be saved
- **WHEN** the repair pass runs
- **THEN** the second request and the records of every other schema are restamped
- **AND** the failure is logged with the schema, the id and the error, and counted as failed
- **AND** `occ humaniq:manager:restamp` exits 1

#### Scenario: Records past the first 500 are restamped

@e2e exclude A repair step with no screen of its own; covered by ManagerStampRepairTest::testMoreThan500RowsAreAllRead
- **GIVEN** 600 reimbursed claims, then one submitted claim with a self stamp
- **AND** Sanne's placement is the 601st assignment
- **WHEN** the repair pass runs
- **THEN** the submitted claim carries `managerUserId: "pieter"`

#### Scenario: No stamp is rewritten when a lookup read was cut off

@e2e exclude A repair step with no screen of its own; covered by ManagerStampRepairTest::testNoStampIsRewrittenWhenALookupWasCutOff
- **GIVEN** the assignment read stops before Tim's placement
- **AND** Tim's open change request carries `managerUserId: "kees"`, and Sanne's carries her own account
- **WHEN** the repair pass runs
- **THEN** both requests keep their stamp, and both count as failed

#### Scenario: The repair step runs once

@e2e exclude A repair step with no screen of its own; covered by RestampOpenManagerRecordsTest::testACleanPassSetsTheDoneMarker, testAPassWithFailuresLeavesTheMarkerUnset and testTheStepRunsOnlyOnce
- **GIVEN** a repair pass that finished without failures
- **WHEN** a later upgrade runs the repair steps
- **THEN** the restamp step does not run the pass again
- **AND** after a pass with a failure, the next upgrade runs it again

## MODIFIED Requirements

### Requirement: The approval-carrying schemas SHALL gain an optional denormalized `managerUserId` scoping property (REQ-MSS-001)

`Timesheet` (`lib/Settings/register.d/hr-timesheet.json`), `Expense` (`hr-expense.json`) and
`LeaveRequest` (`hr-leave.json`) SHALL each declare `managerUserId`: string, nullable, NOT in
`required`, NEVER a `$ref`. It names a Nextcloud account, like `userId` and `approvedBy`.
Manager assignment truth lives ONLY on the org structure: `OrgAssignment`, `OrgUnit.managerId`,
`OrgUnit.parentUnitId` and `OrgUnit.administrationId`. `managerUserId` is a server-maintained cache of that chain. It
exists because the manifest filter grammar cannot join across schemas for the `@me` team-queue
filters.

The shared `OrgResolutionService` SHALL resolve an employee's manager per active placement:

1. Read the manager of the placement's unit.
2. When that manager is the employee themself, read the manager of the unit's parent
   (`parentUnitId`) instead. Repeat until the manager is a different person. The employee
   themself is the same Employee record, or another Employee record with the same Nextcloud
   account.
3. The placement resolves no manager when the walk reaches a unit without a parent, a parent
   that does not resolve, or a unit it already visited (a loop in the unit tree).
4. An unmanaged unit, a manager Employee that does not resolve, and a manager without a
   Nextcloud account also resolve no manager for that placement. Only a self-managed unit
   makes the walk climb.
5. The walk never leaves the boundary administration: the `administrationId` of the
   placement's unit, or the employee's own `administrationId` when that unit has none. A unit
   above the placement unit in another administration, or without an administration, ends
   the walk and the placement resolves no manager. Only when neither the placement unit nor
   the employee has an administration is there no boundary.
6. A placement in a unit of another administration than the employee's own
   `administrationId` resolves no manager. This applies only when both are set.
7. The manager the walk ends at is the employee's manager only when that manager's own
   Employee `administrationId` equals the boundary administration. A manager in another
   administration, or without one, ends the walk and the placement resolves no manager. This
   holds for the placement unit's own manager and for a manager above it. Without a boundary
   the manager's administration is not checked.

The approver belongs to the employee's administration because the stamp grants the manager
access: read access to relations cases and the decision on change requests. A manager in
another administration has no part in the employee's records. Both the unit and the manager's
own Employee record must be inside that administration.

The walk climbs because an employee may not approve their own timesheet (separation of duties
in `humaniq-timesheet-approval`). A self-resolved manager would put the employee's records in a
queue nobody may act on. Every caller that needs an employee's manager SHALL use this
resolution, so the stamps, the approvals inbox fallback, notifications, the audit and the
learning feed cannot disagree.

For `Timesheet`, the cache SHALL be stamped server-side on every write by the process stamping
listener through `OrgResolutionService`, the same code path the `nl-mss-manager-consistency`
audit (`NlOrgChecks`) evaluates. A stamp is written only when the placements resolve exactly
one distinct manager; otherwise it is null. It SHALL never appear on any form, and client input
to it is inert. For `Expense`, the cache SHALL be stamped server-side the same way on every write of a claim
that is not approved or reimbursed, resolved for the claim's `expenseDate`, else today;
humaniq's own writes leave it as it is. For `LeaveRequest` the property, its filter role and
its audit are unchanged: it remains HR/back-office-populated until its own process redesign. Each
property description SHALL be user-oriented ("The team manager who reviews this record, kept
up to date automatically from the organisation structure" for Timesheet), with the
denormalization rationale in `x-notes`.

#### Scenario: Record without a manager stamp stays valid

@e2e exclude Register-level validation of an optional nullable property; no UI journey exists for creating an org-managerless employee's timesheet; verified by the stamping listener unit tests (null path) and the register import
- **GIVEN** the imported hrmq register after the schema bumps
- **WHEN** a Timesheet is created for an employee with no resolvable org manager
- **THEN** creation succeeds and `managerUserId` is `null` (fail-closed: it appears in no team
  queue)

#### Scenario: Stamped record round-trips

@e2e exclude Plain-string persistence contract; backend round-trip verified by the existing register import tests
- **WHEN** a LeaveRequest is written with `managerUserId: "admin"`
- **THEN** the value persists and is returned as a plain string (no reference resolution)

#### Scenario: The Timesheet cache follows the org structure, not the client

@e2e exclude Stamping is a backend write-path behaviour; verified by TimesheetProcessStampListener and OrgResolutionService unit tests (the team-queue UI consequence is covered by the existing TeamUrengoedkeuring filter scenario)
- **GIVEN** an employee whose active `OrgAssignment` unit's manager resolves to
  `nextcloudUserId` "admin"
- **WHEN** any write persists that employee's Timesheet, even one supplying
  `managerUserId: "someone-else"`
- **THEN** the persisted `managerUserId` is "admin": the org chain wins, client input is inert

#### Scenario: The Expense cache follows the org structure, not the client

@e2e exclude Stamping is a backend write-path behaviour; verified by ExpenseManagerStampListenerTest::testAClaimOfEveryCategoryGetsTheResolvedApprover
- **GIVEN** an employee whose manager resolves to `nextcloudUserId` "sanne"
- **WHEN** the employee saves a draft claim supplying `managerUserId: "tim"`
- **THEN** the persisted `managerUserId` is "sanne"

#### Scenario: A manager placed in their own unit goes to the parent unit's manager

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testASelfManagedPlacementClimbsToTheParentUnitManager and HoursRegisterGatewayTest::testTheStampOfAUnitManagerNamesTheManagerAboveThem
- **GIVEN** Sanne manages Consultancy and is placed in Consultancy
- **AND** Consultancy sits under Directie, managed by Pieter with account `pieter`
- **WHEN** Sanne's manager is resolved
- **THEN** the manager is `pieter`, and Sanne's timesheet is stamped `managerUserId: "pieter"`

#### Scenario: The walk climbs more than one level

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testTheClimbRepeatsOverSeveralLevels and OrgResolutionServiceTest::testTheSameAccountOnAnotherEmployeeRecordCountsAsSelf
- **GIVEN** Sanne manages Team Noord and its parent Consultancy, and is placed in Team Noord
- **AND** Consultancy sits under Directie, managed by Pieter with account `pieter`
- **WHEN** Sanne's manager is resolved
- **THEN** the manager is `pieter`

#### Scenario: Nobody else manages a unit up to the root

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testNoOtherManagerUpToTheRootResolvesNothing
- **GIVEN** Sanne manages Consultancy and its parent Directie, and Directie has no parent
- **WHEN** Sanne's manager is resolved
- **THEN** no manager resolves, her timesheet carries `managerUserId: null`, and it shows in no
  team queue

#### Scenario: A parent unit in another administration gives no manager

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testTheClimbStopsAtTheAdministrationBoundary and HoursRegisterGatewayTest::testTheStampDoesNotClimbIntoAnotherAdministration
- **GIVEN** Sanne manages Consultancy and is placed in it, and Consultancy is in administration
  `ADM-1`
- **AND** Consultancy sits under Directie in administration `ADM-2`, managed by Pieter with
  account `pieter`
- **WHEN** Sanne's manager is resolved
- **THEN** no manager resolves, and her timesheet carries `managerUserId: null`

#### Scenario: A parent unit without an administration gives no manager

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testTheClimbStopsAtTheAdministrationBoundary
- **GIVEN** Sanne manages Consultancy in administration `ADM-1` and is placed in it
- **AND** Consultancy sits under Directie, which has no administration and is managed by Pieter
- **WHEN** Sanne's manager is resolved
- **THEN** no manager resolves

#### Scenario: A placement unit without an administration uses the employee's

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testAPlacementUnitWithoutAnAdministrationUsesTheEmployeeAdministration and HoursRegisterGatewayTest::testTheStampUsesTheEmployeeAdministrationWhenTheUnitHasNone
- **GIVEN** Sanne's Employee record is in `ADM-1`, and she manages Consultancy and is placed in it
- **AND** Consultancy has no administration and sits under Directie in `ADM-2`, managed by
  Pieter
- **WHEN** Sanne's manager is resolved
- **THEN** no manager resolves, and a team member placed in Consultancy still resolves Sanne

#### Scenario: A manager above in another administration gives no manager

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testAManagerAboveInAnotherAdministrationGivesNoManager and HoursRegisterGatewayTest::testTheStampNamesOnlyAManagerInTheEmployeeAdministration
- **GIVEN** Sanne manages Consultancy in `ADM-1` and is placed in it
- **AND** Consultancy sits under Directie, also in `ADM-1`, managed by Pieter
- **AND** Pieter's own Employee record is in `ADM-2`, or has no administration
- **WHEN** Sanne's manager is resolved
- **THEN** no manager resolves, and her timesheet carries `managerUserId: null`

#### Scenario: A unit's own manager in another administration gives no manager

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testADirectManagerInAnotherAdministrationGivesNoManager
- **GIVEN** Tim's Employee record is in `ADM-1`, and he is placed in Consultancy in `ADM-1`
- **AND** Consultancy is managed by Sanne, whose Employee record is in `ADM-2`
- **WHEN** Tim's manager is resolved
- **THEN** no manager resolves

#### Scenario: A placement in a unit of another administration gives no manager

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testAPlacementInAnotherAdministrationsUnitGivesNoManager and HoursRegisterGatewayTest::testTheStampNamesOnlyAManagerInTheEmployeeAdministration
- **GIVEN** Tim's Employee record is in `ADM-1`
- **AND** Tim is placed in Extern, a unit in `ADM-2` managed by Pieter in `ADM-2`
- **WHEN** Tim's manager is resolved
- **THEN** that placement resolves no manager
- **AND** when Tim also has a placement in Consultancy in `ADM-1`, managed by Sanne in
  `ADM-1`, his manager is `sanne`

#### Scenario: The boundary holds at every level

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testTheAdministrationBoundaryHoldsOverSeveralLevels
- **GIVEN** Sanne manages Team Noord and Consultancy, and is placed in Team Noord in `ADM-1`
- **AND** Consultancy is in `ADM-2`, and Directie above it is in `ADM-1`, managed by Pieter
- **WHEN** Sanne's manager is resolved
- **THEN** the walk ends at Consultancy and no manager resolves

#### Scenario: A loop in the unit tree ends the walk

@e2e exclude Resolution over the org indexes with no screen of its own; covered by OrgResolutionServiceTest::testALoopInTheUnitTreeEndsTheClimb
- **GIVEN** Sanne manages Consultancy and Directie
- **AND** Consultancy's parent is Directie and Directie's parent is Consultancy
- **WHEN** Sanne's manager is resolved
- **THEN** the walk visits each unit once and no manager resolves

