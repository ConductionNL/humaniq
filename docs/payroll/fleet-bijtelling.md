---
sidebar_position: 11
description: Bijtelling privégebruik auto — company-car addition to taxable wage, folded into the gross BEFORE the payroll calculator runs.
---

# Company car bijtelling

Bijtelling privégebruik auto (Wet LB 1964 art. 13bis) is not a post-tax
adjustment like sick pay, retro-adjustments, or leave buy/sell — it is
a percentage of the car's cataloguswaarde that the employer must **add
to the taxable wage before loonheffing/premies are computed**. It
raises the payroll calculator's taxable gross itself, not just the net
outcome.

## Vehicle and CarAssignment

`Vehicle` (cataloguswaarde, fuel type, `bijtellingCategorie`:
`standaard` or `elektrischGeplafonneerd`) and `CarAssignment` (which
employee has which vehicle, for which effective range, and any
`eigenBijdrage`) are a **dedicated schema pair**, deliberately decoupled
from the custody-tracking `Asset`/`AssetAssignment` register used for
[general company property](/docs/people/assets) — bijtelling has fiscal
semantics an asset record does not carry.

## The 2026 rates

| Parameter | 2026 value |
| --- | --- |
| Standard bijtelling | 22% |
| EV reduced bijtelling | 18%, up to `evReducedCataloguswaardeCap` €30.000 |

Above the EV cap, the excess cataloguswaarde is bijtelled at the
standard 22% rate — only the first €30.000 gets the reduced 18%. These
figures are versioned, sourced table data; a future tax year is a
data-only re-issue.

## How it folds into the run

For each employee with an open `CarAssignment` covering the period, the
service computes the monthly bijtelling
(`max(0, base/12 − eigenBijdrage)`) and **adds it to
`grossMonthlySalaryCents`** immediately before `CalculationInput` is
constructed — `PayrollCalculator` itself receives an already-adjusted
gross and is not modified in any way. The generated `Payslip` carries
`bijtelling` (the computed monthly amount, null when no assignment
covers the period) and `carAssignmentId`.

A large `eigenBijdrage` (employee contribution towards private use)
floors the bijtelling at zero — it can never go negative.

## Machine-checked

`nl-bijtelling-auto-privegebruik` independently re-derives the monthly
bijtelling from the referenced `CarAssignment`/`Vehicle` and flags any
cents-mismatch against the recorded `Payslip.bijtelling`:

```bash
occ hrmq:rules:audit
occ hrmq:payroll:verify --period 2026-06
```

Bijtelling is computed automatically as part of the normal run — there
is no separate trigger command.
