---
capability: time-entry-capture
status: done
built_by: openspec/changes/archive/2026-07-14-time-entry-capture
---

# time-entry-capture Specification

**Status**: done
**Scope**: hrmq
**OpenSpec changes**:
- [time-entry-capture](../../changes/archive/2026-07-14-time-entry-capture/) _(archived 2026-07-14)_ — verify-first: hrmq already owned time-entry capture + the submit→approve lifecycle (the `Timesheet` schema, its `x-openregister-lifecycle`, and `NoSelfApprovalGuard`); this change added the missing hand-off — a `nl.conduction.hrmq.timeentry.approved` CloudEvent emitted on the approval edge via OpenRegister's `WebhookService` so a finance app (shillinq) can consume approved hours for invoice-from-time / WBSO.

## Purpose

Home the fleet's timesheet / hours-capture surface in hrmq (HR's domain) and emit
an approved-time-entry event a finance app consumes — so shillinq's hours-consumers
(WBSO export, urencriterium, invoice-from-time) have a real source, closing the
dangling `bookkeeping-time-tracking` dependency declared by `zzp-urencriterium-tracker`.
Capture and the submit→approve lifecycle already existed at HEAD; the approval
**event** was the gap this capability fills. The shillinq consume side
(projecting the event onto invoice-from-time / WBSO) is a named, separate shillinq
follow-up and is not part of this capability.

## Requirements

### Requirement: hrmq captures time entries under a submit→approve lifecycle (REQ-TEC-001)

hrmq SHALL own the timesheet / hours-capture surface for the fleet: a worker
records a time entry against a period with `hours`, an optional `projectId` /
`costCenter`, a `billable` flag and a `description`, and the entry moves through a
declarative `draft → submitted → approved` lifecycle (with `rejected` and
`reopen`), the `approve`/`reject` transitions guarded so an employee cannot
approve their own hours. This surface is the `Timesheet` schema
(`lib/Settings/register.d/hr-timesheet.json`) and its `x-openregister-lifecycle`
plus `NoSelfApprovalGuard`, which already exist at HEAD; this requirement pins them
as the fleet's canonical hours-capture home so shillinq consumes them rather than
growing a parallel timesheet in the accounting app.

#### Scenario: A worker logs and submits hours for approval

- GIVEN a worker creates a `Timesheet` with `hours: 36.5`, `projectId: proj-alpha`, `billable: true` in status `draft`
- WHEN the worker applies the `submit` transition
- THEN the timesheet status is `submitted` and awaits a manager decision

#### Scenario: A manager may not approve their own hours

- GIVEN a submitted timesheet whose `employeeId` resolves to the same user requesting approval
- WHEN the `approve` transition is attempted by that user
- THEN `NoSelfApprovalGuard` denies the transition and the status stays `submitted`

### Requirement: Approving a timesheet emits the approved-time-entry CloudEvent (REQ-TEC-002)

When a `Timesheet` transitions **into** `approved` (old status not `approved`, new
status `approved`), hrmq SHALL emit exactly one `nl.conduction.hrmq.timeentry.approved`
CloudEvent through OpenRegister's `WebhookService`, dispatched fire-and-forget so a
missing consumer or an unavailable OpenRegister never fails the approval write. A
change that is not the approval edge — a non-approval transition, or a re-save of an
already-approved timesheet — SHALL NOT emit. A status change into `approved` on any
schema other than `Timesheet` SHALL NOT emit this event.

#### Scenario: A submitted→approved timesheet emits the event

- GIVEN a `Timesheet` at status `submitted`
- WHEN it transitions to `approved`
- THEN exactly one `nl.conduction.hrmq.timeentry.approved` CloudEvent is dispatched through OpenRegister's WebhookService

#### Scenario: An unapproved change emits nothing

- GIVEN a `Timesheet` transitioning `draft → submitted`
- WHEN the change is saved
- THEN no `nl.conduction.hrmq.timeentry.approved` event is dispatched

#### Scenario: Re-saving an already-approved timesheet does not re-emit

- GIVEN a `Timesheet` already at status `approved`
- WHEN it is saved again (still `approved`)
- THEN no event is dispatched (the emit is bound to the approval edge, idempotent)

#### Scenario: Approval on a non-Timesheet schema does not emit

- GIVEN a non-`Timesheet` object transitioning into a status `approved`
- WHEN the change is saved
- THEN no `nl.conduction.hrmq.timeentry.approved` event is dispatched

### Requirement: The event carries what a finance consumer needs (REQ-TEC-003)

The `nl.conduction.hrmq.timeentry.approved` event SHALL be a CloudEvents 1.0
envelope (`specversion: "1.0"`, `type`, `source: /apps/hrmq/timesheets`, `id` =
the timesheet uuid, `time`, `datacontenttype: application/json`) whose `data`
carries at least the approved `hours` (number), the `billable` flag (boolean), the
`projectId` and `costCenter` the hours are booked against, the `employeeId`, the
`period`, and the approval provenance (`approvedBy`, `approvedAt`) — the minimum a
downstream finance app needs to raise an invoice-from-time line and a WBSO /
urencriterium row.

#### Scenario: The payload exposes hours, project and billable

- GIVEN an approved `Timesheet` with `hours: 36.5`, `projectId: proj-alpha`, `billable: true`, `approvedBy: manager-jansen`
- WHEN the event is built
- THEN `data.hours` is `36.5`, `data.projectId` is `proj-alpha`, `data.billable` is `true`, and `data.approvedBy` is `manager-jansen`

#### Scenario: The envelope is a valid CloudEvents 1.0 shape

- GIVEN any approved `Timesheet`
- WHEN the event is built
- THEN `specversion` is `1.0`, `type` is `nl.conduction.hrmq.timeentry.approved`, `datacontenttype` is `application/json`, and `time` is non-empty (falling back to the current UTC time when `approvedAt` is unset)
