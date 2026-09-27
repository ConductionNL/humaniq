# Design: non-project time, leave and sickness in the same weekly timesheet

## Context

Read at `development` af702f78.

- `TimeEntry` (`lib/Settings/register.d/hr-timesheet.json`): `employeeId`, `timesheetId`,
  `date`, `startedAt`, `endedAt`, `breakMinutes`, `hours` (derived), `description`, `projectId`
  (a plain string, "no Project schema is modeled in humaniq"), `billable`, `costCenter`
  (stamped), `userId`, `administrationId`, `origin`; plus `domainObjectType` and
  `domainObjectRef` from `hr-cost-rate.json` for bookings against another app's object.
- `Timesheet`: the period aggregate with `hours`, `entryCount`, `status` and its lifecycle;
  `TimesheetAggregationService::computeAggregates()` (:138) recomputes it from the entries,
  writing inside `InternalWriteMarker::runInternal()`.
- `TimeEntryStampListener` stamps and guards `TimeEntry` writes (pre-save).
- `MijnUren` (`src/manifest.d/hr-timesheet.json:261`) is the booking page with an explicit
  `includeFields` allowlist.
- Absence: approved `LeaveRequest` (`startDate`, `endDate`, `hours`) and open `SickLeaveCase`
  (`firstSickDay`, `recoveredDate`, `absenceProgression`); `WorkingHoursService` answers the
  contracted hours per person per day (`a-working-calendar-per-person`).

## Goals / Non-Goals

**Goals**

- Every hour of a week has a kind: project, non-project or absence.
- Leave and sickness hours shown once, from their own records.

**Non-Goals**

- A project schema in humaniq. `projectId` and the domain object reference stay as they are.

## Decisions

### D1. `TimeActivity` as data

`code`, `label`, `kind` (`project`, `non-project`, `absence`), `billableDefault`, `active`,
`administrationId`. Seeded: `project` (kind project), `opleiding`, `intern-overleg`, `overhead`
(non-project), `verlof`, `ziekte` (absence). Alternative considered: an enum on `TimeEntry`.
Rejected for the same reason `LeaveType` became an object: an employer adds its own kinds.

### D2. Absence is composed, not booked

`TimeEntryStampListener` refuses a client write whose activity kind is `absence`.
`TimesheetWeekService::weekFor(timesheetId)` returns the booked entries plus read-only lines for
each day of the week covered by approved leave (the request's hours spread over the working days,
or the day's contracted hours) and by open sickness (contracted hours times the absence fraction
of the day). Alternative considered: writing absence `TimeEntry` rows on approval. Rejected: two
records of the same hours drift apart.

### D3. Totals

`computeAggregates()` adds `projectHours` and `nonProjectHours` from entries; `absenceHours`
comes from the week service at recompute time. The detail page shows the three and the contract
hours of the period.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| activities | declarative schema, administered objects | data |
| refusing absence bookings | pre-save listener | the existing guard |
| absence lines and totals | imperative services | composition across schemas and the working pattern |

## Seed data

- The six activities; one seed week with project hours, two hours of training, and one approved
  leave day.

## Risks / Trade-offs

- [Existing entries have no activity] → an entry with a `projectId` and no activity counts as
  project, one with neither counts as non-project `overhead`, and the page says so.

## Open Questions

- Should non-project activities be allowed per org unit only? Not in this change.
