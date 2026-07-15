---
sidebar_position: 1
description: Per-period hour registration with a submit / approve / reject workflow.
---

# Timesheets

`Timesheet` models a submitted timesheet as an OpenRegister object in the
`hrmq` register, whose approval workflow is governed by a declarative
`x-openregister-lifecycle` state machine — not bespoke PHP transition
code.

## The lifecycle

```
draft → submitted → approved
          ↕
       rejected
```

| Transition | From → to |
| --- | --- |
| `submit` | `draft` or `rejected` → `submitted` |
| `approve` | `submitted` → `approved` |
| `reject` | `submitted` → `rejected` |
| `reopen` | `approved` → `draft` |

A rejected timesheet can be corrected and resubmitted through the same
`submit` transition. An invalid transition — for example attempting
`approve` on a `draft` timesheet — is refused, and the state stays
unchanged.

Every `Timesheet` carries at least `employeeId`, `period`, `hours`,
`status`, `submittedAt`, `approvedBy`, `approvedAt`, and
`rejectionReason`.

## Separation of duties

An employee cannot approve or reject their own timesheet. The `approve`
and `reject` transitions each declare `requires:
OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` — an OpenRegister lifecycle guard
that denies the transition when the acting user equals the timesheet's
`employeeId`. The guard **fails closed**: if the acting user or the
claiming employee cannot be identified at all, the transition is denied
rather than allowed.

A manager (or any other user) approving a different employee's timesheet
is allowed normally, and the transition stamps `approvedBy` and
`approvedAt`.

## Pages

`Timesheets` is a plain index list. `TimesheetApproval` is the same
schema pre-filtered to `status == submitted` — the pending-approval
queue. `TimesheetDetail` drives the lifecycle actions. All three are
declarative manifest pages rendered generically by
`@conduction/nextcloud-vue` — configured only with `{ register: "hrmq",
schema: "Timesheet", ... }`, no bespoke Vue components.

Managers get a team-scoped view of the same queue — see
[Self-service](/docs/hr/self-service) for the `Team-urengoedkeuring`
page.
