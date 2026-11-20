---
capability: mss-team-scope
status: done
built_by: openspec/changes/archive/2026-07-13-mss-team-scope
---

# mss-team-scope Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [mss-team-scope](../../changes/archive/2026-07-13-mss-team-scope/) _(archived 2026-07-13)_ — denormalized `managerUserId` on Timesheet/Expense/LeaveRequest (the round-2 `userId` trade-off applied to the manager axis — two-hop filtering does not exist in the manifest token grammar), three `Team*goedkeuring` pages pre-filtered `managerUserId: @me`, Dashboard approver widgets re-scoped to the manager's team with a global HR fallback row, and the recommended-severity `nl-mss-manager-consistency` corpus rule cross-checking every stamp against the org-chart-basic structure (kind: config)
- [hrmq-hours-process-redesign](../../changes/archive/2026-08-22-hrmq-hours-process-redesign/) _(archived 2026-08-22, merged as humaniq#128)_ — turns `Timesheet.managerUserId` into a server-maintained cache stamped from the org chain on every write (the same `OrgResolutionService` the `nl-mss-manager-consistency` audit evaluates), inert to client input; `Expense`/`LeaveRequest` remain HR-populated until their own process redesigns (kind: code)

## Purpose

Give managers a team-scoped self-service surface per ADR-001 Rule 2 (Dashboard
widgets + scoped acties inside existing menus — never a "Manager portaal" and
never a sibling app): team approval queues and Dashboard KPIs filtered to the
records of the manager's own team, mechanically backed by an optional
denormalized `managerUserId` stamp (HR/back-office maintained, because the
manifest filter-token grammar has no two-hop/join form — re-verified at HEAD
against the vendored app-manifest-v2 schema) and kept honest by a
machine-checkable recommended-severity consistency rule that compares each
stamp against the manager of the employee's active `OrgAssignment` unit from
org-chart-basic. Spectr canon: `hrmq-canon-mss-team-scope` (3/9 competitive
coverage).

## Requirements

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

### Requirement: Three team-scoped approval pages SHALL pre-filter the existing approval queues to `managerUserId: @me` (REQ-MSS-002)

`src/manifest.json` SHALL gain three index pages that copy their global approval page's config and add the fixed base filter `{ "managerUserId": "@me" }` (the `MijnUren` base-filter mechanism, composing with the approval pages' `defaultFilters: { "status": "submitted" }` and user-facing `filters`): `TeamUrengoedkeuring` (`/timesheets/team-approval`, Timesheet, mirroring `TimesheetApproval`'s columns/filters/sort), `TeamDeclaratiegoedkeuring` (`/expenses/team-approval`, Expense, mirroring `ExpenseApproval`) and `TeamVerlofgoedkeuring` (`/leave-requests/team-approval`, LeaveRequest, mirroring `LeaveApproval`). Menu placement per ADR-001 Rule 2: one child per existing group (`TimesheetsGroup` / `ExpensesGroup` / `VerlofVerzuimGroup`), each directly after the global approval entry, icon `AccountCheckOutline`, labels `Team-urengoedkeuring` / `Team-declaratiegoedkeuring` / `Team-verlofgoedkeuring`. NO new top-level menu entry and no "Manager portaal". The global approval pages stay byte-identical.

#### Scenario: Team queue shows only my team's submitted records
@e2e exclude declarative page filtering is covered by the shared CnIndexPage/resolveFilterTokens library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** a logged-in `admin` and the seeded records (three submitted seeds stamped `managerUserId: "admin"`)
- **WHEN** `TeamUrengoedkeuring` renders
- **THEN** only submitted timesheets stamped `managerUserId: "admin"` are listed (the base `@me` filter resolves to the current user at fetch time)

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

### Requirement: The Dashboard approver widgets SHALL be re-scoped to the manager's team, with a global fallback row for HR (REQ-MSS-003)

On the existing `Dashboard` page (adjusted in place, not duplicated): `dash-approve-hours` and `dash-approve-expenses` SHALL change their stat `content.source.filter` from the global `{ "status": "submitted" }` to `{ "managerUserId": "@me", "status": "submitted" }`, retitle to name the team scope ("(mijn team)"), and re-route to `TeamUrengoedkeuring` / `TeamDeclaratiegoedkeuring`; a new `dash-team-approve-leave` stat (LeaveRequest, same team filter, route `TeamVerlofgoedkeuring`) SHALL complete the approver trio as a 3 × width-4 row; and a new HR fallback row of two stats (`dash-hr-approve-hours` / `dash-hr-approve-expenses`, 2 × width-6) SHALL restore exactly the two global submitted-counts the re-scope removed, routing to the existing global `TimesheetApproval` / `ExpenseApproval` pages with captions marking "alle medewerkers". No global leave widget is added here (the sibling change `verzuim-analytics-widgets` owns leave/verzuim analytics). All widgets keep the proven `stat` shape (`register`/`schema`/`metric: "count"`/token-resolved `filter`); the `@me`-scoped object-table shifts down; the page `_note` documents the round-3 re-scope and fallback rationale.

#### Scenario: Team widget counts only my team
@e2e exclude declarative widget wiring is covered by the shared CnStatWidget library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** a logged-in `admin` and the seeded data
- **WHEN** the Dashboard renders
- **THEN** "Te beoordelen uren (mijn team)" counts exactly the submitted timesheets stamped `managerUserId: "admin"`, and the HR fallback widget counts every submitted timesheet regardless of stamp

### Requirement: The labour corpus SHALL gain the recommended-severity `nl-mss-manager-consistency` rule (REQ-MSS-004)

`lib/Standards/rules/labour.json` SHALL gain `nl-mss-manager-consistency`: `domain: labour`, `jurisdiction: NL`, `framework: hr-org-core` (the EXISTING org-integrity slug from org-chart-basic — no new framework, no `SCHEMA.md` edit), `severity: recommended` (a stale stamp degrades list scoping only — it breaks no statutory duty, so it must not trip the `humaniq-rule-compliance-enforcement` mandatory exit gate), `machineCheckable: true`, control-style `source` (administration-integrity control, no `sourceUrl`). The statement: a Timesheet/Expense/LeaveRequest record's `managerUserId` SHOULD equal the `nextcloudUserId` of the manager of the record's employee's active OrgAssignment unit. `RuleCatalogue::VERSION` SHALL bump (checked fresh at HEAD: `2026-07.9` → `2026-07.10`, per SCHEMA.md's "bump on any change" rule).

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ humaniq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads without error, reports the current version, and reports `nl-mss-manager-consistency` as enforced (a CheckProvider predicate exists)

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
