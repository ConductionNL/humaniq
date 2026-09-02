## ADDED Requirements

### Requirement: Tabular checks SHALL delegate matching to OpenRegister's shared decision-table evaluator (REQ-RULE-008)

A `CheckProvider` MAY declare a check as an OpenRegister decision table by implementing
`ProvidesTables` (`lib/Standards/Checks/ProvidesTables.php`): per object type and catalogue rule
id, a `derive` callable that computes the table's inputs from the object (returning `null` when
the rule is not decidable from the object alone — the vacuous pass), and a `table` in the exact
inline grammar `openregister.decision-table` consumes, declaring a boolean `satisfied` output.

`RuleEngine` SHALL merge table-declared checks into the same registry its closure checks use, so
every consumer of `evaluate()` is unaffected by a check's representation.

Matching SHALL be performed by OpenRegister's `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`
through the thin delegate `lib/Standards/TableCheckEvaluator.php`. humaniq SHALL NOT implement
cell-expression matching, hit-policy selection or input coercion of its own, and SHALL NOT carry
a fallback matcher: when the shared evaluator is unavailable or refuses the evaluation, the check
SHALL fail closed as a violation through the engine's existing throwing-predicate path.

**Feature tier**: MVP

#### Scenario: A table-declared check answers through the shared evaluator

- GIVEN a provider declares a rule as a decision table with a derivation
- WHEN `RuleEngine::evaluate()` runs over an object of that type
- THEN the derived inputs and the table are handed to OpenRegister's `DecisionTableEvaluator`
- AND the rule contributes a violation exactly when the table's `satisfied` output is not `true`
- @e2e exclude backend evaluation seam with no humaniq UI surface — pinned by the unit suite
  (delegate wiring, registry merge) and by the table/legacy parity matrix running the real
  evaluator semantics

#### Scenario: A rule that is not decidable passes vacuously

- GIVEN a table-declared rule whose derivation returns `null` for an object (a required snapshot
  field is absent)
- WHEN the object is evaluated
- THEN that rule contributes no violation, matching the corpus' machineCheckable discipline
- @e2e exclude backend evaluation semantics with no UI surface — unit-tested through
  `RuleEngine::evaluate()`

#### Scenario: A missing shared evaluator fails closed

- GIVEN OpenRegister's evaluator class is not resolvable at runtime
- WHEN a table-declared rule is evaluated
- THEN the check reads as a violation (fail closed), never as a silent pass
- @e2e exclude negative infrastructure branch not reproducible in the e2e environment (the app
  requires OpenRegister to serve any register at all) — unit-tested via the delegate's guard

### Requirement: A converted rule SHALL keep its legacy predicate as the parity oracle until retirement is proven (REQ-RULE-009)

While a rule's table form is being proven, the provider SHALL keep the legacy predicate available
(outside the engine's registry) and a parity test SHALL assert that table and predicate agree on
every pinned fixture, compliant and violating. The legacy predicates SHALL only be retired after
an OpenRegister-backed `occ humaniq:rules:audit` run over converted rules matches the pre-change
audit verdicts.

**Feature tier**: MVP

#### Scenario: Table and legacy predicate agree on the pinned fixtures

- GIVEN the fixture matrix the legacy tests pinned (compliant balance plus each violation case)
- WHEN both the table path and the legacy predicate evaluate a fixture
- THEN their verdicts are identical for every fixture and every converted rule
- @e2e exclude backend parity harness with no UI surface — the parity unit suite is itself the
  verification artefact
