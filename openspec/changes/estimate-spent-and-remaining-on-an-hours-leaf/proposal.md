---
kind: config
---

# Estimate, spent and remaining on an hours leaf

## Why

Three of the nine candidates in cluster 59 of the round 4 competitor sweep
(`market-intelligence` `procest/_round4/discovery/build-plan.md`) do not ask where a
person is. They ask what an hour costs and against what ceiling. Decision D19 puts
time in humaniq, so they are humaniq's, and `hours-leaf` is where they land: it
already renders the hours booked against any object in the fleet, and dossiq already
places it.

One of the three is already built, and saying so is the point of this change. The
sweep rated **dossiq**, not humaniq, and it rated C-reporting-12 `no` because dossiq
has no timer. humaniq shipped one on 2026-09-11: `hours-leaf` specifies that a
running timer is one open time entry, that it survives leaving the page, and that a
caller may hold at most one. dossiq inherits it by placing the leaf, which is exactly
the consumption model Ruben's rule describes. So the row moves without a line of new
code, and this change records why rather than building a second timer.

That leaves two real gaps. Nothing in humaniq says how long a piece of work was
expected to take, so nothing can say how much is left. And nothing stops a booking
from running past what was agreed.

## The candidates, with their lane citations

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-reporting-17 | Estimated effort, effort spent and effort remaining are read against each other. | could | gitlab, kanboard, request-tracker | `reporting.tsv:36` |
| C-reporting-12 | A timer runs while you work and stops when you stop, rather than a duration typed afterwards. | could | forgejo, gitea, kanboard | `reporting.tsv:34` |
| C-reporting-23 | The product refuses to book more time against a case than was estimated for it. | could | none, easy-redmine documented | `reporting.tsv:21` |

GitLab proves the three-number read: `time_estimate` beside `total_time_spent`, both
readable in human form. Forgejo proves the timer with
`/issues/{index}/stopwatch/start`, `stop`, `delete` and `/user/stopwatches`
(`api.go`). C-reporting-23 has **no driven passer**: the only evidence is Easy
Redmine's documented "How to restrict logging more time than the estimate", admitted
under decision D21 and labelled documented in the spec.

The lane's own note on C-reporting-17 is worth keeping: "the estimate and the
remainder are the half 10.11 does not ask for", and it names ledger row 10.13.

## What humaniq builds

- **An estimate against a host object.** A `TimeEstimate` holds expected hours for
  one object, in the same `domainObjectType` and `domainObjectRef` shape
  `hours-leaf` already uses for a booking. One estimate per object per role, so a
  bezwaar can carry juridisch hours and vakafdeling hours separately.
- **Remaining as a derived figure, never typed.** Remaining is estimate minus spent.
  It is computed on read from entries that already exist, so it cannot drift from the
  bookings behind it.
- **Three numbers on the leaf.** The KPI tile gains estimated and remaining beside
  the total it already shows. When no estimate exists it shows two numbers and says
  there is no estimate, rather than showing a remaining of zero.
- **A ceiling the product enforces, when the estimate says so.** An estimate may
  declare itself a ceiling. A booking that would carry the total past a ceiling is
  refused on the write, with the refusal naming the estimate and what is left. An
  estimate without the flag reports and refuses nothing, which is the default.

## How dossiq consumes it

dossiq places `humaniq-hours` today and gets the timer for free. It gains three
things and owns none of them.

1. The leaf shows estimated, spent and remaining on a case, with no dossiq query
   against the `hrmq` register.
2. A case type may declare an expected effort, and dossiq writes the estimate when a
   case is created. The number is dossiq's policy; the object is humaniq's.
3. A refusal past a ceiling arrives from humaniq, so a caseworker booking against a
   capped case sees one message wherever they book, not one per app.

## The existing spec this extends

`hours-leaf` only. The timer requirements in it are untouched and are the reason
C-reporting-12 needs no work. The KPI tile requirement gains two figures and one
empty state. The booking path gains one refusal.

## Size and dependencies

**Size: M.** One new schema, a derived figure, two figures on an existing tile and
one write-path refusal.

**Depends on:** nothing. It does not wait on `agenda-rostering-and-resource-booking`,
and that change does not wait on this one.

## What this change does not do

It does not add a second timer, a second stopwatch control or a second place a
running entry lives. `hours-leaf` already says the running entry is the timer, and
there is no second place the state lives.
