---
kind: code
---

## Why

The fleet audit flagged humaniq's compliance engine as engine duplication: the app carries its own
rule-evaluation machinery (`lib/Standards/RuleEngine.php` + 37 `CheckProvider` classes, ~9,900
lines under `lib/Standards/`) while OpenRegister now ships a shared decision-table capability —
the `openregister.decision-table` flow node atop `lib/Service/Dmn/` (`DecisionTableEvaluator`,
`UnaryTestEvaluator`, `DecisionTableValidator`, merged in openregister#3329, openspec change
`flow-decision-tables`). Per ADR-022 (apps consume OR abstractions rather than hand-rolling a
parallel mechanism), the *generic* half of humaniq's rule evaluation — "given a table of
conditions, which rules match and what is the outcome" — should be the shared evaluator's job.
The *domain* half — payroll arithmetic, Dutch labour-law date derivations, CAO semantics — is
humaniq's and stays.

Today every humaniq check is an opaque PHP closure. Where a check is genuinely tabular (thresholds,
enumerations, boolean gates over derived values), that shape duplicates exactly what the shared
evaluator does, in a form no other tool can inspect, validate, or eventually surface in a flow.
And the open change `humaniq-rule-compliance-enforcement` was about to grow *more* in-app rule
machinery on top of this; it is re-scoped by this change to build on the shared capability instead.

## What Changes

- **A table-backed check form**: a `CheckProvider` MAY declare checks as OpenRegister decision
  tables (the exact inline-table grammar `openregister.decision-table` consumes) plus a small
  domain *derivation* callable that computes the table's inputs from the object. New capability
  interface `lib/Standards/Checks/ProvidesTables.php`, mirroring the existing `SeedsObjects` /
  `UpsertsObjects` capability pattern.
- **A thin delegate, no second engine**: `lib/Standards/TableCheckEvaluator.php` hands the table
  and the derived inputs to OpenRegister's `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`
  (which is pure and directly constructible for non-flow callers — no OR-side seam is needed) and
  maps the `satisfied` output to the engine's pass/fail. humaniq implements **no** cell grammar,
  no hit-policy logic, no matching.
- **`RuleEngine` merges table checks into the same registry**: table-declared checks join
  `checks()` wrapped as predicates, so `evaluate()`, `RuleAuditService`, `RosterCheckService`,
  `ObligationsService` and the `occ humaniq:rules:audit` command are all untouched consumers.
- **A first converted provider proves the seam**: `NlLeaveChecks` (4 LeaveBalance rules) moves
  from closures to declared tables + derivations. Its legacy predicates stay as the parity oracle
  (`legacyChecks()`) until the staged retirement task, per the dual-read discipline.
- **Re-scope `humaniq-rule-compliance-enforcement`**: its design's "Option C — ask OpenRegister
  for a validation extension" is amended to name the now-shipped shared decision-table capability,
  and its tasks gain the constraint that future enforcement work builds on the shared evaluator —
  no new in-app rule-evaluation machinery.

## What does NOT change

- **Domain semantics stay in humaniq.** The payroll DSL (`lib/Payroll/Dsl/` — `PackInterpreter`,
  `ExprEvaluator`, `PredicateEvaluator`) computes *amounts* over payslip lines; it is payroll
  arithmetic, not rule/table matching, and is out of scope. Likewise the tax-year parameter files
  (`lib/Standards/tables/`), the CAO registry, and every non-tabular check predicate.
- **No data migration.** humaniq persists no rule definitions: the rule corpus
  (`lib/Standards/rules/*.json`) is versioned static data in code (its SCHEMA.md says so
  explicitly), so the conversion is a code-side representation change guarded by parity tests,
  not a repair step over stored objects.
- **No behaviour change for callers.** `RuleEngine::evaluate()` keeps its signature, its
  jurisdiction scoping, its Violation shape and its fail-closed error handling (an evaluator
  error, including OpenRegister being absent, reads as a violation — exactly how a throwing
  predicate already reads today).

## Capabilities

### New Capabilities

- `rules-onto-or-decision-tables`: tabular compliance checks are declared as OpenRegister
  decision tables and evaluated by the shared `Dmn\DecisionTableEvaluator`; humaniq keeps only
  domain derivations and retires bespoke matching for converted rules.

## Impact

- `lib/Standards/Checks/ProvidesTables.php` (new) — the capability interface.
- `lib/Standards/TableCheckEvaluator.php` (new) — the delegate to OR's evaluator.
- `lib/Standards/RuleEngine.php` — `checks()` additionally merges table-declared checks.
- `lib/Standards/Checks/NlLeaveChecks.php` — converted to `ProvidesTables`; predicates kept as
  the parity oracle pending staged retirement.
- `tests/stubs/OpenRegisterDmn/` (new, test-only) — verbatim copies of OR's pure Dmn classes
  (pinned to openregister@d1594ccd) loaded by `tests/bootstrap.php` only when the real classes
  are absent, so the standalone suite exercises the real evaluation semantics; the real classes
  always win on a live instance.
- `tests/Unit/Standards/` — delegate tests, table/legacy parity tests, updated leave-check tests
  that drive the registry path end-to-end.
- `openspec/changes/humaniq-rule-compliance-enforcement/` — design/tasks amended (re-aim only,
  its features are not implemented here).
- No routes, no schemas, no frontend.
