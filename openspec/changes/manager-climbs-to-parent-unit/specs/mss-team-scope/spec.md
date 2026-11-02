# mss-team-scope

## MODIFIED Requirements

### Requirement: The approval-carrying schemas SHALL gain an optional denormalized `managerUserId` scoping property (REQ-MSS-001)

`Timesheet` (`lib/Settings/register.d/hr-timesheet.json`), `Expense` (`hr-expense.json`) and
`LeaveRequest` (`hr-leave.json`) SHALL each declare `managerUserId`: string, nullable, NOT in
`required`, NEVER a `$ref`. It names a Nextcloud account, like `userId` and `approvedBy`.
Manager assignment truth lives ONLY on the org structure: `OrgAssignment`, `OrgUnit.managerId`
and `OrgUnit.parentUnitId`. `managerUserId` is a server-maintained cache of that chain. It
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
the walk up `parentUnitId` past a self-managed unit. Its inputs are the
`context['related']['OrgAssignment']['byEmployeeId']` index (lists of `{orgUnitId, endDate}`),
the OrgUnit index (with `managerId` and `parentUnitId`) and the Employee index (with
`nextcloudUserId`).

It **violates** only when the record carries a non-empty `managerUserId`, at least one
placement resolves a manager, and NO resolved manager equals `managerUserId`. It is
**vacuous** (passes) when `managerUserId` is absent or empty, `employeeId` is empty, no active
assignment exists, or no placement resolves a manager. The rule reports provable inconsistency
and never punishes absent org data. `RuleAuditService::buildRelatedContext()` SHALL build these
indexes in its single pre-pass and degrade to empty indexes like the others. Unit tests SHALL
pin: the consistent path, the mismatch violation, each vacuous hop, the any-match pass over
several active assignments, a self stamp under a parent manager, and the extended indexes
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

#### Scenario: Vacuous when org data is absent

@e2e exclude A command-line audit with no screen of its own; covered by NlOrgChecksTest
- **GIVEN** a record with `managerUserId: "admin"` whose employee has no active OrgAssignment
  (or whose unit has no manager, or whose manager has no `nextcloudUserId`, or whose own
  units up to the root are all managed by the employee)
- **WHEN** the audit runs
- **THEN** no violation is reported for it

#### Scenario: Unstamped record is never evaluated

@e2e exclude A command-line audit with no screen of its own; covered by NlOrgChecksTest
- **GIVEN** the seeded approved/rejected records that carry no `managerUserId`
- **WHEN** the audit runs
- **THEN** no `nl-mss-manager-consistency` violation is reported for them

### Requirement: Seed data SHALL demo a populated team queue that the consistency rule confirms (REQ-MSS-006)

`lib/Settings/register.d/hr-seed.json` SHALL stamp `managerUserId: "admin"` on exactly the three
existing submitted-status seeds (`timesheet-jansen-2026-05`, `expense-jansen-hotel`,
`leave-jansen-zomer`, the ones already carrying `userId: "admin"`), leaving
approved/rejected/reimbursed seeds unstamped (they demo the vacuous path). It SHALL point
`orgunit-consultancy.managerId` at `employee-jansen`. The org structure (3 units, 3
assignments), the nullable-manager path (backoffice) and the deliberately date-inconsistent
bakker assignment all stay intact. NO seeded `nl-mss-manager-consistency` violation: a wrong
seed stamp would corrupt the team-queue demo just to light a recommended lamp, and unit tests
pin the violating path. All identifiers remain obvious placeholders.

employee-jansen manages both Consultancy and its parent Directie, so under REQ-MSS-001 no
manager resolves for jansen. The three stamped seeds therefore pass the audit vacuously, and a
server-side write of `timesheet-jansen-2026-05` clears its stamp. A seed that demos a team
queue of another employee's records is follow-up work.

#### Scenario: Seeded audit stays green for the new rule

@e2e exclude A command-line audit over seed data with no screen of its own; covered by NlOrgChecksTest and the seed reference validator
- **GIVEN** the seeded data
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** zero `nl-mss-manager-consistency` violations are reported, and no pre-existing check
  regresses

#### Scenario: Idempotent seed

@e2e exclude A repair-step import with no screen of its own; covered by the register import tests
- **WHEN** the register Repair import runs twice
- **THEN** the stamped records and the unit exist exactly once with the seeded values
