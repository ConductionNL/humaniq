---
kind: code
---

# Each time entry carries the approval state of its timesheet

## Why

Shillinq bills approved, billable hours to a customer. Its open change
`sales-time-and-expense-billing` (ConductionNL/shillinq, from shillinq matrix
rows on invoicing time and expenses) reads hours through one interface,
`BillableHoursSource::unbilled(projectId, from, to)`, returning "approved,
billable hours for a project", each with person, date, hours and description.
Hours are humaniq's under hydra ADR-107 decision 6, so shillinq's
`hours-to-humaniq` supplies that read. The cross-project line of
`sales-time-and-expense-billing`, verbatim:

> This change needs a billable marker and an approval state on the hour; if humaniq's `TimeEntry` does not carry them, `hours-to-humaniq` has to add them.

Both changes are shillinq's, so the humaniq side was handed to humaniq in the
owner-moves pass of 2026-09-28.

What humaniq has, read at `e08ffe0`:

- **The billable marker is built.** `TimeEntry.billable`
  (`lib/Settings/register.d/hr-timesheet.json`), "Whether these hours are
  billable to a client or project", from the archived
  `2026-08-22-hrmq-hours-process-redesign`.
- **The approval state is not on the hour.** It lives on the parent
  `Timesheet.status` (`draft`, `submitted`, `approved`, `rejected`); the
  entry's own `x-notes` say it has no status and no lifecycle, and that the
  process lives on the parent timesheet. A reader that wants the approved, billable hours of
  one project in one period has to find the approved timesheets first and then
  their entries, across two schemas.
- **The typed event is per timesheet.** The archived
  `2026-09-06-humaniq-timesheet-approved-typed-event` dispatches
  `TimesheetApprovedEvent` with the timesheet's total hours, its project
  reference and a `billable` flag that is "true iff all entries are billable".
  A timesheet with billable and non-billable entries, or with two projects,
  cannot be billed per hour from it.

Decision in the owner-moves pass: the billable half is `existing` (the
archived redesign); the approval half is `build`, a half a merged shillinq
change depends on.

## What changes

- A time entry carries `approvalState`, the status of its timesheet, and
  `approvedAt` when approved. Both are stamped by humaniq and never taken
  from a client.
- They follow every timesheet edge: submit, approve, reject and reopen.
- One read then answers shillinq: entries with `billable: true` and
  `approvalState: approved` for a project and a date range.

## Matrix rows

None of its own. The half serves shillinq `sales-time-and-expense-billing`
and `hours-to-humaniq`; humaniq row `tim-hours-to-invoice` ("Hand approved
billable hours to invoicing") was decided `existing` on the typed event, and
this change makes the per-hour read possible beside it.

## Out of scope

- Which hours shillinq has already billed. That is shillinq's (references on
  `BillableInvoice`).
- Approving single entries. Approval stays per timesheet.
