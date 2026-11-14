---
kind: code
---

# Settle the shipped example claim on existing tenants

## Why

Until humaniq#783, the register seed shipped the example travel claim `expense-devries-train`
as approved, on the payroll route, without a payroll run. The next payroll run on a tenant
that kept the seed employee would pay it. #783 ships the claim as reimbursed on the direct
route, but on a tenant that already imported the old seed the re-import cannot change it:
an approved claim keeps its route and amounts, and humaniq refuses a hand-made
reimbursement of a payroll-route claim. Ruben decided on 3 November 2026 to settle it once
with a repair step.

## What changes

- A repair step runs on upgrade and install, after the register import.
- It finds the Expense with slug `expense-devries-train`. It changes it only when the claim
  still holds exactly the content the old seed shipped, belongs to the employee with slug
  `employee-devries`, and has no `payrollRunId`.
- It then marks the claim `reimbursed` on the direct route, with `reimbursedAt`
  2026-05-26T10:00:00Z, as humaniq's own write. It touches no other field and no other
  record, and logs the claim's id.
- A second run finds nothing to change: the claim no longer holds the old content.

## Out of scope

- Any claim that is not the shipped example, and the example claim once anyone changed it.
