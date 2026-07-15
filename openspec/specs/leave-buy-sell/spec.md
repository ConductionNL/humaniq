---
capability: leave-buy-sell
status: done
built_by: openspec/changes/archive/2026-07-14-leave-buy-sell
---

# leave-buy-sell Specification

**Status**: done
**Scope**: hrmq (`kind: code+config` — reuses the existing `LeaveRequest`/`LeaveBalance` leave model,
the reused `NoSelfApprovalGuard`, and the `PayrollRunService` current-run fold pattern established by
`retro-adjustments`; adds zero new payroll-engine recompute logic)
**OpenSpec changes**:
- [leave-buy-sell](../../changes/archive/2026-07-14-leave-buy-sell/) _(archived 2026-07-14)_ —
  a declarative `LeaveTransaction` buy/sell request appended to the existing leave fragment
  (`lib/Settings/register.d/hr-leave.json`, alongside `LeaveRequest`/`LeaveBalance`), carrying a
  `draft → submitted → approved/rejected → settled` lifecycle with separation of duties on `approve`/
  `reject` via the **reused** `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` (composed inside the new
  `OCA\Hrmq\Lifecycle\LeaveBuySellApprovalGuard`), plus a structural guarantee that a `sell` can never
  draw down statutory hours: the guard and the new idempotent `LeaveBuySellSettlementService` write
  **only** `LeaveBalance.bovenwettelijkHours`, never `entitledHours`/`usedHours` — since
  `nl-verlof-wettelijk-minimum` is evaluated solely against `entitledHours`, a sell settled through this
  change cannot breach the statutory floor by construction. A new fail-closed
  `OCA\Hrmq\Lifecycle\LeaveSettlementPeriodGuard` gates the `settle` transition on a well-formed
  `settlementPeriod`, and a new corpus rule (`nl-verlof-bovenwettelijk-niet-negatief`,
  `lib/Standards/rules/labour.json`, `RuleCatalogue::VERSION` bumped) plus a `NlLeaveChecks` predicate
  audit-back-stop `bovenwettelijkHours >= 0` independently. The settled euro amount surfaces as a
  **current-run payroll input** — `PayrollRunService.generate()` folds every `settled` transaction
  whose `settlementPeriod` equals the draft run's period into a new nullable `Payslip.leaveBuySell`
  field and `nettoPay`, mirroring the existing `retroAdjustment` fold exactly; `PayrollCalculator` is
  never invoked to (re)compute it. One occ command (`hrmq:leave:settle --id`) and ONE guarded endpoint
  (`POST /api/leave/settle`, `LeaveController::settle`, RBAC-resolve-first → 404) are the only settle
  paths — `settle` is deliberately never a bare `lifecycleActions` button (the `CompAdjustmentDetail`
  orphaned-capability precedent). ADR-001 Rule 6 surfaces: an `EmployeeDetail` dossier row
  (`emp-leave-transactions`), a routed `LeaveTransactionDetail` page (not a menu child), and a
  `MijnVerlofKopenVerkopen` `@me` self-service page added as a child of the existing `MijnHrGroup` menu
  (no new top-level menu). Auto-provisioning a missing `LeaveBalance`, deriving `hourlyRate` from the
  payroll engine, and batch/period-wide settlement are named Non-Goals/fast-follows.

## Purpose

hrmq's leave surface (`leave-management`) gives employees a `LeaveRequest` workflow and a
`LeaveBalance` ledger with a declaratively calculated `remainingHours`
(`entitledHours + bovenwettelijkHours − usedHours`), guarded by the three mandatory NL leave rules in
`lib/Standards/rules/labour.json` (`nl-verlof-wettelijk-minimum`, `nl-verlof-saldo-niet-negatief`,
`nl-verlof-vervaltermijn`). What it lacked was any way for an employee to convert leave hours into
money or money into leave hours — a small flexibility (IKB-style, "individueel keuzebudget") that is
standard in Dutch CAOs and a common ask HR asks for. This capability adds the smallest useful version:
a declarative `LeaveTransaction` request (buy or sell), a separation-of-duties approval reusing the
existing `NoSelfApprovalGuard`, and a settlement step that (a) adjusts the employee's
`LeaveBalance.bovenwettelijkHours` — **never** the statutory `entitledHours` the wettelijk-minimum rule
protects — and (b) surfaces the euro effect as an input component on the **current** payroll run,
mirroring the `retro-adjustments` fold-into-current-run pattern rather than asking the payroll engine
to recompute anything.

The one compliance-critical rule this capability must never break: a sell may only ever draw down
**bovenwettelijk** (above-statutory) hours. `nl-verlof-wettelijk-minimum` is checked against
`entitledHours` alone, and this capability's guard/service never write that field — so a sell that
would push `bovenwettelijkHours` negative is refused before it can ever threaten the statutory floor,
structurally, not just by convention.

## ADDED Requirements

### Requirement: LeaveTransaction SHALL run a declarative request/approve lifecycle with separation of duties reusing NoSelfApprovalGuard (REQ-BUYSELL-001)

`LeaveTransaction` SHALL carry an `x-openregister-lifecycle` state machine on `status`
(`draft → submitted → approved/rejected → settled`, `terminal: [settled]`) mirroring the existing
`LeaveRequest` shape: `submit` (`from: [draft, rejected]`, `to: submitted` — a rejected request may
be corrected and re-submitted), `approve` (`from: [submitted]`, `to: approved`,
`requires: OCA\Hrmq\Lifecycle\LeaveBuySellApprovalGuard`), `reject` (`from: [submitted]`,
`to: rejected`, `requires: OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`), `settle` (`from: [approved]`,
`to: settled`, `requires: OCA\Hrmq\Lifecycle\LeaveSettlementPeriodGuard`). `LeaveBuySellApprovalGuard`
SHALL delegate to the existing `NoSelfApprovalGuard::check()` before any transaction-specific logic
runs, so the approver/rejecter may never be the requesting employee — the identical rule already
enforced on `LeaveRequest`/`Timesheet`/`Expense`/`PerformanceReview`, reused rather than
reimplemented.

#### Scenario: Self-approval is denied via the reused guard
- **GIVEN** a submitted `LeaveTransaction` with `employeeId` equal to the acting user
- **WHEN** that user attempts the `approve` transition
- **THEN** `LeaveBuySellApprovalGuard` denies (delegating to `NoSelfApprovalGuard`) with the same
  message a self-approved `LeaveRequest` would receive

#### Scenario: A rejected request can be corrected and re-submitted
- **GIVEN** a `LeaveTransaction` in status `rejected`
- **WHEN** the employee corrects it and executes `submit`
- **THEN** the status becomes `submitted` again, available for a fresh `approve`/`reject` decision

### Requirement: Selling leave SHALL only ever draw down bovenwettelijk hours, never the statutory minimum (REQ-BUYSELL-002)

For `transactionType: sell`, `LeaveBuySellApprovalGuard` SHALL resolve the `LeaveBalance` matching
the transaction's `(employeeId, year, leaveType)` and deny the `approve` transition when no such
balance resolves, or when `bovenwettelijkHours < hours`. No code path in this change SHALL ever
write `LeaveBalance.entitledHours` or `LeaveBalance.usedHours` — `LeaveBuySellSettlementService`
(REQ-BUYSELL-004) writes only `bovenwettelijkHours`. Because `nl-verlof-wettelijk-minimum`
(`lib/Standards/rules/labour.json`) is evaluated solely against `entitledHours` and
`contractHoursPerWeek`, a sell request can therefore never push a balance's statutory entitlement
below the BW art. 7:634 minimum — the protection is structural (no writable path to `entitledHours`
exists), not merely a runtime check, though the runtime check exists too (belt-and-braces, D2 of
design.md).

#### Scenario: A sell exceeding available bovenwettelijk hours is refused at approval
- **GIVEN** a `LeaveBalance` for the employee/year/leaveType with `bovenwettelijkHours: 20`
- **AND** a submitted `LeaveTransaction` `{transactionType: sell, hours: 30}` against that balance
- **WHEN** a different (non-self) manager attempts the `approve` transition
- **THEN** `LeaveBuySellApprovalGuard` denies with an insufficient-bovenwettelijk message and the
  status stays `submitted`

#### Scenario: A sell within the available bovenwettelijk hours is approvable
- **GIVEN** the same `LeaveBalance` with `bovenwettelijkHours: 20`
- **AND** a submitted `LeaveTransaction` `{transactionType: sell, hours: 8}`
- **WHEN** a different manager approves it
- **THEN** the transition succeeds and `entitledHours` on the referenced balance is unchanged

#### Scenario: Buying leave is not gated by the sufficiency check
- **GIVEN** a submitted `LeaveTransaction` `{transactionType: buy, hours: 10}`
- **WHEN** a different manager approves it
- **THEN** `LeaveBuySellApprovalGuard` allows the transition without evaluating
  `bovenwettelijkHours` sufficiency (only sells are constrained by it)

### Requirement: A corpus rule SHALL audit-backstop the bovenwettelijk-hours invariant (REQ-BUYSELL-003)

`lib/Standards/rules/labour.json` SHALL gain `nl-verlof-bovenwettelijk-niet-negatief`
(`domain: labour`, `jurisdiction: NL`, `severity: mandatory`, `machineCheckable: true`), and the
existing `lib/Standards/Checks/NlLeaveChecks.php` SHALL register a predicate asserting
`LeaveBalance.bovenwettelijkHours >= 0`. This is an audit-time backstop alongside the write-time
guard/service checks (REQ-BUYSELL-002/-004) — it catches any future drift (a bug, a hand-edited
balance, a race) that let a negative `bovenwettelijkHours` through despite them. `RuleCatalogue::VERSION`
SHALL bump to reflect the new rule.

#### Scenario: A negative bovenwettelijk balance is flagged
- **GIVEN** a `LeaveBalance` with `bovenwettelijkHours: -4` (however it arose)
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-verlof-bovenwettelijk-niet-negatief` violation is reported for that object

#### Scenario: A non-negative balance passes clean
- **GIVEN** every seeded `LeaveBalance` (`bovenwettelijkHours >= 0`)
- **WHEN** the audit runs
- **THEN** no `nl-verlof-bovenwettelijk-niet-negatief` violation is reported

### Requirement: Settlement SHALL be idempotent and write LeaveBalance.bovenwettelijkHours exactly once (REQ-BUYSELL-004)

`LeaveBuySellSettlementService::settle(transactionId)` SHALL: return an already-settled outcome
without writing anything when `status` is already `settled`; refuse when `status` is not
`approved`; refuse when `settlementPeriod` is missing or not shaped `YYYY-MM`
(`LeaveSettlementPeriodGuard` enforces the identical precondition at the `settle` transition,
belt-and-braces); refuse when the referenced `LeaveBalance` cannot be resolved; for `sell`, refuse
when `bovenwettelijkHours < hours` at settle time (re-checked, not solely relied on from approval);
otherwise compute `settledAmount = round(hours × hourlyRate, 2)`, write
`LeaveBalance.bovenwettelijkHours += hours` (buy) or `-= hours` (sell) as the ONLY field changed on
the balance, and stamp the transaction's `settledAmount`/`settledAt`/`status: settled` on the same
write that carries the `settle` transition. `settle` SHALL NOT be exposed as a bare
`lifecycleActions` button anywhere in the manifest — the balance write lives in the service, so the
only settle path is a guarded `api-call` action, mirroring the `CompAdjustmentDetail`
"Effectueren"/orphaned-capability precedent exactly.

#### Scenario: Settling twice is a no-op
- **GIVEN** an already-`settled` `LeaveTransaction`
- **WHEN** `settle()` is invoked again (occ retry or a duplicate API call)
- **THEN** the outcome reports `already-settled`, `bovenwettelijkHours` is not written a second
  time, and `settledAmount`/`settledAt` do not change

#### Scenario: Settling without a settlement period refuses
- **GIVEN** an `approved` `LeaveTransaction` with `settlementPeriod` empty
- **WHEN** `settle()` is invoked
- **THEN** the outcome refuses with a no-settlement-period message and no write occurs

#### Scenario: A sell's balance write is exact
- **GIVEN** an `approved` sell `LeaveTransaction` `{hours: 8, hourlyRate: 25.00}` against a
  `LeaveBalance` with `bovenwettelijkHours: 20`
- **WHEN** `settle()` succeeds
- **THEN** the balance's `bovenwettelijkHours` becomes `12`, `entitledHours`/`usedHours` are
  unchanged, and the transaction's `settledAmount` is `200.00`

### Requirement: The settled amount SHALL surface as a current-run payroll input on Payslip, never an engine recompute (REQ-BUYSELL-005)

`PayrollRunService.generate()` SHALL sum every `settled` `LeaveTransaction` whose `settlementPeriod`
equals the draft run's period into each employee's Payslip `leaveBuySell` component (a new nullable
`Payslip` field, mirroring the existing `retroAdjustment` field): sold transactions contribute a
positive amount (a payment), bought transactions contribute a negative amount (a deduction); the
sum is folded into `nettoPay`, mirroring the existing `retroAdjustment` fold exactly. No settled
transaction for an employee/period ⇒ `leaveBuySell` stays `null` and the payslip is byte-identical
to before this change. `PayrollCalculator` SHALL NOT be invoked to compute or verify a
`settledAmount` — the figure is read as-is from the already-settled `LeaveTransaction`.

#### Scenario: A settled sell adds to nettoPay
- **GIVEN** a `LeaveTransaction` settled with `settledAmount: 200.00`, `transactionType: sell`,
  `settlementPeriod: 2026-06` for an employee
- **WHEN** `occ hrmq:payroll:run --period 2026-06` generates that employee's payslip
- **THEN** `leaveBuySell` is `200.00` and `nettoPay` includes it, added on top of the engine's
  computed net

#### Scenario: A settled buy deducts from nettoPay
- **GIVEN** a `LeaveTransaction` settled with `settledAmount: 150.00`, `transactionType: buy`,
  `settlementPeriod: 2026-06` for an employee
- **WHEN** the same run generates
- **THEN** `leaveBuySell` is `-150.00` and `nettoPay` is reduced by that amount

#### Scenario: No settled transaction leaves the payslip unchanged
- **GIVEN** an employee with no `settled` `LeaveTransaction` for the run's period
- **WHEN** the run generates
- **THEN** `leaveBuySell` is `null` and the payslip is otherwise identical to its pre-change shape

### Requirement: The buy/sell surfaces SHALL be a detail-tab plus @me self-service page per ADR-001 Rule 6, with no new top-level menu (REQ-BUYSELL-006)

`src/manifest.json` SHALL add to `EmployeeDetail` an `emp-leave-transactions` object-list widget
row (`LeaveTransaction.employeeId = @objectId`, columns `transactionType`/`hours`/`status`/
`settlementPeriod`, `rowRoute: LeaveTransactionDetail`) as a full-width row inserted after the
`emp-comp-adjustments` row and before the personnel-file Files leaf, which shifts down — the same
insertion pattern every prior HR-dossier addition used. The manifest SHALL further add
`LeaveTransactionDetail` (detail over `LeaveTransaction`, route `/leave-transactions/:id`, **not**
a menu child) with a data widget (excluding `employeeId`/`settlementPayrollRunId` — Related
resolves both), a related widget, `lifecycleActions` exposing **exactly** `submit`/`approve`/`reject`,
the guarded "Verrekenen" `api-call` action for `settle`, and an audit-history sidebar tab; and a new
self-service index page `MijnVerlofKopenVerkopen` (`filter: {userId: "@me"}`) added as a **child of
the existing `MijnHrGroup` menu** — no new top-level menu entry and no new menu group is created
anywhere in this change. A deepLink `LeaveTransaction` → `/apps/hrmq/leave-transactions/{uuid}` is
registered and `src/icons.js` registers one new icon. The manifest MUST validate
(`npm run check:manifest`).

#### Scenario: The dossier row reaches the detail page
- **GIVEN** an Employee with two `LeaveTransaction` records
- **WHEN** `EmployeeDetail` loads for that employee
- **THEN** the `emp-leave-transactions` row lists both, and clicking a row navigates to
  `LeaveTransactionDetail`

#### Scenario: No invented settle button
- **WHEN** the manifest's `LeaveTransactionDetail.lifecycleActions.transitions` are compared to
  `LeaveTransaction`'s `x-openregister-lifecycle` transitions
- **THEN** only `submit`/`approve`/`reject` appear in `lifecycleActions`; `settle` is reachable only
  through the "Verrekenen" `api-call` action

#### Scenario: Self-service page is scoped to the current user and adds no top-level menu
- **GIVEN** two employees each with their own `LeaveTransaction` records
- **WHEN** one employee opens `MijnVerlofKopenVerkopen`
- **THEN** only that employee's own transactions are listed (`userId: "@me"`)
- **AND** the top-level menu gains no new entry — `MijnVerlofKopenVerkopen` appears only as a child
  of the existing `MijnHrGroup` group
