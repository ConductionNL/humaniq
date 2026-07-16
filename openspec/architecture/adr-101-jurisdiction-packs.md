# ADR-101: Jurisdiction packs — a declarative step-DSL with a named PHP escape hatch

**Status**: accepted — implemented by
[jurisdiction-packs](../changes/archive/2026-07-15-jurisdiction-packs/), canonical spec
[jurisdiction-packs](../specs/jurisdiction-packs/spec.md)

**Date**: 2026-07-16

> **Outcome (2026-07-16).** The forcing constraint held: NL re-expressed as
> `lib/Standards/packs/nl-2026.pack.json` reproduces all 9 golden fixtures digit-for-digit with
> `PayrollCalculator::calculate()`'s signature and `CalculationResult`'s 18 fields unchanged, and both
> pinning test classes pass unmodified. Decision 1 landed as specified: `PackInterpreter` names no
> jurisdiction, and NL's netto falls out of the incidence fold. Decision 2 held — `expr` was NOT
> widened. Decision 3 ships with zero registered handlers and zero NL uses, as predicted. Decision 4's
> gate 5 is what proved the migration.
>
> Two things the analysis did not anticipate, both recorded in the change's tasks.md: the tables
> corpus carries **no unit marker** on its leaves, so the ref grammar needed a pack-declared `:cents`
> suffix to keep unit knowledge out of the interpreter; and `engineVersion = {packId}@{packVersion}`
> **broke the `nl-engine-table-version` corpus predicate**, which asserted the stamp was a known table
> id. The predicate and its rule statement were widened to accept both forms.
>
> One requirement did not land as written: REQ-JP-006's "placeholder provenance is stamped onto the
> run". The mechanism exists and is tested, but NL's flagged set is **empty** — the corpus's only
> `placeholder: true` leaf (employer Whk) is not referenced by the pack; it is an employer-level input
> the service resolves. The scenario rests on a false premise about NL. See the change report.

> **Numbering note.** hrmq's local ADR namespace lives in `openspec/architecture/` and today holds
> exactly one record (`adr-001-information-architecture.md`). That number already collides with the
> company-wide `hydra/openspec/architecture/adr-001-data-layer.md` — hrmq specs reference "ADR-001"
> 257 times meaning the local IA record, while the same corpus references ADR-022/031/032 meaning
> hydra's. Rather than deepen that ambiguity (ADR-002 is taken company-wide by API conventions, and
> hydra's sequence runs to 062 and is still growing), this record opens a **local 100+ block**:
> ADR-1xx is always hrmq-local. Renaming is a one-line change if the fleet prefers otherwise.

## Context

hrmq owns the first open-source Dutch payroll engine. Its parameters are already data —
`lib/Standards/tables/nl-2026.json` carries every 2026 rate, bracket and threshold as a
`{value, source, verified}` leaf, and `SCHEMA.md` already promises that "a new tax year is a
data-only change".

That promise is only half true. The **parameters** are data; the **chain that consumes them** is
hardcoded Dutch PHP. `PayrollCalculator::calculate()` is 130 lines of numbered NL steps: a
vakantiegeld reservation, a tabelloon floor-to-multiple, a bracket pick, an AHK taper, an
`arkChain()` tapered min-chain, a whole-euro floor, an informative volksverzekeringen split, a
capped Zvw levy, four capped employer premiums behind a `verzekeringsplichtig` gate, and a netto
line that is literally `tvl - loonheffing`. Every one of those steps names a Dutch concept. Onboarding
a second country today means shipping a second calculator class, a second result DTO, and a second
test suite — per country, forever. That is not a platform; it is a per-country fork farm.

The goal is that other countries arrive as **uploaded configuration**, exactly the way OpenBuild
exchanges apps as config. A jurisdiction pack must be an artefact you can author, validate, upload,
version, and hand to someone else — without shipping PHP.

The forcing constraint: **NL must be re-expressed in the new mechanism and stay behaviour-identical.**
The engine has a hard regression contract — €3.800 wit/maand produces loonheffing €718,83,
arbeidskorting €473,75, netto €3.081,17 — pinned by 9 golden fixtures under
`tests/fixtures/payroll-2026/` plus `BalancingInvariantTest`. If NL cannot be re-expressed digit-for-digit,
the abstraction is aspirational and we have learned something important before writing the upload endpoint.

## Decision

**A jurisdiction pack is uploadable configuration describing an ordered list of calculation steps in a
closed, total step-DSL, which a small pure interpreter executes; plus a named PHP escape hatch for
genuine national exotica.** NL ships as the first pack and must reproduce all 9 payroll golden
fixtures digit-for-digit with `PayrollCalculator`'s public contract unchanged.

Four things make this decision load-bearing rather than decorative.

### 1. Incidence is a first-class step property — and netto stops being hardcoded

The single least portable line in the current engine is Step 10:

```php
$nettoPayCents = ($tvl - $loonheffingCents);   // employer charges never reduce net
```

That is a true statement about the Netherlands, not about payroll. NL's Zvw and Awf/Aof/Wko/Whk are
employer charges; in most other jurisdictions employees pay social contributions that **do** reduce
take-home pay. A pack that could only ever produce `gross - tax` would be an NL calculator with a
JSON hat on.

So every step declares its **incidence** — what the amount *does* to the payslip:

| incidence       | meaning                                                        | NL steps                                   |
| --------------- | -------------------------------------------------------------- | ------------------------------------------ |
| `reduces-net`   | subtracted from gross to reach net                             | loonheffing                                |
| `employer-cost` | employer pays it; never touches net                            | Zvw, Awf, Aof, Wko, Whk                    |
| `informative`   | reported on the payslip; no cash effect                        | volksverzekeringen split, arbeidskorting, appliedTaxRate |
| `reserve`       | accrued now, paid out later; not cash this period              | vakantiegeld 8%                            |

The interpreter then **derives** net as a fold, never as a step:

```
net = gross - sum(step.amount for step where step.incidence == 'reduces-net')
```

For NL that fold yields `tvl - loonheffing` — bit-for-bit today's Step 10 — but as an *emergent
consequence* of every employer step honestly declaring `employer-cost`, not as a rule baked into
PHP. A country whose pension contribution reduces net says `reduces-net` and the fold does the rest.
`BalancingInvariantTest`'s net equation stops being an assertion about NL and becomes the
interpreter's own structural invariant.

`reserve` is a fourth incidence the brief did not name, and it is not padding: vakantiegeld is
neither cash to the employee this period nor an employer charge this period — it is a provision.
Folding it into any of the other three would misstate either net or employer cost.

### 2. The DSL vocabulary is closed and total, not a scripting language

Nine step ops (`rate`, `cappedRate`, `bracket`, `taper`, `piecewiseAccrue`, `quantize`, `clamp`,
`match`, `expr`), one binding op (`derive`), a rounding modifier on every step, a `when` predicate,
and a reference grammar (`@input.*`, `@table.*`, `@step.*`, `@binding.*`, `@period.*`). Full table in
the change's `design.md`.

The critical constraint: **`expr` is a calculator, not a language.** Closed arithmetic grammar, no
loops, no recursion, no function definitions, no IO, no clock. Every pack is a finite DAG of steps
evaluated once. This is what keeps "uploaded config" from quietly becoming "uploaded code" — and it
is the difference between a DSL and a remote-code-execution endpoint with extra validation.

### 3. The escape hatch names a handler; it can never define one

A pack step may declare `op: phpStep, handler: "some-name"`. The interpreter resolves that name
against a **compile-time allow-list** of handlers that already ship inside hrmq and implement a
`JurisdictionStepHandlerInterface`. A pack supplies a *name* and parameters; it never supplies code,
a class path, or a callable.

Resolution happens at **pack-validation time, not at runtime**. A pack naming a handler that does not
exist is **rejected at upload** with the offending name in the error — it never reaches a payroll run
to fail silently, and it never "degrades gracefully" to a skipped step that quietly under-taxes
someone. This is the orphaned-capability defect class the fleet has been bitten by repeatedly
(a `register.d` guard naming a missing class throws in this fleet, deliberately); the same discipline
applies here, for higher stakes.

### 4. Trust: a pack must prove itself before it can pay anybody

An uploaded pack computes real wages. Guardrails, all blocking at upload:

1. **JSON Schema** — structural validation of the pack envelope and every leaf.
2. **Vocabulary** — every `op` known to the declared `dslVersion`; unknown op rejected.
3. **References** — every `@table.*` resolves to a real leaf; every `@step.*` names a step declared
   *earlier* (DAG, no forward refs, no cycles).
4. **Handler resolution** — every `phpStep` handler is on the allow-list (decision 3).
5. **Self-test vectors, REQUIRED** — every pack MUST carry at least one `selfTest` vector
   `{input, expected}`. Upload runs them in-process through the interpreter; **any mismatch rejects the
   pack.** A pack that cannot reproduce its own declared arithmetic never activates.
6. **Provenance preserved** — `{value, source, verified}` leaves survive from the tables convention.
   `verified: false` / `placeholder: true` do not block activation (NL's employer Whk is legitimately a
   placeholder today) but are **stamped onto the run**, so downstream sees an unverified figure
   rather than assuming engine truth.
7. **Admin-only upload** — `AuthorizedAdminSetting`, no exceptions.
8. **Determinism + bounds** — pure interpreter, no clock/IO/network; step-count and expression-depth
   caps make every pack a total function that cannot hang.
9. **Bundled packs are not shadowable by accident** — an uploaded pack may not silently claim a
   `(jurisdiction, taxYear)` a bundled pack already owns. Overriding the bundled NL pack requires an
   explicit, recorded admin activation. NL is the regression contract; it does not get overwritten by
   a stray upload.

Requirement 5 is the keystone, and it pays for itself immediately: **NL's 9 golden fixtures become the
NL pack's own self-test block.** The machinery that gates a third-party Estonian pack is the exact
machinery that proves the NL migration was behaviour-identical. One mechanism, two jobs.

### Pack identity

`{jurisdiction}-{taxYear}` (e.g. `nl-2026`) — preserving today's table id, which is already stamped as
`PayrollRun.engineVersion`. But `jurisdiction` and `taxYear` become **separately declared fields**, not
substrings to be parsed. Today `PayrollRunService` does `'nl-'.substr($period, 0, 4)` — the country is
hardcoded in the resolver. It becomes a lookup on `(run.jurisdiction, year-of(period))`, and
`PayrollRun.jurisdiction` already exists (hardcoded `'NL'` at creation). Packs additionally carry a
`packVersion` (semver — the `RuleCatalogue::VERSION` analogue) and a `dslVersion` (the interpreter
contract). `engineVersion` becomes `{packId}@{packVersion}`.

### Scope: what stays outside the pack

`PayrollRunService` performs one pre-calc gross fold (bijtelling) and four post-net folds
(retroAdjustment, leaveBuySell, loonbeslag, sick-pay substitution). **All five stay app-level in this
change**, because the pack interpreter is pure and object-blind while all five are orchestration over
stored OpenRegister objects.

This is an honest scope cut, not a principled boundary, and the ADR records why it is uncomfortable:
bijtelling privégebruik auto and the loonbeslag *beslagvrije voet* are both deeply NL law living
outside the "jurisdiction" artefact. Bijtelling in particular is a pure function of
(cataloguswaarde, rates) and belongs in the pack; it is a named follow-up. The MVP boundary is
"the pure per-period wage chain", which is exactly the surface `PayrollCalculator` owns today.

## Alternatives rejected

### Pure DSL, no escape hatch

**Rejected — but it was close, and the honest trade-off matters.**

The NL analysis found that **every one of NL's 13 pack steps is expressible declaratively**; the hatch
ships with **zero NL uses**. So why keep it?

Because that clean result is partly *manufactured*. One primitive — `piecewiseAccrue` — was designed
by staring at `arkChain()`. Its "round each term to 5 decimals, then cap at that segment's own
ceiling, then accumulate" ordering is Rekenvoorschriften arcana. It is a plausible general shape
(phase-in/phase-out credit schedules are common), but its generality is **unproven until a second
country lands on it**. A pure-DSL stance would have forced that same NL exotica in anyway — as
ever-more-specific ops — until the vocabulary was an NL calculator spelled in JSON. The hatch is what
lets the vocabulary stay small and honest: when something is genuinely national, it gets a name and a
PHP handler instead of contorting the DSL.

The second reason is concrete and near: **NL itself will be the hatch's first customer.** VCR
(voortschrijdend cumulatief rekenen — cumulative year-to-date recalculation) is a named NL
fast-follow, and the DSL **cannot** express it, because VCR requires cross-period state while the DSL
is per-period pure by construction. Same for the 30%-ruling netto-operation (an inverse solve, not a
forward chain). A pure-DSL decision would hit that wall with no exit.

The cost we accept: the hatch is a hole in the "config, not code" promise. Mitigation is decision 3
— packs name handlers, never define them — and a hard rule that the hatch is the **exception**. If a
future pack's step list is mostly `phpStep`, the DSL has failed and that is a finding to escalate, not
to route around.

### Per-country PHP provider (a `JurisdictionCalculatorInterface` per country)

**Rejected.** This is the smallest diff and the most familiar shape, and it is genuinely more
expressive than any DSL — arbitrary PHP always is.

It fails the actual requirement. A country would arrive as a merged PR, a release, and a deploy —
not as an upload. Third parties could not ship their own jurisdiction without commit rights to hrmq;
"exchange a pack like OpenBuild exchanges an app" becomes impossible by construction. It would also
make every country's rounding arcana unauditable-by-diff: 40 countries means 40 hand-written chains
with 40 opportunities to floor where they should ceil, and no shared validator that could ever notice.

The DSL's constraint is the point: a pack you cannot express is a pack you must justify.

## Consequences

- **NL gets rewritten with a gun to its head.** The migration is behaviour-identical or it does not
  land: all 9 payroll golden fixtures digit-for-digit, `BalancingInvariantTest` green,
  `PayrollCalculator::calculate(CalculationInput, TaxTables): CalculationResult` unchanged as a public
  contract. `PayrollCalculator` becomes a thin façade over the interpreter + the NL pack. Callers
  (`PayrollRunService`, both test classes) do not change.
- **A new tax year stays data-only** — and now so does a new *country*.
- **`piecewiseAccrue` is on probation.** Country two either validates it or exposes it as NL-shaped.
  That is the real test of this ADR, and it is deferred, not answered.
- **The DSL cannot do VCR or netto-operations.** Stated up front so nobody discovers it at
  implementation time and quietly widens `expr` to compensate. Widening `expr` into a general language
  would void decision 2 and is forbidden; those go to the hatch or to a future ADR.
- **Trust surface grows.** hrmq gains an admin upload endpoint whose payload determines people's
  wages. Decisions 2, 3 and 4 exist entirely to make that acceptable; none of them is optional.
