# agenda-and-resource-booking

## MODIFIED Requirements

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

## ADDED Requirements

### Requirement: An agenda SHALL open only for the people allowed to see it (REQ-AGD-007)

humaniq SHALL decide who reads an agenda from the session user, never from a request
parameter. HR is the `humaniq-hr` group, which a Nextcloud administrator also holds. A
manager is one the shared org resolution gives for the employee. A placement counts
when its `OrgAssignment` is active today, whatever period the agenda covers.

An employee's agenda SHALL open for the employee, their managers, HR and colleagues
placed in the same org unit. An org unit's agenda SHALL open for its members, its
manager and HR. Anyone else SHALL get 403, before any planned item is read.

Interview entries and a secondment's `hoursPerWeek` SHALL reach only the employee, their
managers and HR. A colleague SHALL see the other entries without them, and SHALL NOT see
an entry kind added later until it is listed as one colleagues see. A resource's agenda
SHALL stay open to every signed-in user.

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
