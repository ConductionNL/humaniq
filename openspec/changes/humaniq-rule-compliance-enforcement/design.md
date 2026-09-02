## Context

`RuleEngine`'s own docblock (`lib/Standards/RuleEngine.php:19-20`) claims write-time enforcement
lives in `OCA\Humaniq\Lifecycle\RuleComplianceGuard`. That class was never built. Verified:

- `find . -iname "*RuleComplianceGuard*"` — no match anywhere in the repo.
- `grep -rn "RuleComplianceGuard" lib/ src/` — the only hit is the docblock reference itself.
- `grep -rn "hasMandatory" lib/` — zero call sites.
- `grep -rn "RuleEngine::evaluate" lib/` — one call site, `RuleAuditService::audit()`
  (read-only, occ command only).
- None of `Employee` / `EmploymentContract` / `Payslip` / `PayrollRun` / `LoonaangifteFiling`
  (the five schemas `RuleCatalogue`/`RuleEngine` target, per `hr-objects.json`) declare an
  `x-openregister-lifecycle` `configuration` block. `NoSelfApprovalGuard` is only reachable because
  `Timesheet`/`Expense` **do** have lifecycles with named transitions (`approve`/`reject`) that
  declare `requires:`. The compliance-checked schemas have no equivalent transition to attach a
  guard to.

## Goals / Non-Goals

**Goals**: stop the codebase making a false claim about what it enforces; give the compliance
audit a machine-actionable signal (exit code) so "compliance" is more than a human-read report.

**Non-Goals**: this change does not attempt full write-time blocking of every `mandatory`
violation — that requires either a schema-modelling change (see options below) or new
OpenRegister capability, both out of scope for a doc-fix + exit-code change.

## Decision

Ship the low-risk half now (docblock correction + non-zero exit code on `occ humaniq:rules:audit`).
Record the enforcement-architecture options here rather than picking one silently:

### Option A — Add a minimal lifecycle to the five compliance-checked schemas

Give each schema a trivial `x-openregister-lifecycle` (e.g. a single `active` state with a
self-transition `save`) purely so `LifecycleGuardInterface` has a hook, then write
`RuleComplianceGuard` to call `RuleEngine::evaluate()` + `hasMandatory()` on that transition.

- Pro: closes the gap exactly as the (currently false) docblock describes.
- Con: forces a lifecycle concept onto schemas that have no other workflow need for one, purely as
  a plumbing device — modelling smell. OpenRegister's lifecycle guard mechanism was designed for
  named business transitions, not "every save."

### Option B — Stay audit-only; rely on the exit-code path

Keep `RuleEngine`/`RuleAuditService` as reporting-only, but make the report actionable: CI/ops
scripts run `occ humaniq:rules:audit` and fail the pipeline on a non-zero exit (mandatory violations
present). Catches drift on a cadence (CI run, cron) rather than at the point of write.

- Pro: no schema changes, no new OpenRegister dependency, ships immediately.
- Con: violations are caught after the fact, not blocked at the point of entry — a user can still
  save a non-compliant `Payslip` and it stays non-compliant until the next audit run.

### Option C — Build write-time enforcement on OpenRegister's shared decision-table capability

*(Re-scoped by `rules-onto-or-decision-tables`: when this option was first written the OR-side
substrate did not exist; it now partially does.)* OpenRegister ships the shared decision-table
capability (openregister#3329): the `openregister.decision-table` flow node atop the pure
`lib/Service/Dmn/DecisionTableEvaluator`, which humaniq already consumes for table-declared
compliance checks through `lib/Standards/TableCheckEvaluator.php` (the `ProvidesTables` seam).
Two build-on paths, in preference order:

1. Model enforcement as an OpenRegister **flow** carrying inline decision tables
   (`openregister.decision-table` steps), so the rule matching, its pinning and its audit trail
   are all the shared engine's.
2. Where write-time blocking needs humaniq's full corpus semantics (jurisdiction scoping,
   severity policy), a future `RuleComplianceGuard` calls `RuleEngine::evaluate()` +
   `hasMandatory()` — and `RuleEngine` already delegates tabular matching to the shared
   evaluator, so the guard inherits the consolidation for free.

What may still need an OR-side ask is only the *hook* (a schema-declarative pre-save validation
extension point); the *evaluation* substrate exists and MUST be reused.

- Pro: the architecturally "correct" answer — write-time enforcement without contorting humaniq's
  schemas into a fake lifecycle, and without a second rule engine.
- Con: the pre-save hook point (as opposed to the evaluator) is still not humaniq's to build.

**Recommendation**: ship Option B now (this change). Pursue Option C on the shared decision-table
substrate rather than hand-rolling Option A's lifecycle-as-plumbing workaround, which ADR-022
would flag as a parallel mechanism next audit.

### Standing constraint (rules-onto-or-decision-tables)

Whatever enforcement option is eventually built: rule *evaluation* is the shared OpenRegister
engine's job. New machine-checkable rules whose shape is tabular (thresholds, enumerations,
boolean gates over derived values) SHOULD be authored as decision tables via the `ProvidesTables`
capability; enforcement work MUST NOT introduce new in-app matching, hit-policy or cell-grammar
machinery. Only domain semantics (payroll arithmetic, statutory derivations, decidability) stay
humaniq-side, in `derive` callables or non-tabular predicates.

## Risks / Trade-offs

- Ops/CI must actually wire the new exit code into a gate for Option B to have any teeth — shipping
  the exit code alone doesn't enforce anything until something consumes it. Document this clearly
  in the command's help text and in `docs/` (out of scope for this change's tasks, but flagged).

## Migration Plan

No data migration. `RulesAuditCommand::execute()` changes only its return value; no output format
change for existing consumers parsing stdout.
