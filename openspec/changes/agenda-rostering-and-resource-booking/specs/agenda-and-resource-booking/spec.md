# agenda-and-resource-booking

## ADDED Requirements

### Requirement: One agenda SHALL answer what is planned for a person, a team or a room (REQ-AGD-001)

humaniq SHALL answer, for a subject and a date range, every planned item that holds
that subject. A subject is an employee, an org unit or a resource. The answer SHALL
compose on read from the objects that already exist: `RosterAssignment`,
`LeaveRequest` in status `approved`, `SickLeaveCase` while open, interviews from
`interview-scheduling`, `ResourceBooking`, and any subscribed external calendar. The
agenda SHALL store no entry of its own.

An entry SHALL carry a period, its subject, a kind, and a reference to the object it
came from. The AVG boundary of `leave-calendar-nc` SHALL hold unchanged: an entry
sourced from a `SickLeaveCase` says the person is absent and says nothing about why.

Candidate C-tasks-and-phases-19 (`tasks-and-phases.tsv:13`), relevance `should`,
driven passers glpi, otobo and znuny. Proving system znuny:
`AgentAppointmentCalendarOverview.pm`, `AgentAppointmentAgendaOverview.pm`,
`AgentAppointmentEdit.pm`.

#### Scenario: A handler's week reads from five sources at once
- **GIVEN** an employee with a rostered shift on Monday, approved leave on Wednesday
  and a booked hoorzitting room on Friday
- **WHEN** the agenda is asked for that employee and that week
- **THEN** it returns three entries, each naming its kind and the object it came from

#### Scenario: A cancelled leave request leaves the agenda at once
- **GIVEN** an approved leave request showing on the agenda
- **WHEN** the request is withdrawn
- **THEN** the next read of the agenda does not contain it, with no sync job in
  between

#### Scenario: The reason for an absence never reaches the agenda
- **GIVEN** an open `SickLeaveCase` with a recorded reason
- **WHEN** the agenda is read by anyone
- **THEN** the entry carries the kind "absent" and the person's name, and carries no
  reason, diagnosis or leave type

### Requirement: Availability SHALL be answerable for a period and a competence (REQ-AGD-002)

humaniq SHALL answer which employees are free in a given window, optionally narrowed
to an org unit and to a set of competences held on the dates in the window. Free
SHALL mean: contracted hours in the window, minus rostered assignments, minus
approved leave, minus open sick leave, minus resource bookings that hold the person,
minus busy time from a subscribed external calendar.

The answer SHALL name the free hours per employee, not a yes or a no, so a caller can
tell a person with one free afternoon from a person with a free week.

#### Scenario: An unqualified colleague is not offered
- **GIVEN** two employees free on Thursday, one holding `boa-domein-1` and one not
- **WHEN** availability is asked for Thursday with competence `boa-domein-1`
- **THEN** only the holder is returned

#### Scenario: Busy time from a private calendar counts
- **GIVEN** an employee with a subscribed external calendar showing a three-hour
  event on Thursday morning
- **WHEN** availability is asked for Thursday
- **THEN** those three hours are deducted, and the event's title is not in the answer

### Requirement: A room, a vehicle or a piece of equipment SHALL be bookable for a period (REQ-AGD-003)

humaniq SHALL provide a `Resource` schema in register `hrmq` describing something
bookable: a name, a kind (`room`, `vehicle`, `equipment`), an optional org unit
scope, a `quantity` defaulting to 1, and an `active` flag. A `ResourceBooking` SHALL
hold one resource for one period, with the employee who booked it and an optional
reference to the object it was booked for, expressed the way `hours-leaf` expresses
one: `domainObjectType` as the `<app>:<schema>` literal and `domainObjectRef` as the
object's uuid.

Candidate C-tasks-and-phases-8 (`tasks-and-phases.tsv:24`), relevance `should`,
driven passer glpi (Reservations: `front/reservation.php`, `reservationitem.php`,
`report.reservation.php`).

#### Scenario: A hoorzitting room is booked against the case that needs it
- **GIVEN** a resource "Hoorzittingzaal 2" of kind `room`
- **WHEN** a booking is written for Friday 10:00 to 12:00 with
  `domainObjectType` `dossiq:zaak` and the case's uuid
- **THEN** the booking holds the room for that period and names the case it belongs to

#### Scenario: A resource out of service stops being bookable
- **GIVEN** a resource with `active` false
- **WHEN** a booking is attempted for it
- **THEN** the write is refused

### Requirement: A double booking SHALL be refused in the write path (REQ-AGD-004)

A `ResourceBooking` whose period overlaps existing bookings of the same resource
SHALL be refused when the overlapping count would exceed the resource's `quantity`.
The refusal SHALL happen on the write, not in a later report, and SHALL name the
booking that blocks it.

#### Scenario: The second booking of one room loses
- **GIVEN** "Hoorzittingzaal 2" with quantity 1, booked Friday 10:00 to 12:00
- **WHEN** a second booking is attempted for Friday 11:00 to 13:00
- **THEN** the write is refused and the refusal names the existing booking

#### Scenario: Three inspectors and two meters
- **GIVEN** a resource "Geluidsmeter" with quantity 2 and two overlapping bookings
- **WHEN** a third overlapping booking is attempted
- **THEN** it is refused, and a fourth booking outside the overlap is accepted

### Requirement: An external calendar SHALL be read into the agenda and never written back (REQ-AGD-005)

An employee SHALL be able to subscribe one or more iCalendar feed URLs. humaniq SHALL
poll each feed, cache the busy periods it declares, and place them in the agenda as
busy time carrying no title, no location and no attendees. humaniq SHALL NOT write to
a subscribed feed, and SHALL NOT create a humaniq object from a feed event.

A feed that cannot be reached SHALL record a degradation the way `leave-calendar-nc`
records `skipped-no-calendar`, and SHALL leave the previous cache in place rather
than emptying the person's agenda.

Candidate C-tasks-and-phases-20 (`tasks-and-phases.tsv:15`), relevance `could`,
driven passers glpi (`src/Planning.php:1693-1989`) and otobo
(`AdminAppointmentImport`).

#### Scenario: A private appointment blocks time without disclosing itself
- **GIVEN** a subscribed feed containing an event titled "Tandarts"
- **WHEN** the agenda is read by the employee's manager
- **THEN** the period shows as busy and the title is absent from the answer

#### Scenario: An unreachable feed does not empty an agenda
- **GIVEN** a subscribed feed that has been polled successfully before
- **WHEN** the next poll fails
- **THEN** the cached busy periods stay in the agenda and a degradation is recorded

#### Scenario: Nothing is written outward
- **WHEN** a humaniq roster assignment, leave request or booking is created for an
  employee with a subscribed feed
- **THEN** no request is made to the feed's URL other than a read

### Requirement: A consuming app SHALL place an agenda leaf rather than query the roster (REQ-AGD-006)

humaniq SHALL register an OpenRegister integration leaf `humaniq-agenda` rendering
what is planned for a subject, following the contract `hours-leaf` already
establishes. The leaf SHALL identify its subject the way humaniq stores it. A
consuming app SHALL place the leaf instead of querying humaniq's register.

When humaniq is absent the leaf SHALL NOT be registered, so a host renders no agenda
surface at all rather than an empty one. An empty agenda and a missing humaniq must
not look the same.

#### Scenario: A case page shows the handler's week without reading the hrmq register
- **WHEN** a consuming app places the `humaniq-agenda` leaf on a case detail page
- **THEN** the widget reads the agenda for that case's handler, and the consuming
  app's own manifest contains no query against the `hrmq` register

#### Scenario: The surface is absent when humaniq is
- **WHEN** the consuming app is installed and humaniq is not
- **THEN** no `humaniq-agenda` leaf is registered, and the host renders no agenda
  panel
