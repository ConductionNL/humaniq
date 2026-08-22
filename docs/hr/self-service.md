---
sidebar_position: 4
description: The Mijn HR self-service surface for employees, and team-scoped approval queues for managers.
---

# Self-service

Humaniq gives every logged-in employee an in-app self-service surface — never
a separate portal app — by scoping the same schemas and pages employees
already use with the OpenRegister renderer's `@me` filter token.

## Mijn HR

`Mijn HR` is a **routed group**: clicking its title opens the personal
dashboard at `/mijn`, and the chevron still folds its children away. Every
Mijn surface lives under the `/mijn/...` prefix (the older
`/mijn-hr/gebruikelijk-loon` path redirects there, so existing bookmarks
keep working).

Its children are the `@me`-scoped index pages: uren
(TimeEntry — the booking surface, see
[Hours & timesheets](/docs/hr/timesheets)), urenstaten (Timesheet,
read-only), declaraties (Expenses), verlof (LeaveRequests), and
loonstroken (Payslips) — each pre-filtered to the current user's own
records.

Scoping works through a **denormalized `userId` property** on `Timesheet`,
`TimeEntry`, `Expense`, `LeaveRequest`, `LeaveBalance` and `Payslip` — a plain copy of
the linked `Employee.nextcloudUserId`, filtered with the renderer's `@me`
token. This is the one mechanism verified to work with today's renderer
and OpenRegister; it is not a live join. `Employee.nextcloudUserId` links
an employee record to its Nextcloud account and stays `null` for
portal-only external employees (that scoping remains UUID-claim based in
portaliq and is untouched here). For `Timesheet` and `TimeEntry` the
stamp is **server-maintained**: it is re-derived from `employeeId` on
every write, so it can never drift and employees never set it. For
`Expense` and `LeaveRequest` HR maintains it (their process redesigns
follow), Payslip's `userId` is set by payroll alongside `employeeId`
since employees never author their own payslips, and `LeaveBalance`'s is
written by the leave-accrual job — the schema's only systematic writer —
on both the balances it creates and the ones it accrues onto each month,
so a balance from before the property existed picks the link up on the
next run without a migration. Records whose employee
has no linked account keep `userId: null` and never appear on a Mijn
page — fail-closed.

External (no-NC-account) self-service — for candidates or contractors
without a Nextcloud login — stays with portaliq and is out of scope here.

## The personal dashboard

`/mijn` is the employee's own landing surface — the app Dashboard is the
management steering view and carries no `@me`-scoped widgets. Six widgets,
every one of them filtered to the caller:

- **Mijn uren deze maand** — the sum of the caller's own booked hours since
  the first of the current month, opening their booking page.
- **Mijn open declaraties** — how many of their expense claims are with the
  organisation awaiting a decision (`submitted`); drafts are their own
  unfinished input and are not counted.
- **Te beoordelen urenstaten** — the caller's own approval queue. It always
  renders, so somebody who manages nobody sees a plain `0` rather than a
  tile that silently disappears.
- **Mijn urenstaten** — the most recent timesheets with their period, hours,
  entry count and status; a row opens the timesheet, which is where
  submission happens.
- **Mijn verlofsaldo** — this year's balance per leave type: entitled,
  bovenwettelijk and used hours. It reads the denormalized `userId` above,
  so a balance not yet linked to an account is left out rather than shown
  to the wrong person.
- **Mijn recente loonstroken** — the last few payslips, read-only.

Where a widget shows nothing it is saying "nothing of yours here", never
"nothing exists" — the filters fail closed by design.

## Team-scoped approval for managers

Managers get their own approval queues, never a "Manager portaal" — Humaniq
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
approval page's existing `status: submitted` filter. A manager's own
queue also surfaces on the personal dashboard above, as the
"Te beoordelen urenstaten" tile that opens `Team-urengoedkeuring`.

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
occ humaniq:rules:audit
```

See [The compliance rule engine](/docs/compliance/rule-engine) for how the
audit and its coverage metric work, and [Org chart](/docs/people/org-chart)
for the `OrgUnit`/`OrgAssignment` structure the consistency rule checks
against.
