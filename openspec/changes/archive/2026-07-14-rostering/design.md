# Design — rostering

## Context

**Verified against HEAD 2026-07-15.** This change adds forward-looking shift planning and reuses the
working-time-law machinery the app already ships. Everything below was read at HEAD:

- **The ATW rules** (`lib/Standards/rules/labour.json`, framework `nl-arbeidstijdenwet`):
  `nl-atw-dagelijkse-rust` (art. 5:3 lid 2 — ≥ 11h rest between consecutive working days),
  `nl-atw-max-werkdag` (art. 5:7 lid 1 — ≤ 12h per dienst) and `nl-atw-pauze` (art. 5:4 lid 1, with
  `parameters.breakTiers` `[{minHours:5.5,requiredBreakMinutes:30},{minHours:10,requiredBreakMinutes:45}]`).
  All three are `severity: mandatory`, `machineCheckable: true`. This change writes **no new rule**.
- **`NlAttendanceChecks`** already executes all three over `AttendanceRecord`, computing shift length
  from raw `clockIn`/`clockOut` (never a stored `workedHours`) and reading `breakMinutes` raw. It
  exposes the norms as constants `MIN_REST_HOURS = 11.0` and `MAX_SHIFT_HOURS = 12.0` and reads
  `breakTiers` from the corpus via `RuleCatalogue::all()`. Its daily-rest predicate reads
  `$context['attendance']['clockByEmployeeDate']` — a per-employee, per-date sibling index.
- **`RuleAuditService::buildAttendanceContext()`** builds that index once per audit run
  (`employeeId => [date => ['clockIn'=>…, 'clockOut'=>…]]`) from every `AttendanceRecord`, degrading
  to an empty index when the schema is absent — the same shape as `buildGlPostContext()` /
  `buildNetPayContext()` / `buildCaoContext()`. A cross-object CheckProvider predicate is
  `fn(array $o, array $context): bool`; a rule becomes *enforced* only when a provider registers a
  predicate keyed by the rule `id` (`rules/SCHEMA.md`).
- **`AttendanceRecord`** (`hr-attendance.json`): `x-openregister-lifecycle` on `status`
  (`open ↔ gesloten`), raw `clockIn`/`clockOut` (`date-time`), `breakMinutes`, `date` (the
  working-day key even for a night shift whose `clockOut` is after midnight), denormalised `userId`.
- **`Employee`** (`hr-objects.json`): `nextcloudUserId`, `startDate`/`endDate` (active-window fields
  the run selection uses), `employeeNumber`. `Timesheet`/`AttendanceRecord` carry `userId` as a
  plain copy of `Employee.nextcloudUserId`, never a `$ref`.
- **Endpoint guard**: `DocumentController::generate` — `#[NoAdminRequired]` + resolve the posted
  object id through `ObjectService` under the caller's ambient RBAC *before* any work;
  unknown and unauthorised collapse to the same 404.
- **Manifest v2** patterns (read from `src/manifest.json`): an `index` page = `config`
  register/schema/columns/filters/sort; a `detail` page = `widgets` + `layout` + optional
  `lifecycleActions` (`{field, transitions:[{action,from,to,label}]}`, which renders exactly the
  schema's `x-openregister-lifecycle` edges — see `AttendanceRecordDetail`'s sluiten/heropenen) +
  `sidebar`. The archived `payroll-core-engine` change established the detail-page `api-call` action
  (`params:{…:"@objectId"}`, confirm, toasts) and FK-scoped child `object-list`
  (`filter:{fk:"@objectId"}`, `allowCreate:false`) idioms.

## Goals / Non-Goals

**Goals:** define reusable shifts; assign employees to shifts per date within a publishable roster;
a `concept → gepubliceerd` publish lifecycle on the roster header; an ATW cross-check that **reuses**
the three existing rules over the *planned* assignments (pre-publish, occ, and the standing audit
corpus); MVP list/detail surfaces.

**Non-Goals (binding, from the proposal):** auto-optimisation / demand forecasting / auto-scheduling
(future **openconnector** WFM integration); drag-and-drop planbord / calendar grid (fast-follow);
availability, preferences, skills-matching, open-shift bidding / shift-swap, coverage alerts;
roster→attendance/timesheet auto-generation; publish-time hard *block* on ATW violation (guard wiring
is `hrmq-rule-compliance-enforcement`'s scope); new working-time rules (the corpus is reused as-is).

## Decisions

### D1 — Three schemas: Shift (template), Roster (publishable header), RosterAssignment (the plan)

- **`Shift`** is a reusable definition, not a dated instance: `name`, `startTime`/`endTime` (`HH:MM`
  wall-clock), `breakMinutes` (`>= 0`, default 0), optional `orgUnitId` (`$ref OrgUnit`) and
  `active`. `endTime <= startTime` marks a midnight-crossing night shift (the `AttendanceRecord`
  night-shift convention). Shifts are authored once and reused across many assignments.
- **`Roster`** is the unit of publication: `period` (`YYYY-Www` week or `YYYY-MM` month), optional
  `orgUnitId`/`administrationId` scope, `status`. It groups its assignments; publishing freezes the
  plan (D3). A roster does not itself carry hours — it is a header, like `PayrollRun`.
- **`RosterAssignment`** is one employee × one shift × one date within a roster: `rosterId`
  (`$ref Roster`), `employeeId` (`$ref Employee`), `shiftId` (`$ref Shift`), `date` (`format: date`,
  the working-day key), `userId` (denormalised `Employee.nextcloudUserId`), and the **projected**
  planned-clock fields (D2). No approval `status` — the plan's only lifecycle is the header's.

### D2 — Assignments carry projected planned-clock fields so the ATW check is decidable standalone

`NlAttendanceChecks` decides ATW compliance from `clockIn`/`clockOut`/`breakMinutes` + a `date` key.
To reuse that logic unchanged, a `RosterAssignment` copies the shift's shape at assignment time into
`plannedStart`/`plannedEnd` (`date-time`, composed from the assignment `date` + the shift's
`startTime`/`endTime`, with `plannedEnd` rolled to the next day when `endTime <= startTime`) and
`plannedBreakMinutes` (from `Shift.breakMinutes`). This is the same pattern `AttendanceRecord` uses
for the *realised* day: the check reads raw planned fields, never a derived total. Copying (vs. a
live `Shift` join at audit time) keeps the predicate a pure function of the object + a sibling index,
and keeps a published plan stable even if the shift template is later edited.

### D3 — The publish lifecycle lives on the Roster header (a real x-openregister-lifecycle)

`Roster.status` is governed by `x-openregister-lifecycle` (`field: status`, `initial: concept`):
`publiceren` (`concept → gepubliceerd`) and `intrekken` (`gepubliceerd → concept`). This is the
`AttendanceRecord` sluiten/heropenen precedent — a genuine backend-modelled state machine, so
`RosterDetail` legitimately renders a `lifecycleActions` widget (the exact opposite of
`payroll-core-engine`, where `PayrollRun` deliberately had *no* lifecycle and the change was careful
never to invent one). Publishing is the semantic "the plan is now the team's roster"; `intrekken`
returns it to `concept` for edits, with the change visible in the audit trail. The ATW verdict is
*surfaced* at/around publish (D4/D5) but the transition itself is not hard-guarded here (Non-Goal;
guard wiring is `hrmq-rule-compliance-enforcement`'s).

### D4 — The ATW cross-check REUSES the three existing rules — NlRosterChecks + a roster audit context

- `NlRosterChecks` registers, for object type `RosterAssignment`, predicates keyed by the **same
  three rule ids** `nl-atw-dagelijkse-rust`, `nl-atw-max-werkdag`, `nl-atw-pauze`. Each predicate
  projects `plannedStart`/`plannedEnd`/`plannedBreakMinutes` into the clock shape and applies the
  **same thresholds** — reusing `NlAttendanceChecks::MIN_REST_HOURS` (11), `::MAX_SHIFT_HOURS` (12)
  and the corpus `nl-atw-pauze` `breakTiers` (read via `RuleCatalogue`, never hard-coded). Same
  vacuous-pass discipline: a null `plannedEnd` passes max-werkdag/pauze (shift length undecidable);
  no previous-day sibling, or a sibling with null `plannedEnd`, passes daily-rest.
- `RuleAuditService::buildRosterContext()` builds `plannedClockByEmployeeDate`
  (`employeeId => [date => ['clockIn'=>plannedStart, 'clockOut'=>plannedEnd]]`) from every
  `RosterAssignment` whose roster is `gepubliceerd`, mirroring `buildAttendanceContext()` and
  degrading to an empty index when the schema is absent. The daily-rest predicate reads
  `$context['rostering']['plannedClockByEmployeeDate']`.
- **Scope discipline**: the standing audit (`occ hrmq:rules:audit`) evaluates the ATW rules over
  `RosterAssignment`s only for **published** rosters — a `concept` roster is work-in-progress and
  must not raise mandatory violations across the whole app. Concept rosters are checked on demand
  (D5), where the operator explicitly asked.

### D5 — One check service, one occ command, one guarded endpoint

- `RosterCheckService::checkRoster(rosterId)` (and `checkPeriod(period, administrationId)`) resolves
  the roster + its `RosterAssignment`s via container-resolved `ObjectService` (the `RuleAuditService`
  idiom) and runs the `RuleEngine` over exactly that assignment set — regardless of publish status,
  so a `concept` roster can be validated *before* publishing. Returns per-assignment violations and a
  mandatory/advisory count. Never-throw degradation.
- `occ hrmq:roster:check --roster ID | --period YYYY-Www [--administration ADM]` prints the
  per-assignment ATW outcome and exits non-zero on any `mandatory` violation, `0` otherwise (the
  `hrmq:rules:audit` exit-code convention). Registered in `appinfo/info.xml`.
- `POST /api/roster/check` (`RosterController::check`, `#[NoAdminRequired]`) resolves the posted
  `rosterId` through `ObjectService` under the caller's ambient RBAC first (unknown/unauthorised →
  404, the `DocumentController` no-admin-idor pattern), then delegates to `RosterCheckService`. ONE
  endpoint, no CRUD (ADR-022). Route added before the SPA catch-all in `appinfo/routes.php`.

### D6 — Manifest: list/detail surfaces + the publish lifecycle + the ATW-controle action

- `Shifts` (index: `name`, `startTime`, `endTime`, `breakMinutes`, `active`) + `ShiftDetail`
  (data + related).
- `Rosters` (index: `period`, `orgUnitId`, `status`, sorted `period` desc) + `RosterDetail`:
  data + related widgets, a `lifecycleActions` widget for `publiceren`/`intrekken` (D3), an
  `api-call` action **"ATW-controle"** (`url:/api/roster/check`, `method:POST`,
  `params:{rosterId:"@objectId"}`, confirm + success/error toasts), and an FK-scoped
  `RosterAssignments` child `object-list` (`filter:{rosterId:"@objectId"}`).
- `RosterAssignments` (index: `date`, `employeeId`, `shiftId`, `plannedStart`, `plannedEnd`, sorted
  `date` asc — the "calendar-ish", MVP planning surface; a true planbord/grid is a Non-Goal) +
  `RosterAssignmentDetail` (data + related). Menu entry under the planning group.
- `npm run check:manifest` gates all schema `$ref`s to real slugs (the manifest-schema-ref-must-be-slug
  discipline: multi-word schema refs must be the slug, but Shift/Roster/RosterAssignment are all
  single-token slugs).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Shift / Roster / RosterAssignment persistence & fields | **declarative** OpenRegister schemas (`hr-roster.json`) | ADR-031 default; plain CRUD data with `$ref` relations |
| Publish state machine | **declarative** `x-openregister-lifecycle` on `Roster.status` | the AttendanceRecord/Timesheet precedent — no imperative status writes |
| Planned-clock projection (shift → assignment fields) | imperative (assignment write) | composing `date` + `HH:MM` into `date-time` with a night-shift roll-over is not expressible in `x-openregister-calculations` (prop/+/- over numerics only — the `AttendanceRecord.workedHours` precedent) |
| ATW compliance decision | **corpus rules + CheckProvider predicates** (`NlRosterChecks`) | the app's established exception; here it *reuses* the three existing ATW rules, adding no new law |
| Roster audit sibling index | imperative `RuleAuditService::buildRosterContext()` | cross-object daily-rest needs a per-employee/date index (the `buildAttendanceContext()` precedent) |
| Check trigger | occ command + ONE guarded endpoint | operator-demand; the roster lifecycle exists but hard-guarding it is a Non-Goal |
| Roster pages | declarative manifest (`lifecycleActions`, `api-call`, object-list) | ADR-031 default; v2 supports all three |

## Seed Data (ADR-001)

No new seed objects required for the MVP. The golden path is exercised through the real stack: seed
(or hand-create) a couple of `Shift`s and a `concept` `Roster` with `RosterAssignment`s that violate
`nl-atw-dagelijkse-rust` (e.g. a late shift ending 23:00 followed by an early shift starting 06:00
next day → 7h rest < 11h), run `occ hrmq:roster:check --roster <id>` and confirm the mandatory
violation + non-zero exit; publish the roster and confirm `occ hrmq:rules:audit` now reports the same
violation for the published assignments. If a seed slice is added later it belongs in `hr-seed.json`
alongside the existing attendance/timesheet seeds, keeping realised and planned data parallel.

## Risks / Trade-offs

- **Projected planned-clock fields can drift from a later-edited Shift.** Intended: a published plan
  must be stable; `intrekken → edit → publiceren` is the correction path, and the projection is
  re-derived on each assignment write. Documented, not automated.
- **Reusing AttendanceRecord constants couples two providers.** Mitigated by reading the shared
  values from one place (`NlAttendanceChecks` constants + the corpus `breakTiers`) rather than
  re-declaring them — a change to the norm updates both call sites at once, which is the point.
- **Concept rosters excluded from the standing audit.** Deliberate (D4): planning-in-progress must
  not spam mandatory violations app-wide; the on-demand check covers concept rosters where asked.
- **No publish-time hard block.** MVP surfaces the verdict; blocking is `hrmq-rule-compliance-enforcement`'s
  guard scope — named, not silently dropped.

## Open Questions

- None blocking. Availability/preferences, a visual planbord, coverage alerts and the openconnector
  WFM optimiser integration are named fast-follows / Non-Goals, not gaps in this MVP.
