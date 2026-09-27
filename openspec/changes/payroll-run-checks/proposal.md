---
kind: code
---

# Check a payroll run before it is approved

## Why

A payroll officer calculates the month's run in humaniq and sees one toast: "Payroll run
(re)calculated". The run service knows more than that. It returns every employee it skipped
and why (no contract, no tax table colour, no salary, a missing BSN), but that list lives only
in the response, and no page shows it. The corpus audit that checks a calculated run exists
too, as `occ humaniq:payroll:verify`, and it has no page either. So the reviewer approves a run
without seeing who was left out, or which payslip breaks a mandatory rule.

Nor does anything compare a payslip with the employee's own past. The mutation report compares
this run with the previous one per employee, which catches a change but not an outlier: a net
pay twice the usual, an employer cost that jumped with no raise, sick pay, retro correction or
bonus to explain it. HR2day groups such deviations per alert and lets the sensitivity be set
per component.

This change adds a run check that lists, on the run's page, who will not be paid and why, which
payslips break a rule, and which amounts are far from the employee's own history, each with its
explanation when humaniq knows one.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `pay-run-check` | Get a pre-run check that flags missing or odd data before payroll is final. | `partial`, built.state `built`: `occ humaniq:payroll:verify` audits one period's run; no route or page calls it |
| `dm-pay-anomaly` | Have pay runs compared with each employee's own pay history so unusual amounts are flagged before payroll closes. | `partial`, built.state `built`: `PayrollMutationService` compares with the previous run only; no history and no per-component sensitivity |

### Demand

- `dm-pay-anomaly`, changelog: https://data.maglr.com/1697/issues/68666/820322/index.html
  (HR2day ZomerBonus 2026, Payroll Detect).

### Competitors rated yes

- `pay-run-check`, AFAS Profit: "the Payroll Auditor runs more than 130 checks on the payroll
  process, extensible with your own" (https://www.afas.nl/software/salarisadministratie).
- `pay-run-check`, Visma Raet Youforce: "the Signaleringsverslag automatic after each payroll
  run, listing all unresolved signals"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/2).
- `pay-run-check`, HR2day: "automatic checks on input and outcomes, missing wage components
  and AI-flagged deviations before closing the period"
  (https://www.hr2day.com/features/payroll-intelligence/).
- `pay-run-check`, Loket.nl: "more than 250 error checks and smart validations help you catch
  errors before they happen" (https://loket.nl/functionaliteiten/salaris/).
- `pay-run-check`, Personio: "the task column shows warnings and blockers per employee before
  approval"
  (https://support.personio.de/hc/en-us/articles/360001796137-Monthly-Payroll-Accounting-With-Personio-Payroll).
- `dm-pay-anomaly`, HR2day: "Payroll Detect combines AI with own checks and groups deviations
  per alert"; "detection level adjustable per wage component"
  (https://data.maglr.com/1697/issues/68666/820322/index.html).

### Recorded non-goals this change respects

- payroll-mutation-reports (`openspec/changes/archive/2026-07-14-payroll-mutation-reports/proposal.md`):
  "Approval workflow / write-time gating: the report is advisory input to a human approval; it
  never changes a run's status and does not gate draft to approved." The run check follows the
  same line: it informs the approval, it does not block it. Gating approval stays with the
  owners named there and with the open change `payroll-run-as-a-flow`.
- payroll-mutation-reports: "Recompute / re-derive any payroll figure." The check reads stored
  payslips and never recalculates.

## What Changes

- **A check per run, kept.** A `PayrollRunFinding` object records each finding of a run: the
  employee, the kind, a severity (`blocking`, `warning`, `info`), the message, and for an
  amount the component, the current value and the baseline. Findings stay on the run until the
  next check replaces them.
- **Who will not be paid.** Every employee the calculation skipped becomes a `blocking`
  finding with the skip reason in words. Approved timesheets and claims of the period that no
  run will pay become warnings.
- **Which payslip breaks a rule.** The corpus audit of the run (the one behind
  `occ humaniq:payroll:verify`) becomes findings: mandatory violations as `blocking`, the rest
  as `warning`.
- **What is far from the employee's own history.** For each payslip the check compares gross,
  net, wage tax, employer cost and hours paid with the median of the employee's last six paid
  payslips. A deviation above the component's threshold becomes a warning. When the payslip
  carries a known cause, such as sick pay, a retro correction, leave bought or sold, a wage
  garnishment, a new bijtelling or an applied raise, the finding names it and drops to `info`.
  Thresholds per component are settings.
- **On the page, after every calculation.** The check runs after each (re)calculation of a
  draft run and on demand from a "Check run" action. `PayrollRunDetail` lists the findings with
  their counts per severity. A reviewer can acknowledge a finding with a note; an acknowledged
  finding that comes back unchanged keeps its acknowledgement.

## Capabilities

### New Capabilities

- `payroll-run-checks`: a stored, per-run check of skipped employees, rule violations and
  deviations from each employee's own pay history, shown on the run page.

## Impact

- `lib/Settings/register.d/hr-objects.json`: new schema `PayrollRunFinding` with an
  `acknowledge` transition; `PayrollRun` gains `checkedAt`, `blockingFindings`,
  `warningFindings`.
- `lib/Service/PayrollRunCheckService.php` (new): composes the three sources and writes the
  findings.
- `lib/Service/PayAnomalyDetector.php` (new, pure): median baseline, thresholds, explanations.
- `lib/Service/PayrollRunService.php`: keeps the skipped list for the check and calls it after
  generation.
- `lib/Controller/PayrollController.php` and `appinfo/routes.php`: `POST /api/payroll/check`.
- `lib/Service/SettingsService.php`: thresholds per component.
- `src/manifest.d/hr-objects.json`: the action, the findings list and two stat tiles on
  `PayrollRunDetail`.
- `lib/Settings/register.d/hr-seed.json`: three months of history for one employee.

## Out of scope

- Blocking approval on a blocking finding. That is the approval owner's decision, see above.
- Learned models or AI scoring. The baseline is a median and a threshold anyone can recompute.
- Checks before a run exists for the period. The check belongs to a draft run.
