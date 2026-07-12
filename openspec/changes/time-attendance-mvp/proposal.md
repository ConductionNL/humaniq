---
kind: config
---

# Time & Attendance MVP (klokregistratie + Arbeidstijdenwet-regels)

## Why

The 2026-07-12 market deep-research (logged in Spectr, insights `hrmq-insight-nc-ecosystem-gap` / `hrmq-insight-ranked-buildlist`) found that time tracking is table stakes in every NL competitor (AFAS, Loket.nl, Nmbrs, Shiftbase, L1NDA) and that the Nextcloud ecosystem has German ArbZG time-compliance apps (e.g. work-time trackers citing Arbeitszeitgesetz) but **nothing covering the Dutch Arbeidstijdenwet** — no NC app checks the 11-hour dagelijkse rusttijd, the 12-hour maximum dienst, or the statutory pauze against actual clock data. hrmq's `Timesheet` schema (with its full approval lifecycle) records per-period *totals*, but there is no raw clock surface at all: no per-day clock-in/clock-out record, no break tracking, and consequently no ATW compliance evidence. The `spec/time-attendance` draft (2026-05-23) designed a five-schema clock/kiosk/GPS/geofence/CAO-toeslag platform; this change modernises that draft to a right-sized MVP against current development HEAD, where the `Verlof & verzuim` menu group, the `labour.json` corpus, the `Mijn HR` `userId`/`@me` self-service pattern, and the `RuleAuditService` related-context mechanism now all exist to build on.

## What Changes

- **New `AttendanceRecord` schema** in a new fragment `lib/Settings/register.d/hr-attendance.json` — one record per employee per working day: `employeeId` ($ref Employee), `date`, `clockIn`/`clockOut` (ISO 8601 UTC date-times, matching the fleet's `submittedAt`/`approvedAt` convention), `breakMinutes`, **stored** `workedHours` (the `x-openregister-calculations` operator vocabulary is `prop`/`+`/`-` over numbers — no time/date-time subtraction — so a declarative calculation is not expressible; the writer maintains the field and the ATW checks deliberately read the raw clock fields, never `workedHours`, so a stale value can't mask a violation), `location` (kantoor/thuis/klant/anders), `userId` (nullable NC uid, the round-1 Mijn HR denormalisation pattern), and `status` with a minimal declarative `x-openregister-lifecycle` `open ⇄ gesloten` (`sluiten`/`heropenen` — the SickLeaveCase gemeld⇄hersteld precedent: two states, no guard needed, but ADR-031 default is declarative and it feeds `lifecycleActions` on the detail page).
- **Three new machine-checkable Arbeidstijdenwet rules** in the existing corpus `lib/Standards/rules/labour.json`, under a **new framework slug `nl-arbeidstijdenwet`** (added to SCHEMA.md's framework examples; sourceUrl `https://wetten.overheid.nl/BWBR0007671`): `nl-atw-dagelijkse-rust` (≥11h rest between consecutive working days, ATW art. 5:3 lid 2 — a cross-record check fed by a new `RuleAuditService` attendance context, the established pension/glpost sibling-index mechanism), `nl-atw-max-werkdag` (≤12h per dienst, ATW art. 5:7 lid 1) and `nl-atw-pauze` (>5.5h work requires ≥30 min break, >10h requires ≥45 min, ATW art. 5:4 lid 1 — tier thresholds in rule `parameters`, the milestoneWeeks data-over-code convention). New check provider `lib/Standards/Checks/NlAttendanceChecks.php` (does not exist yet; auto-discovered by `RuleEngine::providers()`); `RuleCatalogue::VERSION` bumps.
- **Attendance pages** under the existing `Verlof & verzuim` menu group — `AttendanceRecords` (index: date/employee/clockIn/clockOut/workedHours columns) and `AttendanceRecordDetail` (data + lifecycleActions Sluiten/Heropenen + related + audit sidebar) — plus **`MijnAanwezigheid`** under the existing `Mijn HR` group, filtered `userId=@me`, mirroring `MijnUren` exactly.
- **Relation to Timesheet documented, not automated** — AttendanceRecord is raw per-day clock data; `Timesheet` stays the per-period approved-hours record. Aggregating attendance→timesheet is a declared follow-up (design.md); no automation in this MVP.
- **Seed data** — 4 AttendanceRecords for the seeded employees: one compliant closed day (stamped `userId: "admin"`, matching employee-jansen's `nextcloudUserId`), a consecutive-day pair with an 8-hour overnight gap (**violates `nl-atw-dagelijkse-rust`**), and an 8-hour day with `breakMinutes: 0` (**violates `nl-atw-pauze`**).

### Non-goals

- **No kiosk/PWA/GPS/geofence channels** — the draft's multi-channel clock-in, device fingerprints, geofence polygons and reverse geocoding are follow-up surface; MVP records are created/edited through the standard OpenRegister object UI. GPS/workplace monitoring also carries AVG/OR-consent obligations that deserve their own spec.
- **No CAO overtime/toeslag calculation** — CAO premium matrices are rulesets/configuration (ADR-001 rule 1), not MVP corpus law; the ATW rules shipped here are statute, identical for every employer.
- **No attendance→Timesheet aggregation job** and no payroll/pipelinq/planix export contracts — the draft's aggregation pipeline needs cutoff jobs and cross-object write hooks; explicitly deferred (design.md documents the boundary).
- **No weekly/4-weekly ATW averages** (art. 5:7's 55h/week and 48h/16-week averages need period windows, not per-record predicates) and no verwerking of the once-per-7-days 8-hour rest reduction — the MVP checks the unconditional per-day norms and says so honestly in the rule statements.

## Capabilities

### New Capabilities

- `time-attendance`: the `AttendanceRecord` schema with open⇄gesloten lifecycle and stored-but-honestly-documented `workedHours`, the three Arbeidstijdenwet corpus rules with the `NlAttendanceChecks` provider and the cross-record daily-rest context in `RuleAuditService`, the attendance pages under `Verlof & verzuim`, the `MijnAanwezigheid` self-service page, and the seeds exercising both violation branches.

### Modified Capabilities

<!-- none — Timesheet (hrmq-timesheet-approval), leave-management, verzuim-wvp and mijn-hr-self-service are untouched; this change only adds a sibling surface and documents the attendance↔timesheet boundary. -->

## Impact

- `lib/Settings/register.d/hr-attendance.json` — **new fragment**, `AttendanceRecord` schema (0.1.0) with `x-openregister-lifecycle`.
- `lib/Settings/hrmq_register.json` — `info.version` 0.3.0 → 0.4.0 (version-gated re-import picks up the new fragment).
- `lib/Standards/rules/labour.json` — 3 new rules (framework `nl-arbeidstijdenwet`); `lib/Standards/rules/SCHEMA.md` framework examples gain `nl-arbeidstijdenwet`; `RuleCatalogue::VERSION` bumps (`2026-07` → `2026-07.1` — same-month corpus change, SCHEMA.md requires a bump on any change).
- `lib/Standards/Checks/NlAttendanceChecks.php` — new check provider (auto-discovered).
- `lib/Service/RuleAuditService.php` — new `buildAttendanceContext()` → `$context['attendance']` per-employee clock index, following the existing `buildRelatedContext()`/`buildGlPostContext()` pattern.
- `src/manifest.json` — `AttendanceRecords` menu child under `VerlofVerzuimGroup`, `MijnAanwezigheid` child under `MijnHrGroup`, pages `AttendanceRecords`/`AttendanceRecordDetail`/`MijnAanwezigheid`, deepLink for `AttendanceRecord`.
- `lib/Settings/register.d/hr-seed.json` — 4 seed AttendanceRecords.
- `lib/Repair/InitializeRegister.php` — no change (fragment import picks up the new fragment + version bumps).
- Related: `leave-verzuim-mvp` (archived) owns the `Verlof & verzuim` group this change extends; `mijn-hr-self-service` (archived) owns the `userId`/`@me` pattern this change adopts; `hrmq-ia-navigation-alignment` owns any further IA re-ordering; `hrmq-rule-compliance-enforcement` owns guard wiring — the ATW checks here stay audit-only, per the loonaangifte/leave precedent. The superseded `spec/time-attendance` draft branch is the source material; its kiosk/GPS/aggregation scope is recorded above as follow-up.
