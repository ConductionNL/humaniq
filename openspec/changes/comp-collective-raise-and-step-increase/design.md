# Design: collective raises and yearly step increases

## Context

Read at `development` af702f78.

- `lib/Settings/register.d/hr-comp.json` holds the compensation model:
  - `SalaryBand` (line 5): `bandId`, `title`, `grade`, `minSalary`, `referenceSalary`,
    `maxSalary` (integer cents), `cao`, `caoSchaal`, `effectiveFrom`, `active`. No steps.
  - `CompReviewCycle` (line 86): `name`, `period`, `effectiveDate`, `status` (`open`,
    `closed`), lifecycle `sluiten` (open to closed). No kind, no scope.
  - `CompAdjustment` (line 155): `cycleId`, `employeeId`, `contractId`, `currentSalary`,
    `proposedSalary`, `targetBandId`, `effectiveDate`, `status` (`draft`, `proposed`,
    `approved`, `effective`), `proposedBy`, `approvedBy`, `rationale`, `appliedAt`. Lifecycle
    (lines 179 to 207): `propose`, `approve` and `reject` (both `NoSelfApprovalGuard`),
    `effectuate` (`CompEffectiveDateGuard`). `reject` returns the proposal to `draft`.
- `lib/Service/CompAdjustmentService.php`: `effectuateOne()` (line 106) and
  `effectuateCycle(cycleId, asOf, dryRun)` (line 133), both through `effectuate()` (line 165):
  refuses a non-approved or not-due adjustment, checks the band (`withinBand()`, line 290),
  writes `Employee.grossMonthlySalary` in euros, stamps `appliedAt` and drives `effectuate`.
  The class docblock records that `EmploymentContract` carries only `hourlyWage` and no
  monthly salary, so the Employee record is the salary the engine reads.
- `lib/Controller/CompController.php`: `effectuate()` behind `POST /api/comp/effectuate`
  (`appinfo/routes.php:72`), `#[NoAdminRequired]`, resolves the adjustment under the caller's
  RBAC first (`authorizeAdjustment()`), then refuses anything not `approved`.
- `lib/Command/CompEffectuateCommand.php`: `occ humaniq:comp:effectuate`, the only caller of
  `effectuateCycle()`.
- `src/manifest.d/hr-comp.json`: `CompReviewCycleDetail` (line 4) lists the cycle's
  adjustments and offers `sluiten` and an open-form "Aanpassing voorstellen";
  `CompAdjustmentDetail` (line 204) offers `propose`, `approve`, `reject` and the "Effectuate"
  api-call (lines 218 to 231).
- `EmploymentContract` (`lib/Settings/register.d/hr-objects.json:59`) carries `caoSchaal`
  (line 81) and `hourlyWage` (line 79), no step. `Employee.nextcloudUserId` (line 45) is the
  account a notification can reach.
- `lib/Service/AdministrationService.php:165` `getActiveAdministrationRole()` answers `hr`,
  `accountant` or `employee` for the caller's active administration; `AnalyticsController`
  gates on it.
- The library's `CnIndexPage` takes declarative `bulkActions` and hands the current selection
  to an `open-modal` target (`@conduction/nextcloud-vue` 2.40.0, `CnIndexPage.vue:1789` and
  `openBulkModal()`); `CnAppRoot` resolves a registry entry of `kind: 'modal'`
  (`CnAppRoot.vue:812`).
- No schema in `lib/Settings/register.d/` declares `x-openregister-notifications` today.

## Goals / Non-Goals

**Goals**

- Propose, approve and effectuate a raise for many employees from the cycle page.
- Keep separation of duties per adjustment, as today.
- Generate the yearly step proposal from the employee's band and step date.
- Refuse a proposal with a reason, and tell the employee the outcome.

**Non-Goals**

- A merit budget, calibration grid or compensation letter.
- Per-step tables in the CAO corpus.
- Changing what the payroll engine reads: it keeps reading `Employee.grossMonthlySalary`.

## Decisions

### D1. A collective raise is a set of proposals in a cycle

A collective cycle generates one ordinary `CompAdjustment` per employee in scope. Everything
after that is the lifecycle that exists: propose, approve, effectuate.

Alternative considered: one write that raises every in-scope `Employee.grossMonthlySalary`
directly. Rejected: it skips the four-eyes approval and leaves no per-employee record of what
changed and why, which is the audit comp-cycles was built for.

### D2. Proposals are generated server side and idempotently

`CompCollectiveService::proposeForCycle(cycleId, dryRun)` reads the cycle's scope
(`administrationId`, `cao`, `orgUnitId`, or an explicit `employeeIds` list from the bulk
action), selects employees with a contract that covers the cycle's `effectiveDate`, and for
each one computes `currentSalary` from `Employee.grossMonthlySalary` and `proposedSalary` as
`round(current x (1 + raisePercentage / 100))` or `current + raiseAmountCents`. Where the
contract carries an `hourlyWage`, the same percentage fills `proposedHourlyWage`. A proposal
already present for `(cycleId, employeeId)` is left alone, so a second run creates nothing.
`dryRun` returns the count and the first rows without writing. `proposedBy` is the caller.

Alternative considered: a browser loop creating adjustments through the object API. Rejected:
hundreds of client writes with no idempotency, and `proposedBy` would be client supplied.

### D3. Bulk approval keeps the guard per adjustment

`CompCollectiveService::approveCycle(cycleId)` walks the cycle's `proposed` adjustments and
approves each one with an ordinary object write that carries the `approve` transition, so
`NoSelfApprovalGuard` runs for every single adjustment. Adjustments the caller proposed are
skipped and reported as `refused-self-approval`.

Alternative considered: a cycle-level transition that flips all children. Rejected: the guard
would run once, on the cycle, and a proposer could approve their own proposals in bulk.

### D4. Effectuation reuses the existing batch, with a preview

`POST /api/comp/cycles/effectuate` calls `effectuateCycle(cycleId, null, dryRun)`. The page
calls it with `dryRun: true` first and shows what will be written; the confirmed call writes.
`effectuate()` gains two writes for a step or hourly change: `EmploymentContract.hourlyWage`
from `proposedHourlyWage` when present, and `salaryStep = toStep` with `stepDate` moved one
year on.

Alternative considered: a new effectuation path for collective cycles. Rejected: one
implementation for one write, and it already refuses non-approved and not-due proposals.

### D5. Steps live on the employer's band

`SalaryBand.steps` is a list of `{step, monthlySalaryCents}`. `EmploymentContract` gains
`salaryBandId`, `salaryStep` and `stepDate` (the date the next periodiek falls due). A
step-increase cycle proposes `toStep = salaryStep + 1` with that step's salary for every
contract whose `stepDate` falls inside the cycle's `period` and whose step is below the band's
highest step. `adjustmentKind` is `step-increase`; `fromStep`/`toStep` are stored.

Alternative considered: per-step tables in `lib/Standards/cao/*.json`. Rejected: the
cao-library change named per-step progression a non-goal, and an employer's own scale
(`SalaryBand`) is tenant configuration, which is what OpenRegister holds.

### D6. Refusal is a decision with a reason

A new transition `refuse` (`proposed` to `refused`, terminal) carries `decisionReason`. A new
read-only guard `DecisionReasonGuard` refuses the transition when the reason is empty, and it
chains `NoSelfApprovalGuard` so a proposer cannot refuse their own proposal either. `approve`
accepts an optional `decisionReason`. `reject` keeps meaning "send back for rework".

Alternative considered: reuse `reject` with its `rationale`. Rejected: `reject` returns the
proposal to `draft`, and a refused step is an outcome the employee must receive, not a draft.

### D7. The employee is told declaratively

`CompAdjustment` declares `x-openregister-notifications` with two rules, trigger
`transition` on `approve` and on `refuse`, channel `nc-notification`, recipient
`{kind: field, field: employeeUserId}`, subject and message in nl and en carrying
`{{decisionReason}}`. `employeeUserId` is stamped from `Employee.nextcloudUserId` when the
service creates the proposal, the same denormalisation `Payslip.userId` uses.

Alternative considered: a PHP notifier. Rejected by ADR-031: the dialect is the declared path
and a hand-rolled `IManager::notify()` dispatcher is an anti-pattern there.

### D8. Three guarded endpoints, no pass-through CRUD

`CompController` gains `proposeCollective(cycleId, dryRun)`, `approveCycle(cycleId)` and
`effectuateCycle(cycleId, dryRun)` at `POST /api/comp/cycles/propose`,
`POST /api/comp/cycles/approve` and `POST /api/comp/cycles/effectuate`. Each resolves the
cycle under the caller's RBAC first (404 otherwise) and requires an administrator or the `hr`
role for the active administration. None of them creates or edits a cycle or an adjustment
field by field: that stays the object API (ADR-022).

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| adjustment lifecycle, new `refuse` transition | declarative `x-openregister-lifecycle` | the engine's own state machine |
| reason required on refuse | PHP guard `DecisionReasonGuard` | a precondition the state machine cannot express |
| telling the employee | declarative `x-openregister-notifications` | the canonical dialect |
| generating proposals for a scope | imperative `CompCollectiveService` | a cross-schema selection that writes many objects |
| bulk approval | imperative, per-object transition writes | the guard must run per adjustment |
| salary, hourly wage and step write | imperative `CompAdjustmentService` | guards are read-only, as today |
| cycle and index actions | declarative manifest actions plus one host modal | the library has `bulkActions` and api-call actions |

## Seed data

- `salaryband-a` gains four steps; `contract-jansen-vast` gains `salaryBandId`,
  `salaryStep: 2` and a `stepDate` inside the seeded 2026 cycle.
- A second seeded cycle `compcycle-2026-collective` of kind `collective` with a 2% raise and
  scope `ADM-001`, status `open`, so the propose action has something to show.

## Risks / Trade-offs

- [A large scope writes many objects in one request] -> the dry run shows the count first; the
  service writes in one loop with per-row outcomes, and a failure on one row does not stop
  the rest.
- [Percentage rounding] -> rounding is to the whole cent, per adjustment, and the rounded
  figure is what the approver sees before approving.
- [Notifications before the recipient field is set on old rows] -> the rule reads
  `employeeUserId`; old adjustments without it simply send nothing.

## Open Questions

- Should a collective cycle refuse a proposal that lands outside the target band, or only
  flag it through the existing `comp-adjustment-within-band` check? This design flags it.
