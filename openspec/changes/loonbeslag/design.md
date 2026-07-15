# Design — loonbeslag

## Context

**Verified against HEAD 2026-07-15.** Read read-only, directly grounding this design:

- `lib/Payroll/PayrollCalculator.php` — pure, stateless, zero-NC-dependency gross-to-net chain
  (`calculate(CalculationInput, TaxTables): CalculationResult`); `nettoPayCents = tvl −
  loonheffingCents` is its LAST step. Nothing about it changes; loonbeslag is post-net and never
  invokes it.
- `lib/Service/PayrollRunService.php::generate()` — per employee: `PayrollCalculator::calculate()`
  runs once, then the retro-adjustment fold (`retroAdjustmentFields()`, sums every `applied`
  `PayrollAdjustment.deltaNet` settling THIS period) and the leave-buy-sell fold
  (`leaveBuySellFields()`, sums every `settled` `LeaveTransaction.settledAmount` settling THIS
  period, signed by `transactionType`) each read an *already-decided* external fact and add it onto
  `nettoPay`, in that order (leave-buy-sell folds "on top of any retro-adjustment delta"). Neither
  ever re-invokes the calculator. This is the exact shape loonbeslag reuses as a fourth fold.
- `lib/Settings/register.d/hr-objects.json` — `Payslip.nettoPay`/`grossPay`/`retroAdjustment`/
  `leaveBuySell` are **`type: number`, euro-denominated** (not cents — `PayrollRunService::euros()`
  converts internal integer-cents to a 2-decimal euro float before every write). `loonbeslag`
  follows the identical convention: euro `number`, nullable, internal arithmetic in integer cents.
- `lib/Settings/register.d/hr-retro.json` (`PayrollAdjustment`) — the closest schema precedent: a
  financial delta against an employee, `status` is a **plain enum** (`draft`/`applied`)
  "deliberately no `x-openregister-lifecycle` map ... driving `lifecycleActions` here would invent
  a transition the backend does not guard" (its own doc comment). `PayrollRun` draws the identical
  line (`payroll-core-engine` design.md D6). `Loonbeslag.status` follows the same rule for the same
  reason, sharpened below (D5).
- `lib/Controller/PayrollController.php::mutations()` / `::wkrAssess()` — the admin/HR-gated
  pattern: `isAdminOrHr()` (an `IGroupManager::isAdmin()` check) returns 403 **before** any
  ObjectService resolve (an unauthorized caller cannot probe existence), then the posted id is
  RBAC-resolved (`authorizeRun()`/`authorizeAssessment()`-style, 404 collapsing unknown/
  unauthorized), only then does the service run. `LoonbeslagController` reuses this exact two-gate
  shape for `activate()`/`settle()`/`withdraw()`.
- `lib/Lifecycle/NoSelfApprovalGuard.php` — denies a transition when
  `$object['employeeId'] === $userId` (the claiming employee may not approve/reject their own
  claim). Considered for `Loonbeslag`'s activation step and explicitly **not** reused — see D5.
- `lib/Standards/Checks/CheckProvider.php` — a `checks(): array<objectType, array<ruleId,
  callable>>` interface, **auto-discovered** by `RuleEngine::providers()` (grep confirms zero
  manual registration anywhere in `Application.php`); dropping
  `lib/Standards/Checks/NlLoonbeslagChecks.php` implementing it is sufficient for the RuleEngine to
  pick it up.
- `lib/Standards/Checks/NlEngineChecks.php` + `RuleAuditService`'s `payroll.runsById` context
  enrichment — the precedent for `payroll.loonbeslagenById` (D6).

## Goals / Non-Goals

**Goals:** model a garnishment order; enforce the beslagvrije-voet floor as a hard, machine-checked
rule; fold the floor-clamped deduction into the current-run payslip exactly like
retro-adjustments/leave-buy-sell; gate the sensitive state changes behind an admin/HR + RBAC
double-check, never a bare lifecycle button.

**Non-Goals (binding, from the proposal):** computing `beslagvrijeVoet` from income/household
composition (Wet vereenvoudiging beslagvrije voet) — stored input only; multiple concurrent
garnishments / preferente-vordering priority ordering — MVP is one `actief` Loonbeslag per
employee-period; any change to `PayrollCalculator`.

## Decisions

### D1 — `Loonbeslag` is a new schema, not a `PayrollAdjustment` variant

A `PayrollAdjustment` corrects a *sealed prior payslip* (a one-off delta, diffed and settled once).
A `Loonbeslag` is an *ongoing order* spanning many periods until the claim is satisfied or the
order is withdrawn — a fundamentally different lifecycle (recurring vs. one-shot) and a different
set of legally-mandated fields (creditor, dossier reference, the beslagvrije voet). Reusing
`PayrollAdjustment` would conflate "a correction to what already happened" with "an ongoing
third-party claim on what happens next"; a dedicated schema keeps both honest.

Fields (`lib/Settings/register.d/hr-loonbeslag.json`, fragment `hr-loonbeslag`):

| Field | Type | Notes |
|---|---|---|
| `employeeId` | string, format uuid, `$ref: Employee` | the garnished employee |
| `creditor` | string, required | the creditor/deurwaarder name |
| `dossierRef` | string, required | the deurwaarder's dossier/case reference — the idempotency-adjacent human key an HR user matches against the court order |
| `totalClaim` | number, required, minimum 0 | the total ordered claim, euro |
| `orderedAmount` | number, required, minimum 0 | the periodic deduction amount the order specifies, euro — the un-clamped figure |
| `beslagvrijeVoet` | number, required, minimum 0 | the protected minimum, euro — **stored input for the MVP** (Non-Goals) |
| `status` | string, enum `[concept, actief, voldaan, ingetrokken]`, default `concept` | plain enum, no `x-openregister-lifecycle` (D5) |
| `effectiveFrom` | string, format date, required | first period the deduction may apply |
| `effectiveTo` | string, format date, nullable | last period, or null while open-ended |
| `activatedBy` / `activatedAt` | string / date-time, nullable | stamped by `LoonbeslagController::activate()` |
| `settledBy` / `settledAt` | string / date-time, nullable | stamped by `LoonbeslagController::settle()` |
| `withdrawnBy` / `withdrawnAt` / `withdrawnReason` | string / date-time / string, nullable | stamped by `LoonbeslagController::withdraw()` |

### D2 — The floor formula, computed in `PayrollRunService`, never in `PayrollCalculator`

For the one `actief` Loonbeslag covering an employee's period (D4 selection), after the existing
retro-adjustment and leave-buy-sell folds have produced `nettoPaySoFar` (cents):

```
deductionCents = min(orderedAmountCents, max(0, nettoPaySoFarCents − beslagvrijeVoetCents))
Payslip.nettoPay = euros(nettoPaySoFarCents − deductionCents)
Payslip.loonbeslag = deductionCents === 0 ? null : euros(deductionCents)
```

This is the hard rule stated as a requirement (REQ-BESLAG-002): the deduction can never push net
pay below the floor, by construction — `max(0, ...)` clamps a would-be-negative headroom to zero
(nothing deducted when the employee is already at or below the voet from other components), and
`min(orderedAmount, ...)` never deducts more than the order specifies even when headroom exceeds
it. Arithmetic is integer cents internally (converting `Loonbeslag.orderedAmount`/
`beslagvrijeVoet` euro floats to cents on read, exactly like `appliedRetroAdjustmentsByEmployeeId()`
converts `deltaNet`), euro floats only at the Payslip write boundary — the same convention as every
other component field.

Present-but-zero is represented as `null` (not `0.00`) so an untouched payslip with no headroom
stays visually/semantically distinct from one with a real, non-zero deduction — the same
null-means-absent convention `retroAdjustment`/`leaveBuySell` already use.

### D3 — Fold order: loonbeslag applies AFTER retro-adjustment and leave-buy-sell

`generate()`'s existing fold order is retro-adjustment, then leave-buy-sell "on top of" it
(`leaveBuySellFields($leaveBuySellCents, $result->nettoPayCents + $retroAdjustmentCents)`).
Loonbeslag is appended as a **third and final** fold, computed against the fully-folded
`nettoPay` (engine net + retroAdjustment + leaveBuySell): the beslagvrije voet protects the
employee's actual take-home this period, not an intermediate figure that a nabetaling or a
leave-payout would still inflate past. Folding loonbeslag *before* the other two would let a
same-period nabetaling silently widen the garnishable headroom in a way the deurwaarder's order was
never computed against; folding it last is the only order that keeps the floor meaningful.

### D4 — Active-beslag selection is pure and idempotent; single-active is a checked MVP assumption

`PayrollRunService` gains `activeLoonbeslagenByEmployeeId(period): array<employeeId, Loonbeslag>`
(the `openSickCasesByEmployeeKey()`/`appliedRetroAdjustmentsByEmployeeId()` precedent): loads all
`Loonbeslag` objects, keeps `status === 'actief'` AND `effectiveFrom ≤ period-end` AND
(`effectiveTo` null OR `≥ period-start`) (the existing `coversPeriod()` helper, reused verbatim),
keyed by `employeeId`. No accumulator anywhere: every `generate()` call — including a
`--recalculate` — re-derives `deductionCents` from `orderedAmount`/`beslagvrijeVoet`/the
freshly-computed `nettoPaySoFar`, so recalculating a draft run is idempotent per
(loonbeslagId, period) by construction, the same reasoning that makes the retro-adjustment/
leave-buy-sell folds idempotent.

The MVP assumption "one active beslag per employee-period" is **not** left as a silent doc note:
`NlLoonbeslagChecks::checks()['Loonbeslag']['nl-loonbeslag-single-active']` flags any employee with
more than one `actief` Loonbeslag whose effective ranges overlap (context-enriched with
`payroll.loonbeslagenById`, D6) — so a data-entry mistake that would silently violate the MVP's own
stated scope becomes a mandatory audit violation instead of an unenforced assumption (the
orphaned-capability class of defect this app's own quality gates already watch for). When the
selection encounters more than one active match for an employee/period despite the check, D4's
`activeLoonbeslagenByEmployeeId()` picks the earliest `effectiveFrom` deterministically and the
outcome names the employee — never silently drops or double-deducts.

### D5 — `Loonbeslag.status` stays a plain enum; sensitive transitions go through a guarded controller, not `x-openregister-lifecycle`

Two lifecycle-guard shapes exist in this codebase: `NoSelfApprovalGuard` (stateless,
`object['employeeId'] !== userId` — separation between the *claiming* employee and their
*approver*) and `PayrollRunApprovedGuard` (container-resolved, checks a *referenced object's*
status). Neither expresses the rule this change actually needs: "the acting user must hold
admin/HR group membership" is a caller-*role* check, not an object-state or claimant-identity
check, and `NoSelfApprovalGuard`'s specific mechanism is a poor fit besides — a `Loonbeslag` is
admin/HR-entered data about a third-party court order, not an employee's own claim, so
"employeeId !== userId" would almost always trivially pass and add no real protection (an HR
admin activating a colleague's garnishment is not a self-approval scenario the way an employee
submitting their own expense claim is).

`PayrollRun`/`PayrollAdjustment` already answered this exact question for this exact class of
surface ("sensitive, computed-adjacent write, plain enum, guarded controller"): `Loonbeslag.status`
carries **no** `x-openregister-lifecycle` map. `LoonbeslagController` — the SAME two-gate shape as
`mutations()`/`wkrAssess()` — owns every transition:

- `activate()` (`concept → actief`): `isAdminOrHr()` 403 gate, then RBAC-resolve the posted
  `loonbeslagId` (404 unknown/unauthorized), then write `status: actief, activatedBy: $uid,
  activatedAt: now()`. This IS the "verification" step named in the feature brief — a second
  admin/HR principal confirms the order before any deduction starts; it is enforced by the same
  admin/HR gate as everything else here, not by a bespoke self-approval check.
- `settle()` (`actief → voldaan`): same two-gate shape; refuses (400) unless `status === 'actief'`.
- `withdraw()` (`concept`/`actief` → `ingetrokken`, terminal): same two-gate shape; requires a
  `reason` string, stamped as `withdrawnReason`.

Manifest: `LoonbeslagDetail`'s actions are three `api-call` page actions against these endpoints —
NOT a `lifecycleActions` widget (there is no `x-openregister-lifecycle` map to render transitions
from, and even if there were, the widget cannot express "and the caller must be admin/HR").

### D6 — `nl-loonbeslag-beslagvrije-voet-floor`: the machine-checkable floor enforcement

`lib/Standards/Checks/NlLoonbeslagChecks.php` (auto-discovered `CheckProvider`, zero registration
wiring — D-context):

- `nl-loonbeslag-beslagvrije-voet-floor` (Payslip): vacuous when `loonbeslagId` is null (no
  garnishment on this payslip — nothing to check). Else resolves the referenced `Loonbeslag` via
  `$context['payroll']['loonbeslagenById']` (enriched in `RuleAuditService::audit()`, the
  `payroll.runsById` precedent) and asserts cents-exact `nettoPay ≥ Loonbeslag.beslagvrijeVoet`
  (`NlPayrollChecks::centsEqual`-style comparison, `≥` not `=` — the employee may legitimately keep
  more than the floor when the order's `orderedAmount` was smaller than the available headroom).
  Vacuous also when the referenced Loonbeslag cannot be resolved (dangling reference — a different,
  pre-existing class of data-integrity problem, not this rule's job).
- `nl-loonbeslag-single-active` (Loonbeslag): vacuous when the employee has zero or one `actief`
  Loonbeslag; else flags every Loonbeslag in an overlapping-effective-range group of two or more
  `actief` records for the same `employeeId` (D4).

Both rule ids are declared in the corpus (`lib/Standards/rules/*.json`, a new `loonbeslag.json`
fragment) with the usual `RuleCatalogue` metadata (mandatory, description, legal basis reference)
so `occ hrmq:rules:audit` picks them up fleet-wide the moment this change lands, exactly like
`nl-engine-table-version`/`nl-engine-output-consistency` did for the engine chain.

### D7 — `beslagvrijeVoet` is an input for the MVP; computing it is a named follow-up

The *Wet vereenvoudiging beslagvrije voet* (in force since 2021) defines the protected minimum as a
function of the applicable bijstandsnorm, the employee's living situation (alone/with
partner/co-residents), housing costs, and health-insurance premium, normally computed by the UWV/
Belastingdienst's "Rekentool beslagvrije voet" or communicated directly by the deurwaarder on the
garnishment order. This change does **not** implement that computation: `beslagvrijeVoet` is a
required, HR-entered field on `Loonbeslag`, trusted as the authoritative figure from the order
itself (the deurwaarder is legally required to state it on the beslagbrief). A follow-up change can
add a computed-voet path (income/household-composition inputs → the statutory formula) without
touching this change's fold mechanics — `beslagvrijeVoet` would simply gain a second, computed
source feeding the same field the floor formula already reads.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `Loonbeslag` record shape | declarative schema | ordinary OpenRegister object, no computation |
| Floor-clamped deduction | **imperative pure arithmetic** in `PayrollRunService` | `min(ordered, max(0, headroom))` over already-computed facts — the exact class of "read a decided external fact, fold it" `retroAdjustment`/`leaveBuySell` already establish; not a schema-declarative concern |
| Garnishment persistence | imperative service via ObjectService | cross-object read (active-beslag index) + Payslip write, the netpay/glpost precedent |
| Activate/settle/withdraw | guarded controller (imperative), NOT `x-openregister-lifecycle` | the guard needed is caller-role, not object-state (D5) |
| Detail/index pages | declarative manifest (`actions`, object-list) | ADR-031 default |
| Floor + single-active enforcement | corpus rule + `CheckProvider` predicate | the app's established exception, auto-discovered |

## Seed Data (ADR-001)

One seeded `Loonbeslag` for the existing seeded employee (3.800/wit/permanent, per the
`payroll-core-engine` anchor): `status: actief`, `orderedAmount` set high enough (e.g. €800) that it
exceeds the headroom above a `beslagvrijeVoet` set close to the employee's engine-computed net pay
(e.g. €2.950 against a ~€3.081 anchor nettoPay) — this exercises the **clamped** branch of the
floor formula in the dev-container gate, not just the trivial unclamped case. The dev-container
verification gate: `occ hrmq:payroll:run --period 2026-08 --recalculate` against the seeded
employee + this Loonbeslag, confirm the generated Payslip's `loonbeslag` equals the clamped
(not the full ordered) amount and `nettoPay` equals exactly `beslagvrijeVoet`, then
`occ hrmq:rules:audit` / `hrmq:payroll:verify --period 2026-08` exits 0 (the floor check holds by
construction).

## Risks / Trade-offs

- **`beslagvrijeVoet` as a trusted input is only as good as the HR data entry.** Mitigated by
  `nl-loonbeslag-beslagvrije-voet-floor` catching any *computation* error post-hoc; it cannot catch
  a wrong *input* value — the same honesty boundary `payroll-core-engine`'s disclaimer already
  draws for the tax tables themselves.
- **Single-active-beslag MVP scope is a real limitation**, not just a simplification: a genuinely
  concurrent second garnishment (e.g. alimony arriving mid-order on top of an existing tax-debt
  beslag) is exactly the priority-ordering case this MVP defers. `nl-loonbeslag-single-active` at
  least makes the boundary loud rather than silent.
- **Admin/HR gate reuses the plain Nextcloud admin-group check** (`IGroupManager::isAdmin()`), the
  same `isAdminOrHr()` precedent `PayrollController` already carries the "no dedicated HR group
  exists yet" caveat for — introducing one is a fast-follow shared across every admin/HR-gated
  endpoint in this app, not specific to loonbeslag.

## Open Questions

- None blocking. `beslagvrijeVoet` computation and multi-beslag priority ordering are named
  fast-follows (Non-Goals); a dedicated Nextcloud "HR" group (vs. reusing the admin group) is a
  shared fast-follow across every `isAdminOrHr()`-gated endpoint in this app.
