# Delta — rostering

MVP shift/roster planning for hrmq: reusable shift definitions, a publishable roster header with a
`concept → gepubliceerd` lifecycle, per-date employee↔shift assignments, and an Arbeidstijdenwet
cross-check that **reuses** the three existing ATW corpus rules over the planned roster. Deeper
workforce management (auto-optimisation, demand forecasting) is a Non-Goal — a future openconnector
integration with a dedicated WFM tool.

## ADDED Requirements

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
the standing `occ hrmq:rules:audit` does not raise mandatory violations for work-in-progress concept
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
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** no mandatory violation is raised for those assignments (concept plans are checked only on
  demand)

### Requirement: The roster SHALL be checkable on demand via one command and one guarded endpoint (REQ-ROST-005)

`lib/Service/RosterCheckService.php` SHALL resolve a roster and its `RosterAssignment`s through
OpenRegister's `ObjectService` (container resolve, register `hrmq`) and run the `RuleEngine` over
exactly that assignment set — regardless of publish status, so a `concept` roster can be validated
before publishing — returning per-assignment violations and a mandatory/advisory count.
`occ hrmq:roster:check --roster ID | --period YYYY-Www [--administration ADM]` SHALL print the
per-assignment ATW outcome and exit non-zero on any `mandatory` violation, `0` otherwise (the
`hrmq:rules:audit` exit-code convention), registered in `appinfo/info.xml`. `appinfo/routes.php` SHALL
add `POST /api/roster/check` → `RosterController::check` (`#[NoAdminRequired]`), which resolves the
posted `rosterId` through `ObjectService` under the caller's ambient RBAC before any computation
(unknown/unauthorised collapse to 404 — the `DocumentController` no-admin-idor pattern) and delegates
to the service. It SHALL be ONE endpoint with no CRUD (ADR-022).

#### Scenario: A concept roster is validated before publishing
- **GIVEN** a `concept` roster with an assignment breaching `nl-atw-max-werkdag`
- **WHEN** `occ hrmq:roster:check --roster ROSTER-2026-W28` runs
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
alerts are named fast-follows. The section SHALL make clear hrmq owns the plan of record and the ATW
compliance view, not the WFM optimiser.

#### Scenario: The scope boundary is present and complete
- **WHEN** `README.md` is read after this change
- **THEN** it contains the delivered rostering MVP scope and the deeper-WFM openconnector-integration
  non-goal
