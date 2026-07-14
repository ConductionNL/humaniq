---
kind: config
depends_on: []
chain:
  - payroll-core-schema   # this change — tax-year table corpus + schema deltas + engine-output rules (config head)
  - payroll-core-engine   # next — PayrollCalculator + PayrollRunService + occ commands + golden tests (kind: code)
---

# Payroll Core — 2026 tax-year table corpus & engine-output contract (chain head)

## Why

The 2026-07-12/13 market deep-research (Spectr canon `hrmq-canon-payroll-engine`, 7/9 coverage,
P1-strategic; insight `hrmq-insight-odoo-nl-enterprise-only`) confirmed the strategic centerpiece:
**no open-source Dutch payroll calculation engine exists anywhere** — Odoo's NL localisation is
Enterprise-only, and every NL competitor (AFAS, Loket.nl, Nmbrs, Employes, Exact) ships bruto-netto
as closed SaaS. hrmq already models the *outputs* (Payslip carries the full NL gross-to-net
breakdown, PayrollRun the period totals) and *audits* them against 30+ machine-checkable NL payroll
rules — but nothing computes them: every seeded loonheffing figure is hand-typed. The engine is the
missing producer for the whole downstream pipe this repo already shipped: `PayrollGLPostService`
and `PayrollNetPayService` consume **approved runs**; loonaangifte and UPA filings report on them.

This change is **spec 1 of 2 in the payroll-core chain** (ADR-032: declare → consume). It ships the
declarative surface only: the versioned 2026 tax-year parameter file (the same
annual-re-issue-is-a-data-change philosophy as the rule corpus, `lib/Standards/rules/SCHEMA.md`),
the three small schema deltas the engine needs, and the two corpus rules that pin the engine's
output contract. Spec 2, `payroll-core-engine` (`kind: code`, `depends_on: [payroll-core-schema]`),
implements `PayrollCalculator` + `PayrollRunService` + occ commands + the `NlEngineChecks`
predicates against the tables and rules this change lands.

Every rate in the new tables file was verified 2026-07-13 against primary sources: the
Belastingdienst *Rekenvoorschriften voor de geautomatiseerde loonadministratie 2026* (uitgave
januari 2026, versie 2), the SZW *Regeling premiepercentages en maximumpremieloon 2026* annexes on
open.overheid.nl, and rijksoverheid.nl WML/AOW pages. Values carry `source` + `verified` fields;
the single value that cannot be pinned from a national publication (the employer-specific Whk
percentage — it comes from a per-employer Belastingdienst beschikking) ships as an explicitly
flagged placeholder. Honest data beats invented precision.

## What Changes

- **NEW `lib/Standards/tables/nl-2026.json`** — the versioned NL tax-year parameter corpus
  (design.md carries the complete verified content): loonheffing schijventarief (below-AOW and
  both AOW-age variants, incl. `Lv`/`Lmax`/tijdvakfactoren), algemene heffingskorting and
  arbeidskorting as piecewise parameter sets exactly per Rekenvoorschriften tables 2/3/6 (plus
  ouderenkorting/alleenstaande-ouderenkorting parameters for the AOW path), premie
  volksverzekeringen composition, AOW-leeftijd, Zvw percentages + maximumbijdrageloon, Awf
  laag/hoog, Aof laag/hoog + Wko opslag + Whk (gemiddeld, placeholder-flagged), maximumpremieloon,
  WML hourly reference (1 Jan + 1 Jul) + referentiemaandloon, vakantiebijslag minimum 8%. Every
  value: `{value, source, verified}`.
- **NEW `lib/Standards/tables/SCHEMA.md`** — the shape doc for tax-year table files, mirroring
  `rules/SCHEMA.md` (versioned static data, NOT OpenRegister; annual re-issue = new
  `nl-<year>.json`, data-only).
- **Schema deltas** (`lib/Settings/register.d/hr-objects.json`):
  - `Employee` gains `loonheffingskortingToegepast` (boolean, default `true`) — the
    loonbelastingverklaring toggle that decides whether the heffingskortingen are applied in the
    withholding (the existing `loonheffingenVerklaringOnFile` records that the *statement* is on
    file; this records what the employee *elected* on it).
  - `PayrollRun` gains `calculatedAt` (date-time, nullable) + `engineVersion` (string, nullable) —
    a calculated run is traceable to the exact table version that produced it.
  - `Payslip` gains two fields (each individually justified in design.md D4): `arbeidskorting`
    (number, nullable) — the applied arbeidskorting tijdvakbedrag, which Rekenvoorschriften
    §2.2.3.4 requires the loonadministratie to record (separate witte-tabel column) and which none
    of the ~54 existing fields houses; and `payrollRunId` (uuid, `$ref` PayrollRun, nullable) —
    the run association without which spec 2's idempotent recalculation and run-scoped verify
    cannot work (mirrors the existing `PensionFiling.payrollRunId` `$ref` precedent). No other
    Payslip fields — every other engine output already has a home (design.md maps them).
- **Two new corpus rules** (`lib/Standards/rules/payroll.json`) pinning the engine-output contract:
  `nl-engine-table-version` (a calculated run's `engineVersion` must reference an existing
  `lib/Standards/tables/` file) and `nl-engine-output-consistency` (on a calculated Payslip,
  `nettoPay` reconciles cents-exact to the engine's declared equation). Both `machineCheckable:
  true`. **Enforcement split (declared here, implemented in spec 2):** the `NlEngineChecks.php`
  check provider that registers the predicates ships in `payroll-core-engine` — until it lands the
  two rules are corpus-only (unenforced), which the audit coverage metric reports honestly.
- **`RuleCatalogue::VERSION` bump** (corpus content changed).

### Non-goals (named, so nobody reads them in)

- **CAO-specific rules** — a later `payroll-cao-mvp` owns CAO logic; the engine exposes extension
  points (table-driven parameters + pure calculator), it does not hardcode any CAO.
- **Groene tabel completeness beyond structure** — the parameter sets cover the groene path
  structurally (same schijven/AHK, no arbeidskorting per Rekenvoorschriften §2.2.3.4); pension/
  benefit payroll runs are not an MVP flow.
- **Bijzonder-tarief table** (bijzondere beloningen) — not in the tables file; vakantiegeld is
  *reserved* in the MVP, its May payout at bijzonder tarief is a follow-up.
- **30%-ruling netto-operation** — the Employee fields exist for the audit rules; the engine does
  not compute the 30% split in the MVP.
- **Automatic loonaangifte-message generation** — the filing lifecycle stays manual
  (loonaangifte-filing-lifecycle); XML rendering is a separate spec.
- **Pension premie calculation** — fund-specific (ABP/PFZW/StiPP factors); `pensionContribution`
  stays operator-entered.
- **Multi-year tables** — 2026 only; `nl-2027.json` is next year's data-only change.

## Capabilities

### New Capabilities

- `payroll-core-schema`: the versioned NL 2026 tax-year parameter corpus (+ SCHEMA.md), the
  Employee/PayrollRun/Payslip schema deltas the calculation engine reads and writes, and the two
  corpus rules that pin the engine's traceability + output-consistency contract.

### Modified Capabilities

<!-- none — existing specs untouched; payroll-core-engine (spec 2) consumes this one -->

## Impact

- `lib/Standards/tables/nl-2026.json` — NEW versioned tax-year data file.
- `lib/Standards/tables/SCHEMA.md` — NEW shape doc.
- `lib/Settings/register.d/hr-objects.json` — `Employee` +1 field (version 0.3.0 → 0.4.0),
  `PayrollRun` +2 fields (0.1.0 → 0.2.0), `Payslip` +1 field (0.2.0 → 0.3.0).
- `lib/Standards/rules/payroll.json` — +2 rules (`nl-engine-table-version`,
  `nl-engine-output-consistency`); `lib/Standards/RuleCatalogue.php` VERSION bump.
- No manifest change (pages change in spec 2), no seed-data change (all new fields are
  nullable/defaulted; existing seeds stay valid and audit-green).
- Chain: `payroll-core-engine` (spec 2, `kind: code`) depends on this change.
