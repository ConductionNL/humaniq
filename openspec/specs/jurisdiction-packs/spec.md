---
capability: jurisdiction-packs
status: done
built_by: openspec/changes/archive/2026-07-15-jurisdiction-packs
---

# jurisdiction-packs Specification

**Status**: done
**Scope**: hrmq (re-expresses the merged `payroll-core-engine`'s chain; consumes `payroll-core-schema`)
**OpenSpec changes**:
- [jurisdiction-packs](../../changes/archive/2026-07-15-jurisdiction-packs/) _(archived 2026-07-15)_ —
  a jurisdiction becomes uploadable configuration: an ordered step-DSL (`lib/Payroll/Dsl/`) that a
  small pure interpreter executes, incidence as a first-class step property so `netto` is a FOLD
  rather than a hardcoded NL rule, a named PHP escape hatch resolved at pack-validation time against
  a compile-time allow-list (shipping with zero handlers), and NL re-expressed as the first bundled
  pack (`lib/Standards/packs/nl-2026.pack.json`) reproducing all 9 golden fixtures digit-for-digit
  with `PayrollCalculator`'s public contract unchanged. See **ADR-101**.

## Purpose

hrmq owns the first open-source Dutch payroll engine, and its *parameters* were already data
(`lib/Standards/tables/nl-2026.json`). The **chain that consumed them was hardcoded Dutch PHP**:
`PayrollCalculator::calculate()` was 130 lines of numbered NL steps ending in a netto line that was
literally `tvl - loonheffing`. A second country meant a second calculator class, a second result DTO
and a second test suite — per country, forever. That is not a platform; it is a per-country fork farm.

This capability makes a jurisdiction an **uploadable, exchangeable artefact**. A pack declares its
steps in a closed step-DSL; a pure interpreter runs them; a named PHP handler exists for genuine
national exotica. NL ships as the first pack and is behaviour-identical — which is the only available
proof the abstraction is real.

**The forcing constraint held.** All 9 golden fixtures under `tests/fixtures/payroll-2026/` reproduce
digit-for-digit through the pack path (anchor: EUR 3.800 wit/maand -> loonheffing 718,83 /
arbeidskorting 473,75 / netto 3.081,17), `PayrollCalculatorTest` and `BalancingInvariantTest` pass
UNMODIFIED, and `SickPayCalculator` — out of pack scope — is provably untouched.

## Known limits (recorded, not hidden)

- **`piecewiseAccrue` is on probation** (ADR-101). It was designed by staring at NL's `arkChain()`;
  its round-each-term-then-cap ordering is Rekenvoorschriften arcana. Country two either validates it
  or exposes it as NL-shaped. This is the capability's central unproven claim.
- **The DSL cannot express VCR** (cross-period state) or inverse solves (the 30%-ruling
  netto-operation). Stated up front so nobody widens `expr` to compensate — widening it would void the
  trust model that allows executing an uploaded pack at all.
- **Some NL law lives outside the "jurisdiction" artefact**: bijtelling privégebruik auto and the
  loonbeslag *beslagvrije voet* stay in `PayrollRunService`, because they read stored objects while
  the interpreter is pure and object-blind. That is a *scope* cut, not a principled one. Bijtelling is
  a named follow-up.
- **A second real country is unproven.** This capability proves the mechanism against NL and ships the
  upload surface.

## Requirements

### Requirement: A jurisdiction pack SHALL declare its gross-to-net chain as ordered configuration executed by a pure interpreter (REQ-JP-001)

A jurisdiction pack SHALL be a single self-contained JSON artefact declaring `id`, `jurisdiction` (ISO 3166-1 alpha-2), `taxYear`, `packVersion` (semver), `dslVersion`, `tables`, `currency`, `basedOn`, `inputs`, `bindings`, `steps` and `selfTest`.

`jurisdiction` and `taxYear` SHALL be declared fields, never substrings parsed from the pack id. Parameter values SHALL be referenced from the pack's declared `tables` corpus via `@table.*` refs and SHALL NOT be copied into the pack, so the verified `{value, source, verified}` leaves keep exactly one home. A pack SHALL be exchangeable: downloadable from one instance and uploadable to another with no code change on either side.

#### Scenario: A pack declares jurisdiction and tax year as fields
- **GIVEN** the bundled `nl-2026` pack
- **WHEN** the resolver looks up a pack for a run
- **THEN** it matches on the pack's declared `jurisdiction: "NL"` and `taxYear: 2026` fields
- **AND** no code path parses the country out of the pack id or out of the run period

#### Scenario: A pack references parameters rather than copying them
- **GIVEN** the `nl-2026` pack and the verified `lib/Standards/tables/nl-2026.json`
- **WHEN** the pack's steps resolve their rates, brackets and thresholds
- **THEN** every parameter arrives through an `@table.*` ref into the existing tables file
- **AND** no rate, bracket or threshold value is duplicated inside the pack

### Requirement: The step-DSL vocabulary SHALL be closed and total, never a scripting language (REQ-JP-002)

The interpreter SHALL support exactly the MVP vocabulary: step ops `rate`, `cappedRate`, `bracket` (affine mode), `taper`, `piecewiseAccrue`, `quantize`, `clamp`, `match`, `expr` and `phpStep`; the binding op `derive`; a `round` modifier (`floor|ceil|nearest` over `cent|euro|decimals`) on every step and binding; the predicates `eq/ne/lt/lte/gt/gte`, `and/or/not`, `ageReached(dob, years, asOf, granularity)` and `yearOf(date)`; and the reference grammar `@input.*`, `@table.*`, `@step.*`, `@binding.*`, `@period.*`, `@pack.*`.

The `expr` op SHALL be a closed, total arithmetic grammar (`+ - * /`, `min max abs round floor ceil`, parentheses, refs and literals) with no loops, no recursion, no function definitions, no IO and no clock. The interpreter SHALL be pure — no container, no clock, no IO beyond the injected tax tables — so that the same `(input, pack, tables)` always yields the same output. All monetary arithmetic SHALL be integer cents. A step SHALL reference only steps declared earlier, so every pack is a finite acyclic graph evaluated once.

#### Scenario: An unknown op is rejected rather than ignored
- **GIVEN** a pack declaring a step with `op: "eval"`, which is not in the vocabulary
- **WHEN** the pack is validated
- **THEN** validation fails and the error names the unknown op
- **AND** no step of that pack is ever executed

#### Scenario: The interpreter is deterministic
- **GIVEN** the same input, pack and tables
- **WHEN** the interpreter runs the chain a thousand times, on different days and hosts
- **THEN** every run produces byte-identical integer-cents output

#### Scenario: piecewiseAccrue rounds each term before capping it
- **GIVEN** the NL ARK segment 2 with tabelloon `L = 4557600` cents, where the accumulated term is 530001,58 cents and the segment cap `arkm2` is 530000 cents
- **WHEN** `piecewiseAccrue` evaluates that segment with `roundTerm: 3`
- **THEN** the term is rounded to 5 decimals of a euro first and the segment cap binds second, yielding 530000
- **AND** the chain result matches `PayrollCalculator::arkChain()` at HEAD digit-for-digit

### Requirement: Every step SHALL declare its incidence and net SHALL be derived from it (REQ-JP-003)

Every step SHALL declare exactly one incidence: `reduces-net` (subtracted from gross to reach net), `employer-cost` (paid by the employer, never touching net), `informative` (reported with no cash effect) or `reserve` (accrued now, paid later).

The interpreter SHALL derive net as `gross - sum(step.amount where incidence == 'reduces-net')` and employer charges as `sum(step.amount where incidence == 'employer-cost')`. A pack SHALL NOT declare a net step, and the interpreter SHALL NOT contain any jurisdiction-specific net rule. The Dutch property that employer charges never reduce net SHALL therefore hold as a consequence of NL's employer steps declaring `employer-cost`, not as interpreter logic.

#### Scenario: NL net emerges from the incidence fold
- **GIVEN** the NL anchor chain where `loonheffing` (71883 cents) is the only `reduces-net` step and zvw/awf/aof/wko/whk are all `employer-cost`
- **WHEN** the interpreter folds incidence over a gross of 380000 cents
- **THEN** net is 308117 cents (EUR 3.081,17), identical to `PayrollCalculator` Step 10 at HEAD
- **AND** employer charges are 65094 cents, identical to `CalculationResult::$employerChargesCents` at HEAD

#### Scenario: A contribution that reduces net needs no interpreter change
- **GIVEN** a pack declaring an employee social contribution with `incidence: "reduces-net"`
- **WHEN** the interpreter folds incidence
- **THEN** that contribution is subtracted from gross alongside the tax step
- **AND** no interpreter code changed to make that happen

#### Scenario: A reservation affects neither net nor employer cost
- **GIVEN** the NL vakantiegeld step (30400 cents) declaring `incidence: "reserve"`
- **WHEN** the interpreter folds incidence
- **THEN** net stays 308117 cents and employer charges stay 65094 cents
- **AND** the reserved amount is still reported as `vakantiegeldReserved`

### Requirement: NL SHALL be re-expressed as a pack using the declarative vocabulary only (REQ-JP-004)

`lib/Standards/packs/nl-2026.pack.json` SHALL express the entire NL chain — vakantiegeld reservering, tabelloon, schijventarief, AHK/ARK/OUK heffingskortingen, loonheffing, the informative volksverzekeringen split, Zvw and the four capped employer premiums — using the declarative vocabulary with zero `phpStep` uses.

The AOW-age switch, the groen table-set switch, the `loonheffingskortingToegepast` gate and the `verzekeringsplichtig` (DGA) gate SHALL all be expressed as declared `when` predicates and `match` selections over bindings, not as interpreter branches. `PayrollCalculator`'s private helpers `arkChain()`, `selectBracket()`, `isAowAge()`, `schijvenSet()`, `floorEuroCents()`, `ceilEuroCents()`, `round5Cents()` and `round2Cents()` SHALL be deleted, their behaviour absorbed into interpreter ops — any survivor means NL logic stayed in PHP.

#### Scenario: The NL pack uses no escape hatch
- **GIVEN** the bundled `nl-2026` pack
- **WHEN** its steps are enumerated
- **THEN** no step declares `op: "phpStep"`
- **AND** the handler registry ships with zero registered handlers

#### Scenario: The DGA gate is a declared predicate
- **GIVEN** the dga-anchor case with `verzekeringsplichtig: false`
- **WHEN** the interpreter evaluates the awf/aof/wko/whk steps' `when` predicates
- **THEN** all four are skipped and report 0 cents
- **AND** loonheffing, arbeidskorting, zvw, vakantiegeldReserved and net are byte-identical to the non-DGA anchor, with net still 308117 cents

#### Scenario: The AOW-age switch is a table-set selection
- **GIVEN** an employee born 1959-03-15 in period 2026-06 with the tables' AOW-leeftijd of 67
- **WHEN** the `aow` binding evaluates `ageReached` at month granularity and the `schijvenSet` binding matches on it
- **THEN** the AOW bracket set and AOW korting columns are selected by data, not by an interpreter branch
- **AND** the aow-age fixture reproduces loonheffing 291,42 and net 3.508,58

### Requirement: The escape hatch SHALL name an allow-listed handler and SHALL fail at validation time (REQ-JP-005)

A pack step MAY declare `op: "phpStep"` with a `handler` name. The interpreter SHALL resolve that name against a compile-time allow-list of handlers implementing `JurisdictionStepHandlerInterface` that already ship inside hrmq. A pack SHALL NOT be able to supply code, a class path, a callable, a file, or any other executable artefact — only a name and parameters.

Handler resolution SHALL happen at pack-validation time, never at runtime. A pack naming a handler that does not exist SHALL be rejected at upload with the offending handler name in the error, and SHALL NOT be stored, activated, or executed. The interpreter SHALL NOT skip, ignore, or degrade gracefully around an unresolvable handler at runtime.

#### Scenario: A pack naming a missing handler is rejected loudly at upload
- **GIVEN** an uploaded pack declaring `op: "phpStep"` with `handler: "fr-cotisations-speciales"`, which is not on the allow-list
- **WHEN** the pack is validated
- **THEN** the upload is rejected and the error names `fr-cotisations-speciales`
- **AND** the pack is not stored and never reaches a payroll run

#### Scenario: A pack cannot supply executable code
- **GIVEN** an uploaded pack whose step carries a class path, a callable, or an inline code string
- **WHEN** the pack is validated
- **THEN** the upload is rejected
- **AND** no code from the pack is ever loaded, evaluated or executed

### Requirement: A pack SHALL carry its own golden vectors and SHALL prove them before activation (REQ-JP-006)

Every pack SHALL carry a `selfTest` block of at least one `{input, expected}` vector. On upload the validator SHALL execute every vector in-process through the interpreter and SHALL reject the pack on any mismatch — a pack that cannot reproduce its own declared arithmetic SHALL never activate.

Validation SHALL additionally reject: a leaf failing JSON Schema, an unknown op, an unresolvable `@table.*` ref, a `@step.*` forward reference or cycle, and an unresolvable escape-hatch handler. Upload SHALL be admin-only. A pack containing any `verified: false` or `placeholder: true` leaf MAY activate but SHALL stamp that provenance onto every run it computes, so downstream consumers see an unverified figure rather than assuming engine truth. An uploaded pack SHALL NOT silently shadow a bundled pack claiming the same `(jurisdiction, taxYear)`; overriding a bundled pack SHALL require an explicit admin activation recorded on the pack object, and SHALL still pass every other gate. Runs SHALL stamp `engineVersion` as `{packId}@{packVersion}`.

#### Scenario: A pack whose self-tests fail is rejected
- **GIVEN** an uploaded pack whose `selfTest` vector declares an expected net its own steps do not produce
- **WHEN** the validator dry-runs the vectors
- **THEN** the pack is rejected and the error reports the mismatching component, the expected value and the computed value
- **AND** the pack is never activated and never pays anyone

#### Scenario: A pack with no self-test vectors is rejected
- **GIVEN** an uploaded pack with an empty or absent `selfTest` block
- **WHEN** the pack is validated
- **THEN** the upload is rejected for carrying no golden vectors

#### Scenario: An upload cannot silently replace the bundled NL pack
- **GIVEN** an uploaded pack claiming `jurisdiction: "NL"` and `taxYear: 2026`, which the bundled pack already owns
- **WHEN** the pack is validated
- **THEN** the upload is rejected unless an admin explicitly activates it as a recorded override
- **AND** the bundled NL pack stays the resolved pack for NL 2026 runs by default

#### Scenario: A dangling reference is rejected
- **GIVEN** an uploaded pack whose step references `@table.loonheffing.doesNotExist` or a `@step.*` declared later in the chain
- **WHEN** the pack is validated
- **THEN** the upload is rejected and the error names the offending reference

#### Scenario: Placeholder provenance is stamped, not silenced
- **GIVEN** the NL pack resolving the employer Whk leaf, which carries `placeholder: true`
- **WHEN** a run computes through that pack
- **THEN** the run activates and stamps the placeholder provenance
- **AND** downstream consumers can see the figure is a stand-in rather than a verified rate

### Requirement: The NL migration SHALL be behaviour-identical against the existing golden acceptance set (REQ-JP-007)

Re-expressing NL as a pack SHALL reproduce all 9 existing golden fixtures under `tests/fixtures/payroll-2026/` digit-for-digit through the pack path: anchor, aow-age, bracket-3, groen, min-wage, no-korting, part-time, bijtelling-anchor and dga-anchor.

`PayrollCalculator`'s public contract SHALL remain green and unchanged: `calculate(CalculationInput, TaxTables): CalculationResult` keeps its signature, `CalculationResult` keeps its 18 fields, and `PayrollRunService` is unchanged except for pack resolution. `tests/Unit/Payroll/PayrollCalculatorTest.php` and `tests/Unit/Payroll/BalancingInvariantTest.php` SHALL pass UNMODIFIED — needing to edit either to make the migration pass SHALL be treated as drift and SHALL fail the change. `SickPayCalculator` is out of pack scope and SHALL be provably untouched, with `tests/fixtures/sick-pay-2026/anchor.json` still green. This is a behaviour-identical migration, not a rewrite with drift.

#### Scenario: The anchor contract survives the migration digit-for-digit
- **GIVEN** EUR 3.800,00 wit, korting toegepast, below AOW, period 2026-02, awf low, aof laag, whk 1,52
- **WHEN** `calculate()` runs through the NL pack and the interpreter
- **THEN** loonheffing is EUR 718,83, arbeidskorting EUR 473,75, volksverzekeringen EUR 470,86, zvw EUR 231,80, werknemersverzekeringen EUR 419,14, employerCharges EUR 650,94, vakantiegeldReserved EUR 304,00 and nettoPay EUR 3.081,17
- **AND** appliedTaxRate is 18,92

#### Scenario: All nine payroll golden fixtures reproduce exactly
- **GIVEN** the 9 fixtures under `tests/fixtures/payroll-2026/`
- **WHEN** each runs through the pack path against the real `nl-2026` tables
- **THEN** every asserted component matches its `expected` block cents-exact
- **AND** `PayrollCalculatorTest` passes without any modification

#### Scenario: The balancing invariants hold as the interpreter's own fold
- **GIVEN** every payroll golden fixture
- **WHEN** `BalancingInvariantTest` runs unmodified
- **THEN** nettoPay equals grossPay minus loonheffing for every fixture
- **AND** that equation holds because it is the interpreter's incidence fold, not because an NL rule asserts it

#### Scenario: Sick pay is out of scope and provably undisturbed
- **GIVEN** `tests/fixtures/sick-pay-2026/anchor.json` and the unchanged `SickPayCalculator`
- **WHEN** the sick-pay suite runs after the migration
- **THEN** doorbetaaldLoon is EUR 2.660,00 and payableGross EUR 2.660,00, unchanged
- **AND** no pack, interpreter or step op participated in that calculation

### Requirement: An uploaded pack SHALL be bounded and unable to exhaust the host (REQ-JP-008)

Because a pack is authored outside hrmq and executed inside it, the interpreter SHALL evaluate every pack as a total function that terminates. The DSL SHALL provide no loop, no recursion and no function definition, and the validator SHALL enforce a maximum step count and a maximum expression nesting depth, rejecting packs that exceed either.

The interpreter SHALL perform no IO, no network access and no clock reads during evaluation. Validation rejections SHALL always name the offending op, reference, handler or bound, so a rejected pack is diagnosable by its author rather than opaquely refused.

#### Scenario: An over-deep expression is rejected at upload
- **GIVEN** an uploaded pack whose `expr` step nests beyond the configured maximum depth
- **WHEN** the pack is validated
- **THEN** the upload is rejected and the error names the depth bound that was exceeded

#### Scenario: Evaluation cannot reach the network or the clock
- **GIVEN** any validated pack
- **WHEN** the interpreter evaluates its chain
- **THEN** no IO, network call or clock read occurs
- **AND** the only external data are the injected tax tables and the supplied inputs
