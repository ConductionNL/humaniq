---
kind: code
---

# An approved claim keeps its amounts

## Why

Payroll pays an approved expense claim on the payroll route: it pays `amount` to the
employee in `employeeId`, when `taxableAmount` is zero, `approvedAt` falls on or before the
period's last day, and `payrollRunId` is empty or the current run. An edit that keeps the
status `approved` never reaches OpenRegister's lifecycle check, so nothing kept those fields
as they were when the claim was approved. Such an edit could change what is paid, when, or by
which run. A create runs no lifecycle check at all, so a claim could also be created as
approved.

## What changes

- On any write of a claim stored as `approved` or `reimbursed`, the claim keeps its stored
  `amount`, `taxFreeAmount`, `taxableAmount`, `currency`, `approvedBy` and `approvedAt`.
- On the approve write (stored `submitted`, saved `approved`), the claim keeps the `amount`,
  `taxFreeAmount`, `taxableAmount` and `currency` it was submitted with. `approvedBy` and
  `approvedAt` are stamped by that write, as before.
- `payrollRunId` and `paidInPeriod` change only through humaniq's own writes (the payroll
  run). Any other write keeps their stored values.
- A claim stored as `approved` or `reimbursed` also keeps its `reimbursementRoute`, so an edit
  cannot move a claim paid directly onto the payroll route and pay it twice. Only humaniq's own
  writes may change it. This fails closed: a wrong route is not corrected by an edit.
- A new claim starts as a draft or as submitted. A create with any other status is refused,
  and a new claim always starts without `approvedBy`, `approvedAt`, `payrollRunId` and
  `paidInPeriod`, whatever the create sends. OpenRegister does not run the lifecycle on a
  create, so without this a claim could be created as approved and be paid.
- humaniq's own register and demo imports count as humaniq's own writes here: they run as a
  shipped import, so the example claims shipped as approved or reimbursed still import.
- The expense category setting is hardened:
  - a save without the list of categories, or with something that is not a list of names,
    is refused with a 400 response and changes nothing;
  - at least one category stays on;
  - a category counts as allowed only when it is one of the seven known names, compared
    without case or surrounding spaces, and is on.

## Out of scope

- Which account the no-self-approval guard compares, and the lifecycle check on a create:
  both belong to OpenRegister and are handled there.
