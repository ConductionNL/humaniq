---
capability: time-attendance
status: done
built_by: openspec/changes/archive/2026-07-13-time-attendance-mvp
---

# time-attendance Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [time-attendance-mvp](../../changes/archive/2026-07-13-time-attendance-mvp/) _(archived 2026-07-13)_ — `AttendanceRecord` per-day clock schema with open⇄gesloten lifecycle, 3 machine-checkable Arbeidstijdenwet rules (dagelijkse rust / max werkdag / pauze) under the new `nl-arbeidstijdenwet` framework with `NlAttendanceChecks` + the cross-record daily-rest audit context, attendance pages under `Verlof & verzuim` and `MijnAanwezigheid` under `Mijn HR` (kind: config)
- [matrix-rows-without-a-requirement](../../changes/archive/2026-11-21-matrix-rows-without-a-requirement/) _(archived 2026-11-21)_ — adds the requirements above that the HR matrix rows named without one

## Purpose

Give humaniq a raw clock surface with Dutch working-time-law depth no Nextcloud
app has (Spectr `hrmq-insight-nc-ecosystem-gap`: German ArbZG time-compliance
apps exist in the ecosystem, nothing covers the Arbeidstijdenwet): a
per-employee/per-day `AttendanceRecord` (clockIn/clockOut/breakMinutes,
stored writer-maintained `workedHours`, declarative open⇄gesloten
lifecycle), three versioned machine-checkable ATW rules — ≥11h dagelijkse
rust between consecutive working days (art. 5:3 lid 2, a cross-record check
fed by a `RuleAuditService` attendance context), ≤12h per dienst (art. 5:7
lid 1), and the statutory pauze tiers (art. 5:4 lid 1) — enforced by
`NlAttendanceChecks`, plus the attendance pages under the existing
`Verlof & verzuim` menu group and a `MijnAanwezigheid` `@me` self-service
page. AttendanceRecord is raw clock data; `Timesheet` remains the per-period
approved-hours record — attendance→timesheet aggregation is an explicitly
deferred follow-up. Clock-in channels (PWA/kiosk/API), GPS/geofence
verification and CAO overtime/toeslag calculation from the 2026-05-23
`spec/time-attendance` draft are likewise out of scope here.

## Requirements

### Requirement: A new `AttendanceRecord` schema SHALL model one clock day per employee with an open⇄gesloten lifecycle (REQ-TA-001)

A new fragment `lib/Settings/register.d/hr-attendance.json` (`x-humaniq-fragment: hr-attendance`) declares `AttendanceRecord` (version 0.1.0): `employeeId` (string, format uuid, `$ref` Employee, required), `date` (string, format date, required — the working day the record belongs to, including for night shifts whose clock-out falls after midnight), `clockIn` (string, format date-time, required — ISO 8601 UTC, the fleet's `submittedAt` timestamp convention), `clockOut` (string, format date-time, nullable — null while the day is open), `breakMinutes` (number, minimum 0, default 0), `workedHours` (number, nullable — stored per REQ-TA-002), `location` (string enum `kantoor`/`thuis`/`klant`/`anders`, nullable), `userId` (string, nullable — denormalized NC user id, the `Timesheet.userId` mijn-hr-self-service pattern: a plain string copy of the linked Employee's `nextcloudUserId`, never a `$ref`), `status` (string enum `open`/`gesloten`, default `open`, required). `configuration` declares `x-openregister-lifecycle`: `field: status`, `initial: open`, transitions `sluiten` (open→gesloten; description documents that the carrying write supplies `clockOut` and `workedHours`) and `heropenen` (gesloten→open; description documents reopening for correction under the audit trail). No approval states — approval lives on `Timesheet` (design D4/D5), and the schema description documents that boundary. Every property carries title + description (gate-28). Required: `employeeId`, `date`, `clockIn`, `status`. Register `info.version` bumped 0.4.0 → 0.5.0 (HEAD had already advanced past the proposal's assumed 0.3.0 baseline via intervening merges).

#### Scenario: Record walks close and reopen
- **GIVEN** an AttendanceRecord created with `date: 2026-07-09` and `clockIn` (status defaults to `open`)
- **WHEN** `sluiten` is executed via the OpenRegister lifecycle endpoint with the carrying write setting `clockOut` and `workedHours`
- **THEN** the object's `status` is `gesloten`; **AND WHEN** `heropenen` is executed **THEN** `status` returns to `open`

#### Scenario: Illegal transition rejected
- **GIVEN** a record in status `open`
- **WHEN** the `heropenen` action is attempted
- **THEN** OpenRegister rejects the transition (`heropenen` is only declared from `gesloten`)

#### Scenario: Incomplete record rejected
- **WHEN** an AttendanceRecord is written without `clockIn`
- **THEN** OpenRegister schema validation rejects it (required-property violation)

### Requirement: `workedHours` SHALL be a stored, writer-maintained field that compliance checks never read (REQ-TA-002)

The `x-openregister-calculations` operator vocabulary (`prop`/`+`/`-` over numeric properties, verified at HEAD via `LeaveBalance.remainingHours` and openregister's calculation validator) cannot express a date-time subtraction, so `workedHours` is NOT a declarative calculation: it is a stored nullable number the writer maintains as `(clockOut − clockIn)` in hours minus `breakMinutes / 60` (2 decimals). Its property description states this derivation, that the value is presentation/aggregation convenience, and that the ATW checks compute from the raw `clockIn`/`clockOut`/`breakMinutes` fields so a stale or wrong `workedHours` can neither mask nor fabricate a violation (design D2). No `x-openregister-calculations` annotation is declared on the schema.

#### Scenario: Checks are independent of workedHours
- **GIVEN** an AttendanceRecord with `clockIn: 2026-07-10T08:00:00Z`, `clockOut: 2026-07-10T21:00:00Z` (13h elapsed) and a hand-edited `workedHours: 8`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** a `nl-atw-max-werkdag` violation is reported regardless of the `workedHours` value

Pinned by `tests/Unit/Standards/Checks/NlAttendanceChecksTest::testMaxWerkdagIgnoresAHandEditedWorkedHours`.

### Requirement: The rule corpus SHALL gain three machine-checkable Arbeidstijdenwet rules under a new `nl-arbeidstijdenwet` framework (REQ-TA-003)

`lib/Standards/rules/labour.json` gained `nl-atw-dagelijkse-rust` (ATW art. 5:3 lid 2 — ≥11 hours unbroken rest between consecutive working days: `clockIn(D) − clockOut(D−1) ≥ 11h` per employee; the statement notes the once-per-7-days 8-hour reduction is not modeled, so the check is the strict default norm), `nl-atw-max-werkdag` (ATW art. 5:7 lid 1 — `clockOut − clockIn ≤ 12h` per dienst) and `nl-atw-pauze` (ATW art. 5:4 lid 1 — work >5.5h requires ≥30 min break, >10h requires ≥45 min; tiers carried in `parameters.breakTiers` so the thresholds are rule data, not PHP constants — the `milestoneWeeks` convention). All three: `domain: labour`, `jurisdiction: NL`, `framework: nl-arbeidstijdenwet`, `severity: mandatory` (the corpus severity enum has no advisory tier — the `NlVerzuimChecks` precedent), `machineCheckable: true`, `effectiveDate: "1996-01-01"`, `sourceUrl: https://wetten.overheid.nl/BWBR0007671`. `lib/Standards/rules/SCHEMA.md`'s framework examples gained `nl-arbeidstijdenwet`. `RuleCatalogue::VERSION` was bumped `2026-07.2` → `2026-07.3` (HEAD had already advanced past the proposal's assumed `2026-07` baseline via intervening merges).

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ humaniq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads payroll.json AND labour.json without error, reports version `2026-07.3`, and reports the three ATW rules as enforced (each has a CheckProvider predicate)

### Requirement: `NlAttendanceChecks` SHALL enforce the three ATW rules, with the daily-rest sibling index built by `RuleAuditService` (REQ-TA-004)

A new auto-discovered provider `lib/Standards/Checks/NlAttendanceChecks.php` (implements `CheckProvider`) registers, under object type `AttendanceRecord`:
1. **Dagelijkse rust** — reads `$context['attendance']['clockByEmployeeDate']`: for a record on date *D*, if the same employee has a record on the previous calendar day with a non-null `clockOut`, the gap to this record's `clockIn` must be ≥ 11 hours; passes vacuously when no previous-day record exists (≥24h rest is implied) or when the index is absent.
2. **Max werkdag** — violation when `clockOut` is present and `clockOut − clockIn` exceeds 12 hours; passes vacuously while `clockOut` is null (open day, not decidable).
3. **Pauze** — evaluated over the tiers from the rule's `parameters.breakTiers` (never hard-coded): violation when the elapsed `clockOut − clockIn` exceeds a tier's `minHours` and `breakMinutes` is below that tier's `requiredBreakMinutes`; passes vacuously while `clockOut` is null.

`RuleAuditService` gained a private `buildAttendanceContext()` — consistent with the existing `buildRelatedContext()`/`buildGlPostContext()` pattern: loads `AttendanceRecord` objects once per audit run, returns `['clockByEmployeeDate' => [employeeId => [date => ['clockIn', 'clockOut']]]]`, wired into `audit()` as `$context['attendance']`, degrading to an empty index when the schema does not exist yet. Each predicate is side-effect free and keyed by its corpus rule id.

#### Scenario: Short overnight rest raises mandatory violation
- **GIVEN** the seed records `attendance-devries-0708` (`clockOut: 2026-07-08T23:00:00Z`) and `attendance-devries-0709` (`clockIn: 2026-07-09T07:00:00Z` — an 8-hour gap)
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** a `nl-atw-dagelijkse-rust` violation with mandatory severity is reported for `attendance-devries-0709`

#### Scenario: Missing break flagged
- **GIVEN** the seed record `attendance-bakker-0710` (8 hours elapsed, `breakMinutes: 0`)
- **WHEN** the audit runs
- **THEN** a `nl-atw-pauze` violation is reported for that object

#### Scenario: Compliant closed day passes clean
- **GIVEN** the seed record `attendance-jansen-0709` (8.5h elapsed ≤ 12, 30 min break, no adjacent working day within 11h)
- **WHEN** the audit runs
- **THEN** no attendance-rule violation is reported for that object

#### Scenario: Open day passes vacuously
- **GIVEN** an AttendanceRecord with `clockIn` set, `clockOut: null`, `status: open`
- **WHEN** the audit runs
- **THEN** no `nl-atw-max-werkdag` or `nl-atw-pauze` violation is reported for it (shift length not decidable while the day is open)

Pinned by `tests/Unit/Standards/Checks/NlAttendanceChecksTest.php` (15 tests — all three predicates, including the missing-sibling and open-day vacuous-pass paths and the `workedHours`-independence scenario) and `tests/Unit/Service/RuleAuditServiceTest::testSeededAttendanceDataFlagsExactlyTheIntendedViolations` (the `buildAttendanceContext()` index exercised end-to-end through `RuleAuditService::audit()` against the exact seeded fixture shapes).

### Requirement: The attendance pages SHALL surface the record, the lifecycle, and the `@me` self-service view (REQ-TA-005)

`src/manifest.json` gained: menu child `AttendanceRecords` ("Aanwezigheid", icon `ClockOutline`) under the existing `VerlofVerzuimGroup`; menu child `MijnAanwezigheid` ("Mijn aanwezigheid", icon `ClockOutline`) under the existing `MijnHrGroup`; page `AttendanceRecords` (index over `AttendanceRecord`, route `/attendance`: columns `employeeId`, `date`, `clockIn`, `clockOut`, `workedHours`, `status`; filters `status`, `location`; sort `date` desc); page `AttendanceRecordDetail` (detail, route `/attendance/:id`: "Attendance" data widget excluding `employeeId` — Related resolves the Employee —, related widget, `lifecycleActions` exposing exactly `sluiten` ("Sluiten") and `heropenen` ("Heropenen"), audit-history sidebar tab; no files widget — the schema carries no document/retention field, the EmploymentContractDetail no-fabricated-leaves reasoning); page `MijnAanwezigheid` (index, route `/mijn/aanwezigheid`, `filter: {userId: "@me"}`, columns `date`, `clockIn`, `clockOut`, `workedHours`, `status`, sort `date` desc — mirroring `MijnUren` exactly). An `AttendanceRecord` deepLink (`/apps/humaniq/attendance/{uuid}`) is registered. The manifest validates against app-manifest-v2 (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: No invented edges
- **WHEN** the manifest's `lifecycleActions.transitions` for `AttendanceRecordDetail` are compared to the `x-openregister-lifecycle` in `lib/Settings/register.d/hr-attendance.json`
- **THEN** they match action-for-action (`sluiten`/`heropenen`, same from/to) with no additional action

#### Scenario: Self-service page shows only own records
@e2e exclude declarative index filtering (@me token) is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** AttendanceRecords with `userId: "admin"` and records without a userId
- **WHEN** the `MijnAanwezigheid` page loads for the `admin` user
- **THEN** only the `userId: "admin"` records are listed, newest working day first

### Requirement: Seed data SHALL exercise a compliant day, a daily-rest breach, and a missing break (REQ-TA-006)

`lib/Settings/register.d/hr-seed.json` gained the four AttendanceRecord objects: `attendance-jansen-0709` (compliant closed day, `userId: "admin"` so `MijnAanwezigheid` is non-empty for the dev admin — matching employee-jansen's seeded `nextcloudUserId`), `attendance-devries-0708` + `attendance-devries-0709` (consecutive days with an 8-hour overnight gap → one `nl-atw-dagelijkse-rust` violation on the second record), and `attendance-bakker-0710` (8 hours with `breakMinutes: 0` → one `nl-atw-pauze` violation). Every `workedHours` equals its exact derivation, every shift is ≤ 12 hours, and all references stay obvious slug-style placeholders matching the existing seed employees.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the four records exist exactly once

#### Scenario: Seeded audit shows exactly the intended attendance violations
- **GIVEN** a fresh import of the seed data
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** the attendance violations are exactly: one `nl-atw-dagelijkse-rust` for `attendance-devries-0709` and one `nl-atw-pauze` for `attendance-bakker-0710`, with no `nl-atw-max-werkdag` violations and no pre-existing rule regressing

Pinned by `RuleAuditServiceTest::testSeededAttendanceDataFlagsExactlyTheIntendedViolations`, run against the exact seeded fixture shapes.

### Requirement: A manager SHALL see who is present, absent or late today (REQ-TA-007)

The attendance page SHALL offer a today view for the manager's team. Each person SHALL read as
present (clocked in), late (clocked in after their rostered or pattern start), away (approved
leave or sick) or not clocked in. Only people the caller may read SHALL be listed.

Rows: `tim-attendance-overview` (humaniq matrix).

#### Scenario: A team at 09:30
- **GIVEN** a team of four: one clocked in at 08:00, one at 09:15 with a 09:00 shift, one on approved leave and one not clocked in
- **WHEN** their manager opens the today view at 09:30
- **THEN** they read as present, late, away and not clocked in

#### Scenario: Another team stays hidden

@e2e exclude Needs a manager-only account; verified by AttendanceTodayServiceTest::testAnotherTeamStaysHidden.
- **WHEN** a manager opens the today view
- **THEN** people outside the units they may read are not listed

### Requirement: An employee SHALL clock in and clock out from My attendance (REQ-TA-008)

An employee SHALL start their working day from `My attendance` with one action that records the
current time as `clockIn` on a new open `AttendanceRecord`. The server SHALL stamp the record with
the caller's own employee and user id, so the employee never picks themselves. Clocking out SHALL
close the record through the existing `sluiten` transition, record the current time as
`clockOut`, and compute `workedHours` on the server from the clock times and the break.
An employee SHALL have at most one open record at a time.

Rows: `tim-clock-in` (humaniq matrix).

#### Scenario: An employee clocks in at the start of the day
- **GIVEN** an employee with a linked Nextcloud account and no open attendance record
- **WHEN** they press Clock in on `My attendance` at 08:30
- **THEN** an open attendance record for today exists with `clockIn` 08:30, their employee and their user id
- **AND** the record is listed on their `My attendance` page

#### Scenario: An employee clocks out at the end of the day
- **GIVEN** the open record from 08:30 with a 30 minute break
- **WHEN** they press Clock out at 17:00
- **THEN** the record is `gesloten` with `clockOut` 17:00 and `workedHours` 8.0

#### Scenario: A second clock in on an open day is refused
- **GIVEN** an employee whose record for today is still open
- **WHEN** they press Clock in again
- **THEN** no second record is created and the answer says a day is already open
