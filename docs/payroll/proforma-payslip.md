---
sidebar_position: 8
description: A persist-nothing "what if" simulator over the same payroll engine — no Employee, Contract, Run, or Payslip is ever created.
---

# Proforma payslip simulation

HR admins repeatedly need the answer to a hypothetical — "if I offer
€4.100 bruto wit, what is the net?", "what does an AOW-age hire net?",
"how does a 0,8 part-time factor land?" — without creating throw-away
`Employee`/`EmploymentContract`/`PayrollRun`/`Payslip` records. The
proforma simulator is a **persist-nothing front door** to the same
engine documented on [The payroll engine](/docs/payroll/payroll-engine):
it builds a `CalculationInput` from hypothetical inputs, runs the
existing `PayrollCalculator` against the real `nl-<YYYY>` tables, and
returns the full gross-to-net breakdown.

## Nothing is written

The simulator creates no `Employee`, `EmploymentContract`, `PayrollRun`
or `Payslip`, and performs no OpenRegister write of any kind — the
register's object count is unchanged before and after a simulation. It
adds **no new tax logic**: the calculator, its input/result value
objects, and the tables are consumed exactly as they are for a real
run. Given the anchor input (€3.800,00 wit, below AOW, period 2026-02),
the simulator reproduces the engine's own anchor figures exactly —
netto €3.081,17 — proving the reuse is faithful rather than a
reimplementation that happens to agree today.

## What it returns

The full breakdown: `grossPay`, `loonheffing`, `arbeidskorting`,
`volksverzekeringen`, `zvw` (mode + rate), `appliedTaxRate`,
`vakantiegeldReserved`, `werknemersverzekeringen`, `employerCharges`,
and `nettoPay`.

A one-off bijzondere beloning is folded into the period gross as a
**combined-loon estimate** — explicitly not the statutory bijzonder
tarief (a named engine fast-follow) — so the response is honest about
what shortcut it took.

## Running a simulation

```bash
occ humaniq:payroll:proforma --gross 3800 --table wit --period 2026-02
```

This is the support-facing surface: reproduce a net figure with no
browser and no database access. Flags: `--gross`, `--table`
(`wit`/`groen`), `--date-of-birth`, `--parttime`, `--bijzonder`,
`--period`, `--aof`, `--whk`.

In the UI, the "Simuleer loonstrook" page renders the hypothetical-input
form and the breakdown by calling `POST /api/payroll/proforma`. This
endpoint is RBAC-gated the same way the rest of payroll is: a caller
whose RBAC cannot resolve the payroll register — i.e. anyone who could
not see a real Payslip — gets HTTP 404, so the simulator surface itself
is never leaked to an unauthorized caller.
