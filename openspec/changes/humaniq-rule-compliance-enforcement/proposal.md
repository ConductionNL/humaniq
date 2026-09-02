---
kind: code
---

## Why

humaniq's own architecture doc says the compliance engine has two halves: "the predicates are
side-effect free and unit-tested; the lifecycle wiring + object loading live in
`OCA\Humaniq\Lifecycle\RuleComplianceGuard`" (`lib/Standards/RuleEngine.php:19-20`). That class does
not exist anywhere in the codebase — `find . -iname "*RuleComplianceGuard*"` returns nothing, and
`lib/Lifecycle/` contains only `NoSelfApprovalGuard.php`. `RuleEngine::evaluate()` has exactly one
caller in the whole app, `RuleAuditService::audit()` (`lib/Service/RuleAuditService.php:101`), which
is wired only to the read-only `occ humaniq:rules:audit` command
(`lib/Command/RulesAuditCommand.php`). `RuleEngine::hasMandatory()` — a method whose entire purpose
is "true when any violation is `mandatory` (i.e. a lifecycle guard must block)"
(`lib/Standards/RuleEngine.php:202-218`) — has **zero call sites** anywhere under `lib/`.

Put together: humaniq ships an app whose store description and summary lead with "built-in
labour-law and wage-tax compliance" (`appinfo/info.xml`), a `RuleCatalogue` of machine-checkable
labour/payroll rules with a `mandatory`/`conditional`/`recommended` severity model, and a
`hasMandatory()` gate designed to block non-compliant saves — but no code path anywhere actually
blocks a save. An `Employee`, `EmploymentContract`, `Payslip`, or `PayrollRun` object that violates
a `mandatory` rule (e.g. a contract missing a required CAO field, a payslip missing a mandatory
wage-tax component) saves successfully and is only discovered later, if and when an operator
manually runs `occ humaniq:rules:audit`. Compounding this: none of those five schemas
(`Employee`, `EmploymentContract`, `Payslip`, `PayrollRun`, `LoonaangifteFiling` — verified via
`lib/Settings/register.d/hr-objects.json`) declare an `x-openregister-lifecycle` `configuration`
block at all, so there is today no transition for a `LifecycleGuardInterface` `requires:` guard
(the mechanism `NoSelfApprovalGuard` uses) to attach to even if one were written — the guard
mechanism the docblock names literally has no hook point on these object types as currently
modelled.

This is a genuine gap between documented design intent and shipped behaviour (a stub/dead
reference — `hydra-gate-stub-scan` territory) and a real product gap: "compliance" today means
"a report you can run," not "a rule that is enforced."

## What Changes

- Correct the misleading docblock in `lib/Standards/RuleEngine.php` — remove the reference to a
  `RuleComplianceGuard` class that does not exist, and document accurately that today the engine
  is **advisory/reporting-only** (no write-time enforcement), pending the design decision below.
- Make the read-only audit **actionable** without requiring new OpenRegister guard-hook
  infrastructure: `occ humaniq:rules:audit` SHALL exit with a non-zero status code when any
  `mandatory`-severity violation is found, so it can be wired into CI/ops checks as a real gate
  rather than only human-read output.
- **Design decision required** (see `design.md`): whether humaniq should (a) add a minimal
  `x-openregister-lifecycle` to the compliance-checked schemas purely to give
  `LifecycleGuardInterface` a hook point for a real `RuleComplianceGuard`, or (b) stay
  audit-only and rely on the CI/ops-facing exit code from this change, or (c) build write-time
  enforcement on OpenRegister's shared decision-table capability (openregister#3329) — the
  evaluation substrate humaniq already consumes via `rules-onto-or-decision-tables`'
  `ProvidesTables` seam — where the only remaining OR-side ask is a schema-declarative "pre-save
  validation" hook point, never a second evaluator (see the re-scoped `design.md`).
- No change to the existing `hasMandatory()` predicate logic — it is correct, just unused; this
  change gives it a real caller (the exit-code path) and documents the remaining gap honestly
  rather than leaving a misleading claim in the code.

## Capabilities

### New Capabilities
- `humaniq-rule-compliance-enforcement`: the compliance audit surfaces mandatory violations as an
  actionable (non-zero exit) signal, and the codebase accurately documents what is and is not
  enforced at write time.

## Impact

- **`lib/Standards/RuleEngine.php`** — docblock correction only (no behavioural change to
  `evaluate()`/`hasMandatory()`/`checks()`).
- **`lib/Command/RulesAuditCommand.php`** — `execute()` returns a non-zero exit code when
  `$report['violationsBySeverity']['mandatory'] > 0`.
- **`design.md`** (new) — records the three enforcement options and the recommendation, since a
  true write-time guard requires either a schema-modelling change (adding lifecycles to schemas
  that don't need one for any other reason) or a new OpenRegister capability, both of which are
  bigger than this change's scope.
- No route, schema, or frontend changes.
