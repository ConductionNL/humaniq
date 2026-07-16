---
sidebar_position: 12
description: Director-major-shareholder payroll — no werknemersverzekeringen premiums, plus the gebruikelijkloon norm check.
---

# DGA payroll mode

A DGA (directeur-grootaandeelhouder — director-major-shareholder) is
generally **not verzekeringsplichtig** for the werknemersverzekeringen:
no Awf (WW), Aof (WIA/WAO), Wko, or Whk premium is levied, employer or
employee side. Loonheffing and Zvw still apply in full.

## What actually changes — and what does not

Setting `Employee.isDga` drives `verzekeringsplichtig: false` into the
calculation, which zeroes **all four** employer premium lines
(Awf/Aof/Wko/Whk are all drawn from the same capped premieloon bucket —
there is no partial subset). `employerCharges` reduces to Zvw only.

**Netto pay does not change.** In this engine `nettoPay = gross −
loonheffing` only — werknemersverzekeringen are employer-borne charges
that never reduced the employee's net pay to begin with, DGA or not.
What changes for a DGA is `employerCharges`, which drops by exactly the
zeroed premium amount. Any UI copy or support conversation about this
feature should say **"the employer no longer pays werknemersverzekeringen
for this DGA"** — never "the DGA's net pay increases," which is simply
false in this engine.

`Payslip.isDga` is stamped as a denormalized copy of `Employee.isDga`,
so downstream consumers can see *why* werknemersverzekeringen read
zero for a given payslip.

## The gebruikelijkloonregeling

A DGA is instead subject to the customary-salary rule (Wet LB 1964 art.
12a): the BV must pay at least a norm salary, verified at **€58.000 per
jaar in 2026** (up from €56.000 in 2024/2025). This figure is versioned,
sourced table data.

A machine-checked, auto-discovered rule flags a DGA paid below the
norm — unless a justification is on file:

- Vacuous when `isDga` is not true
- Vacuous when `gebruikelijkloonJustification` is non-empty (MVP:
  presence-only exemption; validating the justification's content
  against the statutory grounds is a named follow-up)
- Otherwise satisfied only when `grossMonthlySalary × 12 ≥` the loaded
  gebruikelijkloon norm

```bash
occ hrmq:rules:audit
```

## Named non-goals

- **Doorbetaaldloonregeling** (one payer paying loon across multiple
  linked BVs on a DGA's behalf) is not modelled — each Employee/
  PayrollRun stays single-administration.
- **Multiple-BV aggregation** for the gebruikelijkloon norm — the check
  evaluates one Employee record in isolation.
- **30%-ruling interaction** — a DGA with a granted 30%-ruling reduces
  the effective gebruikelijkloon norm; this is not wired in. The check
  compares against the raw norm only.

DGA handling applies automatically inside the normal run — there is no
separate command.
