---
capability: hrm-rule-engine
status: done
built_by: openspec/changes/hrm-rule-engine (archived; spec promoted 2026-08-18)
---

# hrm-rule-engine Specification

**Status**: done
**Scope**: humaniq

**OpenSpec changes**: [humaniq-rule-compliance-enforcement](../../changes/archive/2026-09-07-humaniq-rule-compliance-enforcement/) _(archived 2026-09-07)_
- [matrix-rows-without-a-requirement](../../changes/archive/2026-11-21-matrix-rows-without-a-requirement/) _(archived 2026-11-21)_ — adds the requirements above that the HR matrix rows named without one

## Why this file exists

Fifteen shipped source files carried `@spec` anchors pointing at three change
directories that no longer exist:

```
openspec/changes/hrm-rule-engine/specs/hrm-rule-engine/spec.md      (10 files)
openspec/changes/hrm-rule-testdata-seed/specs/hrm-rule-engine/spec.md (3 files)
openspec/changes/hrm-rule-audit/specs/hrm-rule-engine/spec.md        (2 files)
```

Those changes were archived and their spec was never promoted into
`openspec/specs/`, so every anchor pointed at nothing (gate-46,
`spec-anchor-existence`). All three name the same capability, which is why
this is one file rather than three.

The gate's instruction is "fix the TARGET, not each tag", and that is the
right reading: the capability is live and shipping, so the honest repair is
the missing document, not fifteen re-pointed comments. This spec is written
from the code as it stands, not from memory of the original change — where
the code and a remembered intention would disagree, the code wins.

## Purpose

humaniq audits its HR, labour and payroll data against a versioned corpus of
machine-checkable rules drawn from EU labour directives, ILO core conventions,
GDPR for employee data, occupational health & safety, national labour law
(NL first, then DE/FR/BE) and payroll / wage-tax & social-security compliance.

The corpus is deliberately larger than what is enforced: a rule is *catalogued*
when it is written down, and *enforced* only once an executable predicate
exists for it. Keeping those two numbers apart is the point — an audit that
reported only enforced rules would describe its own coverage as 100% and say
nothing about the rules nobody has implemented yet.

## Requirements

### Requirement: A versioned read-only catalogue SHALL define the rule corpus (REQ-RULE-001)

`lib/Standards/RuleCatalogue.php` SHALL hold the operative HR/labour rules as
static, versioned data: each rule carries an id, a statement, a severity, a
jurisdiction and a source citation back to the directive, convention or law it
comes from.

The catalogue SHALL be read-only at runtime. It is data, not behaviour — a rule
is added by editing the corpus, never by a caller registering one.

#### Scenario: A rule traces back to its source

- GIVEN any rule id in the catalogue
- WHEN the rule is read
- THEN it carries a severity and a source citation sufficient to identify the
  standard or law it derives from

### Requirement: The engine SHALL evaluate an object against the rules that apply to it (REQ-RULE-002)

`lib/Standards/RuleEngine.php` SHALL evaluate an HR/labour object against the
applicable corpus rules and return structured `Violation` objects
(`lib/Standards/Violation.php`), each carrying the rule id, severity, source
citation and statement — so a caller can act on the severity and a human can
trace the finding back to the law.

Applicability SHALL be scoped by jurisdiction: a rule applies to its own
country, plus EU-wide rules for EU members, plus `global` rules everywhere. The
engine SHALL NOT enforce a rule the organisation is not subject to.

The engine SHALL carry no built-in checks of its own.

#### Scenario: A rule outside the organisation's jurisdiction is not enforced

- GIVEN a rule scoped to a country the organisation does not operate in
- WHEN an object is evaluated
- THEN that rule contributes no violation

### Requirement: Executable checks SHALL be contributed by auto-discovered per-domain providers (REQ-RULE-003)

Every executable check SHALL be contributed by a `CheckProvider`
(`lib/Standards/Checks/CheckProvider.php`) discovered automatically by
`RuleEngine::providers()`. A new compliance domain SHALL be addable by adding a
provider file, without editing `RuleEngine`.

Providers SHALL be side-effect free and SHALL key every check to a real
catalogue rule id — a predicate that answers to no catalogued rule produces a
finding nothing can explain.

#### Scenario: Adding a domain does not modify the engine

- GIVEN a new provider file under `lib/Standards/Checks/`
- WHEN the engine runs
- THEN its checks are executed without any change to `RuleEngine`

### Requirement: The engine SHALL offer a block decision that nothing yet enforces (REQ-RULE-004)

This requirement described a `lib/Lifecycle/RuleComplianceGuard.php` that has never existed. It is
restated here to say what the engine actually contracts to do, because a requirement asserting an
absent file in a spec marked `status: done` is what put the same false claim into
`lib/Standards/RuleEngine.php`'s docblock.

`RuleEngine` SHALL remain a pure evaluator and SHALL expose `hasMandatory()` so that a future
write-time guard can ask it whether to block, without the engine itself loading objects or knowing
about lifecycles.

Write-time blocking is NOT implemented and SHALL NOT be claimed as implemented. It is blocked on an
OpenRegister capability that does not exist yet: a schema-declarative pre-save validation hook
point, requested in ConductionNL/openregister#3493. `LifecycleGuardInterface` is not a substitute,
because it attaches to a named transition in `x-openregister-lifecycle`, and none of the five
compliance-checked schemas (Employee, EmploymentContract, Payslip, PayrollRun, LoonaangifteFiling)
declares a lifecycle or has any business workflow that would justify one.

Until that hook exists, the enforcement point is REQ-RULE-008: the audit's exit code, which CI and
ops gate on. Detection is therefore on a cadence rather than at the point of write, and that
trade-off is recorded in this change's `design.md`.

#### Scenario: The engine offers a decision, and does not act on it

- GIVEN an object that violates a rule with severity `mandatory`
- WHEN `RuleEngine::evaluate()` runs over it
- THEN `hasMandatory()` reports the breach
- AND no write is refused, because no guard is wired

#### Scenario: The claim matches the code

- GIVEN a reader looks for the guard this requirement once named
- WHEN they search the repository for `RuleComplianceGuard`
- THEN they find no such class
- AND the specification says so rather than asserting it exists

### Requirement: An audit SHALL report enforced-versus-catalogued coverage (REQ-RULE-005)

`lib/Service/RuleAuditService.php`, exposed as `occ humaniq:rules:audit`
(`lib/Command/RulesAuditCommand.php`), SHALL load every object of each
engine-supported type, run the engine over it, and aggregate a report of:

- **coverage** — how many corpus rules are actually enforceable today, as a
  fraction of the machine-checkable corpus,
- how many objects were checked and how many are compliant,
- violations grouped by severity and by rule.

The audit SHALL be read-only, and SHALL report coverage as a fraction rather
than a bare count. A report that omitted the denominator would describe a corpus
with one implemented check as fully audited.

#### Scenario: Coverage names both numbers

- WHEN `occ humaniq:rules:audit` runs
- THEN the report states how many rules are enforced AND how many are
  machine-checkable, not merely how many violations were found

### Requirement: Test data SHALL be seedable to a compliant state, idempotently (REQ-RULE-006)

`lib/Service/RuleTestDataSeeder.php`, exposed as
`occ humaniq:rules:seed-testdata` (`lib/Command/RulesSeedTestDataCommand.php`),
SHALL backfill local TEST data so it satisfies the enforced rules: creating a
provider's sample objects when its type is empty, and backfilling
provider-declared field defaults on rows that are missing them.

It SHALL be idempotent, and SHALL NOT overwrite a value a human has already
set. It is a development and test affordance; it is not a data-repair tool for
production.

#### Scenario: Running the seeder twice changes nothing the second time

- GIVEN a freshly seeded environment
- WHEN the seeder runs again
- THEN no object is created or modified

### Requirement: The register SHALL be initialised on install as well as on upgrade (REQ-RULE-007)

`lib/Repair/InitializeRegister.php` SHALL import the hrmq register, and SHALL be
registered as a `repair-steps/install` step as well as `post-migration`.

Nextcloud guards both the pre- and post-migration blocks with
`if ($previousVersion !== '')`, so on a FRESH install neither runs and only
`repair-steps/install` executes unconditionally. Declaring post-migration alone
means the register is never imported on a new instance — and the failure is
silent, because `InitializeSettings::run()` swallows the exception and
`occ app:enable` still exits 0.

#### Scenario: A fresh install has its register

- GIVEN a Nextcloud instance where humaniq has never been installed
- WHEN the app is enabled
- THEN the hrmq register exists in OpenRegister

### Requirement: The compliance audit is a machine-actionable signal (REQ-RULE-008)

`occ humaniq:rules:audit` SHALL exit with a non-zero status code when the compliance report contains
one or more `mandatory`-severity violations, and SHALL exit `0` otherwise, so the audit can be
wired into CI/ops pipelines as an enforceable gate rather than only human-read output.

**Feature tier**: MVP

#### Scenario: The audit exits non-zero when a mandatory violation exists

- GIVEN the register contains at least one object with a `mandatory`-severity rule violation
- WHEN `occ humaniq:rules:audit` runs
- THEN the command MUST print the violation in its report
- AND MUST exit with a non-zero status code

#### Scenario: The audit exits zero when fully compliant

- GIVEN the register contains no `mandatory`-severity rule violations
- WHEN `occ humaniq:rules:audit` runs
- THEN the command MUST exit with status code `0`

### Requirement: Documentation accurately describes enforcement scope (REQ-RULE-009)

The codebase SHALL NOT claim write-time enforcement infrastructure that does not exist.
`lib/Standards/RuleEngine.php`'s documentation SHALL accurately state that the engine is
advisory/reporting-only unless and until a real write-time guard is implemented, rather than
referencing a non-existent `RuleComplianceGuard` class.

**Feature tier**: MVP

#### Scenario: The RuleEngine docblock matches actual behaviour

- GIVEN a reader inspects `lib/Standards/RuleEngine.php`'s class-level documentation
- WHEN they check whether a lifecycle guard enforces mandatory violations at write time
- THEN the documentation MUST NOT reference a class that does not exist in the codebase
- AND MUST accurately describe the audit as reporting-only (or reference the real guard, if one
  has since been implemented)

### Requirement: HR SHALL see the audit's violations and coverage on a page (REQ-RULE-010)

HR SHALL run the compliance audit from a `Compliance check` page and read its result there,
without `occ`. The page SHALL list every violation with its rule, its severity and the record it
is about, link each violation to that record, and show the corpus coverage as rules enforced
against rules catalogued. Only users who may read the audited records SHALL see their
violations.

Rows: `cmp-rule-engine` (humaniq matrix).

#### Scenario: HR finds a contract below the CAO minimum

@e2e exclude Needs a contract seeded below its CAO scale minimum; the listing and its record link are verified by ComplianceCheckServiceTest, RuleAuditServiceTest::testFindingsNameTheRecordOfEachViolation and recordRoute.test.mjs.
- **GIVEN** a contract paid below the minimum of its CAO scale
- **WHEN** an HR adviser runs the check on `Compliance check`
- **THEN** the page lists a mandatory `nl-cao-minimumloon-schaal` violation that opens the contract

#### Scenario: Coverage is shown beside the findings
- **WHEN** the check has run
- **THEN** the page shows how many catalogued rules are enforced and how many are not

#### Scenario: A manager sees only their own people

@e2e exclude Needs a second, manager-only account; the caller-scoped filter is verified by ComplianceCheckServiceTest::testAManagerSeesOnlyRecordsTheyMayRead.
- **GIVEN** a manager of one team
- **WHEN** they open `Compliance check`
- **THEN** no violation about an employee outside their team is listed

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
