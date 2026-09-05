# A time entry can be booked to a day

## Why

Three apps in the fleet declare a time-entry schema, and a schema slug is global
per organisation, so `SchemaMapper::find()` returns whichever row it reaches
first. The decision taken was to consolidate onto one owner, and humaniq is it.

Measuring the three showed why that cannot be a slug rename:

| | humaniq `TimeEntry` | pipelinq `timeEntry` | planninq `timeEntry` |
| --- | --- | --- | --- |
| required | `startedAt`, `endedAt` | `title`, `hours`, `date` | `task`, `user`, `duration`, `date` |
| shape | a clocked interval | a duration booked to a day | a duration booked to a day |

humaniq records when work STARTED and STOPPED and derives the hours;
`TimeEntryStampListener::deriveHours()` throws
`De start- of eindtijd van de urenboeking is ongeldig` when either is absent,
and a client-supplied `hours` is overwritten rather than read. Neither of the
other two captures clock times at all.

So the owner refuses the shape both consumers record. Moving them onto it as it
stands would have meant fabricating a start and an end that nobody measured,
which is worse than the collision.

## What changes

`TimeEntry` accepts both shapes. It gains a `date` (the day the hours are booked
to), and `startedAt`/`endedAt` stop being required.

`deriveHours()` branches once, at the top:

- **clocked** — `startedAt` and `endedAt` present: unchanged, including every
  refusal (end not after start, break swallowing the span).
- **booked to a day** — neither present: a valid `date` and a positive `hours`
  are required, and hours above 24 are refused as a data error rather than a
  long shift.

The invariant "either a clocked pair or a day and an hours" is enforced in the
listener rather than in the schema, because JSON Schema's `required` cannot
express an either/or, and the listener is already where the other refusals live
with their structured Dutch messages.

`date` is STAMPED, not required. A clocked entry gets its `date` from
`gmdate('Y-m-d', $startedAt)` on every write, so an entry written before this
field existed gains one on its next write and no data migration is needed. Both
shapes answer "which day?" identically afterwards, which is what the timesheet
grain and the cost-centre lookup read.

## What this does NOT do

It does not move pipelinq or planninq. This is the owner-side prerequisite they
both need, shipped and tested on its own, the way OpenRegister's writable
organisation projection went ahead of stackiq's migration.

Each consumer still needs its own change: a satellite schema for the fields the
owner has no column for (pipelinq's `client`, `lead`, `billingCategory`,
`status`, `approvedAt`/`approvedBy`, `wipSyncStatus`/`wipSyncedAt`,
`billingBatchId`, `billingInvoiceId`, `metadata`, `title`; planninq's
`contractorRef` and `hourlyRate`), a mapping for `task` onto the existing
`domainObjectType`/`domainObjectRef` pair, a `user` to `employeeId` resolution,
and a migration for existing rows.
