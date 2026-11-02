# agenda-and-resource-booking

## MODIFIED Requirements

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
the units above it, a deputy standing in for one of those managers today, and HR.
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

#### Scenario: A placement that cannot be trusted makes nobody a colleague
@e2e exclude needs register rows a form would not save; covered by AgendaReadAccessTest::testASubjectWithoutAnEmployeeRecordIsRefused, testAPlacementInAnUnregisteredUnitOpensNothing and testAPlacementWithAnUnreadableDateDoesNotCount
- **GIVEN** a placement in a unit that is not registered, a placement with an
  unreadable date, or a placement naming an employee without an Employee record
- **WHEN** someone relies on it to read an agenda
- **THEN** the answer is 403

## ADDED Requirements

### Requirement: Availability and capacity SHALL open only for the people allowed to see the agenda (REQ-AGD-008)

`GET /api/availability` and `GET /api/capacity` SHALL follow REQ-AGD-007. With an
org unit, the answer SHALL open for whoever may read that unit's agenda, and cover the
unit's members. Without one, HR SHALL get every employee, and anyone else only the
employees whose agenda they may read. A caller left with nobody SHALL get 403 with a
short reason, before any planned item is read.

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
