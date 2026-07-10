## Context

`RuleEngine`'s own docblock (`lib/Standards/RuleEngine.php:19-20`) claims write-time enforcement
lives in `OCA\Hrmq\Lifecycle\RuleComplianceGuard`. That class was never built. Verified:

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

Ship the low-risk half now (docblock correction + non-zero exit code on `occ hrmq:rules:audit`).
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
scripts run `occ hrmq:rules:audit` and fail the pipeline on a non-zero exit (mandatory violations
present). Catches drift on a cadence (CI run, cron) rather than at the point of write.

- Pro: no schema changes, no new OpenRegister dependency, ships immediately.
- Con: violations are caught after the fact, not blocked at the point of entry — a user can still
  save a non-compliant `Payslip` and it stays non-compliant until the next audit run.

### Option C — Request a schema-declarative "pre-save validation" extension from OpenRegister

Propose a new `x-openregister-validation` (or similar) schema extension that runs arbitrary
predicates on every create/update regardless of lifecycle state, consumed the same way
`x-openregister-lifecycle`'s `requires:` is consumed today. This is the ADR-022/031-aligned path
(consume a declarative OR abstraction rather than hand-roll it) but requires OR-side work outside
hrmq's control and timeline.

- Pro: the architecturally "correct" answer — write-time enforcement without contorting hrmq's
  schemas into a fake lifecycle.
- Con: not hrmq's to build; needs an OR-side proposal + timeline hrmq doesn't control.

**Recommendation**: ship Option B now (this change). File Option C as a cross-cutting OR-abstraction
request (see the parent review's cross-cutting candidates) rather than hand-rolling Option A's
lifecycle-as-plumbing workaround, which ADR-022 would flag as a parallel mechanism next audit.

## Risks / Trade-offs

- Ops/CI must actually wire the new exit code into a gate for Option B to have any teeth — shipping
  the exit code alone doesn't enforce anything until something consumes it. Document this clearly
  in the command's help text and in `docs/` (out of scope for this change's tasks, but flagged).

## Migration Plan

No data migration. `RulesAuditCommand::execute()` changes only its return value; no output format
change for existing consumers parsing stdout.
