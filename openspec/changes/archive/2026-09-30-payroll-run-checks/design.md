# Design: check a payroll run before it is approved

## Context

Read at `development` af702f78.

- `lib/Service/PayrollRunService.php` `generate()` (line 371) builds a `skipped` list with a
  reason per employee (lines 427, 433, 439, 446) and returns it in the outcome (line 626). It
  is not stored anywhere.
- `lib/Controller/PayrollController.php:165` `calculate(runId)` behind
  `POST /api/payroll/calculate` returns that outcome; the `PayrollRunDetail` action
  "(Re)calculate" (`src/manifest.d/hr-objects.json`, header actions from line 1010) shows only a
  success message.
- `lib/Service/RuleAuditService.php:706` `auditPayrollRunScope(period, administrationId,
  context)` runs the rule engine over one period's runs and their payslips and returns
  `violations` with `objectType`, `objectId`, `ruleId`, `severity`, `statement`.
  `lib/Command/PayrollVerifyCommand.php` (`occ humaniq:payroll:verify`) is its only caller.
- `lib/Service/PayrollMutationService.php` diffs a run with the previous run per employee on
  four headline figures and persists a `PayrollMutationReport`. It reads the prior run only.
- `Payslip` (`hr-objects.json:97`) carries the causes a deviation can have: `sickLeaveCaseId`
  (line 171), `retroAdjustment` (line 178), `leaveBuySell` (line 179), `loonbeslag` (line 180),
  `bijtelling` (line 182), plus `grossPay`, `nettoPay`, `loonheffing`,
  `werknemersverzekeringen`, `zvw`, `hoursWorked`.
- `CompAdjustment.appliedAt` (`hr-comp.json:292`) records when a raise reached the salary.
- `lib/Service/Percentile.php:70` `value(sortedValues, rank)` is a pure percentile helper.
- `PayrollRunDetail` (`hr-objects.json:826`) shows stat tiles, a data widget and the payslips
  list.

## Goals / Non-Goals

**Goals**

- Every calculated draft run carries a stored check the reviewer reads on the run page.
- A deviation is measured against the employee's own paid history, per component.
- A known cause is named, so the reviewer spends time on the unexplained ones.

**Non-Goals**

- Gating approval.
- Recomputing any figure.
- A learned or opaque score.

## Decisions

### D1. Findings are objects, replaced per check

`PayrollRunFinding`: `payrollRunId`, `employeeId`, `kind` (`skipped`, `unpaid-input`,
`rule-violation`, `deviation`), `severity`, `message`, `ruleId`, `component`, `currentValue`,
`baselineValue`, `explanation`, `status` (`open`, `acknowledged`), `acknowledgementNote`,
`acknowledgedBy`, `checkedAt`. A check deletes the run's previous findings except those
acknowledged and matched again by `(employeeId, kind, ruleId or component)`, which keep their
acknowledgement and get the new values.

Alternative considered: one array field on `PayrollRun`. Rejected: a list of objects is what
`object-list` renders, filters and links; an array in the data widget is a block of JSON.

### D2. Three sources, one service

`PayrollRunCheckService::check(runId)`:

1. Skipped employees: `generate()` hands its `skipped` list to the check; each becomes a
   `blocking` finding, with the reason rewritten in plain words.
2. Unpaid inputs: approved timesheets and payroll-route claims of the period that the run did
   not stamp become `warning` findings. They exist only once the hours and claims changes have
   landed; without them this source is empty.
3. Rule violations: `auditPayrollRunScope()` for the run's period and administration; a
   mandatory violation is `blocking`, others `warning`.
4. Deviations: `PayAnomalyDetector`, D3.

It then writes the counts to the run (`checkedAt`, `blockingFindings`, `warningFindings`).
`generate()` calls it after a successful save; `POST /api/payroll/check {runId}` calls it on
demand, resolving the run under the caller's RBAC first and requiring an administrator or the
`hr` role.

Alternative considered: a new flow node in the Loonrun flow. Rejected for now: the check must
also run for an operator who calculates from the page or occ, and the flow's calculate step
already calls `generate()`, so it gets the check for free.

### D3. The baseline is the median of the employee's last six paid payslips

For each payslip in the run, `PayAnomalyDetector` takes the employee's previous payslips from
runs with status `approved`, `posted` or `paid`, plus hand-entered payslips (no run), most
recent six. For each component (gross, net, wage tax, employer cost as
`werknemersverzekeringen + zvw`, hours paid) it computes the median with `Percentile`. A
deviation is flagged when `|current - median| > max(relative threshold x median, absolute
floor)`. Defaults: 15% and 50.00 for money, 20% and 8 hours for hours; each is a setting.
Fewer than three prior payslips yields an `info` finding "not enough history".

Alternative considered: compare with the previous run only. Rejected: that is the mutation
report, and one odd prior month then hides or fakes a deviation.

### D4. Known causes explain a deviation

When the current payslip has `sickLeaveCaseId`, a non-null `retroAdjustment`, `leaveBuySell`,
`loonbeslag` or `bijtelling` that the baseline payslips lack, or a `CompAdjustment` for the
employee has `appliedAt` inside the period, the finding's `explanation` names it and its
severity drops to `info`. The number is still shown.

### D5. Acknowledgement is a declared transition

`PayrollRunFinding` declares `acknowledge` (`open` to `acknowledged`) with
`acknowledgementNote`; the writer is stamped as `acknowledgedBy`. No guard: any reader of the
run may acknowledge, and the audit trail records who did.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| finding acknowledgement | declarative `x-openregister-lifecycle` | a plain state change |
| counts on the run page | declarative stat tiles over `PayrollRunFinding` | a count per severity on one schema |
| composing the check | imperative `PayrollRunCheckService` | three sources across schemas |
| baseline and thresholds | imperative, pure `PayAnomalyDetector` | a median over history, no declarative primitive |

## Seed data

- Three hand-entered payslips for `employee-jansen` for 2026-02 to 2026-04 at a gross of
  3800.00, so a 2026-05 payslip has a baseline.
- The seeded `employee-visser` without salary is the skipped finding on a fresh instance.

## Risks / Trade-offs

- [Deleting unacknowledged findings on every check] -> the check is cheap to rerun and the
  audit trail keeps the deleted objects' history.
- [December bonuses and holiday allowance months look like outliers every year] -> the
  explanation list grows as those payments get their own payslip fields; until then the
  finding is a warning a reviewer acknowledges once a year.

## Open Questions

- Should the thresholds be per administration? This design starts per instance.
