---
kind: config
---

# Mijn HR Self-Service (employee-scoped pages + Dashboard)

## Why

The 2026-07-12 market deep-research (logged in Spectr, insights `hrmq-insight-afas-baseline` / `hrmq-insight-ranked-buildlist`) established employee self-service as a baseline expectation for any NL HR/payroll product — AFAS Pocket alone claims 2.4M end users, and Loket.nl, Nmbrs and Employes all ship an in-product "mijn" experience (my hours, my expenses, my leave, my payslips). hrmq's own accepted IA record (`openspec/architecture/adr-001-information-architecture.md`) already freezes `Mijn HR` as top-level menu 2 and `Dashboard` as top-level menu 1, and Rule 2 mandates that self-service is a **role-filtered wrapper inside hrmq** — never a sibling portal app (portaliq covers EXTERNAL people without Nextcloud accounts; the in-app employee experience is this change). Today hrmq has **zero** self-service surface for its own logged-in users and no Dashboard page at all: every index page (Timesheets, Expenses, Payslips) lists everyone's records, so an employee cannot even find their own.

## What Changes

- **NC-account link on Employee** — new optional `nextcloudUserId` property (the durable link between an Employee domain object and a Nextcloud account; HR sets it on the employee record). Schema version bump.
- **Per-record user scoping on the self-service schemas** — new optional denormalized `userId` property (Nextcloud user id) on `Timesheet`, `Expense`, `LeaveRequest` and `Payslip`, filtered with the renderer's `@me` token. The investigation (design.md, "Scoping mechanism (investigated)") verified that `@me` in an index page's base filter is resolved at fetch time by today's renderer, while OpenRegister `owner` metadata is **not** reachable from a manifest filter and two-hop filtering (record → Employee → nextcloudUserId) does not exist in the token grammar — so a one-hop denormalized property is the only mechanism that demonstrably works today.
- **New menu group `Mijn HR`** (icon `account`, ADR-001 menu 2) with four employee-scoped index pages, each base-filtered `{ "userId": "@me" }`: `MijnUren` (Timesheet), `MijnDeclaraties` (Expense), `MijnVerlof` (LeaveRequest), `MijnLoonstroken` (Payslip, read-only — employees never create their own payslips).
- **New `Dashboard` page** (`type: dashboard`, icon `view-dashboard`, ADR-001 menu 1) with a small, demonstrably-supported widget set: three `@me`-scoped stat KPIs (my submitted hours / expenses / leave), two approver stat KPIs (submitted timesheets / expenses awaiting review, deep-linking to the existing approval pages — ADR-001 Rule 2 routes manager scope to dashboard widgets), and one `object-table` of my recent items.
- **Menu ordering per ADR-001** — Dashboard first (order 10), Mijn HR second (order 20). The existing groups (orders 90–120) are NOT restructured: the active change `hrmq-ia-navigation-alignment` owns the full IA realignment (fragment pipeline + re-homing Uren/Onkosten); this change only adds the two frozen top slots in front of them.
- **Seed data** — extend `hr-seed.json`: add the (previously missing) seed `Employee` "employee-jansen" carrying `nextcloudUserId: "admin"`, stamp `userId: "admin"` onto the existing Jansen timesheet + expense seed objects, and add one LeaveRequest and one Payslip for that employee — so a dev-instance `admin` login shows populated Mijn HR pages out of the box.

### Non-goals

- **No manager self-service beyond the dashboard approver widgets** — ADR-001 Rule 2 routes manager scope to Dashboard widgets + the approval index pages that already exist (`TimesheetApproval`, `ExpenseApproval`). No "Manager portaal".
- **No automatic `userId` stamping on employee-created records** — the renderer has no create-form token defaults today (verified: `open-form` has no prefill, `object-op` create `values` are not token-resolved, the built-in create dialog does not inherit the page's base filter). Population is seed + back-office maintained; the renderer feature request is recorded as an open question in design.md.
- **No mobile app, no notifications** (x-openregister-notifications adoption is deliberately deferred app-wide, same stance as `loonaangifte-filing-lifecycle`).
- **No leave detail/approval pages** — the active change `leave-verzuim-mvp` owns `LeaveRequests`/`LeaveApproval`/`LeaveRequestDetail`; `MijnVerlof` here is a self-scoped list that works standalone.

## Capabilities

### New Capabilities

- `mijn-hr-self-service`: the `Mijn HR` menu group with four `@me`-scoped employee pages, the `Dashboard` page with self-service + approver KPI widgets, the `Employee.nextcloudUserId` account link, and the denormalized `userId` scoping property on Timesheet/Expense/LeaveRequest/Payslip.

### Modified Capabilities

<!-- none — the existing specs (hrmq-expenses, hrmq-timesheet-approval, loonaangifte-filing-lifecycle, portal-*) are untouched; the new properties are optional and additive -->

## Impact

- `lib/Settings/register.d/hr-objects.json` — `Employee`: add `nextcloudUserId`; `Payslip`: add `userId`; bump both schema versions.
- `lib/Settings/register.d/hr-timesheet.json` — `Timesheet`: add `userId`; version bump.
- `lib/Settings/register.d/hr-expense.json` — `Expense`: add `userId`; version bump.
- `lib/Settings/register.d/hr-leave.json` — `LeaveRequest`: add `userId`; version bump.
- `lib/Settings/register.d/hr-seed.json` — seed Employee (nextcloudUserId "admin"), `userId` stamps on existing Jansen objects, one LeaveRequest + one Payslip seed.
- `src/manifest.json` — `Dashboard` page + menu entry (order 10), `Mijn HR` menu group (order 20) + `MijnUren`/`MijnDeclaraties`/`MijnVerlof`/`MijnLoonstroken` index pages.
- `lib/Repair/InitializeRegister.php` — no change (fragment import picks up the schema bumps).
- No PHP (kind: config).
- Related active changes: `hrmq-ia-navigation-alignment` (owns the full menu realignment + manifest fragment pipeline — this change adds only the two ADR-001 top slots and leaves the other groups where they are; if the IA change lands first, these pages move into its fragment layout mechanically), `leave-verzuim-mvp` (owns the back-office leave pages; `MijnVerlof` does not depend on them).
