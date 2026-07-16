---
kind: code
---

# Jurisdiction packs — onboard a country by uploading configuration, not by shipping PHP

## Why

hrmq owns the first open-source Dutch payroll engine. Its **parameters** are already data:
`lib/Standards/tables/nl-2026.json` carries every rate, bracket and threshold as a
`{value, source, verified}` leaf, and `lib/Standards/tables/SCHEMA.md` already promises that a new
tax year is a data-only change.

The **chain that consumes those parameters is hardcoded Dutch PHP.**
`PayrollCalculator::calculate()` is 130 lines of numbered NL steps — vakantiegeld reservering,
tabelloon floor-to-multiple, schijventarief bracket pick, AHK/ARK/OUK heffingskortingen with an
`arkChain()` tapered min-chain, whole-euro floors, an informative volksverzekeringen split, a capped
Zvw levy, four capped employer premiums behind a `verzekeringsplichtig` gate, and a netto line that
is literally `tvl - loonheffing`. Every step names a Dutch concept. A second country today means a
second calculator class, a second result DTO and a second test suite — per country, forever.

This change makes a jurisdiction an **uploadable, exchangeable artefact** — the way OpenBuild
exchanges apps as config. A pack declares its steps in a closed step-DSL; a small pure interpreter
runs them; a named PHP escape hatch exists for genuine national exotica (ADR-101).

NL is re-expressed as the first pack and must stay **behaviour-identical**. That is not politeness to
the existing tests — it is the only available proof that the abstraction is real. The engine has a
hard regression contract (EUR 3.800 wit/maand produces loonheffing 718,83 / arbeidskorting 473,75 /
netto 3.081,17) pinned by 9 golden fixtures plus `BalancingInvariantTest`. If NL cannot be
re-expressed digit-for-digit, we learn that before building an upload endpoint, not after.

## What Changes

- **NEW `lib/Payroll/Dsl/`** — the interpreter: `PackInterpreter` (pure; executes an ordered step
  DAG), `StepContext` (integer-cents bindings + resolved refs), `ExprEvaluator` (a **closed, total**
  arithmetic grammar — no loops, no recursion, no IO, no clock), and the nine step ops
  (`rate`, `cappedRate`, `bracket`, `taper`, `piecewiseAccrue`, `quantize`, `clamp`, `match`, `expr`).
  Zero Nextcloud dependencies, matching `PayrollCalculator`'s existing purity discipline.
- **NEW incidence primitive** — every step declares `reduces-net` / `employer-cost` / `informative` /
  `reserve`. The interpreter **derives** net as a fold over `reduces-net` steps rather than executing a
  hardcoded netto line. For NL the fold yields exactly `tvl - loonheffing` (today's Step 10) as an
  emergent consequence of every employer step declaring `employer-cost` (design.md D2).
- **NEW `lib/Standards/packs/nl-2026.pack.json`** — NL re-expressed: 13 declarative steps, **zero**
  escape-hatch uses. The pack **references** the existing `nl-2026.json` parameter leaves via
  `@table.*` — the verified tables file is not rewritten, re-sourced, or duplicated (design.md D4).
- **NEW pack self-tests, REQUIRED** — every pack MUST carry at least one `selfTest` vector. **NL's 9
  payroll golden fixtures become the NL pack's self-test block**, so the machinery that gates a
  third-party pack is the same machinery that proves the NL migration was behaviour-identical.
- **NEW `lib/Payroll/PackValidator.php`** — blocking upload validation: JSON Schema, vocabulary,
  reference resolution (DAG, no forward refs, no cycles), **escape-hatch handler resolution against a
  compile-time allow-list**, and a self-test dry-run. A pack naming a handler that does not exist is
  **rejected at upload with the name in the error**, never silently skipped at runtime (ADR-101
  decision 3 — the orphaned-capability defect class).
- **NEW `lib/Payroll/JurisdictionStepHandlerInterface.php` + registry** — the named escape hatch. A
  pack supplies a handler *name*; it can never supply code, a class path, or a callable. Ships with
  **zero registered handlers** — no NL step needs one.
- **NEW `JurisdictionPack` schema + admin upload endpoint** — `POST /api/payroll/packs`
  (`AuthorizedAdminSetting`, one endpoint, no CRUD per ADR-022). Bundled packs live in
  `lib/Standards/packs/`; uploaded packs live as OpenRegister objects in the hrmq register. An upload
  may not silently shadow a bundled `(jurisdiction, taxYear)` — overriding NL requires an explicit,
  recorded admin activation (design.md D7).
- **CHANGED `lib/Payroll/PayrollCalculator.php`** — becomes a thin façade that resolves the NL pack
  and delegates to the interpreter. Its public contract
  (`calculate(CalculationInput, TaxTables): CalculationResult`) is **unchanged**; `PayrollRunService`,
  `PayrollCalculatorTest` and `BalancingInvariantTest` are not touched.
- **CHANGED pack resolution** — `PayrollRunService` today does `'nl-'.substr($period, 0, 4)`: the
  country is hardcoded in the resolver. It becomes a lookup on `(run.jurisdiction, year-of(period))`.
  `PayrollRun.jurisdiction` already exists (hardcoded `'NL'` at creation). `engineVersion` becomes
  `{packId}@{packVersion}`.

## Impact

- **Affected specs**: `payroll-core-engine` (the calculator's internals are re-expressed; its contract
  is not), `payroll-core-schema` (pack corpus alongside the tables corpus), `dga-payroll-mode` (the
  `verzekeringsplichtig` gate becomes a pack `when` predicate), `multi-administratie` (an
  administration's jurisdiction selects its pack).
- **Affected code**: `lib/Payroll/*` (new `Dsl/`, `PayrollCalculator` internals), `lib/Standards/packs/`
  (new), `lib/Service/PayrollRunService.php` (pack resolution only), `lib/Settings/register.d/`
  (JurisdictionPack schema).
- **Regression surface**: the whole engine. Mitigated by the acceptance contract below.

## Acceptance contract (binding)

The NL migration is behaviour-identical or it does not land:

1. All **9** `tests/fixtures/payroll-2026/*.json` golden fixtures reproduce **digit-for-digit** through
   the pack path (anchor, aow-age, bracket-3, groen, min-wage, no-korting, part-time,
   bijtelling-anchor, dga-anchor).
2. `BalancingInvariantTest` stays green **unmodified** — and its net equation is additionally asserted
   as the interpreter's own incidence-fold invariant.
3. `tests/fixtures/sick-pay-2026/anchor.json` stays green. `SickPayCalculator` is **out of pack scope**
   (design.md D8) and must be provably untouched.
4. `PayrollCalculator`'s public signature and `CalculationResult`'s 18 fields are unchanged.

Fixture 1 + test 2 + test 3 = the 10 golden fixtures at HEAD. The 9/1 split is deliberate and honest:
9 are reproduced *by* the pack, the 10th proves the pack did not disturb what it does not own.

## Non-Goals (binding)

- **VCR** (voortschrijdend cumulatief rekenen) — the DSL is per-period pure and **cannot** express
  cross-period state. Named up front (ADR-101) so nobody widens `expr` to compensate.
- **30%-ruling netto-operation** — an inverse solve, not a forward chain. Hatch or future ADR.
- **A second real country.** This change proves the mechanism against NL and ships the upload surface.
  Any claim that country two "just works" is unproven until country two lands (ADR-101: `piecewiseAccrue`
  is on probation).
- **The five app-level folds stay app-level** — bijtelling (pre-calc gross fold) and retroAdjustment /
  leaveBuySell / loonbeslag / sick-pay (post-net folds) remain in `PayrollRunService`. The interpreter
  is pure and object-blind; these are orchestration over stored objects. Honest discomfort recorded in
  design.md D8: bijtelling and the loonbeslag beslagvrije voet are both NL law living outside the
  "jurisdiction" artefact. Bijtelling is a named follow-up.
- **`bracket` progressive mode** — MVP is affine only (NL's tables ship precomputed `a`/`c`
  constants). Progressive-sum mode is a named follow-up (design.md D3).
- **Above-Lmax "systematiek 1"** — not implemented in the engine today; the pack reproduces the engine
  at HEAD, gaps included, and keeps the `aboveLmax` flag.
- **Pack authoring UI** — upload + validate only.
