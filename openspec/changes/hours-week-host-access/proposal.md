# Proposal: the week's hours on an object are answered only to a reader of that object

## Why

Review of #690 found two faults in `GET /api/time-entries/week`:

1. It did not scope to the caller. The entries were read "as the caller", but `TimeEntry` carries no `authorization` block, so OpenRegister's access rules let every tenant user read every entry. Any user could ask for the names, teams and hours booked on any object, including a case they cannot open.
2. A busy object was undercounted. The read took one page of 500 unsorted rows and filtered the week afterwards in PHP.

## What changes

- **Choice: a host-object read check, not an authorization block on `TimeEntry`.** humaniq had no generic check, so `HostObjectAccess::canRead()` asks OpenRegister, with the caller's access rules and tenancy, for the host object named by `domainObjectType` (`<register>:<schema>`) and `domainObjectRef`. The week answers 404 when the caller may not read it, before any entry is read. An authorization block on `TimeEntry` would answer a different question (whose entries may I see), and would change every other hours read in humaniq, including payroll and timesheets.
- A caller-scoped read through `HoursRegisterGateway::findFiltered(..., true)` now pages through every row (500 per page, at most 200 pages) instead of stopping at the first page.
