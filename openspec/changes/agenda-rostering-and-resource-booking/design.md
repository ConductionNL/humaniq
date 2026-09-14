# Design: agenda, rostering and resource booking

## D1. What humaniq plans today, measured on HEAD

| Object | Schema fragment | What it answers |
|---|---|---|
| `Shift` | `rostering` | a reusable dienst: name, start, end, break, optional org unit |
| `Roster` | `rostering` | a publishable header, `concept` to `gepubliceerd` |
| `RosterAssignment` | `rostering` | one shift projected onto one date for one employee |
| `AttendanceRecord` | `time-attendance` | what was actually clocked, per employee per day |
| `LeaveRequest`, `SickLeaveCase` | `leave-management`, `verzuim-wvp` | who is away and when |
| `TimeEntry` | `hours-leaf`, `time-entry-capture` | hours booked against any object in the fleet |

Five objects already say where a person is on a date. Nothing reads them together,
and nothing says what a person is qualified to do. Those are the two gaps.

## D2. Competence is a property of the shift, not of the roster

The lane's wording is "rules about the competences needed there". Three places could
hold that rule.

1. **On the shift.** A shift is already the reusable definition, and "BOA
   bevoegdheid" is a property of the work, not of the week it is planned in.
2. **On the org unit.** Too coarse: one team runs shifts needing different
   qualifications.
3. **On a separate rule object.** A fourth indirection for a set of strings.

Option 1. `Shift.requiredCompetences` is a set of competence codes, and
`EmployeeCompetence` records who holds one, with a `validUntil`. An expiring
qualification is the interesting half: a BOA pas runs out, and the roster must refuse
the day after, not the day somebody notices.

## D3. The refusal is a check, not a lifecycle guard

`rostering` already refuses on the Arbeidstijdenwet through `NlRosterChecks` and a
never-throw `RosterCheckService`, run before publication. Competence joins that path
rather than opening a second one. One command still answers "is this roster
publishable", and one screen still shows why not.

A competence violation is a finding of its own kind, so a reader can tell "Jan is not
a BOA" from "Jan has eleven hours of rest, not twelve".

## D4. The agenda is a read model with no storage

An agenda entry has four fields: a period, a subject (person, team or resource), a
kind, and a reference to the object it came from. Five sources produce them, listed
in D1 plus resource bookings. Storing them would mean keeping six copies in step and
would make the AVG boundary in `leave-calendar-nc` a thing to re-enforce per copy.

So the agenda composes on read. The cost is a query per source; the gain is that a
leave request cancelled at 16:00 is off the agenda at 16:00.

The AVG boundary is inherited rather than restated: an entry sourced from a
`SickLeaveCase` carries the kind "absent" and the person's name, and nothing else.

## D5. Availability is the query dossiq actually needs

"Show me the agenda" is a screen. "Who can do this on Thursday" is an integration.
The second one is a first-class query:

```
GET /api/availability?from&to&competence[]&orgUnit
```

It answers a list of employees with the hours each has free in the window, after
rostered shifts, approved leave, open sick leave and booked resources are taken out.
It is the one endpoint dossiq calls, and it is the reason this change is not just a
page.

## D6. A double booking is refused, not reported

GLPI's Reservations let a reader see a clash. A room booked twice for one hoorzitting
is a person standing in a corridor, so humaniq refuses the second write rather than
flagging it. The check is a period overlap on the resource, and it runs in the write
path, not in a nightly job.

Equipment differs from a room: a resource carries a `quantity`, and the refusal
fires when the overlapping bookings exceed it. One meter, three inspectors, two of
them wait.

## D7. The external calendar is read only, and that is a decision

GLPI imports an external calendar into its own planning. OTOBO does the same through
`AdminAppointmentImport`. Neither writes back, and neither should: an employee's
private agenda is not humaniq's to edit, and a two-way sync makes a deletion
ambiguous in a way nobody can debug.

So: a per-employee iCalendar URL, polled, cached, shown in the agenda as busy time
with no title. It counts against availability. It never becomes a humaniq object.

## D8. Capacity is forward, and it divides by a real number

OpenProject's Team planners read capacity forward against each user's working hours.
humaniq reads backward today: `absence-rate` and the verzuim widgets report what
happened.

Forward capacity needs a denominator, and the denominator is the person's own
contracted hours per day, which humaniq does not hold yet. That is the whole
dependency on `a-working-calendar-per-person`. Until it lands, a capacity read would
divide by the instance default and be wrong for every part-timer, which is most of a
gemeente's bezwaarteam.

## D9. What is deliberately left open

C-deadlines-19, proposing dates for a set of cases from their dependencies and the
available capacity, is documented only (Jira Data Center, Advanced Roadmaps,
auto-schedule issues) and needs a dependency graph dossiq owns. humaniq ships the
capacity side of it here. The proposing side is recorded in the spec as an explicit
non-requirement so the next reader finds the decision rather than the gap.
