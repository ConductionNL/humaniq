# Design — rules onto OpenRegister decision tables

## Context

Wave 4 of the "One engine" fleet consolidation. OpenRegister's `flow-decision-tables` change
(openregister#3329) shipped `openregister.decision-table`: a flow node over the shared
`lib/Service/Dmn/` evaluators. The evaluator core is deliberately pure:

- `DecisionTableEvaluator::evaluate(array $decisionTable, array $inputs): array` — a
  deterministic function of `(table, inputs) -> {outputs, matchedRuleIds, hitPolicy}` with **no
  OpenRegister, HTTP or database dependency**, default-constructible
  (`new DecisionTableEvaluator()`), throwing a typed `DecisionEvaluationException` on every
  ambiguous case (never a silent default).
- Table grammar: `{hitPolicy, inputs: [{name, type}], outputs: [{name, type}], rules: [{id,
  inputEntries: [cell], outputEntries: [value], priority?}]}`; cells are DMN unary tests
  (`-`, literals, `"quoted"`, `in (a,b)`, ranges `[0..10)`, comparisons `<= >= != < > =`);
  hit policies `UNIQUE | FIRST | COLLECT | PRIORITY | ANY`; types
  `string | number | boolean | date` plus aliases.

**Is an OR seam needed for non-flow callers?** No. The evaluator's purity and default
constructibility are documented API ("the default keeps the engine directly constructible"), so a
sibling app evaluates a table outside any flow with
`new \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator()` behind the fleet's standard
`class_exists()` availability guard (ADR-083). No OR-side PR is required, and none is made.

## What humaniq's RuleEngine actually is

`lib/Standards/RuleEngine.php` is a compliance predicate engine, not a table engine:

- a versioned static rule corpus (`lib/Standards/rules/*.json`, read through `RuleCatalogue`)
  carrying id, severity, jurisdiction and source citation per rule;
- 37 auto-discovered `CheckProvider` classes contributing PHP predicates
  `fn (array $object, array $context): bool` keyed by catalogue rule id;
- jurisdiction applicability (own country + EU-wide for EU members + `global`), severity policy,
  and `Violation` assembly in `evaluate()`;
- consumers: `RuleAuditService` (the `occ humaniq:rules:audit` report), `RosterCheckService`,
  `ObligationsService`. All non-flow, synchronous callers.

Nothing here persists rule definitions per tenant: the corpus, the tax-year parameter files and
the CAO files are all versioned static data in code (their SCHEMA.md files say so explicitly).

## The line: generic evaluation vs domain semantics

| Concern | Where it lives after this change |
| --- | --- |
| Cell matching, hit policies, input typing/coercion, "which rule fired" | OpenRegister `Dmn\DecisionTableEvaluator` (shared) |
| Deriving a table's inputs from an HR object (dates, sums, statutory formulas) | humaniq, in the provider's `derive` callable |
| Deciding decidability (vacuous pass when a snapshot field is absent) | humaniq, `derive` returns `null` |
| Rule corpus, severities, jurisdictions, source citations | humaniq (`RuleCatalogue`, unchanged) |
| Jurisdiction applicability + Violation assembly | humaniq (`RuleEngine::evaluate`, unchanged) |
| Payroll amount computation (`lib/Payroll/Dsl/` DSL) | humaniq, untouched — arithmetic, not matching |
| Non-tabular predicates (cross-object, free-form logic) | humaniq closures, unchanged, convertible per wave |

## The mapping: humaniq rule shapes -> OR decision tables

A table-backed check is declared by a provider implementing `ProvidesTables`:

```php
public static function tables(): array {
    return [
        '<objectType>' => [
            '<catalogue-rule-id>' => [
                'derive' => static fn (array $object, array $context): ?array => [...],
                'table'  => [ /* exact openregister.decision-table inline grammar */ ],
            ],
        ],
    ];
}
```

- `derive` is the domain half: it computes the table's declared inputs from the object (and may
  consult context). Returning `null` means "not decidable from this object alone" — the vacuous
  pass the corpus' machineCheckable discipline already mandates, kept domain-side because
  decidability is a legal-semantics judgement, not a matching rule.
- `table` is the generic half, in OR's grammar verbatim, so the same definition could later be
  pasted into an `openregister.decision-table` flow step unchanged. Every table declares a
  boolean `satisfied` output; the delegate reads exactly that.
- The canonical shape is `hitPolicy: FIRST` with a final catch-all rule (`inputEntries: ['-']`)
  so `no_rule_matched` cannot occur for a well-formed table; a table that still errors (grammar
  fault, missing OpenRegister) fails **closed**: `RuleEngine::evaluate()` already converts a
  throwing check into a violation, and the delegate leans on exactly that path.

Converted in this change (the proof provider, `NlLeaveChecks`, object type `LeaveBalance`):

| Rule id | Derived input(s) | Table decision |
| --- | --- | --- |
| `nl-verlof-wettelijk-minimum` | `shortfallHours = 4 * contractHoursPerWeek - entitledHours` (null when no snapshot) | `<= 0` -> satisfied |
| `nl-verlof-saldo-niet-negatief` | `overdraftHours = usedHours - (entitledHours + bovenwettelijkHours)` | `<= 0` -> satisfied |
| `nl-verlof-vervaltermijn` | `expiryOnStatutoryDate = (expiryDate === 1 July of year+1)` (null when nothing to lapse) | `true` -> satisfied |
| `nl-verlof-bovenwettelijk-niet-negatief` | `bovenwettelijkHours` | `>= 0` -> satisfied |

The remaining 36 providers keep their closures; conversion is per wave, tabular shapes first.
A provider whose logic is genuinely non-tabular is *not* force-fitted: pushing whole checks into
`derive` and leaving a `true`-cell table would move nothing to the shared engine and only add
indirection. `nl-verlof-vervaltermijn` sits at that boundary deliberately — its date equality is
domain derivation, its pass/fail gate is the table — and documents the judgement call.

## The delegate

`TableCheckEvaluator::satisfied(array $table, array $inputs): bool`:

- resolves `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator` once (memoised) via
  `class_exists()` + direct construction — the class is pure, so no container round-trip is
  needed and the delegate works identically under `occ`, background jobs and tests;
- throws `RuntimeException` when OpenRegister is absent (fail closed -> violation, loud in the
  audit; humaniq without OpenRegister has no registers to audit anyway, and no fleet app declares
  the dependency in `info.xml` — the runtime guard is the fleet's standing pattern);
- lets `DecisionEvaluationException` propagate for the same reason;
- maps `result['outputs']['satisfied'] === true` to pass; anything else is a violation.

There is deliberately **no** local fallback matcher: a fallback would be the second engine this
change exists to remove.

## Dual-read and staged retirement

The "old reader" here is code, not data: the legacy predicates. Discipline:

1. This change keeps `NlLeaveChecks`' predicates as `legacyChecks()` (documented as the parity
   oracle, not registered in the engine — the registry serves the table path only).
2. A parity test drives both paths over a fixture matrix (the seeded compliant balance plus every
   violation scenario the old tests pinned) and asserts identical verdicts.
3. Retirement of `legacyChecks()` is a staged, unchecked task, to be done only after a real-data
   `occ humaniq:rules:audit` run on an OpenRegister-backed environment matches the pre-change
   audit (task 6.x), so the migration is proven before the oracle goes.

## Testing the real semantics without OpenRegister installed

The standalone suite (bare `php:8.3-cli`, no server) must exercise the table path, and a
hand-scripted evaluator fake is exactly the "fake that agrees with the caller" trap. Since OR's
Dmn classes are pure PHP with no dependencies, `tests/stubs/OpenRegisterDmn/` carries **verbatim
copies** of `DecisionEvaluationException`, `UnaryTestEvaluator` and `DecisionTableEvaluator`
pinned to openregister@d1594ccd (the merged `flow-decision-tables` state), loaded by
`tests/bootstrap.php` only when the real classes are absent — the same real-class-wins rule the
existing OpenRegister stubs follow. Only the `@spec` anchors (which point at OR-repo paths) are
rewritten to `@spec exclude` provenance notes; the executable code is unmodified, so parity tests
run against the genuine evaluation semantics. Drift risk is bounded: the copies are test-only,
carry their source SHA, and any OR-side grammar change lands in humaniq as a deliberate stub
refresh, itself re-proven by the parity suite.

## Re-scoping humaniq-rule-compliance-enforcement

That change's Option C ("request a schema-declarative pre-save validation extension from
OpenRegister") predates the shared decision-table capability. Amendments (re-aim only; its
features stay unbuilt here):

- Option C now names `openregister.decision-table` + the shared Dmn evaluator as the OR-side
  substrate that partially exists: write-time enforcement can be modelled as a flow carrying an
  inline decision table, or a future guard can call `RuleEngine`, which already delegates tabular
  checks to the shared evaluator via this change.
- New standing constraint in its design and tasks: enforcement work MUST build on the shared
  evaluator through the `ProvidesTables` seam; new machine-checkable rules SHOULD be authored as
  decision tables where their shape is tabular; no new in-app matching/hit-policy/cell-grammar
  machinery.

## Risks / trade-offs

- **Indirection for trivial rules**: a `>= 0` table is more ceremony than a one-line closure. The
  payoff is uniformity (one grammar fleet-wide), inspectability (a table is data), and a future in
  which the same table runs in a flow node without translation.
- **Vendored test copies can drift** from OR's evaluator. Accepted and bounded (see above); the
  alternative fakes were worse, and a wrong stub is caught the first time an audit on a real
  environment disagrees with the suite (task 6.x runs exactly that comparison).
- **Fail-closed on missing OpenRegister** turns an infrastructure gap into visible violations.
  Intended: silent pass on a missing engine is the unacceptable branch.
