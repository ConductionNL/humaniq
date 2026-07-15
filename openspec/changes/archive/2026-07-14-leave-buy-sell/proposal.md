---
kind: code+config
depends_on: []   # leave-verzuim-mvp (LeaveRequest/LeaveBalance/NoSelfApprovalGuard) and payroll-core-engine/retro-adjustments (PayrollRunService fold pattern) are merged/archived at HEAD — consumed, not pending
---

# Leave buy/sell (IKB-style flexibility) — MVP

## Why

hrmq's leave surface (`leave-verzuim-mvp`, archived 2026-07-12) gives employees a `LeaveRequest`
workflow and a `LeaveBalance` ledger with a declaratively calculated `remainingHours`
(`entitledHours + bovenwettelijkHours − usedHours`), guarded by the three mandatory NL leave rules
in `lib/Standards/rules/labour.json` (`nl-verlof-wettelijk-minimum`, `nl-verlof-saldo-niet-negatief`,
`nl-verlof-vervaltermijn`). What it does not have is any way for an employee to convert leave hours
into money or money into leave hours — a small flexibility (IKB-style, "individueel keuzebudget")
that is standard in Dutch CAOs and a common ask HR asks for. This change adds the smallest useful
version: a declarative `LeaveTransaction` request (buy or sell), a separation-of-duties approval
reusing the existing `NoSelfApprovalGuard`, and a settlement step that (a) adjusts the employee's
`LeaveBalance.bovenwettelijkHours` — **never** the statutory `entitledHours` the wettelijk-minimum
rule protects — and (b) surfaces the euro effect as an input component on the **current** payroll
run, mirroring the `retro-adjustments` (archived 2026-07-14) fold-into-current-run pattern rather
than asking the payroll engine to recompute anything.

The one compliance-critical rule this change must never break: a sell may only ever draw down
**bovenwettelijk** (above-statutory) hours. `nl-verlof-wettelijk-minimum` is checked against
`entitledHours` alone, and this change's guard/service never write that field — so a sell that
would push `bovenwettelijkHours` negative is refused before it can ever threaten the statutory
floor, structurally, not just by convention.

## What Changes

- **NEW schema `LeaveTransaction`** (`lib/Settings/register.d/hr-leave.json`, appended to the
  existing leave fragment alongside `LeaveRequest`/`LeaveBalance` — a small addition to the
  existing leave model, not a new fragment file) — `employeeId` (`$ref` Employee), `transactionType`
  (`buy`|`sell`), `year` + `leaveType` (identifies the target `LeaveBalance` row, same enum as
  `LeaveBalance.leaveType`), `hours` (>0), `hourlyRate` (the quoted euro rate — an MVP input, not
  derived from the payroll engine), `settledAmount` (nullable, computed at settle),
  `settlementPeriod` (nullable `YYYY-MM`, the wage period it settles into),
  `settlementPayrollRunId` (nullable `$ref` PayrollRun, stamped at settle), a declarative
  `x-openregister-lifecycle` (`draft → submitted → approved/rejected → settled`,
  `terminal: [settled]`), and the LeaveRequest self-service denormalised `userId`.
- **NEW guard `LeaveBuySellApprovalGuard`** (`lib/Lifecycle/`) on the `approve` transition —
  delegates to the existing `NoSelfApprovalGuard` first (reused, not reimplemented), then, only for
  `transactionType: sell`, resolves the referenced `LeaveBalance` and denies when it cannot be
  found or its `bovenwettelijkHours < hours` — the machine-checkable guard that keeps a sell from
  ever threatening the statutory floor.
- **NEW guard `LeaveSettlementPeriodGuard`** (`lib/Lifecycle/`) on the `settle` transition —
  fail-closed precondition mirroring `CompEffectiveDateGuard`: denies unless `settlementPeriod`
  is present and shaped `YYYY-MM`. Read-only, like every guard in this codebase; the balance write
  itself is the imperative service's job (below).
- **NEW rule `nl-verlof-bovenwettelijk-niet-negatief`** in `lib/Standards/rules/labour.json` +
  one new predicate in the existing `lib/Standards/Checks/NlLeaveChecks.php` (`LeaveBalance`:
  `bovenwettelijkHours >= 0`) — an audit-time backstop alongside the write-time guard, the
  `payroll-core-engine`/`retro-adjustments` "corpus is the self-check" precedent.
  `RuleCatalogue::VERSION` bumps.
- **NEW `lib/Service/LeaveBuySellSettlementService.php`** — `settle(transactionId)`: idempotent
  (an already-`settled` transaction is a no-op, the `CompAdjustmentService::effectuate()` idiom),
  refuses non-`approved` status and a missing/invalid `settlementPeriod` (belt-and-braces alongside
  the guard), resolves the `LeaveBalance` (refuses if unresolvable — this MVP does not auto-create
  balance rows), re-checks sufficiency for `sell` (belt-and-braces alongside
  `LeaveBuySellApprovalGuard`), computes `settledAmount = round(hours × hourlyRate, 2)`, writes
  **only** `LeaveBalance.bovenwettelijkHours` (`+hours` for buy, `-hours` for sell — `entitledHours`
  and `usedHours` are never touched), and drives the `settle` transition on the ordinary object
  write that carries it (`settledAmount`/`settledAt`/`status: settled`).
- **Delta surfacing** — `PayrollRunService.generate()` (extended) sums every `settled`
  `LeaveTransaction` whose `settlementPeriod` equals the draft run's period into each employee's
  Payslip `leaveBuySell` component (signed: bought = deduction/negative, sold = payment/positive)
  and folds it into `nettoPay`, mirroring the existing `retroAdjustment` fold exactly — the engine
  never recomputes a buy/sell amount, it only reads the already-settled figure.
- **NEW nullable field `leaveBuySell`** on `Payslip` (`lib/Settings/register.d/hr-objects.json`) —
  the summed buy/sell net effect settled into this payslip's period, mirroring `retroAdjustment`.
- **NEW occ `hrmq:leave:settle --id <transactionId>`** (`lib/Command/LeaveBuySellSettleCommand.php`)
  + **NEW guarded endpoint** `POST /api/leave/settle` (`LeaveController::settle` or a new
  controller, `#[NoAdminRequired]`) — resolves the posted `transactionId` through ObjectService
  under the caller's ambient RBAC BEFORE any write (unknown/unauthorized → 404, the
  `PayrollController::calculate`/`adjust` no-admin-idor precedent), then delegates to the service.
  ONE endpoint, no CRUD (ADR-022).
- **Manifest, per ADR-001 Rule 6 (detail-tab + self-service, no new top-level menu)** —
  `EmployeeDetail` gains an `emp-leave-transactions` FK-scoped object-list row (the
  `emp-comp-adjustments`/`emp-reviews` insertion pattern, before the personnel-file Files leaf,
  which shifts down again); a new `LeaveTransactionDetail` routed page (NOT a menu child — the
  `CompAdjustmentDetail`/`TimesheetDetail` convention) with `lifecycleActions` exposing **only**
  `submit`/`approve`/`reject` (settle is deliberately **not** a bare lifecycle button — the
  `CompAdjustmentDetail` orphaned-capability precedent: the balance write lives inside the service,
  so the only settle path is the guarded "Verrekenen" `api-call` action); a new self-service index
  page `MijnVerlofKopenVerkopen` (`filter: {userId: "@me"}`) added as a **child of the existing**
  `MijnHrGroup` menu (the `MijnDoelen` precedent — no new top-level menu, no new menu group). A
  deepLink + icon registration complete the wiring. `npm run check:manifest` MUST pass.

### Non-goals (named follow-ups)

- **Auto-creating a `LeaveBalance` row.** Buy/sell settles only against an existing
  `(employeeId, year, leaveType)` `LeaveBalance`; a missing balance refuses settlement rather than
  fabricating one. Named follow-up: `leave-balance-auto-provision`.
- **Deriving `hourlyRate` from the payroll engine.** The MVP takes the rate as a caller-supplied
  input on the request (a quoted/agreed rate), exactly per the brief ("a payroll input... not
  recomputed"); computing it from `Employee.grossMonthlySalary`/contract hours is a named
  follow-up (`leave-buysell-derived-rate`) once the engine's hourly path
  (`payroll-core-engine`'s own named fast-follow) exists.
- **Batch/period-wide settlement.** `settle` operates on one `LeaveTransaction` at a time (the
  `CompAdjustmentService::effectuateOne` shape); a cycle-wide batch settle (the
  `effectuateCycle`/`hrmq:payroll:adjust --settlement-period` shape) is a named follow-up once
  volume warrants it.
- **Non-holiday leave types.** `leaveType` is schema-generic (matches `LeaveBalance`), but in
  practice only `holiday` balances carry meaningful `bovenwettelijkHours`; no schema-level
  restriction is added in the MVP.

## Capabilities

### New Capabilities

- `leave-buy-sell`: the `LeaveTransaction` declarative lifecycle with separation-of-duties on
  approve (reused `NoSelfApprovalGuard`) plus the statutory-floor guard on sell, the idempotent
  settlement service that writes only `bovenwettelijkHours`, the `nl-verlof-bovenwettelijk-niet-negatief`
  audit-time backstop, the current-run payroll-input fold into `Payslip.leaveBuySell`, the occ
  command + guarded endpoint, and the ADR-001 Rule 6 detail-tab/self-service manifest surfaces.

### Modified Capabilities

<!-- none — LeaveRequest/LeaveBalance/NlLeaveChecks/PayrollRunService are extended in place but
     their existing behaviour (submit/approve/reject lifecycle, remainingHours calc, the three
     existing leave rules, sick-pay/retro-adjustment folding) is unchanged; see leave-management,
     payroll-core-engine and retro-adjustments for those capabilities' own specs -->

## Impact

- `lib/Settings/register.d/hr-leave.json` — NEW `LeaveTransaction` schema (version bump); existing
  `LeaveRequest`/`LeaveBalance` untouched.
- `lib/Settings/register.d/hr-objects.json` — `Payslip` gains nullable `leaveBuySell` (version bump).
- `lib/Lifecycle/LeaveBuySellApprovalGuard.php`, `lib/Lifecycle/LeaveSettlementPeriodGuard.php` — NEW.
- `lib/Service/LeaveBuySellSettlementService.php` — NEW (ObjectService idiom per
  `CompAdjustmentService`); `lib/Service/PayrollRunService.php` — `generate()` folds settled
  transactions, mirroring the existing `retroAdjustment` fold.
- `lib/Standards/rules/labour.json` — +1 rule; `lib/Standards/Checks/NlLeaveChecks.php` — +1
  predicate; `lib/Standards/RuleCatalogue.php` — VERSION bump.
- `lib/Command/LeaveBuySellSettleCommand.php` — NEW; `appinfo/info.xml` +1 `<command>` entry.
- `lib/Controller/LeaveController.php` (or a new `LeaveBuySellController.php`) — +`settle`;
  `appinfo/routes.php` +1 route (before the catch-all).
- `src/manifest.json` — `EmployeeDetail` row, new `LeaveTransactionDetail` page, new
  `MijnVerlofKopenVerkopen` self-service page under the existing `MijnHrGroup` menu, deepLink;
  `src/icons.js` — 1 new icon registration; `npm run check:manifest` passes.
- `tests/Unit/Lifecycle/LeaveBuySellApprovalGuardTest.php`,
  `tests/Unit/Lifecycle/LeaveSettlementPeriodGuardTest.php`,
  `tests/Unit/Service/LeaveBuySellSettlementServiceTest.php`,
  `tests/Unit/Standards/NlLeaveChecksTest.php` (extended) — NEW/extended.
- Consumes (merged at HEAD): `leave-verzuim-mvp` (`LeaveRequest`/`LeaveBalance`/`NoSelfApprovalGuard`),
  `payroll-core-engine`/`retro-adjustments` (`PayrollRunService`, the current-run fold pattern).
