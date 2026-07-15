---
kind: code
---

# Rostering — shift/roster planning with an Arbeidstijdenwet cross-check (MVP)

## Why

hrmq already administers who is employed (`Employee`), what they are contracted for
(`EmploymentContract`), what they actually clocked (`AttendanceRecord`) and what hours they claim
(`Timesheet`) — but it has **no forward-looking plan**: nothing that says "next week Fatima works
the 07:00–15:30 early shift on Monday and Wednesday". Rostering fills that gap for the MVP: define
reusable shifts, assign employees to them per period, publish the resulting roster, and — the
differentiator — check the *planned* roster against the same Arbeidstijdenwet (ATW) rules the app
already enforces on realised clock data.

The corpus already carries the three machine-checkable ATW norms (`lib/Standards/rules/labour.json`,
framework `nl-arbeidstijdenwet`): `nl-atw-dagelijkse-rust` (art. 5:3 lid 2 — ≥ 11h rest between
consecutive working days), `nl-atw-max-werkdag` (art. 5:7 lid 1 — ≤ 12h per dienst) and
`nl-atw-pauze` (art. 5:4 lid 1 — the 30/45-minute break tiers). `NlAttendanceChecks` already
executes them over `AttendanceRecord`'s raw `clockIn`/`clockOut`/`breakMinutes`, and
`RuleAuditService::buildAttendanceContext()` already builds the per-employee, per-date sibling index
the daily-rest predicate needs. This change **reuses those exact rules and constants** — it invents
no new working-time law — by projecting each planned `RosterAssignment` into the same clock shape
and evaluating the identical predicates *before* the roster is published, when a violation is still
cheap to fix.

Competitors are weak here: AFAS models only contracted-hours rosters (no shift planning, no ATW
pre-check), and the lighter Dutch HR suites have no rostering at all. An MVP — define, assign,
publish, ATW-check — is therefore already a market-leading position. Deeper workforce management
(auto-optimisation, demand forecasting, drag-and-drop planbord, shift-swap marketplace) is an
explicit **non-goal**: it belongs in a dedicated WFM tool integrated later via openconnector, not in
this MVP.

## What Changes

- **NEW config `lib/Settings/register.d/hr-roster.json`** — three schemas in register `hrmq`:
  - **`Shift`** — a reusable shift *definition* (klok-vorm template): `name`, `startTime`,
    `endTime`, `breakMinutes`, optional `orgUnitId` scope and `active` flag. Times are wall-clock
    `HH:MM`; a shift whose `endTime` ≤ `startTime` denotes a night shift crossing midnight
    (the `AttendanceRecord` night-shift convention).
  - **`Roster`** — the publishable header for one planning period + scope: `period`
    (`YYYY-Www` or `YYYY-MM`), optional `orgUnitId`/`administrationId`, and a `status` governed by
    an `x-openregister-lifecycle` state machine (`concept → gepubliceerd`, `publiceren`; and
    `gepubliceerd → concept`, `intrekken`). A roster groups its assignments; publishing is the act
    that freezes the plan and makes it visible to the team.
  - **`RosterAssignment`** — one employee on one shift on one date within a roster: `rosterId`
    (`$ref Roster`), `employeeId` (`$ref Employee`), `shiftId` (`$ref Shift`), `date`, and the
    *projected* planned-clock fields `plannedStart`/`plannedEnd`/`plannedBreakMinutes` (copied from
    the shift at assignment time so the ATW check is decidable without a live join), plus the
    denormalised `userId` (the mijn-hr convention). No approval states — the plan's lifecycle lives
    on the `Roster` header.
- **NEW `lib/Standards/Checks/NlRosterChecks.php`** — a `CheckProvider` that maps the **three
  existing ATW rule ids** onto `RosterAssignment`, projecting `plannedStart`/`plannedEnd`/
  `plannedBreakMinutes` into the clock shape and **reusing `NlAttendanceChecks`' `MIN_REST_HOURS`
  (11) / `MAX_SHIFT_HOURS` (12) constants and the corpus `nl-atw-pauze` `breakTiers` parameters** —
  exactly as `NlAttendanceChecks` does for realised clock days. No new rule, no duplicated
  threshold. `RuleAuditService::buildRosterContext()` adds the planned-clock sibling index
  (`plannedClockByEmployeeDate`) the daily-rest predicate reads, mirroring
  `buildAttendanceContext()`. So `occ hrmq:rules:audit` now also audits published assignments.
- **NEW `lib/Service/RosterCheckService.php`** — resolves a roster + its assignments through
  OpenRegister's `ObjectService` (the `RuleAuditService` container-resolve idiom) and runs the
  `RuleEngine` over exactly that assignment set, returning per-assignment violations and a
  mandatory/advisory summary; the pre-publish ATW verdict. Never-throw degradation.
- **NEW occ command `hrmq:roster:check --roster ID | --period YYYY-Www [--administration ADM]`** —
  runs `RosterCheckService`, prints per-assignment ATW violations and exits non-zero on any
  `mandatory` violation (the `hrmq:rules:audit` exit-code convention). Registered in
  `appinfo/info.xml`.
- **NEW guarded endpoint `POST /api/roster/check` (`RosterController::check`)** — mirrors
  `DocumentController`'s no-admin-idor pattern: the posted `rosterId` MUST resolve through
  `ObjectService` under the caller's ambient RBAC before anything runs (unknown/unauthorised → 404);
  delegates to `RosterCheckService`. ONE endpoint, no CRUD (ADR-022). `appinfo/routes.php` +1 route
  before the SPA catch-all.
- **Manifest (`src/manifest.json`)** — new pages: `Shifts` index + `ShiftDetail`; `Rosters` index +
  `RosterDetail` (`lifecycleActions` for `publiceren`/`intrekken` — a *genuine*
  `x-openregister-lifecycle`, unlike `PayrollRun`; an `api-call` action "ATW-controle" bound to
  `/api/roster/check`; and an FK-scoped `RosterAssignments` child object-list); `RosterAssignments`
  date-sorted index + `RosterAssignmentDetail`. Menu entry under the planning group.
  `npm run check:manifest` passes.
- **README** — a short Rostering (MVP) section stating the scope boundary and the deeper-WFM
  non-goal (openconnector integration).

### Non-goals (explicit — deeper WFM is a future integration, not this change)

- **Auto-optimisation / demand forecasting / rule-based auto-scheduling** — deferred to a dedicated
  WFM tool integrated via **openconnector**; hrmq owns the plan of record and the ATW compliance
  view, not the optimiser.
- **Drag-and-drop planbord / true calendar grid** — the MVP surfaces are a date-sorted assignment
  list + detail; a visual planbord is a fast-follow.
- **Availability, shift-preferences, skills-matching, open-shift bidding / shift-swap marketplace,
  coverage/understaffing alerts** — named fast-follows, not modelled here.
- **Roster → AttendanceRecord/Timesheet auto-generation** — no automation between plan and realised
  hours (the same deliberate boundary `AttendanceRecord` keeps with `Timesheet`).
- **Publish-time hard block on ATW violation** — publishing surfaces the ATW verdict and the audit
  corpus covers published assignments, but write-time *guard* wiring (blocking a transition) remains
  owned by the active `hrmq-rule-compliance-enforcement` change; this change invents no guard.

## Capabilities

### New Capabilities

- `rostering`: reusable shift definitions, a publishable roster header with a concept→gepubliceerd
  lifecycle, per-date employee↔shift assignments, and an Arbeidstijdenwet cross-check that reuses
  the three existing ATW corpus rules over the planned roster (check provider + roster audit context
  + occ command + guarded endpoint + roster pages), plus the documented MVP/WFM scope boundary.

### Modified Capabilities

<!-- none — the ATW rules and NlAttendanceChecks are reused unchanged; time-attendance is not modified -->

## Impact

- `lib/Settings/register.d/hr-roster.json` — NEW (Shift, Roster, RosterAssignment).
- `lib/Standards/Checks/NlRosterChecks.php` — NEW (reuses the three ATW rule ids + NlAttendanceChecks
  constants + corpus breakTiers); `lib/Service/RuleAuditService.php` — `buildRosterContext()` +
  `rostering.plannedClockByEmployeeDate` context enrichment.
- `lib/Service/RosterCheckService.php` — NEW (ObjectService resolve, RuleEngine over the roster's
  assignments).
- `lib/Command/RosterCheckCommand.php` — NEW; `appinfo/info.xml` +1 `<command>`.
- `lib/Controller/RosterController.php` — NEW; `appinfo/routes.php` +1 route before the catch-all.
- `src/manifest.json` — Shifts/ShiftDetail, Rosters/RosterDetail (lifecycleActions + api-call +
  child list), RosterAssignments/RosterAssignmentDetail, menu entry; `npm run check:manifest` passes.
- `README.md` — Rostering (MVP) section with the WFM non-goal.
- No corpus change: `lib/Standards/rules/labour.json` is read, not written — the three ATW rules and
  their `breakTiers` are reused as-is.
