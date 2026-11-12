---
kind: code
---

# Managers and their deputies read their team's records

## Why

Every schema in the `humaniq` register is HR-only unless it declares its own rules. Employees
read their own records through a rule on the account stamped on the record, and managers read
leave requests, expense claims and other requests through `managerUserId`.

Four kinds of record carry no manager field, so a manager outside HR can no longer read them:

- a team member's `Employee` record (a manager opens a team member),
- a `SickLeaveCase` the manager is notified about,
- a `LeaveTransaction` (buying or selling leave) in the approvals inbox,
- a `CompAdjustment` proposal for a team member.

A deputy standing in for the manager cannot read any of them either. Ruben decided on
11 November 2026 to stamp a manager field and a deputy field on these four schemas, with a
read rule for both. Access stays with OpenRegister's own rules: no group and no controller.

## What changes

- `Employee`, `SickLeaveCase`, `LeaveTransaction` and `CompAdjustment` gain `managerUserId`
  and `deputyUserIds`.
- Each schema gets a read rule for `managerUserId` and a `$contains` rule for
  `deputyUserIds`. `LeaveTransaction` also gets them for update, because the manager or the
  deputy approves the trade. `SickLeaveCase` gets its own block: HR for every verb, manager and
  deputies for read.
- A pre-save listener stamps both fields on every save, from the shared org resolution for
  today and the deputies active today. Values sent by the client are replaced.
- A pass restamps every stored record whose stamps differ. It runs as a repair step after the
  register import (the backfill), every six hours (a deputy period starts or ends), and once
  after an `OrgAssignment`, `OrgUnit` or `ManagerDeputy` is saved or deleted.
- The register version goes to 0.61.0 so OpenRegister imports the new fields and rules.

## Out of scope

- The other approval schemas (`LeaveRequest`, `Expense`, `Timesheet` and so on) keep their
  manager rule and get no deputy field here.
- Who may create a `CompAdjustment` proposal does not change.
