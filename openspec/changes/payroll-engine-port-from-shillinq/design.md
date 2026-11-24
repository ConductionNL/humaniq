# Design: port what only shillinq's payroll engine has into humaniq

## Context

Source: `for-ruben/payroll-engine-move-comparison.md` (build-all, lane 29, 5 Oct 2026). It
read shillinq `origin/development` e079c43d and humaniq `origin/development` 97c4e7f5. Its
summary: humaniq wins every calculation both sides have; port the pension premium (rebuilt
with a franchise, not copied), the pro-rata gross, and `sectorcode` if the sector fund premium
is wanted.

Humaniq today:

- `PayrollRunService` writes `pensionContribution: 0.0` on every payslip (line 1063).
- `PensionFiling` records the period's UPA filing only (`pension-filing-upa-mvp`).
- `coversPeriod()` (line 1987) answers yes or no per employee and period. Nothing scales the
  gross for a partial period.
- The pack (`nl-2026.pack.json`) folds every `reduces-net` step into net pay in
  `PackInterpreter`. `vakantiegeld` is a reserve step; the premium steps `awf`, `aof`, `wko`,
  `whk` are employer charges.
- `PayrollGLPostService::buildLines()` credits wage tax and net pay. It has no pension line.

Shillinq's `pensioen` takes an employer and an employee percentage of the base salary. It has
no franchise, no scheme and no cap. The comparison rejects copying it.

## Goals and non-goals

**Goals**

- A payslip of an employee in a pension scheme withholds the employee premium and records the
  employer premium.
- The premiums reach the payroll journal on their own lines.
- A starter or leaver inside a period is paid for the part they were employed.

**Non-goals**

- Filing the premium to the fund. That is `filings-pension-upa-message`.
- Pension accrual, entitlements or a participant portal. Those belong to the fund.
- Moving any shillinq schema or stored object.
- A running year-to-date on the payslip.

## Decisions

### D1. The premium is a pack step, not code

The premium is one more step in the jurisdiction pack (ADR-101), next to `awf` and `zvw`:

```
pensioengrondslag = max(0, pensionableSalary x participationFactor - franchise / periods)
pensionEmployee   = pensioengrondslag x employeePct     (reduces-net)
pensionEmployer   = pensioengrondslag x employerPct     (employer-charge)
```

`pensionableSalary` and `periods` come from the run, the percentages and the franchise from
the scheme. The step rounds per cent like the others (`Rounder`). The employee part is
deducted before wage tax (it lowers the taxable wage), as the Belastingdienst treats pension
premiums. Golden vectors in the pack validator cover a scheme with and without a franchise.

Rejected: porting shillinq's `pensioen`. It ignores the franchise, so it overcharges every
employee and every employer.

### D2. A participation links an employee to a scheme

A new schema `PensionParticipation` (`schema:Role`): `employeeId`, `schemeCode`, `startDate`,
`endDate`, `participationFactor` (default 1). The run reads the participation in force in the
period. No participation means no premium, as today.

### D3. The partial-period factor scales gross components

The run computes `periodFactor` per employee from the contract start and end dates and the
period. Salary components scale by it. Allowances that are already per day or per trip do
not. The factor is stored on the payslip (`periodFactor`), so a payslip shows why it is
lower. `coversPeriod()` stays the gate for "in the run at all".

### D4. The journal gets pension lines

`buildLines()` adds a debit for the employer premium (employer charges) and a credit for
both premiums on a pension payable account (a new setting `gl_account_pension_payable`). The
credit to net wages drops by the employee premium, because net pay is lower.

## Choices (decided 24 Nov 2026 under the delegation of 22 Nov 2026)

Each choice is named in the comparison, section "What I would port, in order".

### O1. Which schemes, and where their parameters live

The comparison: "which schemes (ABP, PFZW, PMT, a company scheme) and where the scheme
parameters live (a table in the pack or a `PensionScheme` schema)".

- **A table in the pack** (`nl-2026.json`, a `pensioen` block per scheme code). Versioned
  with the year's other rates. A company scheme needs a pack update.
- **A `PensionScheme` schema.** HR keeps percentages and the franchise per year. A company
  scheme is a new object. The pack reads it like the CAO components.

The specs below hold for both. The tasks name the files for each option.

**Decided: the schema, and the one that already exists.** `filings-pension-upa-message` D1
shipped `PensionScheme` in `hr-pension.json` (fund, scheme code, delivery format, franchise,
maximum pensionable salary, employer and employee percentage, validity, source, verified),
with index and detail pages under payroll, and `PensionBase` computes the base from it for
the UPA delivery. The run reads the same record and the same arithmetic, so the premium a
payslip withholds is the premium the fund is told about. A second scheme record or a pack
table would give two answers. Consequences: a participation names the **fund**, not a
per-year scheme code; the run takes the administration's scheme of that fund valid on the
last day of the period (the `PensionMessageService` lookup); the base is `PensionBase`'s:
`max(0, min(wage, cap) - franchise x partTimeFactor)`, per month, with the wage times the
participation factor. For a full-time employee with factor 1 that is D1's formula; for a
part-timer the franchise follows the part-time factor, as funds compute it. No fund rates
are shipped as fact: the seeded schemes stay `verified: false`.

### O2. Calendar days or working days

The comparison: "after you decide the method (calendar or working days)".

- **Calendar days**: `periodFactor = days employed in the period / days in the period`.
  Simple, and the same for every pattern.
- **Working days**: days employed on which the employee's `WorkingPattern` has hours, over
  all such days in the period. Fairer for part-timers; depends on the pattern being kept.

**Decided: calendar days.** The spec's scenario is written for it, the result does not move
when a working pattern is edited after the run, and a missing pattern cannot zero a salary.
A working-day method can come later as an administration setting without changing the
payslip field.

### O3. `sectorcode` on `hrAdministration`

The comparison: "port `sectorcode` if the Whk or sector premium needs it (it does for the
sector fund)". Without it, humaniq computes the Whk premium from the administration's own
percentage (`getPayrollWhkPercentage`). With it, a sector table can set the sector fund part.

**Decided: no.** The Whk percentage an employer pays is set per employer in the
Belastingdienst's beschikking, which the administration already holds; a sector table
would be a second source for the same figure. Task 4.1 is not built.
If Ruben says no, tasks section 4 is dropped.

## Risks

- A wrong franchise lowers or raises every net pay in the scheme. Mitigation: golden vectors
  from the fund's published 2026 example per scheme, and a payroll run check that flags a
  premium above the employee's gross.
- Stored data in shillinq. Before shillinq's seven schemas are retired, the schema-retirement
  checklist runs on each instance (comparison, "Schemas"). That is a shillinq task; this
  change only reports when humaniq covers the engine.
