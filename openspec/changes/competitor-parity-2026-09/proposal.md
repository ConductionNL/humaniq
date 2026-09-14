# Competitor parity 2026-09: the humaniq umbrella

## Why

Round 4 of the dossiq competitor sweep read 36 systems, kept 631 candidates and
grouped them into 70 capability clusters (`market-intelligence`
`procest/_round4/discovery/build-plan.md`, written 2026-09-14). The sweep names an
owner per cluster before it names a design, on Ruben's standing rule: dossiq
consumes, the owner holds the logic. That rule moves 536 of the 631 candidates out
of dossiq, and **9 of them land on humaniq**.

Decision D19 settles why. humaniq owns rostering, availability and working hours.
openregister owns the working calendar a term is counted against. dossiq reads both
and owns neither. So a caseworker's agenda, the roster that puts an inspector at a
place on a date, and the hours booked against a case are humaniq requirements, and
dossiq places a leaf.

This umbrella carries no requirement of its own. It exists so the wave-3 changes
below share one statement of where they came from, and so a reader who finds one of
them can find the other three.

## The changes under it

| change | cluster | candidates | size |
|---|---|---|---|
| `agenda-rostering-and-resource-booking` | 59, agenda, rostering and resource booking | C-tasks-and-phases-8, C-tasks-and-phases-19, C-tasks-and-phases-20, C-tasks-and-phases-28, C-deadlines-19, C-reporting-15 | L |
| `estimate-spent-and-remaining-on-an-hours-leaf` | 59 | C-reporting-12, C-reporting-17, C-reporting-23 | M |
| `a-working-calendar-per-person` | 67, working calendars per instance, unit and person | C-deadlines-3 | M |
| `leave-against-a-department-schedule` | 70, the not bucket | C-parties-and-contacts-11 | S |

Cluster 59 is split because its nine candidates answer two different questions. Six
of them ask where a person is and when. Three of them ask what an hour costs and
against what ceiling. The first six extend `rostering`. The last three extend
`hours-leaf`, which already ships the leaf a consuming app places.

## The decisions these rest on

- **D19.** humaniq owns rostering, availability and working hours. openregister owns
  the working calendar the term reads. dossiq reads both.
- **D17.** The product serves a broad market, including MKB. A candidate a lane rated
  `not` is not disqualified by that rating. `C-parties-and-contacts-11` is the one
  the sweep's own note hands to humaniq: "this is humaniq's job, not the case
  system's".
- **D21.** A documented candidate is admitted and labelled as documented. Two of the
  nine have no driven passer at all, only a vendor claim. Each spec says so on the
  requirement it belongs to, so nobody later reads a vendor page as a measurement.
- **D5.** All five parked round-3 revivals are built. `C-deadlines-14` is one of
  them, and it is openregister's half of the working calendar.
- **D6.** Promotion is led by relevance, and every `must` enters. None of humaniq's
  nine is a `must`, so none of them blocks a tender answer. They are built because
  the sweep measured thirteen passers against the agenda question and humaniq is the
  app that answers it.

## What humaniq does not take

The case, its term and its dates reaching a caseworker's own calendar client is
cluster 9, owned by openregister over CalDAV, and dossiq consumes it. humaniq writes
absence into a Nextcloud calendar already (`leave-calendar-nc`) and does not become
the fleet's calendar provider on the back of it.
