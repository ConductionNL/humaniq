---
sidebar_position: 9
description: A per-run diff report — who entered, who left, what changed, and the total wage-cost delta — before approving a run.
---

# Payroll mutation reports

The one thing a loonadministrateur does before flipping a run from
`draft` to `approved`: compare it to the previous period. Who joined,
who left, whose gross/net/premies moved and by how much, and what the
total wage-cost delta is. `PayrollMutationService` produces that
comparison deterministically as a **pure subtraction over the two
runs' already-persisted payslips** — it never recomputes payroll.

## It reads; it never recomputes

The service resolves two `PayrollRun` ids, keys both payslip sets by
employee, and classifies each employee:

- **entered** — present only in the `to` run
- **left** — present only in the `from` run
- **changed** — present in both, with `grossPay`/`nettoPay`/
  `loonheffing`/employer-cost differing
- **unchanged** — present in both, all four equal

For shared employees it computes integer-cents deltas
(`to.component − from.component`) per component, and rolls the whole
run up into `grossDelta`, `netDelta`, `loonheffingDelta`,
`employerCostDelta`, and `totalWageCostDelta = grossDelta +
employerCostDelta`, plus entered/left/changed/unchanged counts. The
service never constructs `PayrollCalculator`, never reads a tax table,
and never writes a `PayrollRun`/`Payslip` — so it cannot drift from the
engine's own arithmetic.

## Prior-run auto-resolution

Given only a `toRunId`, the service auto-resolves `from` as the run of
the **same administration** whose period is the closest one strictly
before it. A first run for an administration (no prior period) is a
valid input, not an error: every employee is classified `entered` with
`before = 0`. Comparing two runs from **different administrations** is
refused — the pairing is meaningless.

## Persisting a report

A `PayrollMutationReport` is idempotently keyed `(fromRunId, toRunId)`
— regenerating the same pair upserts the existing report in place,
never creating a duplicate.

## Running it

```bash
occ hrmq:payroll:mutations --from <runId> --to <runId>
occ hrmq:payroll:mutations --to <runId> --persist
```

`--to` alone auto-resolves the prior period. `--persist` saves the
report. In the UI, `PayrollRunDetail` carries a "Mutatieoverzicht"
action that generates and persists the report and routes to its detail
page, where the run-level deltas render as stat KPIs alongside the
per-employee mutation table.

Because payroll mutation figures are sensitive, generating a report is
**admin/HR only** — a non-admin caller is refused before any run is
resolved.
