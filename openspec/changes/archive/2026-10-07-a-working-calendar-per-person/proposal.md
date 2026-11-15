---
kind: config
---

# A working calendar per person

## Why

Cluster 67 of the round 4 competitor sweep is "working calendars, per instance, per
unit and per person" (`market-intelligence`
`procest/_round4/discovery/build-plan.md`). It holds four candidates and the sweep
names openregister as its owner: openregister extends `working-calendar-admin` and
`calendar-change-recomputes-timers`, and dossiq's `terms-on-the-engine-calendar`
consumes it.

**Decision D19 splits the cluster.** openregister owns the working calendar a term is
counted against: which days are working days, which are feestdagen, and what happens
to a running termijn when that changes. humaniq owns the person: which days and hours
that individual actually works, and when they are away. Those are different questions
with the same word in them, and answering them in one place is how a 0.6 fte
behandelaar ends up planned as a full-timer.

This change is humaniq's half. It is one candidate, and it is a dependency of
`agenda-rostering-and-resource-booking`, which cannot read capacity forward without a
real denominator.

## The candidate, with its lane citation

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-deadlines-3 | A person's own working days, hours and absences are administered per user, beside the instance calendar. | should | openproject | `deadlines.tsv:14` |

OpenProject's evidence: Administration, Users, with `resources :working_hours` and
`resources :non_working_times` under `/users/:id`, plus `working_days_preview`. Two
resources per user beside one instance calendar, which is exactly the split D19
describes.

The lane's clause names the stake: "a 0.6 fte behandelaar and a gemeente with a
Saturday counter are both ordinary, and one instance calendar expresses neither".

## What the sweep found in dossiq, and why it is not this

The sweep read dossiq as `partial` on this candidate, on
`lib/Controller/SpecialistBeschikbaarheidController.php` and
`lib/BackgroundJob/SpecialistBeschikbaarheidRefreshJob.php`. Those hold availability
for an **external specialist**, not for a caseworker, which is why the row did not
read `yes`. humaniq has nothing here at all today: `rostering` plans shifts and
`time-attendance` records what was clocked, and neither says how many hours a person
is contracted for.

## What humaniq builds

- **A working pattern per employee.** `WorkingPattern` holds the contracted hours per
  weekday for one employee over a period, with a `validFrom` and an optional
  `validUntil`. A contract change is a new pattern, not an edit, so a capacity
  read over last quarter still divides by last quarter's contract.
- **Non-working times per employee.** Days and part-days an individual does not work
  that are neither leave nor sickness: a vaste vrije dag, ouderschapsverlof taken as
  a standing Wednesday, an adjusted schedule during re-integration.
- **One answer to "how many hours does this person work on this date".** A single
  query resolving the pattern in force, subtracting the person's non-working times.
  It is the denominator every capacity and availability read divides by.
- **The instance calendar is read, not owned.** Where openregister publishes a
  working calendar, humaniq reads it to know which days are feestdagen, and the
  person's pattern narrows it. humaniq never writes to it and never defines a
  feestdag.

## How dossiq consumes it

dossiq counts termijnen against openregister's working calendar and does not read
this. It reads it for one thing only: when it asks humaniq who is available to handle
a case, the answer is already divided by the right number. dossiq holds no contract,
no fte and no working pattern.

A gemeente that wants a Saturday counter gets it from openregister's calendar, not
from here. A gemeente whose balie medewerker works Saturdays gets it from here.

## The existing specs this relates to

- `rostering` and `time-attendance` supply the planned and the realised hours. Both
  unchanged.
- `leave-management` and `verzuim-wvp` supply the absences that are leave and
  sickness. Both unchanged: a non-working time is neither, and this change does not
  become a second way to file verlof.
- openregister's `working-calendar-admin` is read. Nothing in it is extended by
  humaniq, and D19 is the reason.

## Size and dependencies

**Size: M.** Two schemas, one resolution query, two manifest pages, and a read of a
calendar another app owns.

**Depends on:** openregister's `working-calendar-admin` for the feestdag half, and
degrading without it is a requirement rather than an accident. Nothing else.

**Depended on by:** `agenda-rostering-and-resource-booking`, for REQ-ROST-C03.

## What this change does not do

- It does not administer freeze periods (`C-deadlines-11`) or week numbering
  (`C-deadlines-23`). Both sit in cluster 67 and both belong to openregister: a
  freeze period stops work of a kind being scheduled anywhere, and a week number has
  to mean one thing across every app, which is precisely what a per-person object
  cannot guarantee.
- It does not hold a second working calendar per case type (`C-deadlines-14`). That
  is one of the five revivals decision D5 admits, and it is openregister's.
