---
kind: code
---

# An approved claim keeps its employee

## Why

Payroll pays an approved expense claim to the employee its `employeeId` names. The approver
stamp keeps a decided claim's `userId`, `managerUserId` and `administrationId`, but the claim's
`employeeId` could still change on the approve write or on a later write. That would point the
payout at another employee.

## What changes

- On the approve write (stored `submitted`, saved `approved`), the claim keeps its stored
  `employeeId`.
- On any write of a claim stored as `approved` or `reimbursed`, the claim keeps its stored
  `employeeId`.
- An open claim (draft, submitted, rejected) may still change its employee. Its identity then
  follows the new employee, as before.

## Out of scope

- Which account the no-self-approval guard compares, and the lifecycle check on a create:
  both belong to OpenRegister and are handled there.
