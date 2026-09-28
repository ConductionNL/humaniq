# employee-history

## ADDED Requirements

### Requirement: The employee page SHALL show one chronological history (REQ-EHI-001)

humaniq SHALL compose, for one employee, the dated events of that person's contracts,
org placements, applied pay changes, approved leave, sickness cases and finalised
reviews, and SHALL show them on `EmployeeDetail` in one list, newest first. Each event
SHALL link to the record it came from. The history SHALL be derived on read and SHALL
NOT be stored.

Rows: `ppl-employee-timeline` (humaniq matrix).

#### Scenario: An HR adviser reads a career in one place
@e2e exclude the order and the links are decided server side; covered by EmployeeHistoryServiceTest::testACareerReadsNewestFirstAndLinksEachRecord, the page mounts in the generic manifest-pages spec
- **GIVEN** an employee with a first contract from 2021, a move to another unit in 2023,
  an applied raise in 2024 and a second contract from 2025
- **WHEN** an HR adviser opens that employee's page
- **THEN** the history section lists the second contract start, the raise, the unit move
  and the first contract start in that order, each linking to its record

#### Scenario: Proposals and rejected requests do not count as history
@e2e exclude covered by EmployeeHistoryServiceTest::testProposalsAndRejectedRequestsDoNotCount
- **GIVEN** the same employee with a compensation proposal not yet applied and a rejected
  leave request
- **WHEN** the history is read through `GET /api/employees/{id}/history`
- **THEN** neither the proposal nor the rejected request appears

### Requirement: The history SHALL show only what the caller may read (REQ-EHI-002)

The history endpoint SHALL answer 404 when the caller may not read the employee, the
same answer as for an employee that does not exist, and SHALL leave out every source
record the caller may not read under OpenRegister RBAC.

Rows: `ppl-employee-timeline` (humaniq matrix).

#### Scenario: A manager outside the team gets nothing
@e2e exclude needs a second scoped user on the test instance; covered by EmployeeHistoryServiceTest::testAManagerOutsideTheTeamGetsNothing and ::testAnUnreadableSourceRowIsDropped
- **GIVEN** a manager who may not read an employee in another unit
- **WHEN** they request that employee's history
- **THEN** the response is 404 and carries no event

### Requirement: Concurrent employments SHALL be shown together (REQ-EHI-003)

When an employee holds more than one contract active on a date, `EmployeeDetail` SHALL
show those contracts side by side with scale, hours per week, CAO and type, and SHALL
show the summed hours per week and FTE. The FTE SHALL be computed on the same full-time
week the absence rate uses, and the response SHALL name that basis. The employee's leave
balances for the year SHALL be shown once beside the contracts.

Rows: `td-multiple-employments` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: A teacher with two appointments
@e2e exclude covered by EmployeeHistoryServiceTest::testATeacherWithTwoAppointments and ::testTheEmploymentsAnswerNamesItsBasisAndThisYearsLeave
- **GIVEN** an employee with a 20-hour contract in schaal 10 and a 16-hour contract in
  schaal 9, both active today
- **WHEN** a payroll officer opens the employee's page
- **THEN** the employments block shows both contracts with their scale and hours, a total
  of 36 hours per week, and the person's leave balance once

#### Scenario: An ended contract is not concurrent
@e2e exclude covered by EmployeeHistoryServiceTest::testAnEndedContractIsNotConcurrent
- **GIVEN** an employee whose first contract ended last month and whose second is active
- **WHEN** `GET /api/employees/{id}/employments` is called for today
- **THEN** only the active contract is returned and `concurrent` is false
