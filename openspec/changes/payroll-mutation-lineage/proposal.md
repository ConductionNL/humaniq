---
kind: code
---

# Trace every payslip line back to the change that caused it

## Why

An employee asks their payroll officer why this month's net pay is 212 euros higher. In humaniq
today the officer can see that it changed: the mutation report compares this run with the
previous one, per employee, on four headline figures. The officer cannot see why. The payslip
stores the calculator's inputs (`engineInputSnapshot`) and the folded amounts, but a retro
correction and a leave sale are stored as one summed figure each, and nothing says which raise,
which correction or which edit to the employee record set the salary the engine read. The
answer is in OpenRegister's audit trail, one object at a time, for whoever knows where to
look.

Loket.nl shipped exactly this in Q3 2026: every wage mutation traceable to its origin, as the
basis for controlled reprocessing and recalculation.

This change stores, for each payslip, one line per component with the object and the audit
entry that produced it, shows the lines on the payslip, lets a correction point at the line it
corrects, and lets the mutation report say which lines caused a change.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `dm-mutation-lineage` | Trace every wage mutation back to how it came about, so a change can be reprocessed and recalculated under control. | `partial`, built.state `built`: a sealed payslip is reproducible from its stored inputs and runs are compared per employee; no lineage from origin to payslip line |

### Demand

- `dm-mutation-lineage`, changelog: https://loket.nl/roadmap/ (Loket.nl, "Herleidbaarheid en
  verwerking van declaraties (loontransactiemutaties)", launched Q3 2026).

### Competitors rated yes

- `dm-mutation-lineage`, Loket.nl: "every mutation becomes fully traceable as a basis for
  controlled processing and recalculation" (https://loket.nl/roadmap/).

### Recorded non-goals this change picks up

- payroll-mutation-reports (`openspec/changes/archive/2026-07-14-payroll-mutation-reports/proposal.md`):
  "Per-component drill-down beyond the four headline deltas (gross/net/loonheffing/
  employer-cost): a fuller component matrix is a named fast-follow, the `lines` shape is the
  extension point." This change adds the causes per employee line through that shape.
- payroll-mutation-reports: "Recompute / re-derive any payroll figure." Unchanged. Lineage is
  recorded when the engine computes, and read afterwards; nothing recalculates to explain.

## What Changes

- **Payslip lines with a source.** Each calculation writes one `PayslipLine` per component of a
  payslip: the salary or hourly pay, bijtelling, sick-pay adjustment, each retro correction,
  each leave sale or purchase, each wage garnishment, and, once their changes land, each
  overtime entry, claim and allowance. Every line carries its amount, the source object, and the
  audit entry of that object's last change before the calculation.
- **Where the salary came from.** The salary line names the applied raise that set the salary
  when there is one, and otherwise the audit entry of the last edit to the employee's salary,
  with who made it and when.
- **Folded sums split again.** Retro corrections and leave transactions keep their total on the
  payslip, and get one line each, so a sum of three corrections shows three causes.
- **A correction points at what it corrects.** A retro correction can name the payslip line it
  corrects. Its own settlement line then links back, so a change, its payslip line and its
  correction form one chain.
- **Causes in the mutation report.** Each changed employee in the mutation report lists the
  lines that differ between the two runs, by component and source.
- **On the payslip page.** `PayslipDetail` shows the lines with a link to each source.

## Capabilities

### New Capabilities

- `payroll-mutation-lineage`: per-component payslip lines that name the object and audit entry
  behind each amount, with corrections and mutation reports linked to them.

## Impact

- `lib/Settings/register.d/hr-objects.json`: new schema `PayslipLine`;
  `PayrollMutationReport.lines[]` items gain `causes`.
- `lib/Settings/register.d/hr-retro.json`: `PayrollAdjustment` gains `correctsPayslipLineId`.
- `lib/Service/PayslipLineageService.php` (new): builds the lines and resolves the audit
  entries through OpenRegister's `AuditHandler::getLogs()`.
- `lib/Service/PayrollRunService.php`: keeps per-source amounts for the folds it sums today and
  hands them to the lineage service after each payslip save.
- `lib/Service/PayrollMutationService.php`: adds the causes per employee line.
- `src/manifest.d/hr-objects.json`, `src/registry.js`: the lines section on `PayslipDetail`.

## Out of scope

- Recalculating from a line. Corrections go through the existing retro adjustment.
- Lineage for payslips calculated before this change. Old payslips keep their snapshot only.
- Field-level history of every object: OpenRegister's audit trail already holds it.
