---
capability: time-entry-capture
status: done
built_by: openspec/changes/archive/2026-07-14-time-entry-capture
---

# time-entry-capture Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [time-entry-capture](../../changes/archive/2026-07-14-time-entry-capture/) _(archived 2026-07-14)_ — verify-first: humaniq already owned time-entry capture + the submit→approve lifecycle (the `Timesheet` schema, its `x-openregister-lifecycle`, and `NoSelfApprovalGuard`); this change added the missing hand-off — a `nl.conduction.hrmq.timeentry.approved` CloudEvent emitted on the approval edge via OpenRegister's `WebhookService` so a finance app (shillinq) can consume approved hours for invoice-from-time / WBSO.
- [hrmq-hours-process-redesign](../../changes/archive/2026-08-22-hrmq-hours-process-redesign/) _(archived 2026-08-22, merged as humaniq#128)_ — splits capture into the `TimeEntry` booking and the `Timesheet` period aggregate (the lifecycle lives on the aggregate alone), derives `hours` server-side, recomputes the timesheet aggregates from the entries, freezes entries once their timesheet leaves `draft`/`rejected`, and fixes the empty approval provenance the transition-endpoint path used to emit — the CloudEvent envelope and its `data` keys are unchanged (kind: code+config)

## Purpose

Home the fleet's timesheet / hours-capture surface in humaniq (HR's domain) and emit
an approved-time-entry event a finance app consumes — so shillinq's hours-consumers
(WBSO export, urencriterium, invoice-from-time) have a real source, closing the
dangling `bookkeeping-time-tracking` dependency declared by `zzp-urencriterium-tracker`.
Capture and the submit→approve lifecycle already existed at HEAD; the approval
**event** was the gap this capability fills. The shillinq consume side
(projecting the event onto invoice-from-time / WBSO) is a named, separate shillinq
follow-up and is not part of this capability.

## Requirements

### Requirement: humaniq captures time entries under a submit→approve lifecycle (REQ-TEC-001)

humaniq SHALL capture worked time at two granularities with English schema names (Dutch only via
l10n labels): an individual booking is a **`TimeEntry`** (NL "urenregistratie") carrying
`startedAt`/`endedAt` timestamps, an optional `breakMinutes`, a server-derived `hours` value, a
`description`, an optional `projectId` and a `billable` flag; the per-employee, per-period
aggregate is the **`Timesheet`** (NL "urenstaat"), which keeps `period` and alone carries the
declarative `x-openregister-lifecycle` submit → approve/reject → reopen state machine on
`status`. A `TimeEntry` never has its own lifecycle. `TimeEntry.hours` SHALL be derived
server-side from `(endedAt − startedAt − breakMinutes)`; a booking whose end does not lie after
its start, or whose break exceeds the span, SHALL be refused with a structured error.

#### Scenario: A worker logs and submits hours for approval

- **GIVEN** an employee with a linked Nextcloud account
- **WHEN** they book a TimeEntry with `startedAt` 2026-05-04T09:00Z, `endedAt` 2026-05-04T17:30Z
  and `breakMinutes` 30
- **THEN** the entry persists with a server-derived `hours` of 8.00
- **AND** it is attached to that employee's draft Timesheet for period `2026-05` (created if
  absent)
- **AND** submitting that Timesheet moves it to `submitted` exactly as before

#### Scenario: A manager may not approve their own hours

- **GIVEN** a submitted Timesheet whose `employeeId` resolves to the acting user
- **WHEN** the acting user attempts the `approve` transition
- **THEN** `NoSelfApprovalGuard` denies the transition (unchanged from the pre-redesign
  contract)

#### Scenario: An impossible time span is refused

- **WHEN** a TimeEntry is written whose `endedAt` is not after its `startedAt`
- **THEN** the write is refused with a structured 422 and no object is persisted

### Requirement: Approving a timesheet emits the approved-time-entry CloudEvent (REQ-TEC-002)

When a `Timesheet` transitions **into** `approved` (old status not `approved`, new
status `approved`), humaniq SHALL emit exactly one `nl.conduction.hrmq.timeentry.approved`
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

The `nl.conduction.hrmq.timeentry.approved` CloudEvent envelope and `data` keys SHALL remain
byte-compatible with the pre-redesign contract (`timesheetId`, `employeeId`, `period`, `hours`,
`billable`, `projectId`, `costCenter`, `clientRef`, `description`, `approvedBy`, `approvedAt`) —
no key added, removed, or retyped. Under the entry/aggregate split their semantics SHALL be:
`hours` is the aggregate of the timesheet's entries; `projectId` and `costCenter` carry the
single shared value when every entry agrees and `''` otherwise (a value the contract has always
permitted); `billable` is true iff every entry is billable. `approvedBy` and `approvedAt` SHALL
be populated from the lifecycle stamping of the carrying write — the pre-redesign behaviour of
emitting empty provenance on the transition-endpoint path is a defect this change removes, not a
compatible behaviour to preserve. A consumer needing per-entry granularity reads the entries via
the register API using the `timesheetId` the event already carries; the event itself SHALL NOT
grow an entries array in this change.

#### Scenario: The payload exposes hours, project and billable

@e2e exclude Backend-only event contract; no UI surface — verified by PHPUnit against TimeEntryEventService, matching the capability's existing exclusion precedent
- **GIVEN** an approved Timesheet whose entries all name `projectId` "project-alpha" and are all
  billable, summing to 152 hours
- **WHEN** the approval edge emits the CloudEvent
- **THEN** `data.hours` is 152, `data.projectId` is "project-alpha", `data.billable` is true

#### Scenario: Heterogeneous entries yield empty allocation fields, not a guess

@e2e exclude Backend-only event contract; no UI surface — verified by PHPUnit
- **GIVEN** an approved Timesheet whose entries name two different projects
- **WHEN** the approval edge emits the CloudEvent
- **THEN** `data.projectId` is `''` and `data.hours` is still the exact aggregate
- **AND** no event key is added, removed or retyped relative to the pre-redesign envelope

#### Scenario: Approval provenance is populated on the sanctioned UI path

@e2e exclude Provenance rendering is asserted in the UI by hours-process.spec.ts journey (d); the emitted payload itself is backend-only and verified by PHPUnit
- **GIVEN** a manager approves a submitted Timesheet through the transition endpoint
- **WHEN** the approval edge emits the CloudEvent
- **THEN** `data.approvedBy` is the manager's uid and `data.approvedAt` is the stamped timestamp
  (neither empty)

#### Scenario: The envelope is a valid CloudEvents 1.0 shape

- GIVEN any approved `Timesheet`
- WHEN the event is built
- THEN `specversion` is `1.0`, `type` is `nl.conduction.hrmq.timeentry.approved`, `datacontenttype` is `application/json`, and `time` is non-empty (falling back to the current UTC time when `approvedAt` is unset)

### Requirement: A time entry's parent timesheet aggregates its entries (REQ-TEC-004)

The system SHALL maintain `Timesheet.hours` and `Timesheet.entryCount` as server-recomputed
aggregates over the TimeEntry objects whose `timesheetId` references it, recomputed on every
TimeEntry create, update, delete and reparent (both the old and new parent). The denormalized
`projectId`/`costCenter`/`billable` aggregate semantics of REQ-TEC-003 SHALL be maintained by
the same recompute. Aggregation SHALL be recompute-from-truth (never increment), so a re-run
over unchanged entries is a no-op. The aggregates SHALL never be client-writable: client input
to them is inert and overwritten by the recompute.

#### Scenario: Booking updates the running total before submission

- **GIVEN** a draft Timesheet with one 8-hour entry
- **WHEN** the employee books a second 4-hour entry in the same period
- **THEN** the timesheet lists `hours` 12.00 and `entryCount` 2 without any submit having
  happened

#### Scenario: An empty timesheet cannot be submitted

- **GIVEN** a draft Timesheet whose entries have all been deleted (`entryCount` 0)
- **WHEN** the employee attempts the `submit` transition
- **THEN** the transition is refused with a message that there are no hours to submit
- **AND** the timesheet remains in `draft`

### Requirement: Entries of a submitted or approved timesheet are immutable (REQ-TEC-005)

The system SHALL refuse any create, update or delete of a TimeEntry whose parent Timesheet is
not in state `draft` or `rejected`, with a structured error naming the parent's state. The
`reopen` transition (unchanged) is the sanctioned route to correcting an approved timesheet.
humaniq's own migration and aggregation writes are exempt via an internal-writer marker that is
request-scoped, never global.

#### Scenario: A booking cannot be edited after submission

- **GIVEN** a Timesheet in state `submitted` with an attached entry
- **WHEN** the employee attempts to edit that entry's hours
- **THEN** the write is refused and the entry is unchanged

#### Scenario: Reopening restores editability

@e2e exclude Covered end-to-end by the submit/approve journey in hours-process.spec.ts only up to approval; the reopen-then-edit tail is backend-verified by the mutability-guard unit tests to keep the e2e suite within one seeded lifecycle pass
- **GIVEN** an approved Timesheet
- **WHEN** the `reopen` transition returns it to `draft`
- **THEN** its entries accept edits again
