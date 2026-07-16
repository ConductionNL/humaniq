# Tasks — jurisdiction-packs

> Verify against HEAD, not this brief. The acceptance contract is binding: the NL migration is
> behaviour-identical or it does not land (REQ-JP-007). `PayrollCalculatorTest` and
> `BalancingInvariantTest` must pass **unmodified** — if they need editing, the migration has drifted.
>
> Engine-touching work builds SEQUENTIALLY (VERSION/manifest collisions).

- [ ] 1. Pack envelope + JSON Schema: `lib/Standards/packs/SCHEMA.md` + the pack JSON Schema —
  `id`/`jurisdiction`/`taxYear`/`packVersion`/`dslVersion`/`tables`/`currency`/`basedOn`/`inputs`/
  `bindings`/`steps`/`selfTest`, mirroring the `lib/Standards/tables/SCHEMA.md` provenance convention
  per REQ-JP-001
- [ ] 2. Interpreter core: `lib/Payroll/Dsl/PackInterpreter.php` + `StepContext.php` — pure (no
  container, no clock, no IO beyond the injected `TaxTables`), ordered step execution, integer cents,
  earlier-steps-only references per REQ-JP-002
- [ ] 3. Reference resolver: `@input.*` / `@table.*` / `@step.*` / `@binding.*` / `@period.*` /
  `@pack.*` against `TaxTables` leaves + the declared input contract per REQ-JP-002
- [ ] 4. `ExprEvaluator` — the CLOSED, TOTAL arithmetic grammar (`+ - * /`, `min max abs round floor
  ceil`, parens, refs, literals). No loops, no recursion, no function definitions, no IO, no clock.
  Depth-capped per REQ-JP-008
- [ ] 5. Step ops part 1: `rate`, `cappedRate`, `quantize`, `clamp`, `match` + the `round`
  modifier (`floor|ceil|nearest` x `cent|euro|decimals`) per REQ-JP-002
- [ ] 6. Step ops part 2: `bracket` (affine mode; first row where `value <= tot`, `null` = unbounded)
  and `taper` per REQ-JP-002
- [ ] 7. Step op `piecewiseAccrue` — segments `{upTo, rate, cap}` with `roundTerm` applied BEFORE the
  per-segment cap, then the descending `tail`, then `zeroAbove`. This is `arkChain()`'s exact
  ordering (design.md D5) per REQ-JP-002
- [ ] 8. Bindings + predicates: `derive`; `eq/ne/lt/lte/gt/gte`, `and/or/not`,
  `ageReached(dob, years, asOf, granularity)`, `yearOf(date)` per REQ-JP-002
- [ ] 9. **Incidence primitive**: `reduces-net` / `employer-cost` / `informative` / `reserve` declared
  per step; interpreter DERIVES `net = gross - sum(reduces-net)` and
  `employerCharges = sum(employer-cost)`. No netto step exists in any pack per REQ-JP-003
- [ ] 10. Escape hatch: `lib/Payroll/JurisdictionStepHandlerInterface.php` + a compile-time
  allow-list registry. `op: phpStep` names a handler; a pack can NEVER supply code, a class path, or
  a callable. Ships with ZERO registered handlers per REQ-JP-005
- [ ] 11. `lib/Payroll/PackValidator.php` — gates 1-5 + 8-9: schema, vocabulary, reference/DAG (no
  forward refs, no cycles), **handler resolution against the allow-list (reject at VALIDATION time,
  naming the handler)**, self-test dry-run, bounds, no-accidental-shadowing per REQ-JP-005/006/008
- [ ] 12. `lib/Standards/packs/nl-2026.pack.json` — NL re-expressed: 13 steps + 6 bindings per
  design.md D5, ZERO `phpStep` uses, every parameter via `@table.*` (the verified `nl-2026.json` is
  NOT copied or re-sourced) per REQ-JP-004
- [ ] 13. NL pack `selfTest` block — the 9 existing `tests/fixtures/payroll-2026/*.json` fixtures as
  the pack's own golden vectors. No fixture copied, edited, or re-derived per REQ-JP-006/007
- [ ] 14. Pack resolver: `lib/Payroll/PackRepository.php` — resolve `(jurisdiction, year-of(period))`
  across the two homes (bundled `lib/Standards/packs/`, uploaded `JurisdictionPack` objects);
  bundled wins by default; explicit recorded admin override only per REQ-JP-006
- [ ] 15. **`PayrollCalculator` becomes a façade** — resolve pack, delegate to interpreter, map back
  to `CalculationResult`. Public signature UNCHANGED, 18 result fields UNCHANGED. Map `awfTariff`
  `low|high` -> `laag|hoog` at the boundary (design.md D6) per REQ-JP-007
- [ ] 16. **DELETE the absorbed private helpers** from `PayrollCalculator`: `arkChain()`,
  `selectBracket()`, `isAowAge()`, `schijvenSet()`, `floorEuroCents()`, `ceilEuroCents()`,
  `round5Cents()`, `round2Cents()`. Any survivor means NL logic stayed in PHP per REQ-JP-007
- [ ] 17. `PayrollRunService` pack resolution — replace `'nl-'.substr($period, 0, 4)` with
  `(run.jurisdiction, year-of(period))`; stamp `engineVersion` as `{packId}@{packVersion}`; stamp
  pack provenance (any `verified:false`/`placeholder:true` leaf) onto the run per REQ-JP-006
- [ ] 18. `JurisdictionPack` schema in `lib/Settings/register.d/` + admin upload endpoint
  `POST /api/payroll/packs` (`AuthorizedAdminSetting`, ONE endpoint, no CRUD per ADR-022); rejects
  carry the offending op/ref/handler name per REQ-JP-005/008
- [ ] 19. Tests: interpreter unit tests per op (incl. `piecewiseAccrue`'s round-then-cap ordering);
  `PackValidator` rejection tests (unknown op, dangling ref, cycle, **missing handler**, failing
  self-test, shadowing attempt); incidence-fold invariant test; **`PayrollCalculatorTest` +
  `BalancingInvariantTest` run UNMODIFIED and green** per REQ-JP-007
- [ ] 20. Verify + document: all 9 payroll fixtures digit-for-digit through the pack path
  (anchor = loonheffing 718,83 / arbeidskorting 473,75 / netto 3.081,17); sick-pay anchor still green
  (`SickPayCalculator` provably untouched); README pack-authoring section + the honest
  `piecewiseAccrue`-on-probation and no-VCR disclaimers per REQ-JP-007
