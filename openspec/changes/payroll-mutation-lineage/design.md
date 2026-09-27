# Design: trace every payslip line back to the change that caused it

## Context

Read at `development` af702f78.

- `lib/Service/PayrollRunService.php` `generate()` (line 371):
  - reads `Employee.grossMonthlySalary` (line 437) as the salary input;
  - the sick-pay case (`openSickCaseFor()`, line 909), the vehicle assignment
    (`openVehicleAssignmentFor()`, line 1082) and the garnishment (`activeLoonbeslagFor()`,
    line 1453) are single sources, stamped as `sickLeaveCaseId`, `assetAssignmentId`,
    `loonbeslagId`;
  - retro corrections (`appliedRetroAdjustmentsByEmployeeId()`, line 1286) and leave
    transactions (`settledLeaveTransactionsByEmployeeId()`, line 1353) are summed per employee
    into one figure; the source ids are dropped;
  - `engineInputSnapshot` stores the resolved calculator input (REQ-AUDP-001).
- `CompAdjustment` (`hr-comp.json:155`) with `appliedAt` (line 292) and `proposedSalary`
  records a raise that reached the salary; `lib/Service/CompAdjustmentService.php` writes
  `Employee.grossMonthlySalary`.
- `PayrollAdjustment` (`hr-retro.json:5`) carries `originalPayslipId`, `correctionRef`,
  `settlementPeriod`, `deltaNet`.
- `lib/Service/PayrollAuditVerificationService.php` documents OpenRegister's per-object audit
  read path, `OCA\OpenRegister\Service\Object\AuditHandler::getLogs($uuid)`, returning
  `AuditTrail` rows with a numeric id; the same path backs humaniq's audit-trail widgets.
- `lib/Service/PayrollMutationService.php:234` `buildReport()` writes one line per employee
  with classification and four before, after and delta figures;
  `PayrollMutationReport.lines` is an array of objects (`hr-objects.json:300`).
- `PayslipDetail` (`hr-objects.json:685`) shows the payslip fields and a "Generate PDF" action.

## Goals / Non-Goals

**Goals**

- Every amount on an engine payslip names the object that caused it.
- A salary names the raise or the edit that set it, with who and when.
- A correction and a mutation report can point at those lines.

**Non-Goals**

- Recalculation from a line.
- Back-filling lineage for old payslips.

## Decisions

### D1. Lines are objects written with the payslip

`PayslipLine`: `payslipId`, `payrollRunId`, `employeeId`, `period`, `component`, `amount`,
`sourceSchema`, `sourceId`, `sourceAuditTrailId`, `sourceChangedBy`, `sourceChangedAt`,
`sourceKind` (`raise`, `correction`, `leave-transaction`, `case`, `assignment`, `garnishment`,
`timesheet`, `claim`, `allowance`, `record-edit`). After each payslip save,
`PayslipLineageService::replaceLines(payslipId, sources)` deletes the payslip's previous lines
and writes the new ones, so a recalculated draft has only current lines. A payslip of a
non-draft run is never recalculated, so its lines are sealed with it.

Alternative considered: an array field on `Payslip`. Rejected: lines are what the mutation
report and corrections point at, and an array element has no id to point at.

### D2. The fold helpers keep their sources

The summing helpers return, next to the per-employee total, the list of
`{sourceId, amount}` they summed. `generate()` passes these to the lineage service. The
payslip's summed fields stay exactly as they are.

Alternative considered: re-query the sources in the lineage service. Rejected: two selections
of "which corrections settle in this period" can disagree; the one the engine used is the
truth.

### D3. The salary's source is the raise, else the last edit

For the salary line the service looks for the latest `CompAdjustment` of the employee with
`appliedAt` on or before the calculation and `proposedSalary` equal to the salary read. If one
exists, it is the source (`sourceKind: raise`). Otherwise the service reads the employee's
audit rows through `AuditHandler::getLogs()` and takes the last row before the calculation
that changed `grossMonthlySalary` (`sourceKind: record-edit`). For every other source the
audit entry is the source object's last row before the calculation. If OpenRegister's audit
read is unavailable, `sourceAuditTrailId` stays null and the line still names the object.

Alternative considered: store the full Employee record per payslip. Rejected: the snapshot
already holds the inputs; what is missing is the pointer to the change, not another copy.

### D4. A correction names the line it corrects

`PayrollAdjustment.correctsPayslipLineId` is optional and, when set, must name a line of
`originalPayslipId`. The settlement run's retro line for that adjustment carries the adjustment
as its source, so the chain reads: source change, payslip line, correction, settlement line.

### D5. The mutation report lists the causes

For each `changed` employee `buildReport()` compares the two payslips' lines by
`(component, sourceSchema, sourceId)` and adds `causes`: the lines that are new, gone or of a
different amount. Employees without lines (old payslips) get `causes: null`, never an empty
list, so "no lineage" is not read as "no cause".

### D6. The page links to each source

A registered host section `PayslipLinesSection` on `PayslipDetail` lists the lines with the
library's table and turns `(sourceSchema, sourceId)` into a link to that schema's detail
route. It computes nothing.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| writing lines | imperative, inside the run | the sources are known only there |
| audit entry lookup | imperative, OpenRegister's audit read | no declarative primitive for "last change before" |
| causes in the mutation report | imperative, the existing service | an extension of its `lines` shape |
| lines on the payslip page | host section over the library table | links across schemas |

## Seed data

No seed: lines are written by a calculation. On a dev instance, calculating the seeded draft
run produces them.

## Risks / Trade-offs

- [More objects per run: about five lines per payslip] -> lines are replaced on
  recalculation, not accumulated, and are small.
- [Audit reads per source per payslip] -> the service reads each object's audit rows once per
  run and caches them for the run.

## Open Questions

- Should lines of a deleted source object keep their `sourceId`? This design keeps it; the
  audit trail still resolves it.
