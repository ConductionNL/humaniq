---
kind: code
---

# The payroll run waits for its pay date, runs on a schedule, and the flow is its only approver

## Why

`payroll-run-as-a-flow` shipped the monthly payroll run as an OpenRegister flow (calculate,
review, approve, post to the ledger, prepare net pay) and named three follow-ups it deliberately
left out (its design D5 and D7): the run posts the moment it is approved, because humaniq stores
no pay date to wait for; the flow ships manual-triggered, because a schedule needs a service
account humaniq cannot choose for a customer; and the raw `PayrollRun.status` edit still
approves a run outside the flow, because that edit is the production path until adopted flows
carry the approval everywhere. This change carries those three, split out on 2026-11-24 so the
shipped flow could be archived on its own.

## What changes

- An administration gets a pay date (`paymentDayOfMonth`), and the Loonrun flow waits between
  approve and post until that day of the run's period.
- The schedule adoption recipe (an `openregister.trigger-schedule` node with an explicit `runAs`)
  is documented on the admin surface, with oversight registration if the scheduled flow counts as
  autonomous work.
- Once adopted flows carry the approval everywhere, the raw `status` edit is guarded so the
  recorded decision is the only approver.

## Impact

- `lib/Settings/register.d/hr-administratie.json` (`paymentDayOfMonth`), the Loonrun flow
  declaration, the admin documentation surface, and a guard on `PayrollRun.status`.
- Depends on `payroll-run-as-a-flow` (archived 2026-11-24, `openspec/changes/archive/2026-11-24-payroll-run-as-a-flow`).
