# Design: one employment history per employee

## Context

Read at `development` af702f78.

- `EmployeeDetail` (`src/manifest.d/hr-objects.json:4`) is the hub page for one person. It
  carries a `data` widget, four `stats-block` tiles and one `object-list` per related
  schema (contracts, timesheets, payslips, expenses, org assignments, reviews, objectives,
  compensation proposals, leave transactions, generated documents). Each list is sorted on
  its own; there is no merged view. The page declares no `bodyWidgets`.
- `lib/Service/EmployeeTimeline.php` places `Employee` rows on a timeline to answer
  headcount, starters and leavers for `AnalyticsService` (REQ-DSI-003). It is a pure
  function over all employees and has no per-person entry point. It stays as it is.
- The dated records that make up a person's history already exist, each with its own
  `employeeId`:
  - `EmploymentContract` (`hr-objects.json`): `type`, `startDate`, `endDate`,
    `hoursPerWeek`, `hourlyWage`, `cao`, `caoSchaal`, `normfunctieId`.
  - `OrgAssignment` (`hr-org.json`): `orgUnitId`, `role`, `startDate`, `endDate`.
  - `CompAdjustment` (`hr-comp.json`): `currentSalary`, `proposedSalary`,
    `effectiveDate`, `status`, `appliedAt`.
  - `LeaveRequest` (`hr-leave.json`): `leaveType`, `startDate`, `endDate`, `hours`,
    `status`, `approvedAt`.
  - `SickLeaveCase` (`hr-verzuim.json`): `firstSickDay`, `recoveredDate`, `status`.
  - `PerformanceReview` (`hr-performance.json`): `cycleId`, `status` (final value
    `vastgesteld`), `rating`, `besprokenOp`.
- `LeaveBalance` (`hr-leave.json`) is keyed per `employeeId`, `year` and `leaveType`, not
  per contract.
- The library's `CnDetailPage` renders `config.bodyWidgets` sections: a registered host
  component placed in the page body with `@objectId` resolved into its props
  (`@conduction/nextcloud-vue` 2.40.0, `CnDetailPage.vue:1361`). `CnTimelineView`
  renders a date-grouped event list from an `events` array.
- `lib/Controller/LeaveScheduleController.php` is the precedent for a composed read: it
  reads with the gateways and filters what the caller may see through
  `lib/Service/RbacObjectReader.php`, which leaves OpenRegister RBAC on.

## Goals / Non-Goals

**Goals**

- One chronological history per employee, composed on read from the records above.
- A combined view of concurrent employments with summed hours and FTE, and the person's
  leave balances shown once beside it.
- The history never shows a record the caller could not open on its own page.

**Non-Goals**

- Storing a history object. The history is derived and never persisted.
- Replacing the per-schema lists on `EmployeeDetail`; they stay for editing.
- Changing how leave accrues or how payroll reads salary.

## Decisions

### D1. Compose on read in a service, expose through one controller

`EmployeeHistoryService::historyFor(string $employeeId): array` loads each source schema
filtered on `employeeId` and maps every row to an event
`{id, schema, kind, start, end, title, description, route}`. Kinds: `contract-start`,
`contract-end`, `placement-start`, `placement-end`, `pay-change` (only `CompAdjustment`
with a non-null `appliedAt`), `leave` (only `status` approved), `sickness`, `review`
(only `status` `vastgesteld`). Events sort by `start`, newest first.

Alternative considered: a declarative `x-openregister-aggregations` view. Rejected: an
aggregation answers counts and sums over one schema; a merged, typed event list over six
schemas is a composition, which ADR-031 allows imperatively.

### D2. Visibility through the existing RBAC reader

The controller resolves the employee with `RbacObjectReader` first; a caller who may not
read the employee gets 404, the same answer as "does not exist". Every source row is then
kept only when `RbacObjectReader` returns it, so a manager scoped by
`mss-team-scope` sees what they already see on the lists.

### D3. Concurrent employments are the contracts active on a date

`GET /api/employees/{id}/employments?date=YYYY-MM-DD` (default today) returns every
`EmploymentContract` whose `startDate` is on or before the date and whose `endDate` is
empty or on or after it, with `hoursPerWeek`, `caoSchaal`, `cao` and `type`, plus the
summed hours and an `fte` of `hoursPerWeek / fullTimeHoursWeek`. The full-time week is the
same figure the absence rate divides by, `AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK`
(40) unless the caller's administration overrides it, and the response names which one it
used (`normSource`). `AbsenceRateService` already sums overlapping contracts per employee
(`fteByEmployee()`, `AbsenceRateService.php:478`); the new service was planned to call the same
helper, made public. Built (2026-09-28): the service divides `hoursPerWeek` by the same
`DEFAULT_FULL_TIME_HOURS_PER_WEEK` constant inline, because `fteByEmployee()` sums per
employee over a period and cannot return the per-contract figures this block shows; the
test asserts the same 0.9 FTE the absence rate would count for 36 hours. The response carries `concurrent: true` when
more than one contract is active.

### D4. Leave shown once, per person

The employments block shows the current year's `LeaveBalance` rows for the employee
once, below the contracts, labelled as the person's balance. Splitting leave per contract
would contradict how `leave-accrual-job` accrues and is out of scope.

### D5. One host section, library view

A registered host widget `employee-history` (kind `widget` in `src/registry.js`, with the
custom-widget ratchet's exclude marker because no built-in widget renders per-row routes)
calls the two endpoints and hands the events to `CnTimelineView` and the contracts to the
library table. It holds no business logic; ordering and filtering happen server side.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| merged event list over six schemas | imperative (`EmployeeHistoryService`) | composition across schemas, no declarative primitive |
| active contracts on a date and their sums | imperative, same service | date-window filter plus the absence rate's FTE helper |
| rendering | declarative `bodyWidgets` on `EmployeeDetail` | the library primitive exists |

## Seed data

No schema changes. The existing seed in `lib/Settings/register.d/hr-seed.json` already
holds employees with contracts and placements; one seed employee gains a second active
contract (a 16-hour contract beside a 20-hour one, for example a teacher at two schools
of the same foundation) so the combined block and the history both have content.

## Risks / Trade-offs

- [Six reads per page view] → each read is filtered on `employeeId` and capped; the
  endpoint is called once per page open, not per widget.
- [One full-time week for every CAO] → the figure matches the absence rate's own basis
  and the response names its source, so the two numbers never disagree silently.

## Open Questions

- Should withdrawn or rejected leave appear as history? This design leaves them out.
