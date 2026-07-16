# Design — jurisdiction-packs

## Context

**Verified against HEAD 2026-07-16** (detached checkout of `origin/development`, commit `1afa47f`).
Every figure in D5 below was recomputed by hand from `PayrollCalculator.php` + `nl-2026.json` at HEAD
and matches the golden fixtures; nothing here is quoted from a brief.

What exists today:

- `lib/Payroll/PayrollCalculator.php` (419 lines) — 10 numbered NL steps, pure, integer cents, zero
  Nextcloud deps. Private helpers: `isAowAge()`, `schijvenSet()`, `selectBracket()`, `arkChain()`,
  `floorEuroCents()`, `ceilEuroCents()`, `round5Cents()`, `round2Cents()`.
- `lib/Payroll/CalculationInput.php` — 9 named params. `CalculationResult.php` — 18 fields.
- `lib/Payroll/TaxTables.php` (558 lines) — loader + 14 typed accessors; euros to integer cents at
  load; percentages/ratios pass through unconverted.
- `lib/Standards/tables/nl-2026.json` — `{value, source, verified}` leaves; exactly one
  `placeholder: true` (employer Whk, `checkAgainst` a per-employer beschikking).
- `lib/Service/PayrollRunService.php` — one pre-calc gross fold (bijtelling) and four post-net folds
  (sick-pay substitution, retroAdjustment, leaveBuySell, loonbeslag).
- Acceptance: 9 fixtures in `tests/fixtures/payroll-2026/`, 1 in `tests/fixtures/sick-pay-2026/`,
  `PayrollCalculatorTest` (7 tests), `BalancingInvariantTest` (4 tests).

Reference: **ADR-101** (this change's architecture record) and **ADR-032** (spec sizing/chaining).

## Goals / Non-Goals

**Goals:** a jurisdiction is uploadable config; NL re-expressed behaviour-identically; incidence as a
first-class primitive so net is derived not hardcoded; a validation model that makes an uploaded pack
safe to pay wages with.

**Non-Goals (from the proposal, binding):** VCR; 30%-ruling netto-operation; a second real country;
the five app-level folds; `bracket` progressive mode; above-Lmax systematiek 1; pack authoring UI.

## Declarative vs imperative (ADR-031)

ADR-031 asks: what belongs in declarative config, and what genuinely requires imperative code?

| Concern | Declarative (pack config) | Imperative (PHP) | Why |
| --- | --- | --- | --- |
| Tax-year parameters (rates, brackets, thresholds) | `@table.*` leaves in `nl-2026.json` | — | Already data at HEAD; unchanged by this change |
| The gross-to-net step chain | pack `steps[]` | — | **The whole point.** Today PHP; becomes config |
| Step incidence (what an amount does to net) | `step.incidence` | — | Was a hardcoded NL assumption (Step 10); becomes declared |
| Net computation | — (derived) | interpreter fold | Not authorable — a structural invariant over incidence |
| Rounding rules (floor/ceil/nearest, unit) | `step.round` | — | NL's arcana is per-step data, not control flow |
| Conditional steps (korting, wit/groen, AOW, DGA gate) | `step.when` predicate | — | Boolean predicate over declared inputs |
| Table-set selection (AOW/groen/cohort) | `match` expression on a `derive` binding | — | Was `schijvenSet()`; a lookup, not logic |
| Arithmetic between steps | `expr` (closed grammar) | — | Total; no loops/recursion — a calculator, not a language |
| Pack validation, DAG check, ref resolution | — | `PackValidator` | Must run before any pack is trusted |
| The interpreter itself | — | `PackInterpreter` | Something has to execute the config |
| Cross-period state (VCR) | **cannot express** | hatch / future ADR | DSL is per-period pure by construction |
| Inverse solves (30%-ruling netto-operation) | **cannot express** | hatch / future ADR | Forward chain only |
| Genuine national exotica | `op: phpStep, handler: name` | allow-listed handler | Named, resolved at validation, **zero NL uses** |
| Object orchestration (the 5 folds) | — | `PayrollRunService` | Interpreter is pure and object-blind |

The line: **anything that is a per-period pure function of (wage, parameters) is declarative.**
Everything that touches state, storage, or time is imperative and stays outside the pack.

## Seed Data

No new seed objects. The change is corpus + code:

- `lib/Standards/packs/nl-2026.pack.json` — the NL pack (bundled, ships in code, mirroring the
  `lib/Standards/tables/` precedent: universal facts live in code, not OpenRegister).
- `JurisdictionPack` schema in `lib/Settings/register.d/` — for **uploaded** packs only. Bundled packs
  are never imported as objects; a bundled and an uploaded pack are resolved by the same key but from
  different homes (D7).
- The NL pack's `selfTest` block is **not new data** — it references the 9 existing fixtures under
  `tests/fixtures/payroll-2026/`. No fixture is copied, edited, or re-derived.

## Decisions

### D1 — A pack is an ordered DAG of steps executed by a pure interpreter

```jsonc
{
  "id": "nl-2026",
  "jurisdiction": "NL",          // ISO 3166-1 alpha-2, DECLARED not parsed
  "taxYear": 2026,               // DECLARED not parsed
  "packVersion": "1.0.0",        // semver — the RuleCatalogue::VERSION analogue
  "dslVersion": "1.0",           // the interpreter contract
  "tables": "nl-2026",           // the TaxTables id this pack's @table.* refs resolve against
  "currency": "EUR",
  "basedOn": [ /* {doc, url} — the tables convention */ ],
  "inputs":   { /* declared input contract, see D6 */ },
  "bindings": [ /* named intermediates, no incidence */ ],
  "steps":    [ /* ordered; each declares incidence */ ],
  "selfTest": [ /* REQUIRED, >= 1 vector */ ]
}
```

`PackInterpreter::run(inputs, pack, tables): array<stepId, cents>` is pure: no container, no clock, no
IO beyond the `TaxTables` instance handed in — the exact discipline `PayrollCalculator` holds at HEAD.
Steps execute in declared order; a step may reference only *earlier* steps.

Money is integer cents throughout, preserving the existing rule that percentages/ratios multiply
directly against cents-valued operands. `TaxTables` is reused **as-is** — the pack references its
accessors' underlying leaves, so the euro-to-cents conversion stays exactly where it is today.

### D2 — Incidence is declared per step; net is a fold, never a step

The least portable line at HEAD:

```php
$nettoPayCents = ($tvl - $loonheffingCents);   // "employer charges never reduce net"
```

True of the Netherlands, not of payroll. Every step declares:

| incidence | effect | NL steps |
| --- | --- | --- |
| `reduces-net` | subtracted from gross to reach net | `loonheffing` |
| `employer-cost` | employer pays; never touches net | `zvw`, `awf`, `aof`, `wko`, `whk` |
| `informative` | reported; no cash effect | `volksverzekeringen`, `arbeidskorting`, `appliedTaxRate` |
| `reserve` | accrued now, paid later | `vakantiegeld` |

The interpreter derives:

```
net = gross - sum(step.amount where step.incidence == 'reduces-net')
```

For NL that is `380000 - 71883 = 308117` — bit-for-bit today's Step 10, but as a **consequence** of
every employer step honestly declaring `employer-cost`. A country whose pension contribution reduces
net declares `reduces-net` and the fold does the rest, with no interpreter change.

`reserve` is a fourth incidence the original framing did not have, and it is load-bearing: vakantiegeld
is neither cash to the employee this period nor an employer charge this period. Folding it into any of
the other three would misstate either net or employer cost.

`employerCharges = sum(step.amount where incidence == 'employer-cost')` — for NL, `41914 + 23180 =
65094`, matching `CalculationResult::$employerChargesCents` at HEAD.

### D3 — The full DSL vocabulary (MVP)

**Step ops** — every step is `{id, op, incidence, when?, round?, ...params}`:

| op | signature | semantics | NL use |
| --- | --- | --- | --- |
| `rate` | `base, rate` | `base * rate / 100` | vakantiegeld 8% |
| `cappedRate` | `base, rate, cap` | `min(base, cap) * rate / 100` | Zvw, Awf, Aof, Wko, Whk |
| `bracket` | `value, table, mode: affine` | first row where `value <= tot` (`null` = unbounded); then `(value - a) * pct / 100 + c` | schijventarief X1 |
| `taper` | `base, value, threshold, rate, floor` | `max(floor, base - max(0, value - threshold) * rate)` | AHK, OUK |
| `piecewiseAccrue` | `value, segments[{upTo, rate, cap}], tail{from, rate}, zeroAbove, roundTerm` | capped piecewise-linear build-up; each term rounded to `roundTerm` decimals **then** capped at that segment's own ceiling, accumulating; then the descending tail; then hard zero above `zeroAbove` | ARK (`arkChain`) |
| `quantize` | `value, step, mode: floor\|ceil\|nearest` | round `value` to a multiple of `step` | tabelloon (step=Lv), floorEuro/ceilEuro (step=100) |
| `clamp` | `value, min?, max?` | bound a value | `X = max(0, ...)` |
| `match` | `on, cases{}, default?` | select a value/key by an input or binding | awf laag/hoog, schijven-set |
| `expr` | `expression, refs` | **closed, total** arithmetic: `+ - * /`, `min max abs round floor ceil`, parens, refs, literals. **No loops, no recursion, no function definitions, no IO, no clock.** | loonheffing X, vv split, appliedTaxRate |
| `phpStep` | `handler, params?` | **escape hatch** — resolve `handler` against the compile-time allow-list (D9) | **none** |

**Binding op** — `{id, op: derive, ...}` produces a named intermediate with **no** incidence (not money
out):

| form | semantics | NL use |
| --- | --- | --- |
| `derive` + any step op | a named intermediate value | `annualised`, `L` (tabelloon) |
| `derive` + predicate | a named boolean | `aow`, `wit` |

**Rounding modifier** — `round: {mode: floor|ceil|nearest, unit: cent|euro|decimals}`, applicable to
every step and binding. `unit: euro` is `quantize(step=100)`; `decimals: 3` is the `round5Cents()`
rule (5 decimals of a euro = 3 in cents-space).

**Predicates** (for `when` and boolean `derive`):

| predicate | notes |
| --- | --- |
| `eq ne lt lte gt gte` | scalar comparison |
| `and or not` | boolean composition |
| `ageReached(dob, years, asOf, granularity: month\|day)` | `granularity: month` = applies from the first day of the month the age is reached — today's `isAowAge()` semantics, made explicit rather than assumed |
| `yearOf(date)` | cohort selection — today's `schijvenSet()` birth-year test |

**Reference grammar:**

| ref | resolves to |
| --- | --- |
| `@input.x` | a declared input (D6) |
| `@table.a.b.c` | a `nl-2026.json` parameter leaf's `value` |
| `@step.id` | an earlier step's amount (cents) |
| `@binding.id` | an earlier binding |
| `@period.year`, `@period.lastDay` | derived from the run period |
| `@pack.currency`, `@pack.taxYear` | pack metadata |

**Why keep named ops when `expr` subsumes several?** Intent and auditability. `taper` says "this is a
phase-out"; the equivalent `expr` says "some arithmetic". Named ops are individually validatable and
diffable across countries; `expr` is the last resort *inside* the DSL and NL uses it exactly 3 times.
Constraining `expr`'s grammar to a total calculator is what keeps "uploaded config" from becoming
"uploaded code" (ADR-101 decision 2). **Widening `expr` into a general language is forbidden** — that
would void the entire trust model, and the pressure to do it will come from VCR. Say no; use the hatch.

### D4 — The pack references the tables; it does not replace them

`nl-2026.json` is verified, sourced, and carries provenance leaves. The pack **does not** copy those
values — every parameter arrives via `@table.*`. Consequences:

- The verified corpus keeps its single home and its `{value, source, verified}` discipline.
- `BalancingInvariantTest::testTablesAgreeWithTheExistingCorpusRuleStatements()` keeps working
  unmodified — it reads the JSON directly.
- Annual re-issue stays data-only: a new tax year is a new `nl-2027.json` **plus** a pack whose
  `tables` field points at it; if the chain is unchanged, the pack is a copy with two fields bumped.
- Provenance survives to the run: a pack activating over any `verified: false` / `placeholder: true`
  leaf **stamps that onto the run** rather than blocking. NL's employer Whk is legitimately a
  placeholder today, and the current engine says nothing about it — this is an improvement.

### D5 — Worked re-expression: the NL anchor chain, step by step

Input: EUR 3.800,00 wit, korting toegepast, below AOW, period `2026-02`, awf `low`, aof `laag`, whk
`1,52` — `tvl = 380000` cents, `F = 12`, `Lv = 5400`.

Every figure below was recomputed by hand from HEAD and matches `tests/fixtures/payroll-2026/anchor.json`.

| # | id | op | expression | incidence | result (cents) |
| --- | --- | --- | --- | --- | --- |
| B1 | `aow` | `derive` | `ageReached(@input.dateOfBirth, @table.aow.leeftijdJaren, @period.lastDay, month)` | — | `false` |
| B2 | `wit` | `derive` | `ne(@input.taxTableColor, 'groen')` | — | `true` |
| B3 | `annualised` | `derive`/`expr` | `@input.gross * @table.loonheffing.tijdvakFactoren.maand` | — | `4560000` |
| B4 | `aboveLmax` | `derive` | `gt(@binding.annualised, @table.loonheffing.Lmax)` | — | `false` |
| B5 | `L` | `derive`/`quantize` | `quantize(@binding.annualised, step: @table.loonheffing.Lv, mode: floor)` | — | `4557600` |
| B6 | `schijvenSet` | `derive`/`match` | `on: @binding.aow` -> `false: 'belowAow'`; `true: match(yearOf(@input.dateOfBirth) <= 1945)` | — | `'belowAow'` |
| S1 | `vakantiegeld` | `rate` | `base: @input.gross, rate: @table.vakantiebijslag.minRatePercent` round nearest cent | **reserve** | `30400` |
| S2 | `x1` | `bracket` | `value: @binding.L, table: @table.loonheffing.schijven[@binding.schijvenSet], mode: affine` round **floor euro** | informative | `1641300` |
| S3 | `ahk` | `taper` | `base: ahkm1, value: @binding.L, threshold: ahkg1, rate: ahka1, floor: 0` round **ceil euro**, `when: @input.loonheffingskortingToegepast` | informative | `210200` |
| S4 | `ark` | `piecewiseAccrue` | `value: @binding.L`, segments arkg1/o1/m1, arkg2/o2/m2, arkg3/o3/m3, tail `{from: arkg3, rate: arka1}`, `zeroAbove: arkg4`, `roundTerm: 3`, round **ceil euro**, `when: and(korting, @binding.wit)` | informative | `568500` |
| S5 | `ouk` | `taper` | oukm1/oukg1/ouka1, round **ceil euro**, `when: and(korting, @binding.aow)` | informative | `0` |
| S6 | `loonheffingJaar` | `expr`+`clamp` | `clamp(@step.x1 - (@step.ahk + @step.ouk + @step.ark), min: 0)` | informative | `862600` |
| S7 | `loonheffing` | `expr` | `@step.loonheffingJaar / F` round nearest cent | **reduces-net** | `71883` |
| S8 | `arbeidskorting` | `expr` | `@step.ark / F` round nearest cent | informative | `47375` |
| S9 | `volksverzekeringen` | `expr` | `min(@step.loonheffing, (@step.loonheffing * vvJaar) / @step.x1)` where `vvJaar = min(@binding.L, @table...schijven[@binding.schijvenSet][0].tot) * vvRate / 100`, `when: gt(@step.x1, 0)`, else 0 | informative | `47086` |
| S10 | `zvw` | `cappedRate` | `base: @input.gross, rate: zvw.werkgeversheffing, cap: maximumpremieloon.maand` | **employer-cost** | `23180` |
| S11 | `awf` | `cappedRate` | `rate: match(@input.awfTariff -> {laag: awf.laag, hoog: awf.hoog})`, `when: @input.verzekeringsplichtig` | **employer-cost** | `10412` |
| S12 | `aof` | `cappedRate` | `rate: match(@input.aofTariff -> {laag: aof.laag, hoog: aof.hoog})`, `when: @input.verzekeringsplichtig` | **employer-cost** | `23826` |
| S13 | `wko` | `cappedRate` | `rate: wkoOpslag`, `when: @input.verzekeringsplichtig` | **employer-cost** | `1900` |
| S14 | `whk` | `cappedRate` | `rate: @input.whkPercentage`, `when: @input.verzekeringsplichtig` | **employer-cost** | `5776` |
| S15 | `appliedTaxRate` | `expr` | `(@step.loonheffing / @input.gross) * 100` round 2 dec, `when: gt(@input.gross, 0)` | informative | `18.92` |

**Derived by the interpreter, not authored:**

```
werknemersverzekeringen = 10412 + 23826 + 1900 + 5776           = 41914
employerCharges         = sum(employer-cost)  = 41914 + 23180   = 65094
netto                   = 380000 - sum(reduces-net) = 380000 - 71883 = 308117
```

**EUR 3.081,17.** The anchor contract, reproduced with no NL netto rule anywhere in the interpreter.

Worked detail for the two hard steps:

**S2 bracket (affine).** `L = 4557600` falls in row 2 (`tot: 7842600`, `pct 37,56`, `a 3888300`,
`c 1390000`): `(4557600 - 3888300) * 37,56 / 100 + 1390000 = 251389,08 + 1390000 = 1641389,08`;
floor-to-euro -> `1641300`.

**S4 piecewiseAccrue (the `arkChain` min-chain).**

| segment | span | term | cap (`m`) | after cap |
| --- | --- | --- | --- | --- |
| 1 | `min(L, g1) = 1196500` | `1196500 * 0,08324 = 99596,66` | `99600` | `99596,66` |
| 2 | `min(L, g2) - g1 = 1388000` | `99596,66 + 1388000 * 0,31009 = 530001,58` | `530000` | **`530000`** (cap binds) |
| 3 | `min(L, g3) - g2 = 1973100` | `530000 + 1973100 * 0,01950 = 568475,45` | `568500` | `568475,45` |
| tail | `L > g3`? `4557600 > 4559200` = **no** | not applied | — | `568475,45` |
| zeroAbove | `L > g4 (13292000)`? no | — | — | `568475,45` |

ceil-to-euro -> `568500`; `/12` -> `47375` = **EUR 473,75**. Note segment 2's cap binds *after* the
5-decimal rounding, not before — that ordering is exactly why `piecewiseAccrue` needs `roundTerm` and
`cap` as separate declared knobs rather than a generic taper.

### D6 — The input contract is declared by the pack

A pack declares its inputs; the interpreter validates the supplied map against that declaration
before executing.

```jsonc
"inputs": {
  "gross":                        {"type": "cents",   "required": true},
  "taxTableColor":                {"type": "enum",    "values": ["wit", "groen"]},
  "loonheffingskortingToegepast": {"type": "boolean", "default": true},
  "dateOfBirth":                  {"type": "date",    "nullable": true},
  "awfTariff":                    {"type": "enum",    "values": ["laag", "hoog"]},
  "aofTariff":                    {"type": "enum",    "values": ["laag", "hoog"]},
  "whkPercentage":                {"type": "percent"},
  "verzekeringsplichtig":         {"type": "boolean", "default": true}
}
```

**One honest wrinkle.** `CalculationInput::$awfTariff` is `low|high` at HEAD while the table keys are
`laag|hoog` — `PayrollCalculator` bridges them with a PHP ternary. A pack cannot inherit that
accident. The façade (D10) maps `low -> laag` / `high -> hoog` at the boundary, so
`CalculationInput`'s public contract is untouched while the pack's vocabulary matches its own tables.
This is a real seam and it is written down rather than papered over.

### D7 — Pack identity, storage, exchange (the OpenBuild analogy)

**Identity:** `{jurisdiction}-{taxYear}` (`nl-2026`) — preserving today's table id, which is already
stamped as `PayrollRun.engineVersion`. But `jurisdiction` and `taxYear` are **declared fields, not
substrings**. Today's resolver hardcodes the country:

```php
$tableId = 'nl-'.substr($period, 0, 4);   // PayrollRunService::generate()
```

It becomes a lookup on `(run.jurisdiction, year-of(period))`. `PayrollRun.jurisdiction` already exists
(hardcoded `'NL'` at creation) — the field is there, waiting.

**Two homes, one key:**

| home | what | why |
| --- | --- | --- |
| `lib/Standards/packs/*.pack.json` | bundled packs (NL) | universal facts live in code — the `lib/Standards/tables/` precedent |
| OpenRegister `JurisdictionPack` objects | uploaded packs | per-tenant config lives in OpenRegister (ADR-022) |

**Resolution order, and the trap avoided:** the obvious design lets an uploaded pack shadow a bundled
one by key. That would let a stray upload silently replace the NL regression contract with someone's
half-finished experiment — and everyone gets paid from it. So: **an uploaded pack may not claim a
`(jurisdiction, taxYear)` a bundled pack already owns.** Overriding requires an explicit admin
activation, recorded on the pack object, and the override still must pass every validation gate
including its own self-tests. Bundled wins by default; overriding NL is a deliberate, auditable act.

**Exchange:** a pack is one self-contained JSON file — download, hand over, upload elsewhere. It
carries its own parameters (by reference to a `tables` id it declares), its own step chain, its own
provenance, and its own golden vectors. That last part is what makes it *portable* rather than merely
*transferable*: the recipient's instance can prove the pack computes what its author claimed before it
pays anyone.

`engineVersion` becomes `{packId}@{packVersion}` (e.g. `nl-2026@1.0.0`) — strictly more information
than today's `nl-2026`, and `PayrollRun.engineVersion` is a string field already.

### D8 — What stays outside the pack, and why that is uncomfortable

`PayrollRunService` performs five folds around the calculator:

| fold | when | stays app-level because | honest assessment |
| --- | --- | --- | --- |
| sick-pay substitution | pre-calc | reads `SickLeaveCase` objects; `SickPayCalculator` is a separate pure calculator | Clean cut. Sick pay is NL law but pack-izing it needs a second chain type — follow-up |
| bijtelling | pre-calc gross fold | reads `CarAssignment` + `Vehicle` objects | **Uncomfortable.** The *arithmetic* (`cataloguswaarde * rate / 12 - eigenBijdrage`, two-tier EV blend) is a pure function of parameters already in `nl-2026.json` under `bijtellingPrivegebruikAuto`. This belongs in the pack. Only the object lookup does not. **Named follow-up.** |
| retroAdjustment | post-net | sums stored `PayrollAdjustment.deltaNet` | Clean. Nothing is computed; a stored figure is folded |
| leaveBuySell | post-net | sums stored `LeaveTransaction.settledAmount` | Clean. Same |
| loonbeslag | post-net | `min(orderedAmount, max(0, net - beslagvrijeVoet))` over a stored `Loonbeslag` | **Uncomfortable.** The beslagvrije voet is Dutch law living outside the "jurisdiction" artefact. But the *values* come from the object, not the tables, and it operates on fully-folded net — which the pack does not own. Stays; recorded as a known seam |

**The MVP boundary is "the pure per-period wage chain"** — exactly the surface `PayrollCalculator`
owns at HEAD. That is a defensible cut (it is the surface with a regression contract), but it is a
*scope* cut, not a *principled* one. Two genuinely NL-legal computations (bijtelling, beslagvrije
voet) sit outside the jurisdiction pack after this change. Country two will feel that.

### D9 — The escape hatch: named, allow-listed, resolved at validation time

```jsonc
{"id": "some-exotica", "op": "phpStep", "handler": "nl-vcr-cumulative", "incidence": "reduces-net"}
```

- Handlers implement `JurisdictionStepHandlerInterface` and are registered in a **compile-time
  allow-list** (DI-tagged registry). A pack supplies a **name**; it can never supply code, a class
  path, a callable, or a file.
- **Resolution happens at pack-validation time.** An unknown handler name **rejects the pack at
  upload**, with the name in the error message. It never reaches a payroll run; it never
  "degrades gracefully" to a skipped step that quietly under-taxes someone.

This is the orphaned-capability defect class, and payroll is the worst possible place to meet it. The
fleet's own precedent is a `register.d` guard naming a missing class: it **throws**. Same discipline
here.

**Ships with zero registered handlers.** No NL step needs one. The registry exists so the wall is
built before the first country hits it — the honest expectation (ADR-101) is that **NL itself is the
first customer, at VCR.**

### D10 — `PayrollCalculator` becomes a façade; the public contract does not move

```php
public function calculate(CalculationInput $in, TaxTables $t): CalculationResult
{
    $pack   = $this->packs->resolve('NL', $in->period);
    $out    = $this->interpreter->run($this->inputsFrom($in), $pack, $t);
    return $this->resultFrom($out);   // the same 18 fields
}
```

Signature unchanged. `CalculationResult`'s 18 fields unchanged. `PayrollRunService` untouched except
for pack resolution. `PayrollCalculatorTest` and `BalancingInvariantTest` **not modified** — if they
need editing to pass, the migration has drifted and the change has failed its own acceptance contract.

The private helpers (`arkChain`, `selectBracket`, `isAowAge`, `schijvenSet`, the four rounding
helpers) are **deleted**, their behaviour absorbed into interpreter ops. Deleting them is the proof:
if any survives, some NL logic stayed in PHP.

### D11 — Trust model (ADR-101 decision 4)

Every gate blocks at upload:

| # | gate | failure mode |
| --- | --- | --- |
| 1 | JSON Schema — envelope + leaves | reject |
| 2 | Vocabulary — every `op` known to `dslVersion` | reject, naming the op |
| 3 | References — `@table.*` resolves; `@step.*` names an **earlier** step; DAG, no cycles | reject, naming the ref |
| 4 | Handler resolution — every `phpStep` on the allow-list (D9) | reject, naming the handler |
| 5 | **Self-test dry-run — REQUIRED, >= 1 vector, executed in-process** | reject on any mismatch |
| 6 | Provenance — `verified: false` / `placeholder: true` allowed but **stamped on the run** | activate + stamp |
| 7 | Admin-only upload (`AuthorizedAdminSetting`) | 403 |
| 8 | Determinism + bounds — pure; step-count + expr-depth caps | reject over cap |
| 9 | No accidental shadowing of a bundled pack (D7) | reject unless explicitly activated |

**Gate 5 is the keystone.** A pack that cannot reproduce its own declared arithmetic never activates.
And it pays for itself immediately: **the NL pack's self-test block is the 9 existing golden
fixtures.** The machinery that gates a third-party Estonian pack is the exact machinery that proves
the NL migration was behaviour-identical. One mechanism, two jobs — and the acceptance contract is
enforced by production code rather than by a test someone could later "fix".

## Risks

| risk | mitigation |
| --- | --- |
| **`piecewiseAccrue` is NL-shaped.** It was designed by staring at `arkChain()`; its round-then-cap ordering is Rekenvoorschriften arcana | Named in ADR-101 as **on probation**. Country two either validates it or exposes it. Recorded, not hidden — this is the change's central unproven claim |
| **`expr` becomes a scripting language.** Pressure will come from VCR | Closed grammar, fixed op list, depth cap, forbidden by ADR-101 decision 2. Widening `expr` voids the trust model. Use the hatch |
| **A digit drifts in the NL migration** | The acceptance contract is enforced by gate 5 in production code + 2 unmodified test classes. A drift cannot ship green |
| **Uploaded pack computes wrong wages** | 9 blocking gates (D11); self-tests required; no arbitrary code; admin-only; bundled NL unshadowable by accident |
| **Interpreter is slower than straight-line PHP** | Per-employee per-period arithmetic over ~15 steps; the existing service already does far heavier ObjectService IO per employee. Measure before optimising |
| **Two jurisdiction homes (code + OpenRegister) diverge** | One resolver, one key, bundled-wins default, explicit recorded override (D7) |
| **The scope cut leaves NL law outside the "jurisdiction" pack** (bijtelling, beslagvrije voet) | Recorded honestly in D8 as a scope cut, not a principle. Bijtelling is a named follow-up |
