# Design: hand payroll to an outside bureau and record what comes back

## Context

Read at `development` af702f78.

- `hrAdministration` (`lib/Settings/register.d/hr-administratie.json:5`): `administrationId`,
  `name`, `loonheffingennummer`, `mode` (`standard`, `dga_single_person`,
  `eenmanszaak_no_payroll`), `abpAansluitingsplichtig`. Nothing says who runs payroll.
- `lib/Service/PayrollRunService.php` `runFor()` (line 248) and `generate()` (line 371)
  calculate for every administration; `lib/Flow/PayrollCalculateNode.php` calls `runFor()`.
- The payroll-relevant facts a bureau needs already live in humaniq: `Employee` (`startDate`,
  `endDate`, `grossMonthlySalary`, `iban`, `taxTableColor`, `loonheffingskortingToegepast`,
  `bsn`, `hr-objects.json:5`), `EmploymentContract` (`type`, `hoursPerWeek`, `hourlyWage`,
  `cao`, `caoSchaal`, line 59), `CompAdjustment.appliedAt` (`hr-comp.json:292`), `Timesheet`
  (`hr-timesheet.json:136`), `Expense` (`hr-expense.json:5`), `LeaveTransaction`
  (`hr-leave.json:278`), `SickLeaveCase` (`hr-verzuim.json`), `Loonbeslag`
  (`hr-loonbeslag.json`).
- `Payslip` (`hr-objects.json:97`) requires only `employeeId`, `period`, `grossPay`,
  `nettoPay`; a payslip with `payrollRunId` null is the existing convention for one that the
  engine did not compute. `MijnLoonstroken` (`hr-objects.json:1576`) filters payslips on
  `userId: @me`. `upsertJaaropgaaf()` (`lib/Service/HrDocumentService.php:863`) sums every
  payslip of the employee and year.
- `lib/Support/FleetAppId.php` resolves `integriq`; humaniq calls integriq nowhere today.
  ADR-091 places external API surfaces and their credentials in integriq.

## Goals / Non-Goals

**Goals**

- An employer with an outside bureau enters each mutation once, in humaniq.
- The bureau's results land in humaniq where employees and HR already look.
- humaniq never holds a bureau's credentials or formats.

**Non-Goals**

- Bureau formats and transport.
- Journal and payment for externally processed payroll.

## Decisions

### D1. External processing is an administration setting that stops the engine

`hrAdministration.payrollProcessing` (`engine` default, `external-bureau`) and
`payrollBureauName`. `runFor()` and the flow's calculate node refuse an administration set to
`external-bureau` with the outcome `refused-external-bureau`.

Alternative considered: let both run. Rejected: two sources of payslips for the same period
would disagree, and every downstream sum would double.

### D2. Mutations are differences from the last handoff

`PayrollHandoffService::compile(administrationId, period)` builds, per employee in the
administration, a payroll view: the Employee and contract fields above, plus the period items
(approved timesheets, payroll-route claims, active allowances, settled leave transactions,
sickness cases starting or ending, active garnishments). It compares the view with the view the
previous handoff sent, stored as `sentState` on that handoff's mutations, and writes one
`PayrollHandoffMutation` per difference: `handoffId`, `employeeId`, `kind` (`start`, `leave`,
`contract`, `salary`, `hours`, `claim`, `allowance`, `leave-transaction`, `sickness`,
`garnishment`, `bank-account`, `tax-settings`), `effectiveDate`, `fields` (old and new),
`sourceSchema`, `sourceId`, `sentState`. The first handoff sends everything as `start`.
Compiling again while the handoff is `concept` replaces its mutations.

Alternative considered: send every object updated since the last handoff. Rejected: an edit
that was undone would still be sent, and fields the bureau does not use would travel.

### D3. A declared lifecycle, and integriq drives the middle of it

`PayrollHandoff`: `administrationId`, `period`, `compiledAt`, `mutationCount`, `status`,
`deliveryReference`, `deliveredAt`, `receivedAt`, `intakeFindings`. Lifecycle: `klaarzetten`
(concept to klaargezet, `NoSelfApprovalGuard` against the compiler), `verzenden` (klaargezet to
verzonden, written by integriq), `ontvangen` (verzonden to ontvangen, written by integriq),
`afsluiten` (ontvangen to afgesloten, refused while the intake has blocking findings),
`heropenen` (klaargezet or verzonden back to concept when the bureau rejects). One handoff per
administration and period.

Alternative considered: humaniq pushes to integriq's API. Rejected: the declared state is the
contract; integriq synchronises on it the way it does for other registers, and humaniq stays
free of transport code.

### D4. The intake is checked, not trusted

`POST /api/payroll/handoffs/check-intake {handoffId}` (and the `ontvangen` transition through a
listener) runs `PayrollHandoffService::checkIntake()`: every employee with a mutation or an
active contract in the period has exactly one returned payslip; no payslip names an employee
outside the administration; returned payslips carry `externalSource`, `payrollHandoffId` and
the employee's `userId` (stamped from `Employee.nextcloudUserId` when absent). Findings are
stored on the handoff; a missing or unknown employee is blocking.

### D5. Two guarded endpoints

`POST /api/payroll/handoffs/compile {administrationId, period}` and `check-intake`, both for an
administrator or the `hr` role of that administration, both resolving the handoff or
administration under the caller's RBAC first. Everything else is the object API (ADR-022).

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| handoff lifecycle | declarative `x-openregister-lifecycle` | the contract integriq synchronises on |
| review before sending | `NoSelfApprovalGuard` on `klaarzetten` | four eyes on what leaves the building |
| compiling mutations | imperative `PayrollHandoffService` | a comparison across many schemas |
| intake check | imperative, same service | cross-schema completeness |
| delivery and return | integriq synchronisation | external API and credentials (ADR-091) |

## Seed data

- A new administration `ADM-006` with `payrollProcessing: external-bureau` and one employee,
  so a compile on a dev instance produces a first handoff of `start` mutations.

## Risks / Trade-offs

- [The bureau needs a field humaniq does not hold] -> the mapping lives in integriq, and a
  missing field shows as a bureau rejection that reopens the handoff.
- [Late mutations after sending] -> they fall into the next period's handoff, with their own
  effective date, which is how bureaus handle late mutations anyway.
- [Personal data leaves humaniq] -> only the payroll view's fields travel, and only after a
  second person set the handoff ready.

## Open Questions

- Should an administration be able to switch from `external-bureau` to `engine` mid-year?
  `payroll-period-edge-cases` adds opening balances that would make it possible; this design
  allows the switch only from 1 January.
