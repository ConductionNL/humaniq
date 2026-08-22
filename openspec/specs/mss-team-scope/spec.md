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
`required`, NEVER a `$ref` (it names a Nextcloud *account*, mirroring the `userId`/`approvedBy`
convention). Manager assignment truth lives ONLY on the org structure (`OrgAssignment` →
`OrgUnit.managerId`); `managerUserId` is a **server-maintained cache** of that chain, existing
solely because the manifest filter grammar cannot join across schemas for the `@me` team-queue
filters. For `Timesheet`, the cache SHALL be stamped server-side on every write by the process
stamping listener via the shared `OrgResolutionService` — the same code path the
`nl-mss-manager-consistency` audit (`NlOrgChecks`) evaluates, so stamp and audit cannot
disagree; it SHALL never appear on any form, and client input to it is inert. For `Expense` and
`LeaveRequest` the property, its filter role and its audit are unchanged by this change
(server-stamping those two records is follow-up work, not regressed here — they remain
HR/back-office-populated until their own process redesigns). Each property description SHALL be
user-oriented ("The team manager who reviews this record — kept up to date automatically from
the organisation structure" for Timesheet), with the denormalization rationale in `x-notes`.

#### Scenario: Record without a manager stamp stays valid

@e2e exclude Register-level validation of an optional nullable property; no UI journey exists for creating an org-managerless employee's timesheet — verified by the stamping listener unit tests (null path) and the register import
- **GIVEN** the imported hrmq register after the schema bumps
- **WHEN** a Timesheet is created for an employee with no resolvable org manager
- **THEN** creation succeeds and `managerUserId` is `null` (fail-closed: it appears in no team
  queue)

#### Scenario: Stamped record round-trips

@e2e exclude Plain-string persistence contract on a schema this change does not redesign (Expense); backend round-trip verified by the existing register import tests
- **WHEN** an Expense is written with `managerUserId: "admin"`
- **THEN** the value persists and is returned as a plain string (no reference resolution)

#### Scenario: The Timesheet cache follows the org structure, not the client

@e2e exclude Stamping is a backend write-path behaviour; verified by TimesheetProcessStampListener + OrgResolutionService unit tests (the team-queue UI consequence is covered by the existing TeamUrengoedkeuring filter scenario)
- **GIVEN** an employee whose active `OrgAssignment` unit's manager resolves to
  `nextcloudUserId` "admin"
- **WHEN** any write persists that employee's Timesheet, even one supplying
  `managerUserId: "someone-else"`
- **THEN** the persisted `managerUserId` is "admin" — the org chain wins, client input is inert

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

`NlOrgChecks` SHALL be extended (not a new provider — the rule is an `hr-org-core` org-integrity control consuming the provider's own OrgUnit index, and `RuleEngine::checks()` merges providers additively per object type, verified at HEAD) with one shared predicate registered under the three object types `Timesheet`, `Expense` and `LeaveRequest`, keyed `nl-mss-manager-consistency`. Predicate (pure `fn(array $o, array $c): bool`): resolve `employeeId` → the employee's active OrgAssignments (`endDate` absent or on/after the audit date — the existing `isCurrentlyActive()` semantics) via a new `context['related']['OrgAssignment']['byEmployeeId']` index (lists of `{orgUnitId, endDate}`) → each unit's `managerId` via the OrgUnit index (which gains `managerId`) → the manager's `nextcloudUserId` via the Employee index (which gains `nextcloudUserId`). It **violates** only when the record carries a non-empty `managerUserId`, at least one chain fully resolves to a manager `nextcloudUserId`, and NO resolved value equals `managerUserId`; it is **vacuous** (passes) when `managerUserId` is absent/empty, `employeeId` is empty, no active assignment exists, the unit is unresolvable or unmanaged, the manager Employee is unresolvable, or the manager has no `nextcloudUserId` — the rule reports provable inconsistency and never punishes absent org data. `RuleAuditService::buildRelatedContext()` SHALL gain the three index extensions with the same single pre-pass and degrade-to-empty behaviour as the existing indexes. Unit tests SHALL pin: the consistent path, the mismatch violation, each vacuous hop, the multiple-active-assignments any-match pass, and the extended indexes through `RuleAuditService::audit()` with a fake ObjectService double (the existing RuleAuditServiceTest pattern).

#### Scenario: Mismatching stamp flagged
- **GIVEN** a submitted Timesheet with `managerUserId: "someone-else"` whose employee's active assignment resolves to a unit managed by an Employee with `nextcloudUserId: "admin"`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** a `nl-mss-manager-consistency` violation with recommended severity is reported for that timesheet

#### Scenario: Matching stamp passes
- **GIVEN** the seeded `timesheet-jansen-2026-05` (`managerUserId: "admin"`) with employee-jansen actively placed in a unit managed by employee-jansen (`nextcloudUserId: "admin"`)
- **WHEN** the audit runs
- **THEN** no `nl-mss-manager-consistency` violation is reported for it

#### Scenario: Vacuous when org data is absent
- **GIVEN** a record with `managerUserId: "admin"` whose employee has no active OrgAssignment (or whose unit has no manager, or whose manager has no `nextcloudUserId`)
- **WHEN** the audit runs
- **THEN** no violation is reported for it (vacuous pass on every unresolvable hop)

#### Scenario: Unstamped record is never evaluated
- **GIVEN** the seeded approved/rejected records that carry no `managerUserId`
- **WHEN** the audit runs
- **THEN** no `nl-mss-manager-consistency` violation is reported for them

### Requirement: Seed data SHALL demo a populated team queue that the consistency rule confirms (REQ-MSS-006)

`lib/Settings/register.d/hr-seed.json` SHALL stamp `managerUserId: "admin"` on exactly the three existing submitted-status seeds (`timesheet-jansen-2026-05`, `expense-jansen-hotel`, `leave-jansen-zomer` — the ones already carrying `userId: "admin"`), leaving approved/rejected/reimbursed seeds unstamped (they demo the vacuous path), and SHALL re-point `orgunit-consultancy.managerId` from `employee-devries` to `employee-jansen` so employee-jansen's active Consultancy placement resolves to a manager with `nextcloudUserId: "admin"` and the three stamps evaluate non-vacuously consistent. This deliberately supersedes the archived org-chart-basic seed value (its REQ-ORG-008 named devries, whose Employee object was never seeded — a dangling slug org-chart-basic itself flagged — so manager resolution dead-ended); the org structure (3 units / 3 assignments), the nullable-manager path (backoffice) and the deliberately date-inconsistent bakker assignment all stay intact. NO seeded `nl-mss-manager-consistency` violation (the org-chart `nl-org-unit-cycle` precedent: a wrong seed stamp would corrupt the team-queue demo just to light a recommended lamp; the violating path is pinned by unit tests). All identifiers remain obvious placeholders.

#### Scenario: Seeded audit stays green for the new rule
- **GIVEN** the seeded data after the stamps and the manager re-point
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** zero `nl-mss-manager-consistency` violations are reported, the three stamped records evaluate non-vacuously, and no pre-existing check regresses

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the stamped records and the re-pointed unit exist exactly once with the new values
