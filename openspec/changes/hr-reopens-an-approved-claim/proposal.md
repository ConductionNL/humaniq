---
kind: code
---

# HR reopens an approved expense claim

## Why

An approved claim keeps its amounts, its approval and its reimbursement route, so an edit
can no longer change what payroll pays. That also means a mistake in an approved claim,
such as the wrong route, cannot be corrected by an edit. Ruben decided on 3 November 2026
that HR may reopen such a claim, so it goes through approval again.

## What changes

- The Expense lifecycle gets a `reopen` transition from `approved` to `submitted`.
- `HrReopenGuard` allows it only for a member of the HR group who is neither the claimant nor
  the person who approved the claim, and only while no payroll run holds the claim (no
  `payrollRunId`). A reimbursed claim cannot be reopened: the transition starts at
  `approved` only.
- The reopen write keeps the approved amounts and currency, clears `approvedBy`,
  `approvedAt` and `reimbursementRoute`, and stamps a new `submittedAt`. The route is chosen
  again when the claim is approved again. The claim keeps its employee and identity.
- A reopen is not refused because its category was switched off later: that rule covers a
  claim being made or submitted by an employee, not HR sending one back to approval.
- Each reopen is recorded in OpenRegister's audit trail, as every change to a claim is: the
  account that made it, and the status, approver, approval date and route before and after.
  humaniq keeps no audit log of its own (ADR-022).

## Out of scope

- Reopening a claim that a payroll run holds or has paid.
- Reopening other approvable records.
