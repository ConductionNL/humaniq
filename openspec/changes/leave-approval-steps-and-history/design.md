# Design: a second approval step for leave, and who decided when

## Context

Read at `development` af702f78.

- `LeaveRequest` (`lib/Settings/register.d/hr-leave.json`, 0.4.0) has the lifecycle
  `draft`, `submitted`, `approved`, `rejected` with `submit` (guarded by
  `LeaveTypeConditionGuard`), `approve` and `reject` (both guarded by `NoSelfApprovalGuard`),
  line 41 onward. It carries `submittedAt`, `approvedBy` ("User ID of the manager who
  approved (or rejected)", line 126), `approvedAt`, `rejectionReason`, `userId`,
  `managerUserId`.
- Nothing writes `approvedBy` for leave. `lib/Listener/LeaveApprovalListener.php` is a
  post-save listener that only projects the balance.
  `lib/Listener/TimesheetProcessStampListener.php` is the pre-save precedent: on
  `ObjectCreatingEvent` and `ObjectUpdatingEvent` it restores the stored process fields
  against client input and stamps `approvedBy` from the session and `approvedAt` from the
  clock on the status edge (lines 254-320).
- `LeaveRequestDetail` (`src/manifest.d/hr-leave.json:4`) has an "Approval" data widget with
  `status`, `submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason`, all read-only, and
  lifecycle actions `submit`, `approve`, `reject`.
- The approval queues are `LeaveApproval` and `TeamVerlofgoedkeuring`
  (`src/manifest.d/05-menu.json:272`, `:287`), over status `submitted`.
- `LeaveType` (`hr-leave-types.json`, 0.1.0) has `code`, `label`, `drawsFromBalance`,
  `requiresReason`, `requiresDocument`, `maxNoticeDays`, `active`.
- `lib/Lifecycle/LeaveBuySellApprovalGuard.php` delegates to `NoSelfApprovalGuard` first and
  then adds its own check: the composition precedent.
- The HR check at development is `IGroupManager::isAdmin()` (`OfferController::isAdminOrHr()`,
  `lib/Controller/OfferController.php:129`); distinct HR roles are
  `compliance-roles-and-field-access`.
- `LeaveHoursCalculator::COUNTED_STATUS` is `approved`: only approved requests draw from a
  balance.

## Goals / Non-Goals

**Goals**

- Every decision on a leave request names its maker and time, on the request.
- Leave types that need HR get HR's approval after the manager's, in that order.
- Nothing about a request that needs no HR step changes.

**Non-Goals**

- Configurable chains per unit or amount of steps beyond two.
- Changing which status draws from the balance.

## Decisions

### D1. Stamping moves into a pre-save listener

`LeaveRequestProcessStampListener` mirrors the timesheet listener. On create it forces
`status: draft` and clears the process fields. On update it restores the stored
`submittedAt`, `approvedBy`, `approvedAt` and `decisions`, then stamps the edge found in the
write:

| edge | stamps |
|---|---|
| to `submitted` | `submittedAt`; clears `approvedBy` and `approvedAt` |
| `submitted` to `manager-approved` | appends `{step: manager, decision: approved, by, at, remark}` |
| to `approved` | `approvedBy`, `approvedAt`; appends the step's decision |
| to `rejected` | `approvedBy`, `approvedAt` (as today's field meaning), accepts `rejectionReason`; appends the decision |

`decisions` is never cleared, so a resubmitted request keeps its history. The one
client-supplied value accepted is the remark and `rejectionReason`, as for timesheets.

Alternative considered: read the history from OpenRegister's audit trail. Rejected: the
audit trail records field changes, not which step decided, and the row asks for the answer
on the request.

### D2. The HR step is a status, not a flag

`LeaveRequest.status` gains `manager-approved`. Transitions:

- `approve-manager`: `submitted` to `manager-approved`;
- `approve`: from `submitted` or `manager-approved` to `approved`;
- `reject`: from `submitted` or `manager-approved` to `rejected`.

`LeaveApprovalStepGuard` is `requires` on all three. It calls `NoSelfApprovalGuard` first,
then resolves the request's leave type: when the type needs HR (`requiresHrApproval`, or the
request's hours above `hrApprovalAboveHours`), `approve` from `submitted` is refused and
`approve-manager` is the way on; when it does not, `approve-manager` is refused. From
`manager-approved`, `approve` and `reject` require the HR check and a different person than
the manager who approved.

Alternative considered: a second boolean `hrApproved` beside `status`. Rejected: every
consumer that reads `status` (balance projection, leave calendar, department schedule)
would count a manager-approved request as approved.

### D3. Queues follow the status

`LeaveApproval` and `TeamVerlofgoedkeuring` keep reading `submitted`. A new
`HR-verlofgoedkeuring` index reads `manager-approved`. `LeaveRequestDetail` shows
`decisions` in the library's `CnTimelineStages` and gains the `approve-manager` action.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| the extra status and transitions | declarative `x-openregister-lifecycle` | the state machine is data |
| which step applies | lifecycle guard via `requires` | the ADR-031 PHP seam for a precondition |
| stamping decisions | imperative pre-save listener | acting user and clock, inert to client input, timesheet precedent |
| HR queue and timeline | declarative manifest | existing widgets |

## Seed data

- The seeded `unpaid` leave type gains `requiresHrApproval: true`; `holiday` gets
  `hrApprovalAboveHours: 80`.
- One seeded unpaid request at `manager-approved` with one manager decision, and one
  approved holiday request with its manager decision, so both the timeline and the HR queue
  have content.

## Risks / Trade-offs

- [Requests submitted before this lands have no decisions] → `decisions` starts empty and
  fills from the next edge; the page shows the audit trail for earlier history as today.
- [The HR check is admin until roles land] → the guard calls one method, so
  `compliance-roles-and-field-access` swaps the check in one place.

## Open Questions

- Should HR be able to approve directly from `submitted`, skipping the manager, when the
  manager is the requester's only superior and is away?
