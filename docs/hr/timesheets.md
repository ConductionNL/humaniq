---
sidebar_position: 1
description: Hour booking on time entries, per-period timesheets aggregated from them, and a submit / approve / reject workflow with server-stamped provenance.
---

# Hours & timesheets

Since the hours-process redesign, HRMQ captures worked time at **two
granularities**, both as OpenRegister objects in the `hrmq` register:

- **`TimeEntry`** ("urenregistratie", the booking) — one worked span:
  `startedAt`, `endedAt`, an optional `breakMinutes`, a `description`, an
  optional `projectId` and a `billable` flag. `hours` is **derived
  server-side** from `(endedAt − startedAt − breakMinutes)`; a booking
  whose end does not lie after its start, or whose break exceeds the
  span, is refused. A time entry has **no lifecycle of its own**.
- **`Timesheet`** ("urenstaat", the period aggregate) — one employee ×
  one month. Its `hours` and `entryCount` are **server-recomputed
  aggregates** over the entries that reference it; they are never
  hand-entered, and client input to them is inert.

## Booking

Employees book hours on **Mijn uren** (`/mijn/uren`) with a six-field
form: start, end, break, description, project, billable. Everything else
is resolved server-side: the employee record from the signed-in account,
`userId` and `administrationId` from that employee, and `costCenter`
from the employee's active org assignment. HR can book on behalf of an
employee on **Urenboekingen** (`/time-entries`), which adds only an
employee picker.

A booking without a timesheet is attached automatically: the employee's
draft timesheet for the `YYYY-MM` period of `startedAt` is found or
created. If that period's timesheet is already submitted or approved,
the booking is refused with a message to have it reopened — hours are
never silently booked into a second timesheet for the same period.

## The lifecycle

The approval workflow lives **only on the Timesheet**, governed by a
declarative `x-openregister-lifecycle` state machine — not bespoke PHP
transition code.

```
draft → submitted → approved
          ↕
       rejected
```

| Transition | From → to | Guarded by |
| --- | --- | --- |
| `submit` | `draft` or `rejected` → `submitted` | `TimesheetNotEmptyGuard` |
| `approve` | `submitted` → `approved` | `NoSelfApprovalGuard` |
| `reject` | `submitted` → `rejected` | `NoSelfApprovalGuard` |
| `reopen` | `approved` → `draft` | — |

An **empty timesheet cannot be submitted**: `TimesheetNotEmptyGuard`
denies `submit` when the timesheet has no bookings or its hours sum to
zero. A rejected timesheet can be corrected and resubmitted through the
same `submit` transition. An invalid transition — for example attempting
`approve` on a `draft` timesheet — is refused, and the state stays
unchanged.

## Server-stamped process fields

`status`, `submittedAt`, `approvedBy`, `approvedAt` and
`rejectionReason` never appear on any form and are **inert to client
input** on every write path: a write that supplies them without the
corresponding lifecycle edge has those values replaced by the stored
values before persistence. On the edges the stamping happens **inside
the same write** that changes `status`:

- `submit` stamps `submittedAt` and clears the approval fields;
- `approve` / `reject` stamp `approvedBy` (the acting session user) and
  `approvedAt`;
- `reopen` clears all four.

Because stamping rides the carrying write, the approved-time-entry
CloudEvent emitted on the `→ approved` edge carries real provenance.

## Entry immutability

Entries of a submitted or approved timesheet are **immutable**: any
create, update or delete of a `TimeEntry` whose parent is not in
`draft` or `rejected` is refused with an error naming the parent's
state. The `reopen` transition is the sanctioned route to correcting an
approved timesheet.

## Separation of duties

An employee cannot approve or reject their own timesheet. The `approve`
and `reject` transitions each declare `requires:
OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` — an OpenRegister lifecycle guard
that denies the transition when the acting user equals the timesheet's
`employeeId`. The guard **fails closed**: if the acting user or the
claiming employee cannot be identified at all, the transition is denied
rather than allowed.

## Pages

All pages are declarative manifest pages rendered generically by
`@conduction/nextcloud-vue` — no bespoke Vue components:

- `MijnUren` (`/mijn/uren`) — the employee's own bookings, with the
  six-field booking form.
- `MijnUrenstaten` (`/mijn/urenstaten`) — the employee's own timesheets,
  read-only; submission happens on the detail page.
- `TimeEntries` (`/time-entries`) — the HR booking index over all
  entries in the administration.
- `TimeEntryDetail` (`/time-entries/:id`) — one booking; derived values
  (hours, cost centre, user) display read-only.
- `Timesheets` (`/timesheets`) — the HR aggregate index. Timesheets are
  server-created, so there is no Add button.
- `TimesheetApproval` (`/timesheets/approval`) — the same schema
  pre-filtered to `status == submitted`, the pending-approval queue.
- `TimesheetDetail` (`/timesheets/:id`) — the lifecycle actions, the
  read-only Goedkeuring panel, a total-hours stat summed live over the
  entries, and the Urenboekingen list of the period's bookings.

Managers get a team-scoped view of the same queue — see
[Self-service](/docs/hr/self-service) for the `Team-urengoedkeuring`
page.

## Migration

Pre-redesign timesheets are migrated idempotently by a repair step: user
links are backfilled where the employee link resolves, and a timesheet
with hours but no entries gains exactly one synthetic entry marked
`origin: "migration"` so the aggregates stay exact. The step logs a
single summary line per run.
