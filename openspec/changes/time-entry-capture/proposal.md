---
kind: proposal
depends_on: []
---

## Why

shillinq's `zzp-urencriterium-tracker` spec declares a dependency on a spec
`bookkeeping-time-tracking` that **does not exist**, and every hours-consumer in
shillinq (WBSO export, urencriterium, invoice-from-time) assumes approved hours
arrive from *somewhere*. The correct source is an **hrmq** timesheet leaf —
timesheet / hours capture is HR's domain; shillinq should *consume* approved
hours, not grow its own timesheet inside the accounting app.

**Verified at HEAD (`080389f`, origin/development).** hrmq already ships the
capture + approval half of this:

- `lib/Settings/register.d/hr-timesheet.json` — the `Timesheet` schema: a
  per-employee, per-period time-entry record with `employeeId`, `period`,
  `hours`, `description`, `projectId`, `costCenter`, `billable`, `clientRef`,
  `status`, `approvedBy`, `approvedAt`, `rejectionReason`.
- A declarative `x-openregister-lifecycle` (ADR-031) on `status`:
  `draft → submitted → approved / rejected → reopen`, with the `approve`/`reject`
  transitions guarded by `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` (separation of
  duties — the approver may not be the claiming employee).

**What is missing (the actual gap):** nothing happens *on approval*. hrmq emits
**no event** when a timesheet is approved — it uses `WebhookService` nowhere,
registers no OpenRegister object-event listener, and there is no
`nl.conduction.hrmq.*` CloudEvent (verified: zero `WebhookService` / `dispatchEvent`
/ `nl.conduction` hits under `hrmq/lib`). So a downstream finance app has no way
to learn that approved, billable hours exist. The dangling dependency stays open
not because capture is missing, but because the **hand-off** is.

## What Changes

Add the emit side only. On a `Timesheet` crossing into `approved`, hrmq emits the
`nl.conduction.hrmq.timeentry.approved` CloudEvent (CloudEvents 1.0 envelope)
through OpenRegister's `WebhookService`, mirroring the fleet convention already
shipped by pipelinq's `ShillinqWipService` (`nl.conduction.pipelinq.time.approved`).
The event carries the approved hours, project / cost centre and billable flag a
finance consumer needs to raise an invoice line or a WBSO urenregistratie row.

- `lib/Service/TimeEntryEventService.php` — builds the CloudEvent and dispatches
  it fire-and-forget; owns the schema gate, the approval-edge (idempotent)
  gate, and the payload contract.
- `lib/Listener/TimesheetApprovalListener.php` — a thin `ObjectUpdatedEvent`
  adapter that resolves the changed object's schema slug and delegates.
- `lib/AppInfo/Application.php` — registers the listener on `ObjectUpdatedEvent`.

Capture and approval already exist and are **not** rebuilt. No schema, seed, UI
or i18n key changes are required for the event itself; the change is additive and
touches no existing behaviour.

## Impact

- Affected specs: **time-entry-capture** (new).
- Affected code: `lib/Service/TimeEntryEventService.php` (new),
  `lib/Listener/TimesheetApprovalListener.php` (new), `lib/AppInfo/Application.php`
  (one listener registration).
- **Out of scope — named follow-up (does NOT belong here):** the shillinq
  *consume* side. A separate shillinq change **`shillinq: consume hrmq approved
  time (invoice-from-time / WBSO)`** SHALL register a listener for
  `nl.conduction.hrmq.timeentry.approved` and project the approved hours onto its
  invoice-from-time and WBSO/urencriterium surfaces. That change **closes the
  dangling `bookkeeping-time-tracking` dependency** in
  `zzp-urencriterium-tracker` by pointing it at this hrmq event as its hours
  source. It is not built here and shillinq is not modified by this change.
