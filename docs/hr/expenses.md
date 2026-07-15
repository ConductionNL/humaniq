---
sidebar_position: 2
description: Employee expense claims with a submit / approve / reject / reimburse workflow.
---

# Expense claims

`Expense` models an employee expense-reimbursement claim as an
OpenRegister object in the `hrmq` register, with a declarative
`x-openregister-lifecycle` on its `status` field (initial `draft`,
terminal `reimbursed`).

## The lifecycle

```
draft → submitted → approved → reimbursed
          ↕
       rejected
```

| Transition | From → to |
| --- | --- |
| `submit` | `draft` or `rejected` → `submitted` |
| `approve` | `submitted` → `approved` |
| `reject` | `submitted` → `rejected` |
| `reimburse` | `approved` → `reimbursed` (terminal) |

`reimburse` is only reachable from `approved` — attempting it from
`submitted` is refused. Every `Expense` carries at least `employeeId`,
`title`, `amount`, `currency`, `category`, `expenseDate`, `status`,
`submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason`, and
`reimbursedAt` (stamped when `reimburse` executes).

The lifecycle is expressed declaratively in the register fragment, the
same pattern as [Timesheets](/docs/hr/timesheets) — no hand-written Vue
approval views and no bespoke reimbursement service code.

## Asset custody, not expenses

Physical company property (laptops, phones, vehicles, access passes) is
tracked separately in the [asset register](/docs/people/assets), not as
an expense claim.
