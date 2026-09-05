## Purpose

Make an approved leave request move the leave balance it belongs to. Until now `LeaveBalance.usedHours`
had no writer, so `remainingHours` reported the full entitlement forever and the three rule checks that
read `usedHours` could never fire.

## ADDED Requirements

### Requirement: An approved leave request SHALL be reflected in its leave balance (REQ-LEAVE-POST-001)

When a `LeaveRequest` is created or updated, the system SHALL recompute `LeaveBalance.usedHours` for the
matching `employeeId`, `year` and `leaveType`. The recomputed value SHALL be the sum of the leave hours
of every `LeaveRequest` with status `approved` for that employee, year and leave type.

The recomputation SHALL be idempotent: running it twice over an unchanged set of requests SHALL produce
the same value and SHALL NOT write a second time. The system SHALL skip the write entirely when the
recomputed value equals the stored value.

The recomputation SHALL run on every status, not only on entry into `approved`. Moving a request out of
`approved` therefore returns the hours to the balance, and correcting the dates or hours of an already
approved request restates it.

The system SHALL resolve the balance by `employeeId`, `year` and `leaveType` and SHALL NOT create one.
When no balance matches, the system SHALL log at info level and make no write. Auto provisioning a
missing balance stays the named follow-up `leave-balance-auto-provision`.

A failure to resolve, read or write SHALL be logged and SHALL NOT break the save path.

#### Scenario: Approving a request moves the balance
@e2e exclude Backend projection with no dedicated UI surface. The balance it writes is asserted through the LeaveBalances index page in the leave e2e spec, and the projection arithmetic is verified directly by PHPUnit.
- **GIVEN** a LeaveBalance `{employeeId: e1, year: 2026, leaveType: holiday, entitledHours: 160, bovenwettelijkHours: 0, usedHours: 0}`
- **AND** a LeaveRequest `{employeeId: e1, leaveType: holiday, startDate: 2026-03-02, endDate: 2026-03-06, hours: 40, status: submitted}`
- **WHEN** the request's status becomes `approved`
- **THEN** the balance's `usedHours` is 40
- **AND** its calculated `remainingHours` is 120

#### Scenario: Rejecting an approved request returns the hours
@e2e exclude Backend projection, same surface as the requirement above.
- **GIVEN** the balance and request from the previous scenario, with the request approved and `usedHours` at 40
- **WHEN** the request's status becomes `rejected`
- **THEN** the balance's `usedHours` is 0

#### Scenario: A second identical projection writes nothing
@e2e exclude Backend idempotency, not observable from the browser.
- **GIVEN** a balance whose `usedHours` already equals the sum of its approved requests
- **WHEN** the projection runs again
- **THEN** no write is issued against the balance

### Requirement: Leave hours SHALL be derived from the dates when they are not given (REQ-LEAVE-POST-002)

`LeaveRequest.hours` is optional. When it is absent, null or zero, the system SHALL derive the hours a
request consumes by counting the Monday to Friday days in the inclusive `startDate` to `endDate` range
and multiplying by `contractHoursPerWeek / 5`, taking `contractHoursPerWeek` from the resolved
`LeaveBalance`.

When `hours` is present and greater than zero, the system SHALL use it unchanged and SHALL NOT derive.

When `contractHoursPerWeek` is null or zero the hours are not derivable. The system SHALL count that
request as zero hours, SHALL name its id in a warning, and SHALL still project the requests it could
derive. It SHALL NOT guess a working week.

Public holidays are NOT subtracted. A request spanning a public holiday therefore overstates usage by
one day.

A request with an explicit `hours` value SHALL be attributed wholly to the calendar year of its
`startDate`. A derived request SHALL have only the working days falling inside the target year counted
against that year, so a request spanning New Year splits across two balances.

#### Scenario: A week of leave with no hours is derived from the contract
@e2e exclude Pure arithmetic, verified by PHPUnit.
- **GIVEN** a balance with `contractHoursPerWeek: 40`
- **AND** an approved LeaveRequest from Monday 2026-03-02 to Friday 2026-03-06 with no `hours`
- **WHEN** the projection runs
- **THEN** the request contributes 40 hours

#### Scenario: A weekend is not counted
@e2e exclude Pure arithmetic, verified by PHPUnit.
- **GIVEN** a balance with `contractHoursPerWeek: 40`
- **AND** an approved LeaveRequest from Friday 2026-03-06 to Monday 2026-03-09 with no `hours`
- **WHEN** the projection runs
- **THEN** the request contributes 16 hours

#### Scenario: A request that cannot be derived is named and skipped
@e2e exclude Logging behaviour, verified by PHPUnit.
- **GIVEN** a balance with `contractHoursPerWeek: null`
- **AND** an approved LeaveRequest with no `hours`
- **WHEN** the projection runs
- **THEN** the request contributes 0 hours
- **AND** its id appears in a warning

## MODIFIED Requirements

### Requirement: The accrual job SHALL remain the only writer of entitled hours

`openspec/specs/leave-accrual-job/spec.md` states that "the buy/sell settlement path mutates
`usedHours` on existing rows". It does not. `LeaveBuySellSettlementService` writes
`bovenwettelijkHours` and only `bovenwettelijkHours`, which its own class docblock states and which is
grep verifiable. That sentence is corrected to name `bovenwettelijkHours`.

The accrual job continues to write `entitledHours`, `bovenwettelijkHours` and an initial
`usedHours: 0.0` when it creates a balance. It SHALL NOT write `usedHours` on an existing balance,
which now belongs to the projection service.

#### Scenario: Accrual does not overwrite projected usage
@e2e exclude Backend job behaviour, verified by PHPUnit.
- **GIVEN** an existing balance whose `usedHours` is 40 from approved leave
- **WHEN** the accrual job updates that balance
- **THEN** `usedHours` is still 40
