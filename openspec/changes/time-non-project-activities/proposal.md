---
kind: code
---

# Non-project time, leave and sickness in the same weekly timesheet

## Why

A municipality running projects wants one weekly timesheet per employee: hours on projects, hours
on training, internal meetings and other non-project work, and the leave and sick days of that
week, so the week adds up to the contract. In humaniq a time entry has a free project reference or
nothing; there is no way to say "training" or "overhead", and leave and sickness live in their own
records, invisible on the timesheet. planninq's lane decided this capability belongs to humaniq
(planninq `docs/ARCHITECTURE.md` section 5, resolved question 4: time is logged per task there,
and leave and sickness live in humaniq, whose `TimeEntry` planninq's hours are moving to).

### Matrix rows

| row | matrix | capability | humaniq today |
|---|---|---|---|
| `tim-non-project` | planninq `openspec/parity/capabilities.json` (owned by humaniq, handed over 2026-09-27) | Log time on non-project activities, such as training, leave and sickness, next to project work in the same timesheet. | `TimeEntry.projectId` is a free string; no activity kind; leave and sickness never appear on a timesheet |

### Demand

- `tim-non-project`, tender: https://www.tenderned.nl/aankondigingen/overzicht/365739 (gemeente
  Sittard-Geleen, Projectmanagementtool, requirements 4008, 4009 and 64440: weekly timesheets with
  non-project activities, sickness and leave).

### Competitors

No competitor in planninq's matrix is rated yes; OpenProject and Jira are rated partial (both
need a stand-in project for leave or training). The tender decides it build.

## What Changes

- **Activities.** A new administered `TimeActivity` (code, label, kind `project`, `non-project`
  or `absence`, billable by default or not) is seeded with training, internal meeting, overhead,
  leave and sickness. `TimeEntry` gains `activityCode`; a project booking keeps its `projectId`,
  a non-project booking has an activity instead.
- **Absence comes from the absence records.** Absence-kind activities cannot be booked by hand.
  The week view of a timesheet shows, beside the booked entries, read-only lines for the
  employee's approved leave and open sickness in that week, in hours taken from the working
  pattern, so the week adds up without booking leave twice.
- **Totals per kind.** A timesheet shows project, non-project and absence hours and the contract
  hours of the week, so an under- or over-booked week is visible before submitting.

## Capabilities

### New Capabilities

- `time-activities`: administered time activities for non-project work, and leave and sickness
  shown on the weekly timesheet from their own records.

## Impact

- `lib/Settings/register.d/hr-timesheet.json`: `TimeActivity` (new schema),
  `TimeEntry.activityCode`; `Timesheet` gains `projectHours`, `nonProjectHours`, `absenceHours`.
- `lib/Listener/TimeEntryStampListener.php`: refuses a hand booking on an absence activity.
- `lib/Service/TimesheetAggregationService.php`: totals per kind; a new
  `TimesheetWeekService` composing the absence lines through `WorkingHoursService`.
- `src/manifest.d/hr-timesheet.json`: `activityCode` on the `MijnUren` form; the week view on
  `TimesheetDetail`.

## Cross-app dependencies

- planninq: once its time entries read humaniq's `TimeEntry` (its open change
  `plannedtimeentry-reads-humaniqs-hours`), its timesheet shows these activities and absence
  lines too; the planninq matrix row is set to specified by the coordinator.

## Out of scope

- Paying for non-project hours differently. Payroll reads hours through
  `time-hours-and-overtime-to-payroll`.
