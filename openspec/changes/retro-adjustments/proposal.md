---
kind: code+config
depends_on: []   # payroll-core-engine + payroll-core-schema are merged/archived at HEAD — consumed, not pending
---

# Retroactive payroll adjustments (TWK) + year-transition

## Why

The payroll engine (`payroll-core-engine`, archived 2026-07-14) computes a period's payslips from
the verified `lib/Standards/tables/nl-2026.json` corpus and seals them into a run whose
`engineVersion`/`calculatedAt` are stamped and whose non-`draft` states refuse recalculation
(glpost/netpay consumed them — recomputing booked truth is forbidden). But real payroll inputs
change **after** a period is sealed: a backdated raise agreed in March that takes effect from
January, a sick day corrected two months late, a contract type fixed retroactively. Dutch payroll
settles these with **terugwerkende kracht herrekening** (TWK): the affected *past* payslip is
recomputed against the tax year that governed it, and the **delta** (not the recomputed slip) is
paid or clawed back in the **current** open period as a nabetaling/terugvordering line — the sealed
historical payslip is never rewritten (it was filed in a loonaangifte and posted to the GL).

Nothing in hrmq does this yet. Today the only correction path is editing a draft run, which is
impossible once a run is `approved`/`posted`/`paid`. This change adds the missing capability: a
`PayrollAdjustment` object that carries the computed delta, a `RetroAdjustmentService` that recomputes
the original period with corrected inputs against **the original period's tax year** and diffs it
against the stored payslip, and the surfacing of that delta as a component of the current run — plus a
documented year-transition procedure so the annual roll to a new `nl-YYYY.json` is safe.

## What Changes

- **NEW schema `PayrollAdjustment`** (`lib/Settings/register.d/hr-retro.json`, a new fragment) —
  links an original sealed period + employee (`originalPeriod`, `originalPayrollRunId` `$ref`,
  `originalPayslipId` `$ref`, `employeeId` `$ref`), records the corrected input snapshot
  (`correctionType`, `correctedGrossMonthlySalary`, `correctionRef` — the idempotency key), the
  **computed delta** in euro-denominated number fields mirroring Payslip
  (`deltaGross`, `deltaLoonheffing`, `deltaNet`, `deltaWerknemersverzekeringen`, `deltaZvw`,
  `deltaVolksverzekeringen`, `deltaVakantiegeldReserved`), the `engineVersion` used for the recompute
  (= the original period's table id), the `settlementPeriod` + `settlementPayrollRunId` `$ref` it
  settles into, `settlementLine` (`nabetaling`/`terugvordering`), a `status` enum
  (`draft` → `applied`), and `calculatedAt`. No `x-openregister-lifecycle` (`status` is a plain enum,
  the PayrollRunDetail precedent — never invent a transition the backend does not guard).
- **NEW nullable field `retroAdjustment`** on `Payslip` (`lib/Settings/register.d/hr-objects.json`) —
  the summed retro delta settled into this payslip's period; the current run's payslip carries it as a
  distinct nabetaling/terugvordering component and `nettoPay` includes it. Null on payslips with no
  settled adjustment. The **sealed historical** payslip is never touched.
- **NEW `lib/Service/RetroAdjustmentService.php`** — `adjustFor(originalPeriod, employeeId,
  correctionRef, correctedInput, settlementPeriod?)`: resolve the original sealed Payslip via its
  `payrollRunId`; derive `nl-{originalYear}` and load its `TaxTables` (refuse when the historical
  table file is absent — the same-tax-year MVP boundary, below); recompute with `PayrollCalculator`
  using the corrected inputs; diff **recomputed − stored** into a cents-exact delta; upsert a
  `PayrollAdjustment` keyed idempotently on `(originalPeriod, employeeId, correctionRef)`. Pure
  ObjectService writes (the PayrollRunService idiom); **never** writes the sealed payslip/run.
- **Delta surfacing** — `PayrollRunService.generate()` (extended) sums `applied` PayrollAdjustments
  whose `settlementPeriod` equals the draft run's period into each employee's payslip `retroAdjustment`
  component (and into `nettoPay`), so the delta appears in the **current** run, never in history.
  `hrmq:payroll:adjust --apply` (or the endpoint) flips a draft adjustment to `applied` and stamps its
  `settlementPayrollRunId`.
- **NEW occ `hrmq:payroll:adjust`** (`lib/Command/PayrollAdjustCommand.php`) —
  `--original-period YYYY-MM --employee <id> --correction-ref <ref>
  [--gross <amount>] [--settlement-period YYYY-MM] [--apply]`; prints the computed delta and the
  idempotency outcome. **NEW occ `hrmq:payroll:year-transition`**
  (`lib/Command/PayrollYearTransitionCommand.php`) — `--year YYYY`: the year-transition preflight —
  asserts `lib/Standards/tables/nl-YYYY.json` exists (fail loudly otherwise), confirms there is no
  mutable "active tax year" global (the run derives it from its period, so the roll is data-only:
  ship `nl-YYYY.json`), and confirms the immutable-stamp guard. Both registered in `appinfo/info.xml`.
- **NEW guarded endpoint** `POST /api/payroll/adjust` (`PayrollController::adjust`, `#[NoAdminRequired]`)
  — mirrors `PayrollController::calculate`'s no-admin-idor guard: resolve the posted `adjustmentId`
  (and its `originalPayrollRunId`) through ObjectService under the caller's ambient RBAC BEFORE any
  recompute (unknown/unauthorized → 404); refuse recompute of an `applied` adjustment (400). ONE
  endpoint, no CRUD (ADR-022).
- **Manifest** — `PayrollRunDetail` gains an `open-form` action "Correctie boeken (TWK)" that creates
  a draft `PayrollAdjustment` prefilled with `originalPayrollRunId: "@objectId"` (onSuccessRoute
  `PayrollAdjustmentDetail`); a new `PayrollAdjustmentDetail` page with an `api-call` action
  "Herrekenen" (`POST /api/payroll/adjust`, `params: {adjustmentId: "@objectId"}`, confirm) plus a
  stat block on the delta. `npm run check:manifest` passes.
- **NEW `lib/Standards/Checks/NlRetroChecks.php`** + one rule `nl-retro-adjustment-consistency`
  (PayrollAdjustment) in `lib/Standards/rules/payroll.json` — the corpus is the adjustment's
  self-check (the `payroll-core-engine` D7 precedent): vacuous when `engineVersion` is null; else
  asserts the delta equals recomputed − stored cents-exact given the recorded corrected input. ONE
  static severity (`mandatory`) per the rule-engine constraint (design D-static-severity).
- **GOLDEN TESTS** — `RetroAdjustmentServiceTest` (mocked ObjectService: recompute against the
  original tax year, cents-exact delta, idempotency by correctionRef, sealed-payslip untouched,
  draft-original refusal), `NlRetroChecksTest`, and a same-tax-year-boundary test (a 2025 original
  refuses with the historical-tables message while 2026 computes).

### Non-goals (named follow-ups)

- **Multi-year historical tax tables** — recompute needs the ORIGINAL period's table
  (`nl-2025.json`, `nl-2024.json`, …). Only `nl-2026.json` exists at HEAD, so the MVP scopes to
  **same-tax-year** corrections (an original period in the same tax year as an available table) and
  refuses a cross-year correction with a clear message. Seeding historical `nl-YYYY.json` corpora is
  the named follow-up (`retro-multi-year-tables`).
- **Cumulative/VCR recompute** (voortschrijdend cumulatief rekenen) — the delta is a period-vs-period
  recompute, not a year-to-date cumulative reconciliation (inherits the engine's no-VCR limitation).
- **Automatic loonaangifte correctie-berichten** — a TWK delta may require a correctie on the already
  filed loonaangifte; the filing lifecycle's `corrigeren` transition already exists as the manual
  route; automated correction-message generation stays out of scope.
- **Bijzonder-tarief on the nabetaling** — a nabetaling is often taxed at bijzonder tarief; the MVP
  settles the delta as computed and names bijzonder tarief as a follow-up (the engine's bijzonder
  tarief is itself a non-goal).

## Capabilities

### New Capabilities

- `retro-adjustments`: the `PayrollAdjustment` delta model, the `RetroAdjustmentService` that
  recomputes a sealed period against its own tax year and diffs a cents-exact delta idempotently by
  `correctionRef`, the surfacing of that delta as a current-run payslip component (never a history
  mutation), the occ `hrmq:payroll:adjust` + `hrmq:payroll:year-transition` commands, the guarded
  `POST /api/payroll/adjust` endpoint + run/adjustment pages, and the `nl-retro-adjustment-consistency`
  self-check with its golden tests.

### Modified Capabilities

<!-- none — payroll-core-engine/-schema are consumed (their run/payslip stamps + calculator), not modified -->

## Impact

- `lib/Settings/register.d/hr-retro.json` — NEW (PayrollAdjustment); `lib/Settings/register.d/hr-objects.json`
  — Payslip gains the nullable `retroAdjustment` field (+ version bump).
- `lib/Service/RetroAdjustmentService.php` — NEW (ObjectService idiom per PayrollRunService);
  `lib/Service/PayrollRunService.php` — `generate()` folds applied adjustments into the current run.
- `lib/Command/PayrollAdjustCommand.php`, `lib/Command/PayrollYearTransitionCommand.php` — NEW;
  `appinfo/info.xml` +2 `<command>` entries.
- `lib/Controller/PayrollController.php` — +`adjust`; `appinfo/routes.php` +1 route (before catch-all).
- `lib/Standards/Checks/NlRetroChecks.php` — NEW; `lib/Standards/rules/payroll.json` +1 rule;
  `lib/Standards/RuleCatalogue.php` — VERSION bump.
- `src/manifest.json` — PayrollRunDetail open-form action + new PayrollAdjustmentDetail page;
  `npm run check:manifest` passes.
- `tests/Unit/Service/RetroAdjustmentServiceTest.php`, `tests/Unit/Standards/NlRetroChecksTest.php` — NEW.
- `README.md` — TWK section: same-tax-year boundary, multi-year follow-up, sealed-immutability note.
- Consumes (merged at HEAD): `payroll-core-engine` (`PayrollCalculator`, `TaxTables`,
  `PayrollRunService`, the run stamps) and `payroll-core-schema` (Payslip/PayrollRun fields).
