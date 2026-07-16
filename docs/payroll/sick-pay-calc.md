---
sidebar_position: 7
description: Loondoorbetaling bij ziekte — 70% continuation, the year-1 minimum-wage floor, the wachtdag deduction, and CAO uplift.
---

# Sick pay (loondoorbetaling)

HRMQ already modelled the *administrative* side of sickness
([`SickLeaveCase`](/docs/hr/leave-and-verzuim) and the Poortwachter
clock) and already has the gross-to-net engine, but the two are
connected: a sick employee's payslip does **not** simply feed the engine
the full `grossMonthlySalary`. `SickPayCalculator` computes loondoorbetaling
bij ziekte (BW 7:629 lid 1) first, and the resulting doorbetaald loon
is what the payroll engine actually taxes.

## The calculation

For a fully-sick employee, in order:

1. **Continuation** — `70%` of the reference wage (the case's
   `loondoorbetalingPercentage`, default 70; many CAOs set 90 or 100)
2. **Year-1 minimum-wage floor** — in the first 52 weeks of
   `firstSickDay`, doorbetaald loon is raised to the statutory WML
   monthly floor if the raw 70% would fall below it (part-time scaled).
   Past 52 weeks (year 2) the WML floor drops out, leaving the bare
   70%-of-wage floor.
3. **Wachtdag deduction** — one waiting day, valued at the doorbetaald
   daily rate, deducted **once per case**, only in the month
   `firstSickDay` actually falls in — never in a continuation month.
4. **Samengesteld/aangepast loon** — an optional `aangepastLoon` (wage
   still earned from partial work) composes as the worked wage at 100%
   plus continuation on the non-worked remainder.

All arithmetic is integer cents with half-up rounding at the
percentage-multiply and wachtdag-divide steps — never accumulated
floats. `PayrollCalculator` itself is not modified: `SickPayCalculator`
computes the doorbetaald loon, and that figure is fed into the existing
engine as the period's taxable gross.

## Worked example

A fully-sick, full-time employee earning €3.800,00, 70%, year 1, no
wachtdag: continuation is €2.660,00, the floor doesn't bind
(`floorApplied: false`), and `payableGross` is €2.660,00 — the figure
that goes into loonheffing/net, not €3.800,00.

## The payslip reflects doorbetaald loon, not the full salary

When `PayrollRunService` finds an open (`gemeld`) `SickLeaveCase`
covering the run period for an employee, it feeds the computed
`payableGross` into `CalculationInput` instead of the full salary. The
generated `Payslip` is stamped with `sickLeaveCaseId`, `doorbetaaldLoon`,
`wachtdagDeduction`, `sickPayReferenceWage`, `sickPayPercentage`,
`sickPayMinimumWageFloor` and `sickPayYearOne` (all null/absent on a
non-sick payslip). When no open case covers the period, the full-salary
path is entirely unchanged.

## Machine-checked floor

`nl-loondoorbetaling-floor` independently recomputes the 70%/WML floor
from a Payslip's own recorded fields and flags any doorbetaald loon
below it — a mandatory violation:

```bash
occ hrmq:rules:audit
```

Sick pay is generated automatically as part of the normal run:

```bash
occ hrmq:payroll:run --period 2026-06
```

There is no separate command — `SickLeaveCase` lookups happen inside
`PayrollRunService::generate()` for every active employee.
