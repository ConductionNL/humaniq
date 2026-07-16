# Tasks — jurisdiction-packs

> Verify against HEAD, not this brief. The acceptance contract is binding: the NL migration is
> behaviour-identical or it does not land (REQ-JP-007). `PayrollCalculatorTest` and
> `BalancingInvariantTest` must pass **unmodified** — if they need editing, the migration has drifted.
>
> Engine-touching work builds SEQUENTIALLY (VERSION/manifest collisions).

- [x] 1. Pack envelope + JSON Schema: `lib/Standards/packs/SCHEMA.md` + the pack JSON Schema —
  `id`/`jurisdiction`/`taxYear`/`packVersion`/`dslVersion`/`tables`/`currency`/`basedOn`/`inputs`/
  `bindings`/`steps`/`selfTest`, mirroring the `lib/Standards/tables/SCHEMA.md` provenance convention
  per REQ-JP-001
  - `pack.schema.json` ships as the authoring/editor reference and the bundled NL pack was verified
    against it. NOTE: the repo has no JSON-Schema library, so `PackValidator`'s **blocking** gate 1 is
    programmatic structural validation mirroring that file, not a schema-library run. A schema cannot
    check arithmetic anyway — gate 5 does.
  - The envelope gained one field design.md did not name: **`grossRef`**, declaring which value the
    incidence fold subtracts from. The pack declares WHICH value is gross; the fold stays the
    interpreter's.
- [x] 2. Interpreter core: `lib/Payroll/Dsl/PackInterpreter.php` + `StepContext.php` — pure (no
  container, no clock, no IO beyond the injected `TaxTables`), ordered step execution, integer cents,
  earlier-steps-only references per REQ-JP-002
  - `run()` also takes the run `period` (design.md D1 showed `run(inputs, pack, tables)`); the period is
    a supplied string, never a clock read, so purity holds.
- [x] 3. Reference resolver: `@input.*` / `@table.*` / `@step.*` / `@binding.*` / `@period.*` /
  `@pack.*` against `TaxTables` leaves + the declared input contract per REQ-JP-002
  - Added `TaxTables::resolveLeaf()`/`toCents()` so `@table.*` resolves generically WITH provenance.
  - **Design gap found and closed:** the corpus carries no unit marker on its leaves (`Lv: 54` is euro,
    `zvw.werkgeversheffing: 6.1` is a percentage), and design.md's ref grammar never said how
    euro-to-cents happens. Rather than teach the interpreter which NL leaves are money, the PACK
    declares the unit via a `:cents` ref suffix (and `bracket`'s required `unit`). Unit knowledge stays
    in config; the conversion stays inside `TaxTables` exactly as D4 requires.
- [x] 4. `ExprEvaluator` — the CLOSED, TOTAL arithmetic grammar (`+ - * /`, `min max abs round floor
  ceil`, parens, refs, literals). No loops, no recursion, no function definitions, no IO, no clock.
  Depth-capped per REQ-JP-008
  - Grammar NOT widened. Pinned by `ExprEvaluatorTest::testTheFunctionVocabularyIsClosedAndUnchanged`.
    Parse is separate from evaluate, so the validator bounds an expression without executing it.
- [x] 5. Step ops part 1: `rate`, `cappedRate`, `quantize`, `clamp`, `match` + the `round`
  modifier (`floor|ceil|nearest` x `cent|euro|decimals`) per REQ-JP-002
- [x] 6. Step ops part 2: `bracket` (affine mode; first row where `value <= tot`, `null` = unbounded)
  and `taper` per REQ-JP-002
- [x] 7. Step op `piecewiseAccrue` — segments `{upTo, rate, cap}` with `roundTerm` applied BEFORE the
  per-segment cap, then the descending `tail`, then `zeroAbove`. This is `arkChain()`'s exact
  ordering (design.md D5) per REQ-JP-002
- [x] 8. Bindings + predicates: `derive`; `eq/ne/lt/lte/gt/gte`, `and/or/not`,
  `ageReached(dob, years, asOf, granularity)`, `yearOf(date)` per REQ-JP-002
- [x] 9. **Incidence primitive**: `reduces-net` / `employer-cost` / `informative` / `reserve` declared
  per step; interpreter DERIVES `net = gross - sum(reduces-net)` and
  `employerCharges = sum(employer-cost)`. No netto step exists in any pack per REQ-JP-003
  - `PackInterpreter` names no jurisdiction and no NL concept. Proved by
    `PackInterpreterTest::testAContributionThatReducesNetNeedsNoInterpreterChange` (an employee-borne
    pension contribution reduces net through the unmodified interpreter) and
    `NlPackTest::testLoonheffingIsTheOnlyStepThatReducesNet`.
- [x] 10. Escape hatch: `lib/Payroll/JurisdictionStepHandlerInterface.php` + a compile-time
  allow-list registry. `op: phpStep` names a handler; a pack can NEVER supply code, a class path, or
  a callable. Ships with ZERO registered handlers per REQ-JP-005
- [x] 11. `lib/Payroll/PackValidator.php` — gates 1-5 + 8-9: schema, vocabulary, reference/DAG (no
  forward refs, no cycles), **handler resolution against the allow-list (reject at VALIDATION time,
  naming the handler)**, self-test dry-run, bounds, no-accidental-shadowing per REQ-JP-005/006/008
  - Cycles are impossible by construction (earlier-only refs), not detected by inspection.
  - HONEST LIMIT: a `@table.*` ref with a DYNAMIC segment (`schijven[@binding.schijvenSet]`) cannot be
    resolved statically; those are covered by gate 5, which executes the pack's real vectors. Static
    dangling refs are rejected by gate 3 as specified.
- [x] 12. `lib/Standards/packs/nl-2026.pack.json` — NL re-expressed: 13 steps + 6 bindings per
  design.md D5, ZERO `phpStep` uses, every parameter via `@table.*` (the verified `nl-2026.json` is
  NOT copied or re-sourced) per REQ-JP-004
  - Zero `phpStep`; every parameter via `@table.*` (asserted by
    `NlPackTest::testThePackReferencesParametersRatherThanCopyingThem`).
  - COUNT DIFFERS FROM THE BRIEF, deliberately: **16 steps + 21 bindings**, not "13 steps + 6
    bindings". design.md is internally inconsistent here (the proposal says 13; D5's own table lists
    S1-S15 plus B1-B6). The extra steps are `werknemersverzekeringen` (an informative roll-up, so the
    façade does not sum NL lines in PHP) and `appliedTaxRate`; the extra bindings are decompositions
    forced by the vocabulary being closed — `match` takes a subject, not a nested predicate, so
    `schijvenSet` needs `birthYear`/`bornBefore1946`/`aowSchijvenSet`. The count was never
    load-bearing; the behaviour is.
- [x] 13. NL pack `selfTest` block — the 9 existing `tests/fixtures/payroll-2026/*.json` fixtures as
  the pack's own golden vectors. No fixture copied, edited, or re-derived per REQ-JP-006/007
  - Referenced via a `$fixture` vector form. That form is **bundled-only**: it reads a repository file
    (an upload must never steer that) and an uploaded pack must be self-contained (D7), so uploads
    carry inline `{input, expected}` vectors instead. Fixture inputs run through the SAME boundary
    mapper the façade uses, so the vector proves the whole real path.
- [x] 14. Pack resolver: `lib/Payroll/PackRepository.php` — resolve `(jurisdiction, year-of(period))`
  across the two homes (bundled `lib/Standards/packs/`, uploaded `JurisdictionPack` objects);
  bundled wins by default; explicit recorded admin override only per REQ-JP-006
  - `lib/Payroll/` stays free of Nextcloud/OpenRegister imports: uploads arrive through the pure
    `PackSourceInterface` seam, implemented by `lib/Service/JurisdictionPackService.php` and wired in
    `Application.php` (without that wiring an uploaded pack would validate and store but never
    resolve — an orphaned capability).
- [x] 15. **`PayrollCalculator` becomes a façade** — resolve pack, delegate to interpreter, map back
  to `CalculationResult`. Public signature UNCHANGED, 18 result fields UNCHANGED. Map `awfTariff`
  `low|high` -> `laag|hoog` at the boundary (design.md D6) per REQ-JP-007
  - Seam bridged in `CalculationInputMapper`, shared by the façade and the self-test runner so they
    cannot drift. `CalculationInput` gained an additive, defaulted `jurisdiction` param (the
    `verzekeringsplichtig` precedent) so `PayrollRunService` can pass `run.jurisdiction`; design.md
    D10's `resolve('NL', ...)` literal would have re-hardcoded the country in the façade.
- [x] 16. **DELETE the absorbed private helpers** from `PayrollCalculator`: `arkChain()`,
  `selectBracket()`, `isAowAge()`, `schijvenSet()`, `floorEuroCents()`, `ceilEuroCents()`,
  `round5Cents()`, `round2Cents()`. Any survivor means NL logic stayed in PHP per REQ-JP-007
  - All 8 gone; enforced by `NlPackTest::testTheAbsorbedNlHelpersAreDeletedFromTheCalculator`.
- [ ] 17. `PayrollRunService` pack resolution — replace `'nl-'.substr($period, 0, 4)` with
  `(run.jurisdiction, year-of(period))`; stamp `engineVersion` as `{packId}@{packVersion}`; stamp
  pack provenance (any `verified:false`/`placeholder:true` leaf) onto the run per REQ-JP-006
  - DONE: resolution is now `(run.jurisdiction, year-of(period))`, and the tables id comes from the
    pack's own declared `tables` field — no code path parses a country out of an id or a period.
  - DONE: `engineVersion` stamps `{packId}@{packVersion}` (`nl-2026@1.0.0`). This forced a widening of
    the `nl-engine-table-version` corpus predicate + rule statement (it asserted
    `in_array($engineVersion, TaxTables::availableIds())`, which `nl-2026@1.0.0` fails); legacy bare
    stamps still pass and historical runs are never rewritten. `RuleCatalogue::VERSION` bumped
    2026-07.24 -> 2026-07.25 per its own "bump on any change to the rule files" rule.
  - **NOT DONE — pack provenance is not stamped onto `PayrollRun`.** The mechanism exists and is
    tested end-to-end (`PackRunResult::unverifiedProvenance()`, returned by `PackValidator` gate 6 and
    stamped onto the uploaded `JurisdictionPack` object; proved by
    `PackValidatorTest::testAPackResolvingAPlaceholderLeafValidatesAndReportsItsProvenance`), but
    `PayrollRunService` does not write it onto the run: the façade returns `CalculationResult`'s 18
    fixed fields and deliberately does not carry the interpreter's provenance, and threading it out
    would either break the façade's contract or make it stateful. See the REQ-JP-006 finding in the
    change report: for NL 2026 this set is **empty anyway**, because the corpus's only
    `placeholder: true` leaf (employer Whk) is NOT referenced by the pack — it is an employer-level
    INPUT that `PayrollRunService` resolves from settings. The spec scenario "Placeholder provenance is
    stamped, not silenced" therefore rests on a false premise about NL and does not hold as written.
- [x] 18. `JurisdictionPack` schema in `lib/Settings/register.d/` + admin upload endpoint
  `POST /api/payroll/packs` (`AuthorizedAdminSetting`, ONE endpoint, no CRUD per ADR-022); rejects
  carry the offending op/ref/handler name per REQ-JP-005/008
  - Admin-only is enforced with `#[NoAdminRequired]` + an explicit `isAdmin()` 403 guard, which is
    this app's established pattern (`PayrollController::mutations()`); hrmq uses
    `AuthorizedAdminSetting` nowhere, and inventing a Settings section for one endpoint was not worth
    the divergence. The posture is identical: non-admins get 403 before the payload is parsed.
  - NOT covered: no UI (out of scope per the proposal — upload + validate only), and the endpoint is
    unit-tested at the service/validator level rather than through a live OpenRegister instance.
- [x] 19. Tests: interpreter unit tests per op (incl. `piecewiseAccrue`'s round-then-cap ordering);
  `PackValidator` rejection tests (unknown op, dangling ref, cycle, **missing handler**, failing
  self-test, shadowing attempt); incidence-fold invariant test; **`PayrollCalculatorTest` +
  `BalancingInvariantTest` run UNMODIFIED and green** per REQ-JP-007
  - 46 new tests (643 -> 689), all green. Both protected test classes are byte-for-byte unmodified.
- [x] 20. Verify + document: all 9 payroll fixtures digit-for-digit through the pack path
  (anchor = loonheffing 718,83 / arbeidskorting 473,75 / netto 3.081,17); sick-pay anchor still green
  (`SickPayCalculator` provably untouched); README pack-authoring section + the honest
  `piecewiseAccrue`-on-probation and no-VCR disclaimers per REQ-JP-007
  - All 9 reproduce digit-for-digit. `SickPayCalculator` has zero git changes.
