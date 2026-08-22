# Jurisdiction pack schema

A **jurisdiction pack** declares one country's gross-to-net chain as configuration
that a small pure interpreter executes. Onboarding a country means **uploading a
pack**, not shipping PHP (ADR-101).

This is the companion to `../tables/SCHEMA.md`. The tables corpus already made a
tax year's **parameters** data; a pack makes the **chain that consumes them** data
too. The two stay separate on purpose: a pack *references* the verified corpus and
never copies it, so `{value, source, verified}` leaves keep exactly one home.

Bundled packs live here in code, mirroring `../tables/` — universal facts live in
code, not in OpenRegister. Uploaded packs live as `JurisdictionPack` objects
(per-tenant config, ADR-022). **Bundled wins by default**: an upload may not claim
a `(jurisdiction, taxYear)` a bundled pack already owns unless an admin explicitly
activates it as a recorded override. NL is the engine's regression contract; it
does not get overwritten by a stray upload.

## File naming

`{jurisdiction}-{taxYear}.pack.json`, lowercase (e.g. `nl-2026.pack.json`). The
`id` (`nl-2026`) plus `packVersion` form the stamp every run carries:
`PayrollRun.engineVersion = {packId}@{packVersion}`.

**`jurisdiction` and `taxYear` are declared FIELDS, never substrings parsed from
the filename or the id.** The resolver matches on the declared fields.

## Top-level shape

```jsonc
{
  "id": "nl-2026",
  "jurisdiction": "NL",          // ISO 3166-1 alpha-2, DECLARED
  "taxYear": 2026,               // DECLARED
  "packVersion": "1.0.0",        // semver — the RuleCatalogue::VERSION analogue
  "dslVersion": "1.0",           // the interpreter contract
  "tables": "nl-2026",           // the TaxTables id @table.* resolves against
  "currency": "EUR",
  "grossRef": "@binding.tvl",    // which value is gross, for the incidence fold
  "basedOn": [ {"doc": "...", "url": "..."} ],
  "inputs":   { /* the declared input contract */ },
  "bindings": [ /* named intermediates, NO incidence */ ],
  "steps":    [ /* ordered; each declares incidence */ ],
  "selfTest": { "vectors": [ /* REQUIRED, >= 1 */ ] }
}
```

## Incidence — and why there is no `netto` step

Every step declares exactly one **incidence**: what its amount *does* to the
payslip.

| incidence | effect | NL steps |
| --- | --- | --- |
| `reduces-net` | subtracted from gross to reach net | `loonheffing` |
| `employer-cost` | employer pays; never touches net | `zvw`, `awf`, `aof`, `wko`, `whk` |
| `informative` | reported; no cash effect | `volksverzekeringen`, `arbeidskorting`, `appliedTaxRate` |
| `reserve` | accrued now, paid later | `vakantiegeld` |

The interpreter then **derives**:

```
net             = gross - sum(amount where incidence == 'reduces-net')
employerCharges =         sum(amount where incidence == 'employer-cost')
```

**A pack must not declare a net step**, and the interpreter contains no
jurisdiction-specific net rule. The Dutch property that employer charges never
reduce take-home pay is not built in — it *falls out* of the NL pack declaring
its employer charges `employer-cost`. A country whose pension contribution
reduces net declares `reduces-net`, and the fold does the rest with no
interpreter change.

## Step ops

Every step is `{id, op, incidence, when?, round?, ...params}`.

| op | params | semantics |
| --- | --- | --- |
| `rate` | `base, rate` | `base * rate / 100` |
| `cappedRate` | `base, rate, cap` | `min(base, cap) * rate / 100` |
| `bracket` | `value, table, unit, mode: affine` | first row where `value <= tot` (`null` = unbounded), then `(value - a) * percentage / 100 + c` |
| `taper` | `base, value, threshold, rate, floor?` | `max(floor, base - max(0, value - threshold) * rate)` |
| `piecewiseAccrue` | `value, segments[{upTo, rate, cap}], tail{from, rate}?, zeroAbove?, roundTerm` | capped piecewise build-up; each term rounded to `roundTerm` decimals **then** capped at that segment's ceiling; then the tail; then hard zero |
| `quantize` | `value, step, mode` | round `value` to a multiple of `step` |
| `clamp` | `value, min?, max?` | bound a value |
| `match` | `on, cases{}, default?` | select a value (or a ref) by a subject |
| `expr` | `expression` | the closed arithmetic grammar (below) |
| `phpStep` | `handler, params?` | the named escape hatch (below) |

A binding is `{id, op: "derive", using: {op, ...}, round?}` and carries **no**
incidence — it is a named intermediate, not money out. `using` may be any step op
or any predicate.

**Rounding**: `round: {mode: floor|ceil|nearest, unit: cent|euro|decimals, decimals?}`
on any step or binding.

**Predicates** (for `when` and boolean bindings): `eq ne lt lte gt gte`,
`and or not` (over `of`), `ageReached(dob, years, asOf, granularity: month|day)`,
`yearOf(value, default?)`.

## References

| ref | resolves to |
| --- | --- |
| `@input.x` | a declared input |
| `@table.a.b.c` | a leaf's `value` in the pack's declared `tables` corpus |
| `@step.id` | an **earlier** step's amount (cents) |
| `@binding.id` | an earlier binding |
| `@period.year`, `@period.lastDay` | derived from the supplied run period |
| `@pack.currency`, `@pack.taxYear`, ... | pack metadata |

A step may reference only things declared **earlier**, so every pack is a finite
acyclic graph — forward references and cycles are rejected at upload.

A path segment may be dynamic: `@table.loonheffing.schijven[@binding.schijvenSet]`.
That is how a table-set switch stays *data* instead of becoming an interpreter
branch.

### The `:cents` suffix — units are declared, not guessed

The corpus carries **no unit marker**: `loonheffing.Lv` is `54` (euro),
`zvw.werkgeversheffing` is `6.1` (a percentage) — both bare numbers. A generic
reader cannot tell them apart, and teaching the *interpreter* which NL leaves are
euros would put jurisdiction knowledge straight back into PHP.

So the **pack declares the unit**:

```jsonc
"@table.loonheffing.Lv:cents"        // euro leaf -> integer cents
"@table.zvw.werkgeversheffing"       // percentage -> passed through
```

`bracket` does the same via its required `unit` field, for its rows' `tot`/`a`/`c`.

## `expr` is a calculator, not a language

`+ - * /`, the functions `min max abs round floor ceil`, parentheses, refs and
numeric literals. **That is the whole grammar.** No loops, no recursion, no
function definitions, no variables, no strings, no IO, no clock. Expressions are
depth-capped and length-capped, and every one is a finite tree evaluated once.

**Widening `expr` is forbidden** (ADR-101 decision 2). It is what keeps "uploaded
config" from quietly becoming "uploaded code" — the difference between a DSL and a
remote-code-execution endpoint with extra validation. The pressure will come from
VCR (cumulative year-to-date recalculation), which the DSL **cannot** express
because it is per-period pure by construction. Say no; use the hatch.

Reference segments inside an expression may not contain `-`, which is
unambiguously subtraction there.

## The escape hatch names a handler; it can never define one

```jsonc
{"id": "some-exotica", "op": "phpStep", "handler": "nl-vcr-cumulative", "incidence": "reduces-net"}
```

A pack supplies a **name** and data-only params. It can never supply code, a class
path, a callable or a file — any step carrying such a key is rejected outright.
The name is resolved against a **compile-time allow-list** of handlers that
already ship inside humaniq, **at validation time**. A pack naming a handler that
does not exist is **rejected at upload with the name in the error**; it never
reaches a run to be silently skipped, because a skipped step quietly under-taxes
someone.

**humaniq ships zero handlers, and the NL pack uses zero.** The registry exists so
the wall is built before the first country hits it.

## `selfTest` is required

Every pack MUST carry at least one golden vector. On upload the validator runs
them **in-process through the interpreter** and rejects the pack on any mismatch:
a pack that cannot reproduce its own declared arithmetic never activates.

Portable (and the only form an **uploaded** pack may use):

```jsonc
{"period": "2026-02", "input": {"gross": 380000, ...}, "expected": {"@step.loonheffing": 71883, "@net": 308117}}
```

Expected values are in **cents**, keyed by `@net`, `@employerCharges`, `@gross`,
`@step.*` or `@binding.*`.

The bundled NL pack instead uses the `$fixture` form, which references humaniq's own
golden fixtures in *`CalculationInput` vocabulary* (euros, `awfTariff: low|high`)
and maps components via `selfTest.fixtureMap`:

```jsonc
{"$fixture": "payroll-2026/anchor.json"}
```

That form is **bundled-only**: it reads a repository file (an upload must never
steer that), and an uploaded pack must be self-contained so its recipient can
prove it before paying anyone.

**NL's 9 golden fixtures ARE the NL pack's self-test block.** The machinery that
gates a third-party pack is the exact machinery that proved the NL migration was
behaviour-identical. One mechanism, two jobs.

## Upload gates (all blocking)

1. Structure — envelope + leaves
2. Vocabulary — every `op` known to `dslVersion` (rejected **naming the op**)
3. References — `@table.*` resolves; `@step.*` names an earlier step (naming the ref)
4. Handler resolution — every `phpStep` on the allow-list (naming the handler)
5. **Self-test dry-run — required, >= 1 vector, in-process** (naming the component, expected and computed)
6. Provenance — `verified: false` / `placeholder: true` leaves activate but are **stamped onto the run**
7. Admin-only upload
8. Bounds — step count + expression depth (naming the bound)
9. No accidental shadowing of a bundled pack

## Known limits (honest, and named up front)

- **No VCR.** The DSL is per-period pure and cannot express cross-period state.
- **No inverse solves** (e.g. the 30%-ruling netto-operation) — forward chain only.
- **`bracket` is affine-only.** NL's tables ship precomputed `a`/`c` constants; a
  progressive-sum mode is a named follow-up.
- **`piecewiseAccrue` is on probation.** It was designed by staring at NL's
  `arkChain()`; its round-then-cap ordering is Rekenvoorschriften arcana. Country
  two either validates it or exposes it as NL-shaped. This is the change's central
  unproven claim.
- **Some NL law lives outside the pack.** Bijtelling privégebruik auto and the
  loonbeslag *beslagvrije voet* are computed in `PayrollRunService`, because they
  read stored objects while the interpreter is pure and object-blind. That is a
  *scope* cut, not a principled one, and country two will feel it. Bijtelling is a
  named follow-up.
