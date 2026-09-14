---
kind: config
---

# Agenda, rostering and resource booking

## Why

Cluster 59 of the round 4 competitor sweep is called "agenda, rostering and resource
booking" (`market-intelligence` `procest/_round4/discovery/build-plan.md`). It holds
nine candidates, thirteen systems pass at least one of them, nine of those were
driven, and the sweep names **humaniq** as the owner on **decision D19**: humaniq
owns rostering, availability and working hours, openregister owns the working
calendar a term is counted against, and dossiq reads both.

Six of the nine ask one question: where is a person, when, and who else can be there.
This change answers those six. The other three ask what an hour costs, and they are
`estimate-spent-and-remaining-on-an-hours-leaf` beside this one.

humaniq already plans. `rostering` ships `Shift`, `Roster` and `RosterAssignment`
with an Arbeidstijdenwet cross-check before publication, and it named its own
non-goals: auto-optimisation, demand forecasting and a drag-and-drop planbord. The
sweep did not find an optimiser. It found four cheaper things that are missing, and
one of them is the reason a gemeente cannot plan an inspection today: nothing in
humaniq says which competence a shift needs.

## The candidates, with their lane citations

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-tasks-and-phases-19 | An agenda inside the case system shows every planned item for a person, a group or a room. | should | glpi, otobo, znuny | `tasks-and-phases.tsv:13` |
| C-tasks-and-phases-8 | A room, a car or a piece of equipment is booked for a period from inside the case system. | should | glpi | `tasks-and-phases.tsv:24` |
| C-tasks-and-phases-20 | An external calendar is read into the product's own agenda. | could | glpi, otobo | `tasks-and-phases.tsv:15` |
| C-tasks-and-phases-28 | People are rostered onto a location or a time slot from rules about the competences needed there. | should | none, atabix documented | `tasks-and-phases.tsv:42` |
| C-reporting-15 | Capacity is planned against the team's own working hours, and read forward rather than backward. | should | openproject | `reporting.tsv:8` |
| C-deadlines-19 | The product proposes dates for a set of cases from their dependencies and the available capacity. | could | none, jira-data-center documented | `deadlines.tsv:30` |

The proving system for the cluster is **znuny**, on the agenda candidate:
`AgentAppointmentCalendarOverview.pm`, `AgentAppointmentAgendaOverview.pm`,
`AgentAppointmentEdit.pm`, `AdminAppointmentCalendarManage.pm` and
`AdminAppointmentImport.pm`. GLPI proves the resource half with Reservations
(`front/reservation.php`, `reservationitem.php`, `report.reservation.php`) and the
external calendar half with `src/Planning.php:1693-1989`. OpenProject proves forward
capacity with the Team planners module (`modules/resource_management/`).

Two candidates carry no driven passer. C-tasks-and-phases-28 rests on atabix's
`/gestandaardiseerde-modules` page and C-deadlines-19 on Jira Data Center's
Advanced Roadmaps documentation. **Decision D21** admits a documented candidate and
labels it, so both requirements below say "documented" on their face. A vendor page
is an upper bound, never a measurement.

## What humaniq builds

- **A competence on a shift, and a person who holds it.** `Shift` gains a required
  competence set. An `EmployeeCompetence` records who holds what and until when. An
  assignment that puts a person on a shift they are not competent for is refused,
  through the same never-throw check service `rostering` already uses for the
  Arbeidstijdenwet rules. This is the VTH planning gap the lane names: an inspection
  needs a qualified person at a place on a date.
- **A bookable resource, and a booking for a period.** A `Resource` (a hoorzitting
  room, an inspectieauto, a meter) and a `ResourceBooking` over a period, with a
  double-booking refused rather than reported.
- **One agenda that reads them all.** A read model answering "what is planned for
  this person, this team, this room, between these two dates", over roster
  assignments, leave, sick leave, interviews and resource bookings. It carries no
  data of its own.
- **An availability answer.** Given a period and a competence, who is free. This is
  the query dossiq needs before it can assign anything, and it is the only part of
  this change dossiq calls directly.
- **Forward capacity against real working hours.** Planned hours in a period read
  against each person's own contracted hours, forward from today. The per-person
  hours arrive from `a-working-calendar-per-person`, which is why that change is a
  dependency.
- **An external calendar read in, read only.** An iCalendar feed subscribed per
  employee, shown in the agenda, never written back.

## How dossiq consumes it

dossiq owns no roster and no room. It asks humaniq two questions and places one leaf.

1. Before assignment: who is available in this period with this competence. dossiq
   uses the answer to offer handlers for a hoorzitting or an inspectie.
2. When a hearing is planned: book the room and the car for that period. The refusal
   on a double booking is humaniq's, so two dossiq instances cannot both take the
   room.
3. On a case detail page: an OpenRegister integration leaf, registered the way
   `humaniq-hours` is, showing what is planned for the handler of this case. A leaf
   whose app is absent is never registered, so an instance without humaniq shows no
   agenda rather than an empty one.

## The existing specs this extends

- `rostering` gains the competence requirement, the capacity read and the agenda
  entry contract. Its published non-goals stand: this change adds no optimiser, no
  forecast and no planbord.
- `leave-calendar-nc` is read by the agenda, not changed. Its AVG boundary holds:
  the agenda shows that a person is absent, never why.
- `time-attendance` supplies the realised clock the capacity read is compared
  against. Unchanged.

## Size and dependencies

**Size: L.** Two new schemas, one extended schema, one read model, one availability
query, one leaf and one feed reader.

**Depends on:** `a-working-calendar-per-person` for the contracted hours the capacity
read divides by. Nothing else. It does not wait on openregister's
`working-calendar-admin`, because a working calendar tells you which days count for a
term, and this change asks which hours a person has.

## What this change does not do

- It does not propose dates. C-deadlines-19 asks a product to schedule a set of cases
  from their dependencies and the capacity left. Its only passer is documented, the
  input it needs is dossiq's dependency graph, and humaniq has no claim on that
  graph. This change ships the capacity half and records the rest as an open
  candidate, so the next reader finds it rather than rediscovers it.
- It does not become the fleet's calendar provider. The case, its term and its dates
  reaching a caseworker's own calendar client is cluster 9, owned by openregister
  over CalDAV.
