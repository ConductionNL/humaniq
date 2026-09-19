---
capability: rostering
status: done
built_by: openspec/changes/archive/2026-07-14-rostering
---

# rostering Specification

**Status**: done
**Scope**: humaniq (`kind: code` — reuses the three existing Arbeidstijdenwet corpus rules AS-IS, adds ZERO new working-time law)
**OpenSpec changes**:
- [rostering](../../changes/archive/2026-07-14-rostering/) _(archived 2026-07-14)_ —
  forward-looking shift planning: three OpenRegister schemas (`Shift` reusable definition, `Roster`
  publishable header with a real `concept → gepubliceerd` `x-openregister-lifecycle`,
  `RosterAssignment` projecting a shift's times onto a date into the clock shape), an
  Arbeidstijdenwet cross-check (`NlRosterChecks`) that REUSES the three existing corpus rules
  (`nl-atw-dagelijkse-rust`, `nl-atw-max-werkdag`, `nl-atw-pauze`) over the planned assignments —
  reusing `NlAttendanceChecks::MIN_REST_HOURS`/`::MAX_SHIFT_HOURS` and the corpus `nl-atw-pauze`
  `breakTiers`, `RuleAuditService::buildRosterContext()` (published-roster planned-clock sibling
  index), a never-throw `RosterCheckService`, one occ command `humaniq:roster:check`, one RBAC-gated
  `POST /api/roster/check` (`RosterController::check`, resolve-first → 404), and the Shifts /
  Rosters / RosterAssignments manifest pages. No new rule and no `RuleCatalogue::VERSION` bump.

## Purpose

humaniq administers who is employed, what they clocked and what they claim, but had no forward-looking
plan. Rostering fills that MVP gap: define reusable shifts, assign employees per period, publish the
resulting roster, and — the differentiator — check the *planned* roster against the same
Arbeidstijdenwet rules the app already enforces on realised clock data, before publication, when a
violation is still cheap to fix. Deeper workforce management (auto-optimisation, demand forecasting,
a drag-and-drop planbord, shift-swap) is an explicit non-goal — a future openconnector integration
with a dedicated WFM tool; humaniq owns the plan of record and the ATW compliance view, not the
optimiser.

## Requirements

### Requirement: Reusable shift definitions SHALL be modelled (REQ-ROST-001)

The system SHALL provide a `Shift` schema in register `hrmq` describing a reusable shift definition:
a `name`, wall-clock `startTime` and `endTime` (`HH:MM`), a non-negative `breakMinutes`, an optional
`orgUnitId` (`$ref OrgUnit`) scope and an `active` flag. A shift whose `endTime` is less than or
equal to its `startTime` SHALL denote a night shift crossing midnight (the `AttendanceRecord`
night-shift convention). Shifts are authored once and reused across many assignments; the schema
carries no dated instance data.

#### Scenario: A shift is defined once and reused
- **GIVEN** an administrator authors a `Shift` named "Vroege dienst" with startTime 07:00, endTime
  15:30 and breakMinutes 30
- **WHEN** the shift is saved and later referenced by several assignments
- **THEN** the single `Shift` object supplies the times and break for every assignment that
  references it, without duplicating shift data on each assignment

#### Scenario: A night shift is expressed by endTime not after startTime
- **GIVEN** a `Shift` with startTime 22:00 and endTime 06:00
- **WHEN** the shift is interpreted
- **THEN** it denotes a dienst crossing midnight, and an assignment on a given date SHALL compose a
  `plannedEnd` on the following calendar day

### Requirement: A roster SHALL be a publishable header with a concept-to-gepubliceerd lifecycle (REQ-ROST-002)

The system SHALL provide a `Roster` schema in register `hrmq` with a `period` (`YYYY-Www` or
`YYYY-MM`), optional `orgUnitId`/`administrationId` scope, and a `status` governed by an
`x-openregister-lifecycle` state machine whose `initial` value is `concept` and whose transitions are
`publiceren` (`concept → gepubliceerd`) and `intrekken` (`gepubliceerd → concept`). Publishing SHALL
be the act that freezes the plan and makes it the team's roster; `intrekken` SHALL return it to
`concept` for editing with the change visible in the audit trail. A roster groups its assignments and
carries no hours of its own.

#### Scenario: Publishing moves the roster to gepubliceerd
- **GIVEN** a `Roster` for period 2026-W28 in status `concept`
- **WHEN** the `publiceren` transition runs
- **THEN** the roster status becomes `gepubliceerd` and the transition is recorded in the audit trail

#### Scenario: An invented transition is never offered
- **GIVEN** the `Roster` lifecycle defines only `publiceren` and `intrekken`
- **WHEN** the `RosterDetail` page renders its `lifecycleActions` widget
- **THEN** it exposes exactly those two transitions and no approval or other edge the backend does
  not model

### Requirement: A RosterAssignment SHALL assign an employee to a shift on a date with projected planned-clock fields (REQ-ROST-003)

The system SHALL provide a `RosterAssignment` schema in register `hrmq` with `rosterId`
(`$ref Roster`), `employeeId` (`$ref Employee`), `shiftId` (`$ref Shift`), a `date` (the working-day
key), a denormalised `userId` (a copy of the employee's `nextcloudUserId`, never a `$ref`), and the
projected planned-clock fields `plannedStart`/`plannedEnd` (`date-time`) and `plannedBreakMinutes`.
On write, `plannedStart`/`plannedEnd` SHALL be composed from the assignment `date` and the referenced
shift's `startTime`/`endTime` — with `plannedEnd` rolled to the next calendar day when the shift's
`endTime` is not after its `startTime` — and `plannedBreakMinutes` copied from `Shift.breakMinutes`,
so the ATW cross-check is decidable from the assignment alone without a live shift join. The
assignment SHALL carry no approval status of its own.

#### Scenario: Assignment projects the shift times onto its date
- **GIVEN** a `Shift` 07:00–15:30 break 30 and an assignment of an employee to it on 2026-07-13
- **WHEN** the assignment is written
- **THEN** `plannedStart` is 2026-07-13T07:00, `plannedEnd` is 2026-07-13T15:30 and
  `plannedBreakMinutes` is 30

#### Scenario: A published plan is stable against later shift edits
- **GIVEN** a published assignment whose projected planned fields were copied from its shift
- **WHEN** the underlying `Shift` template is later edited
- **THEN** the existing assignment's `plannedStart`/`plannedEnd`/`plannedBreakMinutes` are unchanged
  until the assignment itself is re-written

### Requirement: The roster ATW cross-check SHALL reuse the three existing ATW corpus rules (REQ-ROST-004)

`lib/Standards/Checks/NlRosterChecks.php` SHALL register predicates for `RosterAssignment` keyed by
the three EXISTING corpus rule ids `nl-atw-dagelijkse-rust` (art. 5:3 lid 2 — ≥ 11h rest between
consecutive working days), `nl-atw-max-werkdag` (art. 5:7 lid 1 — ≤ 12h per dienst) and
`nl-atw-pauze` (art. 5:4 lid 1 — break tiers), projecting `plannedStart`/`plannedEnd`/
`plannedBreakMinutes` into the clock shape and REUSING `NlAttendanceChecks`' `MIN_REST_HOURS` (11)
and `MAX_SHIFT_HOURS` (12) constants and the corpus `nl-atw-pauze` `breakTiers` parameters — no new
working-time rule SHALL be added to `lib/Standards/rules/labour.json`. The same vacuous-pass
discipline SHALL apply (null `plannedEnd`, or an absent/open previous-day sibling, passes).
`RuleAuditService::buildRosterContext()` SHALL supply the daily-rest sibling index
`rostering.plannedClockByEmployeeDate`, built from assignments of `gepubliceerd` rosters only so that
the standing `occ humaniq:rules:audit` does not raise mandatory violations for work-in-progress concept
rosters.

#### Scenario: Insufficient rest between planned shifts is a mandatory ATW violation
- **GIVEN** a published roster assigning an employee a shift ending 2026-07-13T23:00 and a shift
  starting 2026-07-14T06:00 (7h rest)
- **WHEN** the roster is audited
- **THEN** an `nl-atw-dagelijkse-rust` mandatory violation is reported for the 2026-07-14 assignment

#### Scenario: A shift within the norms raises no ATW violation
- **GIVEN** a published assignment for a 07:00–15:30 shift with a 30-minute break (8.5h elapsed, 8h
  worked)
- **WHEN** the roster is audited
- **THEN** none of `nl-atw-dagelijkse-rust`, `nl-atw-max-werkdag` or `nl-atw-pauze` reports a
  violation for it

#### Scenario: Concept-roster assignments stay out of the standing audit
- **GIVEN** a `concept` roster whose assignments would violate `nl-atw-max-werkdag`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** no mandatory violation is raised for those assignments (concept plans are checked only on
  demand)

### Requirement: The roster SHALL be checkable on demand via one command and one guarded endpoint (REQ-ROST-005)

`lib/Service/RosterCheckService.php` SHALL resolve a roster and its `RosterAssignment`s through
OpenRegister's `ObjectService` (container resolve, register `hrmq`) and run the `RuleEngine` over
exactly that assignment set — regardless of publish status, so a `concept` roster can be validated
before publishing — returning per-assignment violations and a mandatory/advisory count.
`occ humaniq:roster:check --roster ID | --period YYYY-Www [--administration ADM]` SHALL print the
per-assignment ATW outcome and exit non-zero on any `mandatory` violation, `0` otherwise (the
`humaniq:rules:audit` exit-code convention), registered in `appinfo/info.xml`. `appinfo/routes.php` SHALL
add `POST /api/roster/check` → `RosterController::check` (`#[NoAdminRequired]`), which resolves the
posted `rosterId` through `ObjectService` under the caller's ambient RBAC before any computation
(unknown/unauthorised collapse to 404 — the `DocumentController` no-admin-idor pattern) and delegates
to the service. It SHALL be ONE endpoint with no CRUD (ADR-022).

#### Scenario: A concept roster is validated before publishing
- **GIVEN** a `concept` roster with an assignment breaching `nl-atw-max-werkdag`
- **WHEN** `occ humaniq:roster:check --roster ROSTER-2026-W28` runs
- **THEN** the violation is printed for that assignment and the command exits non-zero

#### Scenario: An unauthorized roster id never reaches the check
- **GIVEN** a caller whose RBAC cannot see roster X (or X does not exist)
- **WHEN** they POST `/api/roster/check` with `rosterId: X`
- **THEN** the response is 404 and no assignments are loaded or evaluated

### Requirement: The roster pages SHALL surface planning, publishing and the ATW check (REQ-ROST-006)

`src/manifest.json` SHALL add `Shifts` (index) + `ShiftDetail`, `Rosters` (index) + `RosterDetail`,
and `RosterAssignments` (index) + `RosterAssignmentDetail` in register `hrmq`. `RosterDetail` SHALL
render a `lifecycleActions` widget for the `publiceren`/`intrekken` transitions (its `Roster` carries
a real `x-openregister-lifecycle`), an `api-call` action "ATW-controle"
(`url: /api/roster/check`, `method: POST`, `params: {rosterId: "@objectId"}`, confirm plus
success/error toasts), and an FK-scoped `RosterAssignments` child object-list
(`filter: {rosterId: "@objectId"}`). `RosterAssignments` SHALL be a date-sorted list (the MVP
"calendar-ish" planning surface; a visual planbord/grid is a Non-Goal), and a menu entry SHALL sit
under the planning group. `npm run check:manifest` MUST pass.

#### Scenario: The roster detail drives publish and the ATW check
- **GIVEN** a `concept` `Roster` opened on `RosterDetail`
- **WHEN** the page renders
- **THEN** it offers the `publiceren` lifecycle action, the "ATW-controle" api-call action bound to
  `/api/roster/check` with `rosterId: "@objectId"`, and the FK-scoped list of its assignments

#### Scenario: The manifest validates
- **WHEN** `npm run check:manifest` runs after this change
- **THEN** every roster schema `$ref` resolves to a real slug and the check passes

### Requirement: The MVP scope and the deeper-WFM non-goal SHALL be documented (REQ-ROST-007)

`README.md` SHALL gain a Rostering (MVP) section stating the delivered scope — define shifts, assign
employees per period, publish a roster, and check it against the Arbeidstijdenwet — and naming the
explicit Non-Goals: auto-optimisation, demand forecasting and rule-based auto-scheduling are deferred
to a dedicated workforce-management tool integrated via **openconnector**, and a drag-and-drop
planbord, availability/preferences, skills-matching, open-shift bidding/shift-swap and coverage
alerts are named fast-follows. The section SHALL make clear humaniq owns the plan of record and the ATW
compliance view, not the WFM optimiser.

#### Scenario: The scope boundary is present and complete
- **WHEN** `README.md` is read after this change
- **THEN** it contains the delivered rostering MVP scope and the deeper-WFM openconnector-integration
  non-goal

<!-- Synced from openspec/changes/agenda-rostering-and-resource-booking/specs/, which shipped without
     reaching this file. The @spec tags in lib/ and src/ already point here. -->

### Requirement: A shift SHALL declare the competences it needs (REQ-ROST-C01)

The `Shift` schema in register `hrmq` SHALL carry `requiredCompetences`, a set of
competence codes. A new `EmployeeCompetence` schema SHALL record that one employee
holds one competence, with an `issuedOn` and an optional `validUntil`. A competence
whose `validUntil` has passed SHALL NOT count as held.

Candidate C-tasks-and-phases-28 (`tasks-and-phases.tsv:42`), relevance `should`.
**No driven passer.** The only evidence is atabix's documented Roostersysteem on
`/gestandaardiseerde-modules`, admitted under decision D21 and labelled documented
here so no reader takes it for a measurement.

#### Scenario: A shift names what it needs
@e2e tests/e2e/spec-coverage/agenda-and-resource-booking.spec.ts
- **GIVEN** a `Shift` "Nachtcontrole horeca" with `requiredCompetences` `["boa-domein-1"]`
- **WHEN** the shift is read
- **THEN** the competence set travels with the shift, and every assignment that
  references the shift inherits it without restating it

#### Scenario: An expired qualification stops counting on its own date
@e2e exclude a date-boundary assertion over two adjacent days; covered by AgendaAndAvailabilityTest::testAnExpiredCompetenceProducesAFindingOnItsOwnDate, with the last valid day beside it as the control
- **GIVEN** an `EmployeeCompetence` for `boa-domein-1` with `validUntil` yesterday
- **WHEN** the holder is evaluated for a shift requiring `boa-domein-1`
- **THEN** the competence does not count as held, without anybody editing the record

### Requirement: A roster check SHALL refuse an assignment the person is not competent for (REQ-ROST-C02)

`RosterCheckService` SHALL gain a competence cross-check beside the three
Arbeidstijdenwet rules it already runs. For every `RosterAssignment` on the roster,
the check SHALL compare the shift's `requiredCompetences` against the competences the
assigned employee holds on the assignment's date. A missing or expired competence
SHALL produce a finding of kind `competence`, distinct from the working-time findings,
naming the employee, the date and the competence.

The check SHALL keep the never-throw posture `rostering` already specifies, and SHALL
run in the same act as the working-time check, so `occ humaniq:roster:check` and
`POST /api/roster/check` still answer the whole question in one call.

#### Scenario: A roster with an unqualified assignment does not publish clean
@e2e exclude covered by AgendaAndAvailabilityTest::testAnExpiredCompetenceProducesAFindingOnItsOwnDate through RosterCheckService's competence cross-check
- **GIVEN** a concept roster with one assignment putting an employee without
  `boa-domein-1` on a shift that requires it
- **WHEN** `occ humaniq:roster:check` runs
- **THEN** it reports one `competence` finding naming the employee, the date and
  `boa-domein-1`

#### Scenario: The two kinds of finding stay apart
@e2e exclude covered by AgendaAndAvailabilityTest::testAnExpiredCompetenceProducesAFindingOnItsOwnDate, which asserts the finding's own kind, and by RosterCheckService tagging every working-time violation with the other kind
- **GIVEN** a roster that breaks both the daily-rest rule and a competence requirement
- **WHEN** the check runs
- **THEN** the working-time finding and the competence finding are reported
  separately, each with its own kind, so a reader can act on one without the other

### Requirement: Planned capacity SHALL be read forward against each person's own hours (REQ-ROST-C03)

humaniq SHALL answer, for a period and an org unit, the planned hours per employee
against that employee's own contracted hours for the same period, forward from a
given date. Planned hours SHALL be the sum of rostered assignments and resource
bookings that hold the employee, minus approved leave and open sick leave. The
contracted hours SHALL come from the working hours specified by
`a-working-calendar-per-person`; when a person has none, the answer SHALL say so
rather than substitute the instance default.

Candidate C-reporting-15 (`reporting.tsv:8`), relevance `should`, driven passer
openproject (Team planners, `modules/resource_management/`), documented
easy-redmine and jira-service-management.

#### Scenario: A part-timer is measured against their own week
@e2e exclude covered by AgendaAndAvailabilityTest::testCapacityMeasuresAPartTimerAgainstTheirOwnWeek
- **GIVEN** an employee contracted for 24 hours a week and rostered for 20
- **WHEN** the capacity read runs over that week
- **THEN** it reports 20 planned against 24 available, not against the instance's
  full-time week

#### Scenario: A missing contract is said, not guessed
@e2e tests/e2e/spec-coverage/agenda-and-resource-booking.spec.ts
- **GIVEN** an employee with no working hours recorded
- **WHEN** the capacity read runs
- **THEN** the answer marks that employee as having no contracted hours, and no
  percentage is computed for them

### Requirement: humaniq SHALL NOT propose dates for other apps' work (REQ-ROST-C04)

Candidate C-deadlines-19 (`deadlines.tsv:30`) asks a product to propose dates for a
set of records from their dependencies and the capacity available. humaniq SHALL
supply the capacity half through REQ-ROST-C03 and SHALL NOT schedule another app's
records. The dependency graph belongs to the app that owns the records.

This is recorded as a requirement rather than left out, so the decision is findable.
The candidate has **no driven passer**: the evidence is Jira Data Center's
documented Advanced Roadmaps auto-schedule, admitted under decision D21.

#### Scenario: The capacity answer is offered, the schedule is not
@e2e exclude asserts that humaniq has NO scheduling endpoint, which is verified by reading appinfo/routes.php: /api/capacity exists and no route proposes dates
- **WHEN** a consuming app asks humaniq for a proposed set of dates
- **THEN** humaniq answers with available capacity per person per period, and the
  consuming app composes the schedule from its own dependencies
