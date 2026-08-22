## ADDED Requirements

### Requirement: The compliance audit is a machine-actionable signal

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

### Requirement: Documentation accurately describes enforcement scope

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
