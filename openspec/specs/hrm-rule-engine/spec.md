---
capability: hrm-rule-engine
status: done
built_by: openspec/changes/hrm-rule-engine (archived; spec promoted 2026-08-18)
---

# hrm-rule-engine Specification

**Status**: done
**Scope**: humaniq

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

### Requirement: A lifecycle guard SHALL block on mandatory violations (REQ-RULE-004)

`lib/Lifecycle/RuleComplianceGuard.php` SHALL wire the engine into the object
lifecycle: a `mandatory` violation SHALL block the transition; a lower severity
SHALL warn without blocking.

Object loading and lifecycle wiring live in the guard, NOT in the engine — the
engine stays a pure evaluator so its predicates remain unit-testable without a
register.

#### Scenario: A mandatory violation refuses the write

- GIVEN an object that violates a rule with severity `mandatory`
- WHEN a guarded lifecycle transition is attempted
- THEN the transition is refused and the violation is reported

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
