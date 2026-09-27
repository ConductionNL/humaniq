# Design: events for joining, leaving, job change, leave and sickness

## Context

Read at `development` af702f78.

- `lib/Service/TimeEntryEventService.php` builds the `nl.conduction.hrmq.timeentry.approved`
  CloudEvent, sends it fire-and-forget through OpenRegister's `WebhookService` (looked up
  lazily from the container), and also dispatches the typed
  `OCA\Humaniq\Event\TimesheetApprovedEvent` through `IEventDispatcher` (ADR-041). Only the
  approval edge emits (`isApprovalTransition()`, :166). `TimesheetApprovalListener` is its thin
  OpenRegister adapter, registered in `lib/AppInfo/Application.php:393`.
- hydra ADR-001: webhooks go through `WebhookService` with CloudEvents; no custom webhook
  controllers.
- The records that mark each moment: `EmploymentContract` (`startDate`, `endDate`,
  `normfunctieId`), `Onboarding` and `Offboarding` (`hr-onboarding.json`, lifecycle actions
  `afronden`), `OrgAssignment` (`orgUnitId`, `startDate`, `endDate`), `LeaveRequest`
  (`status`, `startDate`, `endDate`), `SickLeaveCase` (`firstSickDay`, `recoveredDate`,
  `status`, lifecycle `herstellen`).
- `leave-calendar-nc` keeps a hard AVG boundary: no reason, type or diagnosis leaves humaniq on
  the calendar. REQ-VWP-002 forbids medical data on a sickness case.

## Goals / Non-Goals

**Goals**

- One event per HR moment, emitted on the edge, never twice for the same moment.
- Payloads a connected system can act on without receiving personal data it does not need.

**Non-Goals**

- A generic object webhook. OpenRegister already offers one per schema; these are the domain
  moments on top.

## Decisions

### D1. Edges, not states

| event | edge |
|---|---|
| `employee.joined` | an `Onboarding` enters its completed state, or a first `EmploymentContract` is created for an employee without earlier contracts |
| `employee.left` | an `Offboarding` enters its completed state, or the last live contract gets an `endDate` in the past |
| `employee.jobchanged` | `EmploymentContract.normfunctieId` changes, or an `OrgAssignment` is created for an employee who already had one |
| `leave.approved` / `leave.withdrawn` | `LeaveRequest.status` enters `approved` / leaves `approved` |
| `sickness.reported` / `sickness.recovered` | a `SickLeaveCase` is created / takes `herstellen` |

Each builder compares old and new data like `isApprovalTransition()`, so a repeated save emits
nothing. Duplicate signals for joining (contract and onboarding) carry the same `subject`
(employee id) and a `joinedOn` date; the event id is derived from employee id plus date, so a
consumer deduplicates on it.

### D2. Payloads

Common: `employeeId`, `nextcloudUserId`, `administrationId`, `occurredOn`. `joined`:
`startDate`, `orgUnitId`. `left`: `lastWorkingDay`. `jobchanged`: `from` and `to` with
`orgUnitId`, `normfunctieId`. `leave.*`: `startDate`, `endDate`, `hours`. `sickness.*`:
`from`, `to`. Nothing else.

### D3. Typed events

`EmployeeJoinedEvent`, `EmployeeLeftEvent`, `EmployeeJobChangedEvent`, `LeaveApprovedEvent`,
`SicknessReportedEvent` (with a flag for recovered and withdrawn), dispatched on the same edge,
independent of the webhook, as `TimesheetApprovedEvent` is.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| detecting the edge and building the payload | imperative listener and service | a cross-record edge (first contract, last contract) and data minimisation |
| delivery | OpenRegister `WebhookService` | the platform path, ADR-001 |
| subscriptions | OpenRegister webhook settings | configuration, no humaniq code |

## Seed data

None. A live check subscribes a request-bin style test endpoint on a dev instance.

## Risks / Trade-offs

- [Two joined signals for one person] → one deterministic event id per employee and date.
- [A consumer wants more data] → it reads the object through the OpenRegister API under its
  own rights; the event says only that something happened.

## Open Questions

- Should department changes of an org unit itself (a reorganisation) emit events for every
  member? This design emits only per assignment.
