---
sidebar_position: 4
description: The Mijn HR self-service surface for employees, and team-scoped approval queues for managers.
---

# Self-service

HRMQ gives every logged-in employee an in-app self-service surface — never
a separate portal app — by scoping the same schemas and pages employees
already use with the OpenRegister renderer's `@me` filter token.

## Mijn HR

The `Mijn HR` menu group holds five `@me`-scoped index pages: uren
(TimeEntry — the booking surface, see
[Hours & timesheets](/docs/hr/timesheets)), urenstaten (Timesheet,
read-only), declaraties (Expenses), verlof (LeaveRequests), and
loonstroken (Payslips) — each pre-filtered to the current user's own
records.

Scoping works through a **denormalized `userId` property** on `Timesheet`,
`TimeEntry`, `Expense`, `LeaveRequest`, and `Payslip` — a plain copy of
the linked `Employee.nextcloudUserId`, filtered with the renderer's `@me`
token. This is the one mechanism verified to work with today's renderer
and OpenRegister; it is not a live join. `Employee.nextcloudUserId` links
an employee record to its Nextcloud account and stays `null` for
portal-only external employees (that scoping remains UUID-claim based in
portaliq and is untouched here). For `Timesheet` and `TimeEntry` the
stamp is **server-maintained**: it is re-derived from `employeeId` on
every write, so it can never drift and employees never set it. For
`Expense` and `LeaveRequest` HR maintains it (their process redesigns
follow), and Payslip's `userId` is set by payroll alongside `employeeId`
since employees never author their own payslips. Records whose employee
has no linked account keep `userId: null` and never appear on a Mijn
page — fail-closed.

External (no-NC-account) self-service — for candidates or contractors
without a Nextcloud login — stays with portaliq and is out of scope here.

## Dashboard KPIs

The Dashboard carries both "mine" and approver KPI widgets: a submitted
timesheet count scoped to the logged-in user alongside a
"te beoordelen" count scoped to everyone (approvers see the full queue,
not just their own submissions), plus a "Mijn recente uren" object-table
of the current user's five most recent timesheets.

## Team-scoped approval for managers

Managers get their own approval queues, never a "Manager portaal" — HRMQ
adds Dashboard widgets and scoped pages inside the *existing* menus
instead. A denormalized `managerUserId` (the same pattern as `userId`)
on `Timesheet`, `Expense`, and `LeaveRequest` drives three team-scoped
pages. For `Timesheet` it is a **server-maintained cache** of the org
chain (`OrgAssignment` → `OrgUnit.managerId` → the manager's
`nextcloudUserId`), stamped on every write; for `Expense` and
`LeaveRequest` it remains HR/back-office maintained until their own
process redesigns:

- `Team-urengoedkeuring` — Timesheet, filtered to `managerUserId: @me`
- `Team-declaratiegoedkeuring` — Expense, same pattern
- `Team-verlofgoedkeuring` — LeaveRequest, same pattern

Each sits directly under its existing menu group, right after the global
approval entry — `TimesheetsGroup`, `ExpensesGroup`,
`VerlofVerzuimGroup` — composing the manager's `@me` filter with the
approval page's existing `status: submitted` filter. The Dashboard's
approver widgets are similarly re-scoped to "(mijn team)", with a
fallback row that restores the global submitted-counts for HR staff who
need the full picture across every team.

### Kept honest by a consistency rule

Because `managerUserId` is a denormalized stamp rather than a live lookup,
a recommended-severity rule cross-checks it against the actual
organisation structure: `nl-mss-manager-consistency` resolves each
record's employee to their active `OrgAssignment`, that unit's manager,
and the manager's `nextcloudUserId` — and flags a stamp that doesn't match
what the org chart says. It never punishes missing org data: unresolvable
hops (no active assignment, an unmanaged unit, a manager without an NC
account) pass vacuously rather than flagging false violations.

```bash
occ hrmq:rules:audit
```

See [The compliance rule engine](/docs/compliance/rule-engine) for how the
audit and its coverage metric work, and [Org chart](/docs/people/org-chart)
for the `OrgUnit`/`OrgAssignment` structure the consistency rule checks
against.
