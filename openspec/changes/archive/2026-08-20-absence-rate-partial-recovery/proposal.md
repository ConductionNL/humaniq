---
kind: code
---

## Why

hrmq cannot compute a verzuimpercentage, and the reason is a missing dimension in one schema.

`SickLeaveCase.status` is `gemeld` or `hersteld` and nothing in between
(`lib/Settings/register.d/hr-verzuim.json`). There is no per-day absence record and no
partial-recovery percentage, so an absence can only be counted as whole calendar days from
`firstSickDay` to `recoveredDate`. Under the Wet verbetering poortwachter that is wrong for most
long cases by construction: partial work resumption is the entire point of the re-integration
duty, so the cases that dominate the absence figure are precisely the ones whole-day counting
overstates.

`verzuim-analytics-widgets` (archived 2026-07-13) already hit this. It shipped four `stat` widgets
— open ziektegevallen, langdurig verzuim past the 42-weken horizon, verlofaanvragen in behandeling,
approved verlofuren this month — and named Bradford factor and frequency/duration trend charts
non-goals "with the precise technical reason each is out". That reason was the data shape, and this
change fixes the data shape rather than working around it again.

The distinction matters more than a rounding error. A dashboard tile labelled *verzuimpercentage*
fed by whole-day counting is not a conservative approximation of the sector figure — it is a
different number wearing that number's name, and HR benchmarks against the sector figure.

## What Changes

- **`SickLeaveCase` gains `absenceProgression`** — an ordered array of
  `{ effectiveFrom, absencePercentage }` steps recording partial work resumption over time. Each
  step holds its percentage from `effectiveFrom` until the next step begins.
- **`SickLeaveCase` gains `currentAbsencePercentage`** — a stored projection of the latest step
  that has taken effect, so an index column or a filter can read it without walking the array (the
  `Jaaropgaaf` aggregate precedent). Never read by the calculation.
- **New `AbsenceRateService`** — a dependency-free calculator turning cases plus
  `EmploymentContract.hoursPerWeek` into an FTE-weighted verzuimpercentage for a period.
- **`SickLeaveCaseDetail` gains a "Work resumption" data widget** so HR can record progression;
  the two new fields are excluded from the generic "Case" widget so they are not rendered twice.
- No new route, no controller, no lifecycle change, no dashboard widget. The service is the unit
  the dashboard rebuild will consume; wiring it to an analytics endpoint is a separate change.

**Not a breaking change, by construction.** `absenceProgression` absent or empty means *full
absence for the whole case window* — so every case recorded before this field existed produces
exactly the figure it produced before, and the field can be back-filled case by case rather than
migrated.

## AVG position

`REQ-VWP-002` bans diagnosis, symptom, cause, medical notes, and unconstrained free-text fields on
this schema. `absenceProgression` carries a date and a percentage and nothing else — deliberately
no `reason`, `note`, or comment field, which is the shape that clause forbids.

Recording the *extent* of work resumption is lawful and separately required: the Autoriteit
Persoonsgegevens beleidsregels "De zieke werknemer" permit an employer to record to what extent an
employee can resume work — it drives loondoorbetaling under BW 7:629 and the WVP re-integration
duty — while forbidding why. `aangepastLoon` already records the euro consequence of exactly this
fact; this change records when and how much, which is what a rate needs.

## Capabilities

### New Capabilities
- `absence-rate`: hrmq computes an FTE-weighted verzuimpercentage over a period from recorded
  partial work resumption.

### Modified Capabilities
- `verzuim-wvp`: `SickLeaveCase` gains two administrative fields; the no-medical-data invariant of
  REQ-VWP-002 is unchanged and re-argued for the new fields.
- `verzuim-analytics-widgets`: the data-shape reason its trend charts were declared non-goals no
  longer holds. This change does not add those charts — it removes the blocker.

## Impact

- **`lib/Settings/register.d/hr-verzuim.json`** — two properties added, schema version 0.3.0 → 0.4.0.
- **`lib/Service/AbsenceRateService.php`** (new) — pure calculator, no DI.
- **`tests/Unit/Service/AbsenceRateServiceTest.php`** (new) — 14 tests.
- **`src/manifest.json`** — one data widget added to `SickLeaveCaseDetail`, two fields excluded
  from its "Case" widget, the files widget shifted down 3 grid rows.
- No PHP route, no schema rename, no data migration.
