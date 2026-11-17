---
kind: code
---

# A unit manager's records go to the manager above them

## Why

humaniq finds an employee's manager in one hop: the employee's active
placement (`OrgAssignment`), its unit, and that unit's `managerId`. When the
employee manages that unit themself, the hop returns the employee. REQ-MSS-001
and REQ-MSS-005 accept that answer.

The separation-of-duties rule in `humaniq-timesheet-approval` forbids approving
your own timesheet. So a unit manager placed in their own unit gets their own
timesheets, expenses and leave requests in their own team queue. They cannot
approve them, and nobody else is routed them.

Ruben decided on 2 November 2026: climb to the parent unit.

## What changes

- Manager resolution walks up the unit tree when the unit's manager is the
  employee themself. It stops at the first different person.
- The employee themself means the same Employee record, or another Employee
  record with the same Nextcloud account.
- The walk ends with no manager at a root unit, at a parent that does not
  resolve, and at a unit it already visited. No manager keeps today's
  behaviour: no stamp, so the record shows in no team queue.
- An unmanaged unit, a manager Employee that does not resolve, and a manager
  without a Nextcloud account still end the walk with no manager. Only a
  self-managed unit makes it climb.
- Every caller uses the same resolution: the Timesheet stamp, the other
  server-side `managerUserId` stamps, the approvals inbox fallback, the
  relations case notification, the hours migration repair, the
  `nl-mss-manager-consistency` audit and the learning people feed.

## Effect on the seed data

The seed places employee-jansen (account `admin`) in Consultancy, and jansen
manages both Consultancy and its parent Directie. The walk finds no other
manager for jansen. The audit passes jansen's three stamped seeds vacuously,
and the next server-side write of `timesheet-jansen-2026-05` clears its stamp.
A seed that demos a team queue of someone else's records is follow-up work for
the seed owner; this change does not edit the seed.

## Out of scope

- Which units a manager leads (`UnitMembership::managedUnitIds`, department
  figures). That reads the tree downwards and is unchanged.
- The org chart's manager column, which shows a unit's own manager.

## Matrix rows

Rows: `ess-manager-team` (humaniq `openspec/parity/capabilities.json`).
