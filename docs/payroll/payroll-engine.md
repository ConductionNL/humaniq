---
sidebar_position: 1
description: The open-source Dutch payroll calculation engine — how it works, how to run it, and its certification status.
---

# The payroll engine

HRMQ ships an open-source Dutch payroll calculation engine
(`lib/Payroll/PayrollCalculator.php`) implementing the Belastingdienst
*Rekenvoorschriften voor de geautomatiseerde loonadministratie 2026*
formula chain — witte/groene maandtabel, schijventarief, AHK/ARK/OUK
heffingskortingen, Zvw werkgeversheffing, Awf/Aof/Wko/Whk employer charges
— over the versioned tax-year parameter file `lib/Standards/tables/nl-2026.json`.

It is the strategic centerpiece of HRMQ: as far as the project's market
research has found, it is the only open-source Dutch payroll calculation
engine available (Odoo's NL payroll localisation is Enterprise-only).

## How the calculation works

`PayrollCalculator` is a pure, stateless, table-driven class with **zero
Nextcloud dependencies** — no container, no clock, no I/O beyond loading
the tax tables. All monetary arithmetic is done in **integer cents** with
per-step rounding rules, never accumulated floats, so results are
reproducible bit-for-bit.

For a monthly wage input it computes, in order:

1. **Tabelloon** — `L = floor((tvl × F) / Lv) × Lv`
2. **Schijventarief** `X1` over the applicable AOW-age / witte-or-groene
   table variant
3. **Heffingskortingen** — algemene heffingskorting (AHK) and
   arbeidskorting (ARK), with ARK's 5-decimal term rounding and cumulative
   caps, both rounded up to whole euros, applied only when
   `loonheffingskortingToegepast` is set
4. **Loonheffing** — `X = max(0, X1 − kortingen)`, floored, converted to a
   tijdvakbedrag `x = X / F` rounded to 2 decimals
5. **Vakantiegeldreservering** — 8% reservation
6. **Zvw werkgeversheffing** — 6,10% over the capped bijdrageloon
7. **Werknemersverzekeringen** — Awf/Aof/Wko/Whk employer charges over the
   capped premieloon, with `awfTariff` selecting the laag/hoog rate

The tax-year parameters — schijven, heffingskorting formulas, premies,
maxima, WML — live in `lib/Standards/tables/nl-2026.json`, an
**annually-versioned** file (`id: nl-2026`). Every parameter leaf carries
`source` and `verified` fields; any value not confirmed against a primary
source carries `verified: false` and a `checkAgainst` note naming the
official document. New tax years ship as new `nl-YYYY.json` files —
re-issuing the table is a data-only change, not a code change.

## Running the engine

Create (or recalculate) the draft `PayrollRun` and its `Payslip` objects
for a wage period:

```bash
occ hrmq:payroll:run --period=2026-06 --administration=ADM-001
occ hrmq:payroll:run --period=2026-06 --recalculate
```

- `--period` — the wage period, `YYYY-MM`
- `--administration` — the administration id (defaults to the seed
  convention `ADM-001`)
- `--recalculate` — regenerate an existing **draft** run in place; approval
  is a human act and moves the run out of draft, so an approved run is
  never silently recalculated

Audit one period's run(s) and their payslips against the machine-checkable
rule corpus:

```bash
occ hrmq:payroll:verify --period=2026-06 --administration=ADM-001 --jurisdiction=NL
```

`hrmq:payroll:verify` is a **run-scoped corpus audit** — the same
`RuleCatalogue`/`RuleEngine` that audits hand-entered HR data also audits
the engine's own output, and the command exits non-zero on any mandatory
violation. Every computed `PayrollRun` carries `engineVersion` (the exact
parameter file that produced it, e.g. `nl-2026`) and `calculatedAt`, and
every computed `Payslip` reconciles cents-exact to its declared net
equation — both enforced by the corpus rules `nl-engine-table-version` and
`nl-engine-output-consistency`.

## The payroll engine is NOT certified

Be aware of the following before relying on the engine's output in
production:

- **Traceability, not certification.** Every run's `engineVersion` and
  `calculatedAt` make its provenance auditable, but the engine has not
  been certified by any authority.
- **Certification gap.** The golden test fixtures
  (`tests/fixtures/payroll-2026/*.json`) are *self-consistent* with the
  parameter tables — the anchor case is hand-computed from the primary
  PDFs — but the official Belastingdienst test sets (loonheffingstabellen
  proefberekeningen) have **not** been run against this engine yet.
- **Known MVP limitations:**
  - Fixed monthly salary only (hourly wage × approved Timesheet hours is a
    named fast-follow)
  - No VCR (voortschrijdend cumulatief rekenen) — premium bases are
    period-capped, not cumulative, which drifts for wages fluctuating
    around the maximum premieloon
  - No anoniementarief computation — employees failing the BSN/ID
    preconditions are skipped with a reason, never computed wrong
  - No CAO logic, no bijzonder tarief (vakantiegeld payout), no
    30%-ruling netto-operation, no pension premie calculation, no Zvw
    inhouding mode, no loonaangifte message generation
- **Production use requires verification** of the engine's output against
  the official Belastingdienst test sets by a qualified
  loonadministrateur.

Honesty is a feature: this disclaimer is a requirement of the
`payroll-core-engine` specification, not a footnote.

## What consumes an approved run

Once a `PayrollRun` is approved, the rest of the payroll pipeline picks it
up automatically:

- [Loonaangifte filing](/docs/payroll/loonaangifte) — wage-tax filing lifecycle
- [Pension UPA filing](/docs/payroll/pension-upa) — sector-pension filing
- [Payslips & jaaropgaven](/docs/payroll/payslips) — loonstrook and annual
  statement PDFs
- [GL posting & SEPA net-pay](/docs/payroll/gl-and-sepa) — the handoff into
  shillinq's bookkeeping and payment-run registers
