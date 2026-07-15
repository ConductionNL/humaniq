---
kind: code
---

# Loonbeslag — court/deurwaarder wage garnishment (POST-TAX, off nettoPay)

## Why

**Verified against HEAD 2026-07-15.** hrmq computes NL gross-to-net (`PayrollCalculator`, pure,
table-driven) and already folds three current-run, post-tax components onto `Payslip.nettoPay`
without ever re-invoking the calculator: sick-pay's doorbetaald loon substitutes the gross
*before* calculation, but retro-adjustments (`retroAdjustment`) and leave-buy-sell
(`leaveBuySell`) are the exact shape this change reuses — `PayrollRunService::generate()` computes
each employee's payslip once, then sums an *already-decided* external fact (an applied
`PayrollAdjustment.deltaNet`, a settled `LeaveTransaction.settledAmount`) into that employee's
component field and folds it into `nettoPay`, never touching `loonheffing`/`premies`/the taxable
wage.

A wage garnishment (derdenbeslag / loonbeslag) is the same shape: a court or deurwaarder orders a
periodic deduction from an employee's NET pay to satisfy a debt, but Dutch law (Wetboek van
Burgerlijke Rechtsvordering art. 475b–475e, and from 2021 the Wet vereenvoudiging beslagvrije voet)
guarantees the employee keeps at least the *beslagvrije voet* — the protected minimum. hrmq has no
representation of this today: nothing models a garnishment order, nothing computes the
floor-clamped deduction, and nothing folds it into a payslip. This change adds exactly that, as a
fourth current-run post-tax component alongside sick-pay/retro-adjustments/leave-buy-sell — never
a fifth code path in `PayrollCalculator`, which stays pure and untouched.

Because a garnishment order is legally sensitive (it exposes an employee's debt situation and a
wrong floor computation is a labour-law violation), the surface is admin/HR-only, and the state
changes that matter (activating a garnishment, marking it settled) go through a guarded controller
endpoint — the exact `PayrollController::mutations()` / `wkrAssess()` shape already in this
codebase (admin/HR 403 gate BEFORE any RBAC resolve, then RBAC-resolve-first, 404 collapsing
unknown/unauthorized) — never a bare `x-openregister-lifecycle` `lifecycleActions` button, which
cannot express "caller must be admin/HR" the way it expresses object-state preconditions.

## What Changes

- **NEW schema `Loonbeslag`** (`lib/Settings/register.d/hr-loonbeslag.json`): creditor/deurwaarder,
  dossier reference, `totalClaim`, the ordered periodic deduction amount, the `beslagvrijeVoet`
  (stored **input** for the MVP — see Non-goals), `employeeId` ($ref Employee), an effective
  period range (`effectiveFrom`/`effectiveTo`), and a **plain** `status` enum (`concept`, `actief`,
  `voldaan`, `ingetrokken`) — deliberately **no** `x-openregister-lifecycle` map, the
  `PayrollRun`/`PayrollAdjustment` precedent (design.md D5): the state changes that matter here
  need a caller-identity check a lifecycle guard's `(object, action, userId)` contract can express
  but that this codebase already chose *not* to lean on for exactly this class of sensitive,
  computed-adjacent write.
- **NEW `Payslip.loonbeslag`** (nullable number, euro-denominated like every other Payslip
  component) + **NEW `Payslip.loonbeslagId`** (nullable `$ref` Loonbeslag) in
  `lib/Settings/register.d/hr-objects.json` — the fourth current-run post-tax fold, populated by
  `PayrollRunService::generate()` exactly like `retroAdjustment`/`leaveBuySell`; `null` when no
  active garnishment applies, so an unaffected payslip stays byte-identical to today's shape.
- **`PayrollRunService::generate()`** gains a garnishment fold, applied **after**
  retro-adjustment and leave-buy-sell (against the fully-folded nettoPay-so-far, the actual figure
  the employee is about to receive this period): `deduction = min(orderedAmount, max(0,
  nettoPaySoFar − beslagvrijeVoet))`, cents-internal exactly like the existing folds, never
  re-invoking `PayrollCalculator`. Idempotent: recalculating a draft run re-derives the same figure
  from scratch every time — no accumulator, no drift.
- **NEW `LoonbeslagController`** (`lib/Controller/LoonbeslagController.php`): `activate()`
  (`concept` → `actief`, the verification step — a second HR/admin principal confirms the order
  before deductions start), `settle()` (`actief` → `voldaan`), `withdraw()` (`concept`/`actief` →
  `ingetrokken`) — every method the SAME two-gate shape as `PayrollController::mutations()` /
  `wkrAssess()`: admin/HR membership check first (403, before any RBAC resolve — an unauthorized
  caller cannot even probe existence), then RBAC-resolve the posted id through ObjectService
  (404 collapsing unknown/unauthorized).
- **NEW `lib/Standards/Checks/NlLoonbeslagChecks.php`** (auto-discovered `CheckProvider`, no
  registration wiring needed — `RuleEngine::providers()` globs the directory): the
  `nl-loonbeslag-beslagvrije-voet-floor` predicate flags any Payslip whose garnishment deduction
  left `nettoPay` below the referenced `Loonbeslag.beslagvrijeVoet` (vacuous when `loonbeslagId` is
  null), plus `nl-loonbeslag-single-active` flagging more than one `actief` Loonbeslag with
  overlapping effective ranges for the same employee (the MVP's single-active-beslag assumption,
  made machine-checkable rather than a silent doc note) — `RuleAuditService::audit()` context
  enrichment `payroll.loonbeslagenById` (the `payroll.runsById` precedent).
- **Manifest**: `Loonbeslagen` index + `LoonbeslagDetail` (page actions "Verifiëren en activeren" /
  "Markeer voldaan" / "Intrekken" wired to the three guarded endpoints, NOT `lifecycleActions`) —
  admin/HR-only surface, same posture as `WkrAssessmentDetail`.

### Non-goals (named fast-follows and exclusions)

- **`beslagvrijeVoet` computation** — the protected minimum is a stored **input** on the
  `Loonbeslag` record for the MVP. Computing it from income + household composition per the *Wet
  vereenvoudiging beslagvrije voet* (partner income, co-residents, housing costs, health-insurance
  premium) is a named fast-follow; the MVP trusts the value the deurwaarder/court communicates.
- **Multiple concurrent garnishments / preferente-vordering ordering** — the MVP handles at most
  one `actief` Loonbeslag per employee per period (enforced by `nl-loonbeslag-single-active`,
  design.md D4). Priority ordering across simultaneous garnishments (alimony precedes tax debt
  precedes ordinary debt, per BW art. 475d) is a named fast-follow.
- **`PayrollCalculator` is never touched, never invoked for this component** — the deduction is
  post-net, computed entirely in `PayrollRunService`, the same non-goal boundary retro-adjustments
  and leave-buy-sell already drew.

## Capabilities

### New Capabilities

- `loonbeslag`: the `Loonbeslag` schema, the floor-clamped post-tax payslip fold, the guarded
  activate/settle/withdraw endpoints (admin/HR-only, RBAC-resolve-first), the
  `NlLoonbeslagChecks` corpus enforcement, and the admin/HR-only manifest surface.

### Modified Capabilities

<!-- none — PayrollCalculator, PayrollRun, and every other engine surface are read, not modified;
     Payslip gains two new nullable fields, the existing shape is otherwise untouched -->

## Impact

- `lib/Settings/register.d/hr-loonbeslag.json` — NEW (`Loonbeslag` schema).
- `lib/Settings/register.d/hr-objects.json` — `Payslip.loonbeslag` + `Payslip.loonbeslagId` NEW
  nullable fields.
- `lib/Service/PayrollRunService.php` — garnishment fold (active-beslag index, floor formula,
  payload merge), folded after retro-adjustment/leave-buy-sell.
- `lib/Controller/LoonbeslagController.php` — NEW; `appinfo/routes.php` +3 routes before the SPA
  catch-all.
- `lib/Standards/Checks/NlLoonbeslagChecks.php` — NEW; `lib/Service/RuleAuditService.php` — context
  enrichment (`payroll.loonbeslagenById`); `lib/Standards/rules/*.json` — 2 new rule ids
  (`nl-loonbeslag-beslagvrije-voet-floor`, `nl-loonbeslag-single-active`).
- `src/manifest.json` — `Loonbeslagen` index + `LoonbeslagDetail` pages, admin/HR-only surface;
  `npm run check:manifest` passes.
- `tests/Unit/Service/PayrollRunServiceTest.php` — garnishment fold cases (clamped, unclamped,
  idempotent recompute); `tests/Unit/Standards/NlLoonbeslagChecksTest.php` — NEW;
  `tests/Unit/Controller/LoonbeslagControllerTest.php` — NEW.
- `README.md` — beslagvrije-voet-is-an-input limitation + single-active-beslag MVP scope note.
