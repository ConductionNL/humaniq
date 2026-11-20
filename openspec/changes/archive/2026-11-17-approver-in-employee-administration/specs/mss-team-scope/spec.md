# mss-team-scope

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
to it is inert. For `Expense` and `LeaveRequest` the property, its filter role and its audit
are unchanged: they stay HR/back-office-populated until their own process redesigns. Each
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

@e2e exclude Plain-string persistence contract on a schema this change does not redesign (Expense); backend round-trip verified by the existing register import tests
- **WHEN** an Expense is written with `managerUserId: "admin"`
- **THEN** the value persists and is returned as a plain string (no reference resolution)

#### Scenario: The Timesheet cache follows the org structure, not the client

@e2e exclude Stamping is a backend write-path behaviour; verified by TimesheetProcessStampListener and OrgResolutionService unit tests (the team-queue UI consequence is covered by the existing TeamUrengoedkeuring filter scenario)
- **GIVEN** an employee whose active `OrgAssignment` unit's manager resolves to
  `nextcloudUserId` "admin"
- **WHEN** any write persists that employee's Timesheet, even one supplying
  `managerUserId: "someone-else"`
- **THEN** the persisted `managerUserId` is "admin": the org chain wins, client input is inert

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

### Requirement: `NlOrgChecks` SHALL enforce manager consistency via consistently-extended related-context indexes (REQ-MSS-005)

`NlOrgChecks` SHALL be extended (not a new provider: the rule is an `hr-org-core` org-integrity
control consuming the provider's own OrgUnit index, and `RuleEngine::checks()` merges providers
additively per object type) with one shared predicate registered under the three object types
`Timesheet`, `Expense` and `LeaveRequest`, keyed `nl-mss-manager-consistency`. The predicate is
pure: `fn(array $o, array $c): bool`. It resolves the record's employee to the managers of their
active placements through `OrgResolutionService`, exactly as REQ-MSS-001 describes, including
the walk up `parentUnitId` past a self-managed unit, its stop at the administration
boundary, and the check of the manager's own administration. Its inputs are the `context['related']['OrgAssignment']['byEmployeeId']` index
(lists of `{orgUnitId, endDate}`), the OrgUnit index (with `managerId`, `parentUnitId` and
`administrationId`) and the Employee index (with `nextcloudUserId` and `administrationId`). Both
indexes SHALL carry `administrationId`, so the audit stops the walk where the stamp does.

It **violates** only when the record carries a non-empty `managerUserId`, at least one
placement resolves a manager, and NO resolved manager equals `managerUserId`. It is
**vacuous** (passes) when `managerUserId` is absent or empty, `employeeId` is empty, no active
assignment exists, or no placement resolves a manager. The rule reports provable inconsistency
and never punishes absent org data. `RuleAuditService::buildRelatedContext()` SHALL build these
indexes in its single pre-pass and degrade to empty indexes like the others. Unit tests SHALL
pin: the consistent path, the mismatch violation, each vacuous hop, the any-match pass over
several active assignments, a self stamp under a parent manager, a parent manager in another
administration, a manager whose own Employee record is in another administration, and the
extended indexes
through `RuleAuditService::audit()` with a fake ObjectService double.

#### Scenario: Mismatching stamp flagged

@e2e exclude A command-line audit with no screen of its own; covered by NlOrgChecksTest
- **GIVEN** a submitted Timesheet with `managerUserId: "someone-else"` whose employee's active
  assignment resolves to a unit managed by an Employee with `nextcloudUserId: "admin"`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** a `nl-mss-manager-consistency` violation with recommended severity is reported for
  that timesheet

#### Scenario: Matching stamp passes

@e2e exclude A command-line audit with no screen of its own; covered by NlOrgChecksTest
- **GIVEN** a Timesheet with `managerUserId: "admin"` whose employee is actively placed in a
  unit managed by another employee with `nextcloudUserId: "admin"`
- **WHEN** the audit runs
- **THEN** no `nl-mss-manager-consistency` violation is reported for it

#### Scenario: A stamp naming the employee is flagged when a manager above exists

@e2e exclude A command-line audit with no screen of its own; covered by NlOrgChecksTest::testASelfStampIsFlaggedWhenAParentManagerExists
- **GIVEN** Sanne manages Consultancy and is placed in it, and Directie above it is managed by
  Pieter with account `pieter`
- **AND** Sanne's Expense carries `managerUserId` set to her own account
- **WHEN** the audit runs
- **THEN** a `nl-mss-manager-consistency` violation is reported for that expense

#### Scenario: A manager above in another administration confirms and flags nothing

@e2e exclude A command-line audit with no screen of its own; covered by NlOrgChecksTest::testAParentManagerInAnotherAdministrationResolvesNoManager and RuleAuditServiceTest::testTheAuditClimbStopsAtTheAdministrationBoundary
- **GIVEN** Sanne manages Consultancy in `ADM-1` and is placed in it, and Directie above it is
  in `ADM-2`, managed by Pieter with account `pieter`
- **AND** Sanne's Timesheet carries `managerUserId` set to her own account
- **WHEN** the audit runs
- **THEN** no `nl-mss-manager-consistency` violation is reported for it, because no manager
  resolves

#### Scenario: A manager whose own record is in another administration confirms nothing

@e2e exclude A command-line audit with no screen of its own; covered by NlOrgChecksTest::testAManagerOutsideTheEmployeeAdministrationResolvesNoManager and RuleAuditServiceTest::testTheAuditChecksTheManagersOwnAdministration
- **GIVEN** Sanne manages Consultancy in `ADM-1` and is placed in it, and Directie above it is
  in `ADM-1`, managed by Pieter whose Employee record is in `ADM-2`
- **AND** Sanne's Timesheet carries `managerUserId` set to her own account
- **WHEN** the audit runs
- **THEN** no `nl-mss-manager-consistency` violation is reported for it, because no manager
  resolves

#### Scenario: Vacuous when org data is absent

@e2e exclude A command-line audit with no screen of its own; covered by NlOrgChecksTest
- **GIVEN** a record with `managerUserId: "admin"` whose employee has no active OrgAssignment
  (or whose unit has no manager, or whose manager has no `nextcloudUserId`, or whose own
  units up to the root, or up to the administration boundary, are all managed by the employee)
- **WHEN** the audit runs
- **THEN** no violation is reported for it

#### Scenario: Unstamped record is never evaluated

@e2e exclude A command-line audit with no screen of its own; covered by NlOrgChecksTest
- **GIVEN** the seeded approved/rejected records that carry no `managerUserId`
- **WHEN** the audit runs
- **THEN** no `nl-mss-manager-consistency` violation is reported for them
