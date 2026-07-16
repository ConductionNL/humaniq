---
sidebar_position: 1
description: The country-pluggable payroll calculation engine — jurisdiction packs, the incidence model, how it works, how to run it, and its certification status.
---

# The payroll engine

HRMQ ships a **country-pluggable payroll calculation engine**. The
gross-to-net chain — brackets, credits, employer charges, the whole
formula — is not hardcoded per country in PHP. It is a declarative
**step-DSL** (a jurisdiction pack) executed by a small, pure interpreter
(`lib/Payroll/Dsl/`). A country onboards by **uploading a pack**, not by
shipping a new PHP calculator class.

The Netherlands ships as the first, bundled pack
(`lib/Standards/packs/nl-2026.pack.json`), and as far as the project's
market research has found, HRMQ's NL chain remains the only open-source
Dutch payroll calculation engine available (Odoo's NL payroll
localisation is Enterprise-only).

## Jurisdiction packs: a country is uploaded configuration

A **jurisdiction pack** is one country's gross-to-net chain as a single
self-contained JSON artefact — author it, validate it, version it,
download it, hand it to someone else, all without shipping PHP. Every
pack declares `id`, `jurisdiction` (ISO 3166-1 alpha-2) and `taxYear` as
**separate fields**, never substrings parsed out of an id or a period —
identity is `{jurisdiction}-{taxYear}` (e.g. `nl-2026`), and parameter
values are referenced from the pack's `tables` corpus via `@table.*`
refs rather than copied in, so the verified `{value, source, verified}`
leaves keep exactly one home.

### The step-DSL is closed and total, not a scripting language

The interpreter supports a fixed MVP vocabulary: step ops `rate`,
`cappedRate`, `bracket`, `taper`, `piecewiseAccrue`, `quantize`, `clamp`,
`match`, `expr` and `phpStep`; the binding op `derive`; a `round`
modifier on every step; `when` predicates; and a reference grammar
(`@input.*`, `@table.*`, `@step.*`, `@binding.*`, `@period.*`). The
`expr` op is a closed, total arithmetic calculator — `+ - * /`, `min max
abs round floor ceil`, parentheses, refs and literals — with **no
loops, no recursion, no function definitions, no IO, no clock**. Every
pack is a finite acyclic graph of steps evaluated once, so the same
`(input, pack, tables)` always yields byte-identical output. All
monetary arithmetic is integer cents.

### Net is a fold, not a rule

The single least portable line of the old hardcoded engine was `net =
tvl - loonheffing` — a true statement about the Netherlands, not about
payroll. So every step now declares its **incidence** — what the amount
*does* to the payslip — and net is *derived*, never asserted:

| Incidence | Meaning | NL steps |
| --- | --- | --- |
| `reduces-net` | subtracted from gross to reach net | loonheffing |
| `employer-cost` | employer pays it; never touches net | Zvw, Awf, Aof, Wko, Whk |
| `informative` | reported on the payslip; no cash effect | volksverzekeringen split, arbeidskorting, appliedTaxRate |
| `reserve` | accrued now, paid out later; not cash this period | vakantiegeld 8% |

The interpreter derives `net = gross − sum(step.amount where incidence
== 'reduces-net')`. For NL that fold yields exactly the old `tvl −
loonheffing` result, but as an **emergent consequence** of every NL
employer step declaring `employer-cost` — not as a jurisdiction rule
baked into the interpreter. **The Dutch property that employer charges
never reduce take-home pay is not an engine rule; it falls out of the NL
pack's own declarations.** A country whose employee-borne pension
contribution *does* reduce net simply declares that step
`reduces-net`, and nothing in the interpreter changes.

### The escape hatch names a handler; it can never define one

A pack step may declare `op: "phpStep"` with a `handler` name. The
interpreter resolves that name against a **compile-time allow-list** of
handlers already shipping inside HRMQ — a pack can never supply code, a
class path, or a callable, only a name and parameters. Resolution
happens at **pack-validation time, not at runtime**: a pack naming an
unknown handler is rejected at upload with the offending name in the
error, and never reaches a payroll run to fail silently or "degrade
gracefully" into a skipped step that quietly under-taxes someone.
**HRMQ ships zero registered handlers, and the bundled NL pack uses
zero of them** — every one of NL's steps, including the AOW-age switch,
the groene-tabel selection, and the DGA `verzekeringsplichtig` gate, is
expressed as declared `when` predicates and `match` selections, not
interpreter branches.

### A pack must prove itself before it can pay anybody

Every uploaded pack is validated against a stack of blocking guardrails:
JSON Schema structure; a closed op vocabulary; every `@table.*` and
`@step.*` reference resolving with no forward refs or cycles; every
`phpStep` handler on the allow-list; admin-only upload
(`AuthorizedAdminSetting`); step-count and expression-depth bounds so no
pack can hang the interpreter; and — the keystone —

**a pack MUST carry at least one golden `selfTest` vector, executed
in-process at upload. Any mismatch rejects the pack outright.** A pack
that cannot reproduce its own declared arithmetic never activates and
never pays anyone. NL's 9 golden fixtures under
`tests/fixtures/payroll-2026/` **are the NL pack's own self-test
block** — the exact machinery that would gate a third-party pack from
another country is the machinery that proved the NL migration to the
new engine was behaviour-identical.

`{value, source, verified}` provenance survives from the tables
convention: a leaf carrying `verified: false` or `placeholder: true`
(NL's employer Whk rate is legitimately a placeholder today) does not
block activation, but is **stamped onto every run it computes**, so
downstream consumers see an unverified figure rather than assuming
engine truth.

**Bundled packs are not shadowable by accident.** Uploading a pack for a
`(jurisdiction, taxYear)` a bundled pack already owns is rejected unless
an admin explicitly activates it as a recorded override — NL is the
regression contract and does not get silently overwritten by a stray
upload.

Upload: `POST /api/payroll/packs` (admin only).

## The NL anchor: the regression contract

Re-expressing NL through the pack mechanism had to reproduce the exact
existing figures, or the abstraction would be aspirational. It does,
digit-for-digit, and this remains the engine's regression contract:

> **€3.800,00 wit/maand**, korting toegepast, below AOW-age, period
> 2026-02, Awf laag, Aof laag, Whk 1,52% →
> **loonheffing €718,83**, **arbeidskorting €473,75**,
> volksverzekeringen €470,86, Zvw €231,80, werknemersverzekeringen
> €419,14, vakantiegeldReserved €304,00, **netto €3.081,17**.

All 9 golden fixtures under `tests/fixtures/payroll-2026/` (anchor,
aow-age, bracket-3, groen, min-wage, no-korting, part-time,
bijtelling-anchor, dga-anchor) reproduce through the pack path
cents-exact, and `PayrollCalculator::calculate(CalculationInput,
TaxTables): CalculationResult` keeps its public signature — callers
(`PayrollRunService` and both pinning test classes) did not change.
`PayrollCalculator` is now a thin façade over the interpreter + the
bundled NL pack.

## Adding a country

A new jurisdiction is a data-only artefact, not a code change. At a
level you could act on:

1. **Author the pack** — declare `id`, `jurisdiction`, `taxYear`,
   `packVersion` (semver), `dslVersion`, a `tables` corpus of
   `{value, source, verified}` parameter leaves, and an ordered `steps`
   list using the closed DSL vocabulary above. Reference every
   parameter via `@table.*` — never inline a rate or bracket.
2. **Declare incidence on every step.** Decide, for each step, whether
   it reduces net pay for the employee (`reduces-net`), is borne by the
   employer only (`employer-cost`), is reported but has no cash effect
   (`informative`), or is accrued now and paid later (`reserve`). Net
   comes out of the fold automatically — you never write a net rule.
3. **Reach for `phpStep` only for genuine national exotica** that the
   DSL cannot express (see Known limits below) — and only by naming an
   allow-listed handler that already ships in HRMQ.
4. **Write golden self-test vectors** — at least one `{input,
   expected}` pair the interpreter must reproduce exactly. This is
   required, not optional: no vectors, no activation.
5. **Upload** `POST /api/payroll/packs` as an admin. The validator runs
   your self-tests in-process before the pack can ever compute a real
   wage.

## Known limits (recorded, not discovered later)

Honesty is a feature. These boundaries are named up front:

- **`piecewiseAccrue` is on probation.** This primitive was designed by
  staring at NL's ARK (arbeidskorting) tapered-cap chain — its
  "round-each-term-to-5-decimals, then cap at the segment ceiling, then
  accumulate" ordering is Rekenvoorschriften arcana. It is a plausibly
  general shape (phase-in/phase-out credit schedules are common
  elsewhere), but **that generality is unproven until a second country
  lands on it.** Country two either validates this primitive or exposes
  it as NL-shaped — that is this design's central unproven claim, and
  it is recorded rather than hidden.
- **The DSL cannot express VCR** (voortschrijdend cumulatief rekenen —
  cumulative year-to-date recalculation). VCR needs cross-period state,
  and the DSL is per-period pure by construction. This is a real,
  present-day NL gap, not just a hypothetical for other countries: NL's
  premium bases are period-capped today, not cumulative, which drifts
  for wages fluctuating around the maximum premieloon. The honest
  expectation is that **NL itself will be the escape hatch's first
  customer** for VCR. Widening `expr` into a general language to
  compensate is explicitly forbidden — it would void the entire
  "config, not code" trust model this engine depends on.
- **No inverse solves** — the 30%-ruling netto-operation is not a
  forward chain and is not expressible in the DSL as designed.
- **Some NL law still lives outside the pack.** Bijtelling privégebruik
  auto (company-car addition) and the loonbeslag *beslagvrije voet*
  (garnishment floor) are computed in `PayrollRunService`, not the
  interpreter, because they read stored OpenRegister objects while the
  interpreter is pure and object-blind. This is a *scope* cut, not a
  principled boundary — bijtelling in particular is arithmetically a
  pure function of parameters already in the tables and belongs in the
  pack; it is a named follow-up that country two will feel.
- **A second real country is unproven.** This mechanism is proved
  against NL and ships the upload surface — any claim that country two
  "just works" is unproven until country two actually lands.

## The payroll engine is NOT certified

Be aware of the following before relying on the engine's output in
production, whichever jurisdiction pack computed it:

- **Traceability, not certification.** Every run's `engineVersion` is
  now `{packId}@{packVersion}` (e.g. `nl-2026@1.0.0`, naming the
  *chain* as well as the parameter set — runs computed before
  jurisdiction packs carry the older bare `nl-2026` form and are never
  rewritten) plus `calculatedAt`, so provenance is always auditable —
  but the engine has not been certified by any authority.
- **Certification gap.** The golden fixtures are *self-consistent* with
  their tax tables — NL's anchor case is hand-computed from the primary
  Belastingdienst PDFs — but the official Belastingdienst test sets
  (loonheffingstabellen proefberekeningen) have **not** been run
  against this engine yet. The marked slot for them is
  `tests/fixtures/payroll-2026/official/README.md`.
- **Known MVP limitations (NL):** fixed monthly salary only (hourly
  wage × approved Timesheet hours is a named fast-follow); no VCR (see
  above); no anoniementarief computation — employees failing the
  BSN/ID preconditions are skipped with a reason, never computed wrong;
  no CAO logic beyond the maintained rule corpus, no bijzonder tarief
  (vakantiegeld payout), no 30%-ruling netto-operation, no pension
  premie calculation, no Zvw-inhouding mode, no loonaangifte message
  generation.
- **Production use requires verification** of the engine's output
  against the official Belastingdienst test sets by a qualified
  loonadministrateur.

Honesty is a feature: this disclaimer is a requirement of the
`payroll-core-engine` specification, not a footnote.

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
- `--recalculate` — regenerate an existing **draft** run in place;
  approval is a human act and moves the run out of draft, so an
  approved run is never silently recalculated

Audit one period's run(s) and their payslips against the
machine-checkable rule corpus:

```bash
occ hrmq:payroll:verify --period=2026-06 --administration=ADM-001 --jurisdiction=NL
```

`hrmq:payroll:verify` is a **run-scoped corpus audit** — the same
`RuleCatalogue`/`RuleEngine` that audits hand-entered HR data also
audits the engine's own output, and the command exits non-zero on any
mandatory violation. Every computed `PayrollRun` carries `engineVersion`
and `calculatedAt`, and every computed `Payslip` reconciles cents-exact
to its declared net equation — both enforced by the corpus rules
`nl-engine-table-version` and `nl-engine-output-consistency`.

## Annual maintenance is data-only

A new NL tax year ships as a new `lib/Standards/tables/nl-YYYY.json`
table file plus refreshed golden fixtures and a `RuleCatalogue::VERSION`
bump — never a PHP change. The same is true of a new **country**: its
pack, its tables, its own self-test vectors. Re-issuing a table, or
onboarding a jurisdiction, is a data-only change either way.

## What consumes an approved run

Once a `PayrollRun` is approved, the rest of the payroll pipeline picks
it up automatically:

- [Retroactive adjustments (TWK)](/docs/payroll/retro-adjustments) —
  correcting a sealed prior period without rewriting it
- [Sick pay](/docs/payroll/sick-pay-calc) — loondoorbetaling bij ziekte
- [Company car bijtelling](/docs/payroll/fleet-bijtelling) — the fiscal
  addition for private use of a company car
- [DGA payroll mode](/docs/payroll/dga-payroll-mode) — director-major-shareholder
  handling
- [Wage garnishment (loonbeslag)](/docs/payroll/loonbeslag) — court/deurwaarder-ordered
  deductions
- [Werkkostenregeling (WKR)](/docs/payroll/wkr-administration) — free-space
  administration and eindheffing exposure
- [Payroll mutation reports](/docs/payroll/payroll-mutation-reports) — run-to-run
  diffing before approval
- [Proforma payslip simulation](/docs/payroll/proforma-payslip) — a persist-nothing
  "what if" front door to this same engine
- [Loonaangifte filing](/docs/payroll/loonaangifte) — wage-tax filing lifecycle
- [Pension UPA filing](/docs/payroll/pension-upa) — sector-pension filing
- [Payslips & jaaropgaven](/docs/payroll/payslips) — loonstrook and annual
  statement PDFs
- [GL posting & SEPA net-pay](/docs/payroll/gl-and-sepa) — the handoff into
  shillinq's bookkeeping and payment-run registers
