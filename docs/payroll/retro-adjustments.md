---
sidebar_position: 6
description: Retroactive corrections (TWK) to a sealed prior period — a delta that never rewrites the original payslip.
---

# Retroactive adjustments (TWK)

Real payroll inputs change *after* a period is sealed — a backdated
raise, a late-corrected sick day, a retroactive contract fix. HRMQ
settles these the Dutch way: **terugwerkende kracht herrekening
(TWK)**. A `PayrollAdjustment` models the correction as a **delta**,
never as a rewrite of the sealed original.

## The sealed original is never mutated

`RetroAdjustmentService` reads the stored, already-approved (or
posted/paid) `Payslip` only to diff against it — it recomputes the
original period with the corrected input and subtracts. It writes a new
`PayrollAdjustment` carrying cents-exact `delta*` fields
(`deltaGross`, `deltaLoonheffing`, `deltaNet`,
`deltaWerknemersverzekeringen`, `deltaZvw`, `deltaVolksverzekeringen`,
`deltaVakantiegeldReserved`). The historical payslip — already filed in
a loonaangifte, already posted to the GL — stays byte-untouched. An
adjustment can exist against a run the engine itself refuses to
recompute directly.

An adjustment can only be created against a **sealed** original run
(`approved`/`posted`/`paid`). A still-`draft` original is corrected
directly via `hrmq:payroll:run --recalculate`, the ordinary engine path
— attempting an adjustment against a draft original is refused
(`refused-original-draft`).

## The recompute uses the ORIGINAL period's tax year

A 2025 correction must use the 2025 schijven/kortingen/premiepercentages,
not 2026. `RetroAdjustmentService` derives `nl-{year of the original
period}` and recomputes strictly against that table.

**Same-tax-year MVP boundary:** today only `nl-2026.json` ships. A
correction whose original period falls in a year for which no table
exists is **refused** with a clear `historical-tables-missing` message
naming the missing file, rather than silently recomputed against the
wrong year. Seeding historical `nl-YYYY.json` corpora is a named
follow-up — the recompute code is already year-generic, so that
follow-up is data, not logic.

## The delta surfaces in the CURRENT run, never in history

An `applied` adjustment folds its net delta into the **current** open
period's `Payslip` — a new `retroAdjustment` component field plus its
effect on `nettoPay` — as a nabetaling (positive) or terugvordering
(negative). A `draft` adjustment is computed-but-unsettled and affects
no run.

Adjustments are idempotent by `(originalPeriod, employeeId,
correctionRef)` — re-running the same correction updates one object and
never double-counts.

## The tax year is period-derived and immutable once stamped

There is deliberately no mutable "active tax year" global: each run
derives its table from its own period, and a generated run's
`engineVersion`/`calculatedAt` stamp plus the non-`draft` recompute
refusal make that stamp immutable. The annual roll is therefore
**data-only** — ship `lib/Standards/tables/nl-YYYY.json` and runs for
`YYYY-MM` periods pick it up automatically.

```bash
occ hrmq:payroll:year-transition --year 2027
```

This is the preflight for the annual roll: it asserts the new table
exists (failing loudly otherwise) and confirms the immutable-stamp
guard — it changes no engine state.

## Known MVP limitations

- **No bijzonder tarief on the nabetaling.** A nabetaling is often taxed
  at bijzonder tarief in real Dutch payroll; the MVP settles the delta
  at the tabel result and names bijzonder tarief as a follow-up — it
  inherits the engine's own bijzonder-tarief non-goal.
- **No cumulative/VCR reconciliation** — the delta is a period-vs-period
  recompute, not a cumulative one.
- **No automated loonaangifte correctie-berichten.** The filing
  lifecycle's `corrigeren` transition is the manual route.

The `nl-retro-adjustment-consistency` corpus rule recomputes every
adjustment's delta during the standing audit, so a tampered delta is a
mandatory violation:

```bash
occ hrmq:rules:audit
```

## Booking a correction

```bash
occ hrmq:payroll:adjust --original-period 2026-02 --employee EID \
  --correction-ref t1 --gross 4000 --settlement-period 2026-04 --apply
```

Or from the UI: `PayrollRunDetail` for the **sealed original run**
carries a "Correctie boeken (TWK)" action that opens a draft
`PayrollAdjustment` prefilled with the run reference; its own detail
page carries a "Herrekenen" action that computes the delta. `settle`/
`apply` is deliberately never a bare lifecycle button — both paths
resolve the target through the caller's ambient RBAC before any
recompute, so an unauthorized or unknown adjustment id never reaches
the engine.
