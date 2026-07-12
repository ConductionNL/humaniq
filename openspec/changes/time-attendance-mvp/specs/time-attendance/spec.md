# Spec: time-attendance

**Status:** in-progress
**Scope:** hrmq
**Kind:** config (declarative schema + manifest + corpus data; check methods and the audit-context builder are the app's established rule-corpus exception)

**OpenSpec changes**
- `time-attendance-mvp` (2026-07-12)

## Purpose

Give hrmq a raw clock surface with Dutch working-time-law depth no Nextcloud app has: a per-employee/per-day `AttendanceRecord` (clockIn/clockOut/breakMinutes, stored writer-maintained `workedHours`, declarative open⇄gesloten lifecycle), three machine-checkable Arbeidstijdenwet rules (11-hour dagelijkse rust art. 5:3, 12-hour max dienst art. 5:7, statutory pauze art. 5:4) under a new `nl-arbeidstijdenwet` framework slug with a `NlAttendanceChecks` provider and the cross-record daily-rest context in `RuleAuditService`, attendance pages under the existing `Verlof & verzuim` group, and a `MijnAanwezigheid` `@me` self-service page under `Mijn HR`. AttendanceRecord is raw clock data; `Timesheet` remains the per-period approved-hours record — aggregation between them is a documented follow-up, not MVP automation.

## ADDED Requirements

### Requirement: A new `AttendanceRecord` schema SHALL model one clock day per employee with an open⇄gesloten lifecycle (REQ-TA-001)

A new fragment `lib/Settings/register.d/hr-attendance.json` (`x-hrmq-fragment: hr-attendance`) SHALL declare `AttendanceRecord` (version 0.1.0): `employeeId` (string, format uuid, `$ref` Employee, required), `date` (string, format date, required — the working day the record belongs to, including for night shifts whose clock-out falls after midnight), `clockIn` (string, format date-time, required — ISO 8601 UTC, the fleet's `submittedAt` timestamp convention), `clockOut` (string, format date-time, nullable — null while the day is open), `breakMinutes` (number, minimum 0, default 0), `workedHours` (number, nullable — stored per REQ-TA-002), `location` (string enum `kantoor`/`thuis`/`klant`/`anders`, nullable), `userId` (string, nullable — denormalized NC user id, the `Timesheet.userId` mijn-hr-self-service pattern: a plain string copy of the linked Employee's `nextcloudUserId`, never a `$ref`), `status` (string enum `open`/`gesloten`, default `open`, required). `configuration` declares `x-openregister-lifecycle`: `field: status`, `initial: open`, transitions `sluiten` (open→gesloten; description documents that the carrying write supplies `clockOut` and `workedHours`) and `heropenen` (gesloten→open; description documents reopening for correction under the audit trail). No approval states — approval lives on `Timesheet` (design D4/D5), and the schema description documents that boundary. Every property carries title + description (gate-28). Required: `employeeId`, `date`, `clockIn`, `status`. Register `info.version` bumps 0.3.0 → 0.4.0.

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

The `x-openregister-calculations` operator vocabulary (`prop`/`+`/`-` over numeric properties, verified at HEAD via `LeaveBalance.remainingHours` and openregister's calculation validator) cannot express a date-time subtraction, so `workedHours` is NOT a declarative calculation: it is a stored nullable number the writer maintains as `(clockOut − clockIn)` in hours minus `breakMinutes / 60` (2 decimals). Its property description SHALL state this derivation, that the value is presentation/aggregation convenience, and that the ATW checks compute from the raw `clockIn`/`clockOut`/`breakMinutes` fields so a stale or wrong `workedHours` can neither mask nor fabricate a violation (design D2). No `x-openregister-calculations` annotation is declared on the schema.

#### Scenario: Checks are independent of workedHours
- **GIVEN** an AttendanceRecord with `clockIn: 2026-07-10T08:00:00Z`, `clockOut: 2026-07-10T21:00:00Z` (13h elapsed) and a hand-edited `workedHours: 8`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** a `nl-atw-max-werkdag` violation is reported regardless of the `workedHours` value

### Requirement: The rule corpus SHALL gain three machine-checkable Arbeidstijdenwet rules under a new `nl-arbeidstijdenwet` framework (REQ-TA-003)

`lib/Standards/rules/labour.json` SHALL gain `nl-atw-dagelijkse-rust` (ATW art. 5:3 lid 2 — ≥11 hours unbroken rest between consecutive working days: `clockIn(D) − clockOut(D−1) ≥ 11h` per employee; the statement notes the once-per-7-days 8-hour reduction is not modeled, so the check is the strict default norm), `nl-atw-max-werkdag` (ATW art. 5:7 lid 1 — `clockOut − clockIn ≤ 12h` per dienst) and `nl-atw-pauze` (ATW art. 5:4 lid 1 — work >5.5h requires ≥30 min break, >10h requires ≥45 min; tiers carried in `parameters.breakTiers` so the thresholds are rule data, not PHP constants — the `milestoneWeeks` convention). All three: `domain: labour`, `jurisdiction: NL`, `framework: nl-arbeidstijdenwet`, `severity: mandatory` (the corpus severity enum has no advisory tier — the `NlVerzuimChecks` precedent), `machineCheckable: true`, `effectiveDate: "1996-01-01"`, `sourceUrl: https://wetten.overheid.nl/BWBR0007671`. `lib/Standards/rules/SCHEMA.md`'s framework examples gain `nl-arbeidstijdenwet`. `RuleCatalogue::VERSION` bumps `2026-07` → `2026-07.1`.

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ hrmq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads payroll.json AND labour.json without error, reports version `2026-07.1`, and reports the three ATW rules as enforced (each has a CheckProvider predicate)

### Requirement: `NlAttendanceChecks` SHALL enforce the three ATW rules, with the daily-rest sibling index built by `RuleAuditService` (REQ-TA-004)

A new auto-discovered provider `lib/Standards/Checks/NlAttendanceChecks.php` (implements `CheckProvider`; does not exist at HEAD) SHALL register, under object type `AttendanceRecord`:
1. **Dagelijkse rust** — reads `$context['attendance']['clockByEmployeeDate']`: for a record on date *D*, if the same employee has a record on the previous calendar day with a non-null `clockOut`, the gap to this record's `clockIn` must be ≥ 11 hours; passes vacuously when no previous-day record exists (≥24h rest is implied) or when the index is absent.
2. **Max werkdag** — violation when `clockOut` is present and `clockOut − clockIn` exceeds 12 hours; passes vacuously while `clockOut` is null (open day, not decidable).
3. **Pauze** — evaluated over the tiers from the rule's `parameters.breakTiers` (never hard-coded): violation when the elapsed `clockOut − clockIn` exceeds a tier's `minHours` and `breakMinutes` is below that tier's `requiredBreakMinutes`; passes vacuously while `clockOut` is null.

`RuleAuditService` gains a private `buildAttendanceContext()` — consistent with the existing `buildRelatedContext()`/`buildGlPostContext()` pattern: loads `AttendanceRecord` objects once per audit run, returns `['clockByEmployeeDate' => [employeeId => [date => ['clockIn', 'clockOut']]]]`, wired into `audit()` as `$context['attendance']`, degrading to an empty index when the schema does not exist yet. Each predicate is side-effect free and keyed by its corpus rule id.

#### Scenario: Short overnight rest raises mandatory violation
- **GIVEN** the seed records `attendance-devries-0708` (`clockOut: 2026-07-08T23:00:00Z`) and `attendance-devries-0709` (`clockIn: 2026-07-09T07:00:00Z` — an 8-hour gap)
- **WHEN** `occ hrmq:rules:audit` runs
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

### Requirement: The attendance pages SHALL surface the record, the lifecycle, and the `@me` self-service view (REQ-TA-005)

`src/manifest.json` gains: menu child `AttendanceRecords` ("Aanwezigheid", icon `ClockOutline`) under the existing `VerlofVerzuimGroup`; menu child `MijnAanwezigheid` ("Mijn aanwezigheid", icon `ClockOutline`) under the existing `MijnHrGroup`; page `AttendanceRecords` (index over `AttendanceRecord`, route `/attendance`: columns `employeeId`, `date`, `clockIn`, `clockOut`, `workedHours`, `status`; filters `status`, `location`; sort `date` desc); page `AttendanceRecordDetail` (detail, route `/attendance/:id`: "Attendance" data widget excluding `employeeId` — Related resolves the Employee —, related widget, `lifecycleActions` exposing exactly `sluiten` ("Sluiten") and `heropenen` ("Heropenen"), audit-history sidebar tab; no files widget — the schema carries no document/retention field, the EmploymentContractDetail no-fabricated-leaves reasoning); page `MijnAanwezigheid` (index, route `/mijn/aanwezigheid`, `filter: {userId: "@me"}`, columns `date`, `clockIn`, `clockOut`, `workedHours`, `status`, sort `date` desc — mirroring `MijnUren` exactly). An `AttendanceRecord` deepLink (`/apps/hrmq/attendance/{uuid}`) is registered. The manifest MUST validate against app-manifest-v2 (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: No invented edges
- **WHEN** the manifest's `lifecycleActions.transitions` for `AttendanceRecordDetail` are compared to the `x-openregister-lifecycle` in `lib/Settings/register.d/hr-attendance.json`
- **THEN** they match action-for-action (`sluiten`/`heropenen`, same from/to) with no additional action

#### Scenario: Self-service page shows only own records
@e2e exclude declarative index filtering (@me token) is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** AttendanceRecords with `userId: "admin"` and records without a userId
- **WHEN** the `MijnAanwezigheid` page loads for the `admin` user
- **THEN** only the `userId: "admin"` records are listed, newest working day first

### Requirement: Seed data SHALL exercise a compliant day, a daily-rest breach, and a missing break (REQ-TA-006)

`lib/Settings/register.d/hr-seed.json` SHALL gain the four AttendanceRecord objects from design.md: `attendance-jansen-0709` (compliant closed day, `userId: "admin"` so `MijnAanwezigheid` is non-empty for the dev admin — matching employee-jansen's seeded `nextcloudUserId`), `attendance-devries-0708` + `attendance-devries-0709` (consecutive days with an 8-hour overnight gap → one `nl-atw-dagelijkse-rust` violation on the second record), and `attendance-bakker-0710` (8 hours with `breakMinutes: 0` → one `nl-atw-pauze` violation). Every `workedHours` equals its exact derivation, every shift is ≤ 12 hours, and all references stay obvious slug-style placeholders matching the existing seed employees.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the four records exist exactly once

#### Scenario: Seeded audit shows exactly the intended attendance violations
- **GIVEN** a fresh import of the seed data
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** the attendance violations are exactly: one `nl-atw-dagelijkse-rust` for `attendance-devries-0709` and one `nl-atw-pauze` for `attendance-bakker-0710`, with no `nl-atw-max-werkdag` violations and no pre-existing rule regressing
