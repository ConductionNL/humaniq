# Design — leave-buy-sell

## Context

**Verified against HEAD 2026-07-15.** Read read-only:

- `lib/Settings/register.d/hr-leave.json` — `LeaveRequest` (draft → submitted → approved/rejected,
  `requires: NoSelfApprovalGuard` on both `approve` and `reject`, denormalised `userId`/`managerUserId`
  for `@me`/team scoping) and `LeaveBalance` (`entitledHours`, `bovenwettelijkHours`, `usedHours`,
  `contractHoursPerWeek` snapshot, `expiryDate`, the just-added `lastAccruedPeriod` idempotency
  marker) with `remainingHours` declared ONLY in `configuration.x-openregister-calculations`
  (`materialise: true`, `entitledHours + bovenwettelijkHours − usedHours`) — never as a stored
  property, so it can never shadow a stored field. This change adds a schema and a service; it does
  not touch this expression.
- `lib/Standards/rules/labour.json` — the three mandatory verlof rules: `nl-verlof-wettelijk-minimum`
  (`entitledHours >= 4 × contractHoursPerWeek`, BW art. 7:634), `nl-verlof-saldo-niet-negatief`
  (`usedHours <= entitledHours + bovenwettelijkHours`), `nl-verlof-vervaltermijn` (statutory hours
  lapse 1 July of the following year). **All three read `entitledHours`/`usedHours`/`bovenwettelijkHours`
  as already-stored facts — none of them are ever written by this change except `bovenwettelijkHours`.**
- `lib/Lifecycle/NoSelfApprovalGuard.php` (stateless, no DI — `$userId !== $object['employeeId']`),
  `lib/Lifecycle/CompEffectiveDateGuard.php` (stateless, fail-closed on the object's own field),
  `lib/Lifecycle/PayrollRunApprovedGuard.php` (DI'd `ContainerInterface`/`IAppConfig`, cross-object
  load via `ObjectService::find()`, fail-closed on missing/dangling/unloadable references) — the
  three guard shapes this change composes from.
- `lib/Service/CompAdjustmentService.php` — the imperative-write-beside-a-guarded-transition
  pattern: a read-only guard (`CompEffectiveDateGuard`) authorises the *timing*, but the actual
  cross-object write (`Employee.grossMonthlySalary`) and the transition-carrying save both happen in
  the service, idempotently (`already-effective` short-circuit) — because `CompAdjustmentDetail`'s
  own manifest `_note` names the trap directly: *"a bare declarative transition would flip status to
  effective without ever writing the salary (the orphaned-capability trap)"*.
- `lib/Service/PayrollRunService.php` `generate()` — the exact fold-in precedent, twice: sick-pay
  substitutes gross before the calculator runs; `retro-adjustments`
  (`appliedRetroAdjustmentsByEmployeeId()` sums `applied` `PayrollAdjustment.deltaNet` cents keyed by
  employeeId for `settlementPeriod === period`, then `retroAdjustmentFields()` merges
  `{retroAdjustment, nettoPay}` onto the payload, `0` delta ⇒ `{retroAdjustment: null}`, byte-identical
  to before). This change's fold is the same shape one component further along.
- `openspec/changes/archive/2026-07-14-retro-adjustments` — the `draft → applied` idempotent-fold
  precedent (a delta object that only affects a run once flipped, and only the CURRENT run).
- `src/manifest.json` — `LeaveRequestDetail` (data split Request/Approval, `lifecycleActions` =
  exactly `submit`/`approve`/`reject`, no invented cancel edge), `CompAdjustmentDetail` (the
  orphaned-capability `_note`: `effectuate` is deliberately NOT a bare `lifecycleActions` button,
  only the guarded "Effectueren" `api-call`), `EmployeeDetail`'s FK-scoped row insertion order
  (assignments → reviews → objectives → comp-adjustments → Files, each a further full-width row),
  and the `MijnHrGroup` menu (`MijnUren`/`MijnDeclaraties`/`MijnVerlof`/`MijnLoonstroken`/
  `MijnAanwezigheid`/`MijnBeoordelingen`/`MijnDoelen` — `MijnDoelen`, from `goals-okr`, is the
  precedent for adding a **child** page to this existing group rather than a new top-level menu).

## Goals / Non-Goals

**Goals:** a declarative `LeaveTransaction` request/approve lifecycle reusing the existing
separation-of-duties guard; a sell that can **structurally never** breach
`nl-verlof-wettelijk-minimum` because the only field it is ever permitted to write is
`bovenwettelijkHours`; an idempotent settlement that adjusts the balance exactly once; the euro
effect surfaced as a current-run payslip input, never an engine recompute; ADR-001 Rule 6
detail-tab + `@me` self-service surfaces, no new top-level menu.

**Non-Goals (from the proposal, binding):** auto-provisioning a missing `LeaveBalance` row;
deriving `hourlyRate` from the payroll engine (caller-supplied input, MVP); batch/period-wide
settlement (one transaction at a time); any restriction of `leaveType` beyond the existing
`LeaveBalance` enum.

## Decisions

### D1 — `LeaveTransaction` is a small addition to the EXISTING leave fragment, not a new file

Per the brief, `LeaveTransaction` is appended to `lib/Settings/register.d/hr-leave.json` alongside
`LeaveRequest`/`LeaveBalance` (same fragment, `x-hrmq-fragment: hr-leave`) rather than a new
`hr-*.json` file — it is the same domain, and `retro-adjustments` only opened a new fragment
(`hr-retro.json`) because it introduced a genuinely separate concern (payroll corrections). Fields:

| Field | Type | Notes |
|---|---|---|
| `employeeId` | string, uuid, `$ref` Employee | required |
| `transactionType` | enum `buy`\|`sell` | required |
| `year` | integer | required — identifies the target `LeaveBalance.year` |
| `leaveType` | enum, identical to `LeaveBalance.leaveType` | required — identifies the target `LeaveBalance.leaveType` |
| `hours` | number, minimum 0 (exclusive in practice) | required |
| `hourlyRate` | number | required — the quoted euro rate (D3: an MVP input, never derived) |
| `settledAmount` | number, nullable | computed at settle: `round(hours × hourlyRate, 2)` |
| `settlementPeriod` | string, nullable, `YYYY-MM` | the wage period this settles into; required before `settle` (D5 guard) |
| `settlementPayrollRunId` | string, uuid, nullable, `$ref` PayrollRun | stamped when `PayrollRunService.generate()` first folds it |
| `status` | enum `draft`\|`submitted`\|`approved`\|`rejected`\|`settled` | default `draft` |
| `submittedAt`/`approvedBy`/`approvedAt`/`rejectionReason`/`settledAt` | mirrors `LeaveRequest` | timestamps/actor stamps |
| `userId` | string, nullable | denormalised NC user id for `@me` self-service (the `LeaveRequest.userId` convention) |

`x-openregister-lifecycle`: `field: status`, `initial: draft`, `terminal: [settled]`,
transitions `submit` (`from: [draft, rejected]`, `to: submitted` — the `LeaveRequest` resubmit
convention), `approve` (`from: [submitted]`, `to: approved`, `requires: LeaveBuySellApprovalGuard`),
`reject` (`from: [submitted]`, `to: rejected`, `requires: NoSelfApprovalGuard` directly — reject
never needs the statutory check, only separation of duties), `settle` (`from: [approved]`,
`to: settled`, `requires: LeaveSettlementPeriodGuard`).

### D2 — The statutory floor is protected structurally: only `bovenwettelijkHours` is ever written

Neither the guard nor the service touches `entitledHours` or `usedHours` anywhere in this change —
`grep`-verifiable once implemented. Because `nl-verlof-wettelijk-minimum` is evaluated solely against
`entitledHours >= 4 × contractHoursPerWeek`, and this change contains no code path that can change
`entitledHours`, a sell **cannot** violate that rule by construction, not merely by a runtime check.
The runtime check exists anyway (belt-and-braces, the `CompAdjustmentService::withinBand` idiom) at
two points:

1. **`LeaveBuySellApprovalGuard`** (approve-time, read-only): delegates to
   `(new NoSelfApprovalGuard())->check($object, $action, $userId)` first — reused, not
   reimplemented, satisfying the brief's separation-of-duties requirement with the identical class
   already proven on `LeaveRequest`/`Timesheet`/`Expense`/`PerformanceReview`. If that denies, its
   `GuardResult` is returned unchanged. Otherwise, only when `transactionType === 'sell'`: resolves
   the `LeaveBalance` for `(employeeId, year, leaveType)` (the `PayrollRunApprovedGuard`
   `ContainerInterface`/`IAppConfig` cross-object-load shape — `loadAll('LeaveBalance')` filtered
   in-guard, since no composite-key `find()` exists on `ObjectService`), and denies when no balance
   resolves or `bovenwettelijkHours < hours`. `transactionType === 'buy'` needs no balance check to
   *approve* (adding hours cannot go negative); D5's settlement step still requires the balance to
   exist to know what to add to.
2. **`LeaveBuySellSettlementService::settle()`** (settle-time, imperative, belt-and-braces): re-runs
   the identical sufficiency check immediately before writing, because — exactly as
   `CompAdjustmentService`'s own docblock states the principle — *"a service write should never rely
   solely on a guard it does not control the invocation of."* A sell approved when sufficient but
   somehow no longer sufficient at settle time (e.g. a concurrent sell drained the balance first)
   refuses rather than writing a negative `bovenwettelijkHours`.

### D3 — `nl-verlof-bovenwettelijk-niet-negatief`: an audit-time backstop, not a substitute for D2

A new rule in `lib/Standards/rules/labour.json` (`domain: labour`, `jurisdiction: NL`,
`framework: hr-leave-core`, `source: "HR-administration control (bovenwettelijk-verlofkoop/-verkoop
balance integrity)"`, `severity: mandatory`, `machineCheckable: true`, `sourceUrl: null` — this is
an internal-control rule the same shape as `nl-org-assignment-consistency`, not itself a statutory
citation) asserts `bovenwettelijkHours >= 0` on every `LeaveBalance`. A new predicate in the
existing `lib/Standards/Checks/NlLeaveChecks.php` (the `leave-management` provider, extended in
place — it already registers the three verlof predicates under object type `LeaveBalance`)
implements it. This is deliberately a **second, independent** line of defence (`occ
hrmq:rules:audit` catches drift even from a future bug or a hand-edited balance) — D2's guard/service
checks are the primary, structural protection; this rule is the corpus's own self-check precedent
(`payroll-core-engine`'s `nl-engine-output-consistency`, `retro-adjustments`'
`nl-retro-adjustment-consistency`), applied to the one new invariant this change introduces.
`RuleCatalogue::VERSION` bumps.

### D4 — Settlement is idempotent and touches the balance exactly once

`LeaveBuySellSettlementService::settle(string $transactionId)` (the `CompAdjustmentService::effectuateOne`
shape exactly):

1. Load the transaction; `status === 'settled'` ⇒ `already-settled` outcome, no-op (idempotent).
2. `status !== 'approved'` ⇒ `refused-not-approved`.
3. `settlementPeriod` empty or not `YYYY-MM`-shaped ⇒ `refused-no-settlement-period` (belt-and-braces
   alongside `LeaveSettlementPeriodGuard`).
4. Resolve the `LeaveBalance` for `(employeeId, year, leaveType)` ⇒ `refused-balance-unresolvable`
   when absent (D-non-goal: no auto-provisioning).
5. For `sell`: re-check `bovenwettelijkHours >= hours` (D2.2) ⇒ `refused-insufficient-bovenwettelijk`
   otherwise.
6. Compute `settledAmount = round(hours × hourlyRate, 2)`.
7. Write the `LeaveBalance` update: `bovenwettelijkHours += hours` (buy) or `-= hours` (sell) —
   **no other field on the balance is touched**, so `remainingHours` recomputes declaratively via
   the existing `x-openregister-calculations` expression on the very next read, exactly as it does
   for any other `bovenwettelijkHours` edit; this change never writes `remainingHours` itself.
8. Write the `LeaveTransaction` update: `settledAmount`, `settledAt`, `status: settled` (the
   ordinary object write that carries the transition — no separate "transition" API exists in this
   codebase, the `NoSelfApprovalGuard`/`CompAdjustmentService` idiom).

Because step 1 short-circuits before step 7 ever runs again, re-invoking `settle()` (occ retry, a
double-click on "Verrekenen", a redelivered webhook) can never double-apply the balance delta.

### D5 — `LeaveSettlementPeriodGuard`: fail-closed precondition, exactly `CompEffectiveDateGuard`'s shape

Stateless (no DI — the field it checks lives on the object being transitioned, the
`CompEffectiveDateGuard` rationale for why injecting `ContainerInterface` here would itself be the
orphaned/unused-dependency anti-pattern): denies the `settle` transition unless `settlementPeriod`
is present and matches `^\d{4}-\d{2}$`. Read-only, like every guard in this codebase — the actual
balance write is D4's service job, re-driving this same transition after writing (never
disagreeing), exactly as `CompEffectiveDateGuard` authorises `effectuate`'s timing while
`CompAdjustmentService` performs the write.

### D6 — The euro effect is a CURRENT-run payroll input, never an engine recompute

`PayrollRunService.generate()` gains a fold, structurally identical to the existing
`retroAdjustment` one:

- `settledLeaveTransactionsByEmployeeId(string $period): array<string, int>` — sums signed
  `settledAmount` (converted to integer cents, the `PayrollRunService::euros()`/`appliedRetroAdjustmentsByEmployeeId()`
  idiom) for every `LeaveTransaction` with `status === 'settled'` and `settlementPeriod === $period`,
  keyed by `employeeId`; **sold** contributes `+settledAmountCents` (a payment, increases net),
  **bought** contributes `-settledAmountCents` (a deduction, decreases net). Degrades to an empty
  map if the schema does not exist yet in the register (the `retro-adjustments`
  cross-change-ordering precedent).
- `leaveBuySellFields(int $cents, int $nettoPayCents): array` — `$cents === 0` ⇒
  `{leaveBuySell: null}` (byte-identical payslip to before this change); otherwise
  `{leaveBuySell: euros($cents), nettoPay: euros($nettoPayCents + $cents)}` — merged onto the payload
  alongside `sickPayFields()`/`retroAdjustmentFields()`, and `$cents` added into `totals['net']`
  exactly like `$retroAdjustmentCents` is today.

The engine (`PayrollCalculator`) is never invoked to (re)compute a buy/sell amount — `settledAmount`
is a fact the settlement service already computed and stored; the run only reads and folds it. This
is the literal meaning of "payroll input, not an engine recompute" from the brief, and it is why the
fold degrades gracefully in either merge order (like `retro-adjustments` before it).

### D7 — Detail-tab + `@me` self-service surfaces per ADR-001 Rule 6; no new top-level menu

- **`EmployeeDetail`**: new `emp-leave-transactions` FK-scoped object-list row (`LeaveTransaction.employeeId
  = @objectId`, columns `transactionType`/`hours`/`status`/`settlementPeriod`, `rowRoute:
  LeaveTransactionDetail`) inserted as the next full-width row after `emp-comp-adjustments` and before
  the personnel-file Files leaf (which shifts down again) — the exact insertion pattern every prior
  HR-dossier addition (`emp-assignments`/`emp-reviews`/`emp-objectives`/`emp-comp-adjustments`) used.
- **`LeaveTransactionDetail`** (detail, route `/leave-transactions/:id`, deliberately **NOT** a menu
  child — the `CompAdjustmentDetail`/`PerformanceReviewDetail`/`TimesheetDetail` convention: reached
  from the dossier row or the deepLink): a data widget (excluding `employeeId`/`settlementPayrollRunId`
  — Related resolves both), a related widget, `lifecycleActions` exposing **exactly**
  `submit`/`approve`/`reject` — **`settle` is deliberately NOT exposed as a bare lifecycleActions
  button**, the `CompAdjustmentDetail` orphaned-capability precedent verbatim: the balance write
  lives inside `LeaveBuySellSettlementService`, so a bare declarative `settle` transition would flip
  `status` to `settled` without ever touching `bovenwettelijkHours` (the exact trap
  `CompAdjustmentDetail`'s own `_note` names for `effectuate`). The only settle path is the guarded
  "Verrekenen" `api-call` action (`POST /api/leave/settle`, `params: {transactionId: "@objectId"}`,
  `confirm: true`), the `CompAdjustmentDetail`/"Effectueren" precedent. Audit history is a sidebar
  tab.
- **`MijnVerlofKopenVerkopen`** self-service index (`filter: {userId: "@me"}`, columns
  `transactionType`/`hours`/`settlementPeriod`/`status`, sort `submittedAt` desc) added as a
  **child of the existing `MijnHrGroup` menu** — the `MijnDoelen` precedent (goals-okr, 2026-07-15)
  proves a `@me`-scoped self-service surface is added as a sibling child, never a new top-level
  group. No 8th "Mijn HR" sub-item collides: the group already holds 7 children
  (`MijnUren`/`MijnDeclaraties`/`MijnVerlof`/`MijnLoonstroken`/`MijnAanwezigheid`/
  `MijnBeoordelingen`/`MijnDoelen`), this is the 8th.
- A deepLink `LeaveTransaction` → `/apps/hrmq/leave-transactions/{uuid}` is registered, and
  `src/icons.js` registers one new icon (`SwapHorizontal`, distinct from `LeaveRequest`'s
  `CalendarClock`/`LeaveBalance`'s `ScaleBalance`). `npm run check:manifest` MUST pass.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Request lifecycle (`draft→submitted→approved/rejected`) | declarative `x-openregister-lifecycle` | ADR-031 default, mirrors `LeaveRequest` exactly |
| Separation of duties on approve/reject | **reused** `NoSelfApprovalGuard` (composed inside `LeaveBuySellApprovalGuard` for approve; directly on reject) | one class, proven on 4 schemas already — never reimplemented |
| Statutory-floor sufficiency check | guard (approve) + service re-check (settle) | the guard cannot write; the service is the sole writer, belt-and-braces per `CompAdjustmentService`'s own stated principle |
| Balance write + settledAmount computation | imperative service via ObjectService | cross-object read-check-write with idempotency (the `CompAdjustmentService`/`PayrollRunService` precedent) — schema-declarative calc cannot express a conditional cross-object write |
| Settle trigger | occ command + ONE guarded endpoint, NOT a bare lifecycle button | the orphaned-capability trap `CompAdjustmentDetail` already names for `effectuate` |
| Current-run payslip fold | imperative service (`PayrollRunService.generate()` extension) | the established `retroAdjustment`/sick-pay fold shape |
| `remainingHours` | **unchanged** declarative calculation | this change writes `bovenwettelijkHours`, never `remainingHours` itself — the expression recomputes on the next read, exactly as designed |
| Output contract | corpus rule (`nl-verlof-bovenwettelijk-niet-negatief`) + `NlLeaveChecks` predicate | the app's established self-check exception, applied to the one new invariant |
| Detail/self-service surfaces | declarative manifest (`lifecycleActions`, `api-call`, object-list) | ADR-031 default; ADR-001 Rule 6 for placement |

## Seed Data (ADR-001)

No new seed objects required for this MVP to be exercisable: the existing seeded `LeaveBalance`
rows (`leave-verzuim-mvp`) already carry non-zero `bovenwettelijkHours` on at least one employee
(e.g. employee-jansen's compliant balance), so a hand-created `LeaveTransaction` against that
employee/year/leaveType exercises the full path (`submit → approve → settle`) without new fixtures.
The dev-container gate exercises the real path: create a `sell` transaction for 10 hours against
the seeded compliant balance, `submit`, `approve` (statutory guard passes — sufficient
bovenwettelijk hours), `occ hrmq:leave:settle --id <id>` (balance drops by 10 bovenwettelijk hours,
`remainingHours` recomputes accordingly), then `occ hrmq:payroll:run --period <settlementPeriod>`
must show the settled amount folded into that employee's `leaveBuySell`/`nettoPay`. A second `sell`
for more hours than remain in `bovenwettelijkHours` must be refused at `approve` by
`LeaveBuySellApprovalGuard` — proving D2's structural guarantee end to end.

## Risks / Trade-offs

- **`hourlyRate` is caller-supplied, not derived.** A wrong/stale rate yields a wrong `settledAmount`.
  Mitigated: named non-goal + follow-up (`leave-buysell-derived-rate`); the value is always visible
  on the request before approval, so an HR reviewer can catch an obviously wrong rate at that step.
- **No `LeaveBalance` auto-provisioning.** An employee with no balance row for the requested
  `(year, leaveType)` cannot settle a transaction at all (refused, not silently skipped) — named
  follow-up `leave-balance-auto-provision`; refusing beats fabricating a balance from nothing.
- **Two independent sufficiency checks (guard + service) could drift if only one is updated.**
  Mitigated: both implement the identical predicate (`bovenwettelijkHours >= hours`) against the
  same schema fields, and `nl-verlof-bovenwettelijk-niet-negatief` (D3) is the audit-time backstop
  that would catch any drift that let a negative balance through regardless.
- **Concurrent sells against the same balance.** The service's belt-and-braces re-check (D2.2/D4.5)
  narrows but does not eliminate a race between two concurrent `settle()` calls reading the same
  balance before either writes; OpenRegister's own save path is the transactional boundary this
  change relies on (no new locking primitive introduced) — a known limitation shared with every
  other imperative balance-mutating service in this codebase (`CompAdjustmentService` has the same
  shape of race on `Employee.grossMonthlySalary`).

## Open Questions

- None blocking. Auto-provisioning, derived hourly rate, and batch settlement are named
  follow-ups; each is additive to this MVP's shape, not a redesign of it.
