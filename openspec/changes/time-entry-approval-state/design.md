# Design: each time entry carries the approval state of its timesheet

Read at humaniq `development` `e08ffe0`.

## Context

- `lib/Settings/register.d/hr-timesheet.json`: `TimeEntry` has `date`,
  `hours`, `projectId`, `billable`, `timesheetId`, `employeeId` and more, and
  no status. `Timesheet.status` is `draft`, `submitted`, `approved`,
  `rejected`, moved only by the lifecycle transitions `submit`, `approve`,
  `reject` and `reopen`.
- `lib/Listener/TimeEntryStampListener.php`: the pre-save stamp on every
  entry write. It resolves or creates the entry's monthly timesheet
  (`findOrCreateTimesheet()`), and refuses any write to an entry whose parent
  is not `draft` or `rejected` (`MUTABLE_PARENT_STATES`).
- `lib/Listener/TimesheetProcessStampListener.php:254` `stampEdge()`: the
  timesheet edges `draft>submitted`, `rejected>submitted`,
  `submitted>approved`, `submitted>rejected`, `approved>draft`.
- `lib/Listener/TimesheetApprovalListener.php`: on `ObjectUpdatedEvent` for a
  timesheet crossing into `approved`, delegates to `TimeEntryEventService`.
- `lib/Listener/TimesheetAggregateListener.php`: recomputes the timesheet on
  every entry write and returns early for internal writes
  (`InternalWriteMarker::isInternal()`, :96).
- `lib/Service/HoursRegisterGateway.php`: `findFiltered()` (:141) and
  `save()` (:163), the register plumbing every listener uses.

## D1. Two stamped properties on the entry

`TimeEntry` gains `approvalState` (enum `draft`, `submitted`, `approved`,
`rejected`, default `draft`) and `approvedAt` (date-time, nullable), both
documented as server-stamped and inert to client input, like the timesheet's
process fields.

## D2. Stamped on the entry's own write

`TimeEntryStampListener::stamp()` sets `approvalState` to the parent
timesheet's status on every create and update, and clears `approvedAt`. An
entry can only be written while its parent is `draft` or `rejected`, so this
is always one of those two.

## D3. Carried down on every timesheet edge

A post-save listener, `TimesheetEntryStateListener`, on `ObjectUpdatedEvent`
for the `Timesheet` schema, acts only when `status` changed. It reads the
timesheet's entries (`findFiltered('TimeEntry', ['timesheetId' => id])`) and
saves each with the new `approvalState`, and with `approvedAt` from the
timesheet on `approved` (cleared otherwise). The saves run under
`InternalWriteMarker`, so the entry mutability guard and the aggregate
recompute do not fire, as for the existing migration writes. A failure is
logged and never breaks the timesheet transition; a repair step
(`occ maintenance:repair`) re-stamps every entry from its parent, so a missed
edge heals.

Alternative considered: leave the state on the timesheet and let the reader
join. Rejected: OpenRegister filters one schema per query, so every reader
would re-implement the join, which is what shillinq's change asks humaniq to
spare it.

## D4. The read shillinq needs

No new endpoint: `TimeEntry` filtered on `projectId`, `date` range,
`billable: true` and `approvalState: approved` through OpenRegister's objects
API. The property is declared filterable so the query is served by the index.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The two properties | Declarative, the register fragment | Data. |
| Stamping on the entry's write | Imperative, the existing pre-save stamp | It already reads the parent. |
| Carrying a timesheet edge to its entries | Imperative, a post-save listener | A fan-out over child objects. |

## Seed data

One approved timesheet of employee Visser for 2026-09 with three entries,
two billable on project "Renovatie Kade 12" and one internal meeting; all three
carry `approvalState: approved`.

## Risks

- **A drift between entry and timesheet.** The timesheet stays the authority;
  the repair step re-stamps from it.
- **Many entries per edge.** A monthly timesheet holds tens of entries; one
  save each on a rare edge.
