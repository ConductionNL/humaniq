# absence-rate Specification

**Status**: proposed
**Scope**: hrmq
**Kind**: code (one schema fragment + one pure service + manifest widget)

## Purpose

Give hrmq the FTE-weighted verzuimpercentage the sector reports, by recording partial work
resumption on `SickLeaveCase` and computing a rate from it — rather than counting whole calendar
days, which overstates every case where the employee is partly back at work.

## Requirements

### Requirement: `SickLeaveCase` SHALL record partial work resumption over time (REQ-ABSRATE-003)

`SickLeaveCase` SHALL declare `absenceProgression`: an array whose items require `effectiveFrom`
(date) and `absencePercentage` (number, 0–100), and declare no other property. Each entry sets the
percentage of contracted hours the employee is absent from `effectiveFrom` onward until the next
entry's `effectiveFrom`.

`SickLeaveCase` SHALL additionally declare `currentAbsencePercentage` (number, 0–100, nullable) as
a stored projection of the latest entry that has taken effect, for index columns and filters. It
is a projection only: no rate computation reads it, so a stale value cannot corrupt a reported
figure.

An absent or empty `absenceProgression` SHALL mean full absence for the whole case window.

The array SHALL carry no free-text, reason, note, diagnosis, symptom or cause field, preserving
`verzuim-wvp` REQ-VWP-002 unchanged.

#### Scenario: A case recorded before the field existed is unaffected
@e2e exclude pure schema + calculator contract; covered by AbsenceRateServiceTest, and the app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** a `SickLeaveCase` with `firstSickDay` 2025-12-01, status `gemeld`, and no `absenceProgression`
- **WHEN** the absence rate is computed over January 2026 for a 40h/week employee
- **THEN** it reports 31 absent day-equivalents of 31 available — 100% — the same figure whole-day counting produced

#### Scenario: The schema declares no field that could carry medical data
@e2e exclude schema-shape assertion, verified by reading the fragment
- **GIVEN** the `absenceProgression` items schema
- **WHEN** its declared properties are enumerated
- **THEN** they are exactly `effectiveFrom` and `absencePercentage` — no free-text property of any kind

### Requirement: `AbsenceRateService` SHALL compute an FTE-weighted verzuimpercentage for a period (REQ-ABSRATE-001)

`AbsenceRateService::absenceRate()` SHALL accept sick-leave cases, employment contracts, a period
start and end (both inclusive) and an optional full-time hours-per-week, and SHALL return
`absentDayEquivalents`, `availableDayEquivalents`, `percentage` and `casesWithoutContract`.

`percentage` SHALL be `absentDayEquivalents / availableDayEquivalents * 100`, where availability is
each contract's days overlapping the period weighted by `hoursPerWeek / fullTimeHoursWeek`.

The full-time week SHALL be a caller-supplied parameter defaulting to 40, because it is
CAO-dependent (36 across much of the public sector, 38 in parts of care) and a constant would
misreport every employer on a different week.

#### Scenario: Partial resumption produces a lower rate than whole-day counting
@e2e exclude pure calculator; covered by AbsenceRateServiceTest
- **GIVEN** one 40h/week employee absent from 2025-12-01, at 100% until 2026-01-15 and 40% from 2026-01-16
- **WHEN** the rate is computed over January 2026
- **THEN** it reports 21.4 absent day-equivalents and 69.03% — where whole-day counting reported 31 and 100%

#### Scenario: Absence is weighted by FTE, not by headcount
@e2e exclude pure calculator; covered by AbsenceRateServiceTest
- **GIVEN** a 40h and a 20h employee, and the 20h one absent for all of January 2026
- **WHEN** the rate is computed
- **THEN** it reports 15.5 of 46.5 day-equivalents — 33.33% — not the 50% a headcount ratio gives

#### Scenario: A non-40-hour full-time week is honoured
@e2e exclude pure calculator; covered by AbsenceRateServiceTest
- **GIVEN** a 36h/week employee absent all month and `fullTimeHoursWeek` of 36
- **WHEN** the rate is computed
- **THEN** it reports 100%, not the 90% a hardcoded 40-hour week would give

### Requirement: The case window SHALL be bounded by the lifecycle status, not by a stale date (REQ-ABSRATE-002)

A case's absence SHALL be clipped to the intersection of the period and the case window. The window
closes on `recoveredDate` only while `status` is `hersteld`; a `recoveredDate` left behind on a
reopened (`gemeld`) case SHALL NOT truncate it, because `heropenen` continues one samengesteld
ziektegeval (BW 7:629 lid 10).

Steps SHALL be sorted before being walked, clipped onto `firstSickDay` when dated earlier, and
skipped when malformed. A progression whose first step begins after `firstSickDay` SHALL count the
opening stretch as full absence. A progression that is entirely malformed SHALL fall back to full
absence, never to zero — a data-entry error must not read as a clean absence record.

#### Scenario: A reopened case is not truncated by its stale recovery date
@e2e exclude pure calculator; covered by AbsenceRateServiceTest
- **GIVEN** a case with `firstSickDay` 2026-01-01, `recoveredDate` 2026-01-10 and status `gemeld`
- **WHEN** the rate is computed over January 2026
- **THEN** it reports 31 absent day-equivalents, not 10

#### Scenario: A resumption-only progression still counts the opening stretch
@e2e exclude pure calculator; covered by AbsenceRateServiceTest
- **GIVEN** a case starting 2026-01-01 whose only step is 50% from 2026-01-21
- **WHEN** the rate is computed over January 2026
- **THEN** it reports 25.5 day-equivalents — 20 days at 100% plus 11 days at 50%

### Requirement: An unmeasurable absence SHALL be excluded and reported, never estimated (REQ-ABSRATE-004)

A case whose employee has no employment contract overlapping the period SHALL contribute to neither
numerator nor denominator, and SHALL be counted in `casesWithoutContract` so the caller can surface
it. Weighting it at an assumed FTE would invent the figure this capability exists to stop
inventing.

When `availableDayEquivalents` is zero, `percentage` SHALL be `null`, never `0.0`. A period with no
contracted employees has no absence rate, and `0.0` renders on a dashboard as "0% — excellent",
which is a measurement that never ran presented as a good result.

#### Scenario: An absence with no contract is excluded and counted
@e2e exclude pure calculator; covered by AbsenceRateServiceTest
- **GIVEN** a case for an employee with no contract, alongside one contracted 40h employee
- **WHEN** the rate is computed
- **THEN** it reports 0 absent day-equivalents, 31 available, and `casesWithoutContract` of 1

#### Scenario: No availability yields null rather than zero
@e2e exclude pure calculator; covered by AbsenceRateServiceTest
- **GIVEN** no cases and no contracts
- **WHEN** the rate is computed
- **THEN** `percentage` is null

### Requirement: HR SHALL be able to record progression from the case dossier (REQ-ABSRATE-005)

`SickLeaveCaseDetail` SHALL carry a full-width "Work resumption" `data` widget including
`currentAbsencePercentage` and `absenceProgression`, and SHALL exclude both from its generic "Case"
widget so they render once. The manifest MUST validate.

#### Scenario: The dossier renders the resumption record once
@e2e exclude manifest wiring; declarative widget rendering is covered by the shared nextcloud-vue component tests
- **GIVEN** the `SickLeaveCaseDetail` page
- **WHEN** its widgets are enumerated
- **THEN** `absenceProgression` appears in exactly one widget's `include` and in the "Case" widget's `exclude`
