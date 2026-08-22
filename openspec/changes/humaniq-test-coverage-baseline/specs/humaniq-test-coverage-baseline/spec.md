## ADDED Requirements

### Requirement: The compliance predicate layer has real unit-test coverage

The pure, side-effect-free predicate layer (`RuleCatalogue`, `RuleEngine`, and `lib/Standards/Checks/*` providers) MUST have PHPUnit unit tests that actually execute, and the
codebase SHALL NOT claim test coverage it does not have.

**Feature tier**: MVP

#### Scenario: RuleEngine jurisdiction scoping is verified by a test

- GIVEN a rule scoped to a single jurisdiction (e.g. `NL`)
- WHEN `RuleEngineTest` evaluates that rule against an object whose jurisdiction is a different,
  non-EU country (e.g. `US`)
- THEN the test MUST assert the rule does not fire
- AND a corresponding assertion MUST exist proving an EU-wide rule fires for every
  `RuleEngine::EU_MEMBER_STATES` entry

#### Scenario: hasMandatory is verified by a test

- GIVEN a set of violations containing at least one `mandatory`-severity entry
- WHEN `RuleEngineTest` calls `RuleEngine::hasMandatory()`
- THEN the test MUST assert the method returns `true`
- AND a corresponding assertion MUST exist proving it returns `false` when no violation is
  `mandatory`-severity

#### Scenario: composer test:unit actually discovers tests

- GIVEN the `tests/` directory scaffolded by this change
- WHEN `composer test:unit` runs
- THEN PHPUnit MUST discover and execute the new test classes
- AND MUST exit non-zero if any assertion fails

### Requirement: At least one real user path is proven end-to-end

A manifest-driven page SHALL be proven to render via an actual browser-driven test, not only via
a unit test that calls backend logic directly.

**Feature tier**: MVP

#### Scenario: The Timesheets index page renders via Playwright

- GIVEN a running humaniq instance
- WHEN a Playwright test navigates to `/apps/humaniq/timesheets` (directly, and via the
  `TimesheetsGroup` app-nav entry)
- THEN the test MUST assert the `Timesheets` index page actually renders
- AND the assertion MUST exercise the real router + manifest + `CnPageRenderer` path, not a
  controller method called directly

### Requirement: No script references a nonexistent file

Every `npm run` / `composer` script SHALL reference a file that exists in the repository.

**Feature tier**: MVP

#### Scenario: check:manifest no longer fails on a missing file

- GIVEN `package.json`'s `check:manifest` script
- WHEN `npm run check:manifest` is invoked
- THEN it MUST NOT fail with "module not found" for `tests/validate-manifest.js`
