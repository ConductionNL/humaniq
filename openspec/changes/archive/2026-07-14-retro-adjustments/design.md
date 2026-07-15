# Design — retro-adjustments

## Context

**Verified against HEAD 2026-07-14.** Consumes the merged `payroll-core-engine` /
`payroll-core-schema` (both archived): the pure `lib/Payroll/PayrollCalculator.php`
(`calculate(CalculationInput, TaxTables): CalculationResult`, integer cents, zero NC deps), the
versioned `lib/Payroll/TaxTables.php` loader (`TaxTables::load('nl-2026')`, derives from the run
period), and `lib/Service/PayrollRunService.php` — which already:

- derives the tax-year table id from the run period (`$tableId = 'nl-'.substr($period, 0, 4)`),
- stamps `engineVersion = tables->id()` + `calculatedAt` on the run at generation,
- **refuses to (re)calculate any run whose `status` is not `draft`** (`runFor()` /
  `recalculateRun()` return `refused-not-draft`) — so approved/posted/paid runs and their payslips
  are already sealed against recomputation,
- writes via container-resolved `OCA\OpenRegister\Service\ObjectService` with `_rbac: false` after
  the controller has RBAC-resolved the target (the no-admin-idor pattern in
  `PayrollController::calculate`).

Register facts at HEAD (`lib/Settings/register.d/hr-objects.json`): `Payslip` carries
`payrollRunId` (`$ref PayrollRun`, nullable — null on hand-entered slips), `employeeId`, `period`,
and the full component set (`grossPay`, `loonheffing`, `volksverzekeringen`,
`werknemersverzekeringen`, `zvw`, `nettoPay`, `vakantiegeldReserved`, `arbeidskorting`);
`PayrollRun` carries `status` (`draft|approved|posted|paid`), `engineVersion`, `calculatedAt`.
Only `lib/Standards/tables/nl-2026.json` exists — no historical corpora.

## Goals / Non-Goals

**Goals:** a `PayrollAdjustment` that carries a **computed delta, not a rewritten payslip**;
recompute of the original period against **the tax year that governed it**; idempotency by
`correctionRef`; the delta surfaced as a **current-run** payslip component (nabetaling /
terugvordering); the sealed historical payslip/run left byte-untouched; a documented, safe
year-transition; the corpus as the adjustment's self-check.

**Non-Goals (binding, from the proposal):** multi-year historical tax tables (same-tax-year MVP,
follow-up `retro-multi-year-tables`), VCR/cumulative recompute, automated loonaangifte
correctie-berichten (the filing `corrigeren` transition is the manual route), bijzonder tarief on
the nabetaling, and any new PayrollRun lifecycle/guard wiring (`status` stays a plain enum).

## Decisions

### D1 — A PayrollAdjustment models a DELTA; the sealed payslip is immutable

The correction never rewrites the filed payslip. `RetroAdjustmentService` **reads** the stored
Payslip for `(originalPeriod, employee)` (resolved via its `payrollRunId`), recomputes an
alternative result with the corrected inputs, and stores only the **difference** on a new object:

```
delta.<component> = recomputed.<component>Cents − stored.<component>Cents   (integer cents, exact)
```

for gross / loonheffing / net / werknemersverzekeringen / zvw / volksverzekeringen /
vakantiegeldReserved. The original Payslip and its PayrollRun are **never** passed to
`saveObject`/`deleteObject`. This is why an adjustment can exist against an `approved`/`posted`/`paid`
run that `PayrollRunService` itself refuses to recompute — the sealed truth stays sealed; the delta
is new, separate data.

### D2 — Recompute uses the ORIGINAL period's tax year (same-tax-year MVP)

A 2025 correction must use the 2025 tables, not 2026 — the schijven, kortingen and premiepercentages
differ every year. `RetroAdjustmentService` derives `tableId = 'nl-'.substr(originalPeriod, 0, 4)`
(the exact PayrollRunService idiom) and calls `TaxTables::load($tableId)`. When that file is absent
(`\RuntimeException` from the loader) the service **refuses** with a clear message
(`historical-tables-missing: recompute of {originalPeriod} needs {tableId}.json — same-tax-year MVP,
multi-year is a follow-up`) rather than silently recomputing against the wrong year. Because only
`nl-2026.json` ships at HEAD, the executable MVP is corrections whose original period is in a year
for which a table exists (2026). Seeding `nl-2025.json` etc. is the follow-up
`retro-multi-year-tables`; no code change is needed then — it is a data-only drop, exactly like the
year-transition (D6). The recorded `engineVersion` on the adjustment is `tables->id()`, so every
delta is traceable to the exact parameter file that produced it.

### D3 — Idempotent by (originalPeriod, employeeId, correctionRef)

`correctionRef` is the caller-supplied stable key for one correction event (e.g. a source-document
id or `backdated-raise-2025-11`). `adjustFor()` probes existing PayrollAdjustments for a match on
`(originalPeriod, employeeId, correctionRef)` (the PayrollRunService probe-before-create pattern):
present → recompute in place (update the same object); absent → create. Re-running the same
`hrmq:payroll:adjust` invocation therefore produces exactly one adjustment and never double-counts a
correction into the current run.

### D4 — The delta is a component of the CURRENT run, never a history mutation

Settlement lands the delta in the open period, not the past one:

- `PayrollAdjustment` carries `settlementPeriod` (default = the current open draft run's period) and,
  once applied, `settlementPayrollRunId`; `settlementLine` is `nabetaling` (positive net delta) or
  `terugvordering` (negative).
- `Payslip` gains a nullable `retroAdjustment` component. When `PayrollRunService.generate()` builds
  the draft run for period P, it sums every **`applied`** PayrollAdjustment whose
  `settlementPeriod == P` for that employee into the payslip's `retroAdjustment` and folds it into
  `nettoPay` — so the money moves in P's payslip.
- `hrmq:payroll:adjust --apply` (or the endpoint's apply mode) flips `status: draft → applied` and
  stamps `settlementPayrollRunId`. A `draft` adjustment is computed-but-not-yet-settled and does not
  affect any run; only `applied` adjustments surface.

The sealed original payslip is read for the diff and otherwise untouched (D1).

### D5 — Adjustments only against SEALED originals; a draft original recomputes directly

An adjustment exists to settle a delta a run can no longer absorb. If the original run is still
`draft`, there is nothing to adjust — the operator recomputes it in place via
`hrmq:payroll:run --recalculate` (the existing engine path). `adjustFor()` therefore refuses when the
original run's `status` is `draft` (`refused-original-draft: recalculate the draft run directly`,
HTTP 400 via the endpoint). Correspondingly it operates on originals in `approved`/`posted`/`paid`.

### D6 — Year-transition: taxYear is period-derived and immutable once stamped

There is deliberately **no** mutable "active tax year" global to repoint — `PayrollRunService`
derives the table id from each run's own period, and once a run is generated its `engineVersion`
(the table id) and `calculatedAt` are stamped and the run's non-`draft` states refuse recomputation
(HEAD behaviour). So the annual roll to a new year is **data-only**: ship `lib/Standards/tables/nl-YYYY.json`
and runs for `YYYY-MM` periods pick it up automatically; existing runs keep their immutable stamp.
`hrmq:payroll:year-transition --year YYYY` is the **preflight** that makes this safe: it asserts
`nl-YYYY.json` exists (fails loudly otherwise), reports the derive-from-period design (no global to
change), and confirms the immutable-stamp guard (a stamped run for a prior year is never
re-pointed). This is the documented procedure; it changes no engine state.

### D7 — The corpus is the adjustment's self-check: NlRetroChecks

`lib/Standards/Checks/NlRetroChecks.php` registers a predicate for one new rule
`nl-retro-adjustment-consistency` (PayrollAdjustment): vacuous when `engineVersion` is null; else it
recomputes the stored `correctedGrossMonthlySalary` against `TaxTables::load(engineVersion)`, diffs
against the referenced original payslip (via the `payroll.runsById`/payslip audit context, the
glpost/engine precedent), and asserts the recorded `delta*` fields equal recomputed − stored
cents-exact. A tampered delta fails the audit exactly as a tampered `nettoPay` fails
`nl-engine-output-consistency`. `hrmq:payroll:adjust` runs clean under `hrmq:rules:audit`; the engine
has no private truth.

### D8 — ONE guarded endpoint, page-actions manifest wiring

`POST /api/payroll/adjust` (`#[NoAdminRequired]`, `PayrollController::adjust`): resolve the posted
`adjustmentId` (and its `originalPayrollRunId`) through ObjectService under the caller's ambient RBAC
FIRST (unknown/unauthorized → 404, the `calculate` no-admin-idor precedent), refuse recompute of an
`applied` adjustment (400 Dutch message), then delegate to `RetroAdjustmentService`. Manifest:
`PayrollRunDetail.actions += {id: book-correction, type: open-form, label: "Correctie boeken (TWK)",
register: hrmq, schema: PayrollAdjustment, prefill: {originalPayrollRunId: "@objectId"},
onSuccessRoute: PayrollAdjustmentDetail}`; a new `PayrollAdjustmentDetail` page with
`{id: recompute, type: api-call, label: "Herrekenen", url: "/api/payroll/adjust", method: POST,
params: {adjustmentId: "@objectId"}, confirm: true}` and a delta stat block. No `lifecycleActions`
(the `status` enum has no `x-openregister-lifecycle` map — the PayrollRunDetail precedent). `npm run
check:manifest` gates it.

### D-static-severity — one rule, one static severity

The rule engine assigns each rule exactly ONE static `severity` in its JSON statement
(`lib/Standards/rules/payroll.json` — `mandatory`/`conditional`); the CheckProvider supplies only the
boolean predicate and cannot vary severity per object. `nl-retro-adjustment-consistency` is therefore
declared `mandatory` outright (a wrong delta is a wrong payment) — there is no dynamic
"mandatory-if-applied / advisory-if-draft" severity; the predicate instead stays **vacuous** while
`engineVersion` is null and only asserts once the adjustment has been computed.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Tax parameters | static `nl-YYYY.json` tables | chain-head corpus; year roll is data-only (D6) |
| Delta recompute | **imperative pure PHP** (reuse `PayrollCalculator`) | a statutory formula chain with per-step rounding — schema-declarative calc cannot express it; the engine's ADR-031 exception |
| Adjustment persistence + surfacing | imperative service via ObjectService | cross-object read-diff-write with idempotency + current-run folding (PayrollRunService precedent) |
| Triggers | occ commands + ONE guarded endpoint | operator-demand; `status` carries no lifecycle to hang a declarative action on (PayrollRunDetail precedent) |
| Run/adjustment pages | declarative manifest (`open-form`, `api-call`, stat) | ADR-031 default |
| Delta correctness | corpus rule + CheckProvider predicate | the app's established self-check exception (engine D7) |

## Seed Data (ADR-001)

No new seed objects. Seeded runs/payslips stay hand-entered (null `engineVersion`) and vacuous under
`nl-retro-adjustment-consistency` (D7's null-guard) exactly as they are under the engine's rules — so
the audit stays green on existing data. The dev-container gate instead exercises the real path
against the seeded employee once a sealed run exists: generate + approve a 2026-02 run, then
`occ hrmq:payroll:adjust --original-period 2026-02 --employee <seed> --correction-ref t1 --gross 4000
--settlement-period 2026-04` must produce a cents-exact delta, and re-running the same command must
be an idempotent no-op (D3). A cross-year attempt (`--original-period 2025-11`) must refuse with the
`historical-tables-missing` message (D2), proving the MVP boundary.

## Risks / Trade-offs

- **Same-tax-year boundary is real, not cosmetic.** Until `nl-2025.json` (etc.) ships, genuine
  prior-year corrections cannot be computed. Mitigated: the refusal is explicit and named, the
  follow-up is filed (`retro-multi-year-tables`), and the recompute code is already year-generic
  (D2) — the follow-up is data, not logic.
- **Double-count risk if settlement is not idempotent.** Mitigated by D3 (one adjustment per
  `correctionRef`) *and* by only `applied` adjustments folding into a run (D4) — a re-generated draft
  run re-reads the same applied set, never accumulating.
- **A tampered/hand-edited delta could misstate a payment.** Mitigated by D7's
  `nl-retro-adjustment-consistency` mandatory rule recomputing the delta during audit.
- **bijzonder tarief on the nabetaling** — the MVP settles the delta at the tabel result, which can
  differ from the statutory bijzonder-tarief treatment of a nabetaling. Named in the README and a
  follow-up (inherits the engine's bijzonder-tarief non-goal); never silently wrong — the disclaimer
  states it.

## Open Questions

- None blocking. Historical `nl-YYYY.json` acquisition and bijzonder-tarief settlement are named
  follow-ups; both are data/rules extensions of a year-generic recompute path.
