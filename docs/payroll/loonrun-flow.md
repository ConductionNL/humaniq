---
sidebar_position: 15
description: Adopting the shipped Loonrun flow, running it every month on a schedule, and letting an approved run wait for the pay day.
---

# The Loonrun flow: adopt, schedule and pay day

Humaniq ships the monthly payroll run as an OpenRegister flow called **Loonrun**: calculate the
draft run and its payslips, ask a reviewer to approve or reject it, then post the approved run to
the ledger and prepare the net pay, both through shillinq (see [GL posting & SEPA
net-pay](./gl-and-sepa.md)). The flow arrives **disabled** and **started by hand**. Nothing
happens until an administrator adopts it.

## Adopt the flow

1. Open **OpenRegister › Flows** and find **Loonrun**.
2. Open the **review** step and point its candidate group at your payroll reviewers.
3. Enable the flow. From now on **Run** starts one payroll run for the current month.

The review step is a task for a person: the run is only approved, posted and paid after someone
in that group has looked at the payslips and chosen *Approve*.

**The flow is the only approver once enabled.** While Loonrun is enabled, a run can no longer be
approved by editing its status directly: a save that moves a draft run to approved, posted or paid
is refused with a message pointing at the review step. Only the flow's own approve step, after the
reviewer's decision, sets a run to approved. While the flow is disabled, the status edit works as
before, so an administration that has not adopted the flow keeps its current way of approving.

## Run it every month

Humaniq does not ship a schedule, because a scheduled flow runs as a named account and only you
can choose which one. To run the payroll every month:

1. In the Loonrun flow, replace the manual start step with an `openregister.trigger-schedule`
   step.
2. Give it a cron expression, for example `0 6 1 * *` (06:00 on the first of every month), or
   one that fits your payroll calendar.
3. Set **Run as** (`runAs`) to the service account of your payroll administration: an existing,
   enabled user with the payroll role. The schedule refuses to save without one.
4. Save and keep the flow enabled.

**Oversight.** A scheduled Loonrun still stops at the review task before anything is approved,
posted or paid: the only thing it does unattended is calculate a draft, which can be recalculated
at any time. It is therefore not autonomous work in OpenRegister's oversight sense, and needs no
oversight registration. If you remove or bypass the review step, it does: register the flow then.

## Wait for the pay day

An administration can carry a **Pay day** (`paymentDayOfMonth`, under Configuration ›
Administrations): the day of the month salaries are paid; in a shorter month the last day. When
a monthly run is calculated, its pay date travels with the run as `payroll.payDate`, for example
`2026-11-25`.

After the reviewer approves, the Loonrun waits in its **payday** step (`openregister.wait` with
`until: "{{ payroll.payDate }}"`) until that date, and only then posts the run to the ledger and
prepares the payment batch. A run without a pay date (an administration without a pay day, or a
four-weekly or weekly period) passes the wait at once and is posted straight after approval, as
is a run approved on or after its pay date. The wait needs an OpenRegister that fills a field of
the run into a wait step (ConductionNL/openregister#11043); an older OpenRegister refuses the
placeholder when the flow is saved.
