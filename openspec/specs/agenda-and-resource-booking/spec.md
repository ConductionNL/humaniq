# agenda-and-resource-booking Specification

## Purpose
What is planned for a person, a team or a room, composed on read from roster assignments, leave, sick leave, interviews, resource bookings and subscribed external calendars; who is free for a period and a competence; and a room, vehicle or piece of equipment booked for a period with a double booking refused. Built by the change agenda-rostering-and-resource-booking (archived 2026-09-30).

## Requirements

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
@e2e tests/e2e/spec-coverage/agenda-and-resource-booking.spec.ts
- **GIVEN** an employee with a rostered shift on Monday, approved leave on Wednesday
  and a booked hoorzitting room on Friday
- **WHEN** the agenda is asked for that employee and that week
- **THEN** it returns three entries, each naming its kind and the object it came from

#### Scenario: A cancelled leave request leaves the agenda at once
@e2e tests/e2e/spec-coverage/agenda-and-resource-booking.spec.ts
- **GIVEN** an approved leave request showing on the agenda
- **WHEN** the request is withdrawn
- **THEN** the next read of the agenda does not contain it, with no sync job in
  between

#### Scenario: The reason for an absence never reaches the agenda
@e2e exclude a sick-leave fixture would put an absence on a person's record; covered by AgendaAndAvailabilityTest::testAnAbsenceEntryCarriesNoReason, which asserts the reason is absent from the whole serialised entry
- **GIVEN** an open `SickLeaveCase` with a recorded reason
- **WHEN** the agenda is read by anyone allowed to see it
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
@e2e exclude covered by AgendaAndAvailabilityTest::testAvailabilityOffersOnlyTheQualified, with the unfiltered answer beside it as the control
- **GIVEN** two employees free on Thursday, one holding `boa-domein-1` and one not
- **WHEN** availability is asked for Thursday with competence `boa-domein-1`
- **THEN** only the holder is returned

#### Scenario: Busy time from a private calendar counts
@e2e exclude needs a live iCalendar feed the e2e instance has no way to serve; covered by AgendaAndAvailabilityTest::testExternalBusyTimeIsDeductedFromFreeHours and CalendarSubscriptionPollerTest
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
@e2e tests/e2e/spec-coverage/agenda-and-resource-booking.spec.ts
- **GIVEN** a resource "Hoorzittingzaal 2" of kind `room`
- **WHEN** a booking is written for Friday 10:00 to 12:00 with
  `domainObjectType` `dossiq:zaak` and the case's uuid
- **THEN** the booking holds the room for that period and names the case it belongs to

#### Scenario: A resource out of service stops being bookable
@e2e tests/e2e/spec-coverage/agenda-and-resource-booking.spec.ts
- **GIVEN** a resource with `active` false
- **WHEN** a booking is attempted for it
- **THEN** the write is refused

### Requirement: A double booking SHALL be refused in the write path (REQ-AGD-004)

A `ResourceBooking` whose period overlaps existing bookings of the same resource
SHALL be refused when the overlapping count would exceed the resource's `quantity`.
The refusal SHALL happen on the write, not in a later report, and SHALL name the
booking that blocks it.

#### Scenario: The second booking of one room loses
@e2e tests/e2e/spec-coverage/agenda-and-resource-booking.spec.ts
- **GIVEN** "Hoorzittingzaal 2" with quantity 1, booked Friday 10:00 to 12:00
- **WHEN** a second booking is attempted for Friday 11:00 to 13:00
- **THEN** the write is refused and the refusal names the existing booking

#### Scenario: Three inspectors and two meters
@e2e tests/e2e/spec-coverage/agenda-and-resource-booking.spec.ts
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
@e2e exclude needs a live feed; covered by CalendarSubscriptionPollerTest::testASuccessfulPollCachesThePeriods, which asserts the title is absent from the cached payload
- **GIVEN** a subscribed feed containing an event titled "Tandarts"
- **WHEN** the agenda is read by the employee's manager
- **THEN** the period shows as busy and the title is absent from the answer

#### Scenario: An unreachable feed does not empty an agenda
@e2e exclude covered by CalendarSubscriptionPollerTest::testAnUnreachableFeedKeepsThePreviousCache
- **GIVEN** a subscribed feed that has been polled successfully before
- **WHEN** the next poll fails
- **THEN** the cached busy periods stay in the agenda and a degradation is recorded

#### Scenario: Nothing is written outward
@e2e exclude covered by CalendarSubscriptionPollerTest::testOnlyReadsAreIssuedAgainstTheFeed, which records every request the poller issues
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
@e2e exclude needs a consuming app installed beside humaniq; both halves of the leaf are pinned by RegisterAgendaLeafListenerTest and the bundle entry is asserted there too
- **WHEN** a consuming app places the `humaniq-agenda` leaf on a case detail page
- **THEN** the widget reads the agenda for that case's handler, and the consuming
  app's own manifest contains no query against the `hrmq` register

#### Scenario: The surface is absent when humaniq is
@e2e exclude asserts the ABSENCE of a registration on an instance without humaniq, which an instance running humaniq cannot observe; the registration sits inside the class_exists guard in Application::boot()
- **WHEN** the consuming app is installed and humaniq is not
- **THEN** no `humaniq-agenda` leaf is registered, and the host renders no agenda
  panel

### Requirement: An agenda SHALL open only for the people allowed to see it (REQ-AGD-007)

humaniq SHALL decide who reads an agenda from the session user, never from a request
parameter. HR is the `humaniq-hr` group, which a Nextcloud administrator also holds. A
manager is one the shared org resolution gives for the employee. A placement counts
when its `OrgAssignment` is active today, whatever period the agenda covers. A
placement whose start or end date is set but cannot be read does not count, and a
placement in a unit that is not registered makes nobody a colleague.

An employee's agenda SHALL open for the employee, their managers, HR and colleagues
placed in the same org unit. A subject without an Employee record SHALL open for HR
only. An org unit's agenda SHALL open for its members, its manager, the managers of
the units above it, a deputy standing in for one of those managers today, and HR. The
climb to the units above stops at the unit's administration, and a manager whose own
Employee record lies in another administration does not count, the same boundary the
shared manager resolution draws.
Anyone else SHALL get 403 with a short reason, before any planned item is read.

Interview entries and a secondment's `hoursPerWeek` SHALL reach only the employee, their
managers and HR. A colleague SHALL see the other entries without them, and SHALL NOT see
an entry kind added later until it is listed as one colleagues see. The managers above
the unit and a deputy see the unit agenda in full, but open no employee's agenda on
that ground. A resource's agenda SHALL stay open to every signed-in user.

#### Scenario: A colleague sees the week without the interview
@e2e exclude needs two signed-in employees placed in one unit; covered by AgendaReadAccessTest::testAColleagueSeesTheAgendaWithoutInterviewsOrSecondmentHours
- **GIVEN** Anna and Bram are placed in one unit, and Anna has an interview and an
  active secondment of 16 hours a week
- **WHEN** Bram reads Anna's agenda
- **THEN** he sees her shift, absence, booking and secondment
- **AND** he sees no interview and no hours per week

#### Scenario: Self, manager and HR see the whole agenda
@e2e exclude needs a manager, HR and an employee signed in in turn; covered by AgendaReadAccessTest::testSelfManagerAndHrSeeTheWholeAgenda
- **WHEN** Anna, her manager or HR reads Anna's agenda
- **THEN** the interview and the secondment's hours per week are included

#### Scenario: Someone outside the unit is refused
@e2e exclude needs a second signed-in employee in another unit; covered by AgendaReadAccessTest::testAnyoneElseIsRefusedAnEmployeeAgenda and testAnyoneElseIsRefusedAUnitAgenda
- **GIVEN** Carl works in another unit and manages nobody in Anna's
- **WHEN** Carl reads Anna's agenda or her unit's agenda
- **THEN** the answer is 403 and no planned item was read

#### Scenario: A name in the request does not open an agenda
@e2e exclude a forged parameter is a request-level probe; covered by AgendaReadAccessTest::testForgedRequestParametersDoNotOpenAnAgenda and testForgedRequestParametersDoNotWidenAColleaguesView
- **GIVEN** Carl may not read Anna's agenda
- **WHEN** Carl sends `userId=anna` or `role=hr` with his request
- **THEN** the answer is still 403

#### Scenario: A member sees the unit with only their own interviews
@e2e exclude needs two signed-in employees placed in one unit; covered by AgendaReadAccessTest::testAMemberSeesTheUnitAgendaWithOnlyTheirOwnInterviewsAndHours
- **WHEN** Bram reads the agenda of the unit he and Anna are placed in
- **THEN** he sees his own interview and secondment hours
- **AND** he sees Anna's other entries without her interview or hours per week

#### Scenario: The manager above the unit and a deputy open the unit
@e2e exclude needs a parent-unit manager and a deputy signed in in turn; covered by AgendaReadAccessTest::testParentManagersAndDeputiesSeeTheWholeUnitAgenda, testAnEndedDeputyIsRefusedTheUnitAgenda and testParentManagersAndDeputiesDoNotOpenAnEmployeeAgenda
- **GIVEN** Olga manages the unit above Zorg, and Dirk stands in for Zorg's manager today
- **WHEN** Olga or Dirk reads the Zorg agenda
- **THEN** they see every member's entries in full
- **AND** neither of them opens Anna's own agenda on that ground

#### Scenario: The climb stops at the administration boundary
@e2e exclude needs two administrations and their managers signed in; covered by AgendaReadAccessTest::testTheClimbStopsAtTheAdministrationBoundary and testAManagerOutsideTheAdministrationOpensNothing
- **GIVEN** the unit above Zorg, or Zorg's manager's own Employee record, lies in
  another administration than Zorg
- **WHEN** that manager, or their deputy, reads the Zorg agenda
- **THEN** the answer is 403

#### Scenario: A placement that cannot be trusted makes nobody a colleague
@e2e exclude needs register rows a form would not save; covered by AgendaReadAccessTest::testASubjectWithoutAnEmployeeRecordIsRefused, testAPlacementInAnUnregisteredUnitOpensNothing and testAPlacementWithAnUnreadableDateDoesNotCount
- **GIVEN** a placement in a unit that is not registered, a placement with an
  unreadable date, or a placement naming an employee without an Employee record
- **WHEN** someone relies on it to read an agenda
- **THEN** the answer is 403

### Requirement: Availability and capacity SHALL open only for the people allowed to see the agenda (REQ-AGD-008)

`GET /api/availability` and `GET /api/capacity` SHALL follow REQ-AGD-007. With an
org unit, the answer SHALL open for whoever may read that unit's agenda, and cover the
unit's members. For anyone but HR, a member SHALL also be placed in the unit today, so
an early window does not bring back former members. Without a unit, HR SHALL get every
employee, and anyone else only the employees whose agenda they may read. A caller left
with nobody SHALL get 403 with a short reason, before any planned item is read.

For an employee the caller does not see in full, the planned hours SHALL leave the
employee's secondments and interviews out, so a colleague cannot read a secondment's
hours per week, or an interview's date and length, off the answer.

#### Scenario: A unit's hours follow the unit agenda
@e2e exclude needs several signed-in users; covered by AgendaReadAccessTest::testAUnitsHoursOpenForWhoMayReadItsAgenda and testAnyoneElseIsRefusedAUnitsHours
- **WHEN** a member, a manager, the manager above, a deputy or HR asks Zorg's
  availability or capacity
- **THEN** the answer covers Zorg's members
- **AND** anyone else gets 403

#### Scenario: Without a unit, each caller sees only who they may see
@e2e exclude needs several signed-in users; covered by AgendaReadAccessTest::testWithoutAUnitHrGetsEveryEmployee, testWithoutAUnitOthersGetOnlyWhoTheyMaySee and testWithoutAUnitSomeoneWhoMaySeeNobodyIsRefused
- **WHEN** Bram asks availability without a unit
- **THEN** the answer covers himself and his colleagues in Zorg
- **AND** HR gets every employee, and a user who may see nobody gets 403

#### Scenario: A colleague cannot read hidden items off planned hours
@e2e exclude needs a seeded secondment and interview with a colleague signed in; covered by AgendaReadAccessTest::testAColleagueCannotReadSecondmentHoursOffPlannedHours and testAColleagueCannotReadAnInterviewOffPlannedHours
- **GIVEN** Anna is seconded 16 hours a week and has an interview on Tuesday
- **WHEN** Bram asks Zorg's availability or capacity
- **THEN** Anna's planned hours count neither, while his own items still count
- **AND** her manager and HR see both counted

#### Scenario: An early window does not bring back former members
@e2e exclude needs a former member's rows; covered by AgendaReadAccessTest::testAnEarlyWindowDoesNotBringBackFormerMembers
- **GIVEN** Eva left Zorg in 2021
- **WHEN** a member or the manager asks Zorg's hours or agenda from 2020
- **THEN** Eva is not in the answer, while HR still gets her
