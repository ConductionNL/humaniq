# time-entry-capture

## MODIFIED Requirements

### Requirement: humaniq captures time entries under a submit→approve lifecycle (REQ-TEC-001)

humaniq SHALL capture worked time at two granularities with English schema names (Dutch only via
l10n labels): an individual booking is a **`TimeEntry`** (NL "urenregistratie") carrying a `date`,
optional `startedAt`/`endedAt` timestamps, an optional `breakMinutes`, an `hours` value, a
`description`, an optional `projectId` and a `billable` flag; the per-employee, per-period
aggregate is the **`Timesheet`** (NL "urenstaat"), which keeps `period` and alone carries the
declarative `x-openregister-lifecycle` submit → approve/reject → reopen state machine on
`status`. A `TimeEntry` never has its own lifecycle.

A `TimeEntry` SHALL be recorded in one of exactly two shapes, and SHALL be refused when it is
neither:

- **Clocked.** `startedAt` and `endedAt` are both present. `hours` SHALL be derived server-side
  from `(endedAt − startedAt − breakMinutes)`; a booking whose end does not lie after its start,
  or whose break equals or exceeds the span, SHALL be refused with a structured error. This is
  unchanged.
- **Booked to a day.** Neither `startedAt` nor `endedAt` is present. A valid `date` and a
  positive `hours` SHALL both be required, and an `hours` above 24 SHALL be refused: on a single
  day that is a data error rather than a long shift, and accepting it would corrupt the timesheet
  total it feeds.

`startedAt`/`endedAt` were required and are no longer. The other two apps in the fleet that
record time — pipelinq and planninq — capture a duration booked to a day and no clock times at
all, so an owner that required them could only have taken their bookings by fabricating a start
and an end that nobody measured.

The either/or SHALL be enforced server-side rather than by the schema's `required`, which cannot
express it.

`date` SHALL be stamped on every write from the booking's reference day, so a clocked entry
written before the field existed gains one on its next write rather than needing a data
migration, and both shapes answer "which day?" identically for the timesheet grain and the
cost-centre lookup.

#### Scenario: A worker logs and submits hours for approval

- **GIVEN** an employee with a linked Nextcloud account
- **WHEN** they book a TimeEntry with `startedAt` 2026-05-04T09:00Z, `endedAt` 2026-05-04T17:30Z
  and `breakMinutes` 30
- **THEN** the entry persists with a server-derived `hours` of 8.00 and a stamped `date` of
  2026-05-04

#### Scenario: A duration is booked to a day

- **GIVEN** an employee with a linked Nextcloud account
- **WHEN** they book a TimeEntry with `date` 2026-05-04 and `hours` 6.5, and no clock times
- **THEN** the entry persists with `hours` 6.5 and is filed on the 2026-05 timesheet

#### Scenario: An entry in neither shape is refused

- **WHEN** a TimeEntry is written with no clock times and no date
- **THEN** it is refused with a structured error naming the missing date

#### Scenario: An impossible day booking is refused

- **WHEN** a TimeEntry is booked to a day with no hours, with zero or negative hours, or with
  more than 24 hours
- **THEN** it is refused with a structured error naming that reason
