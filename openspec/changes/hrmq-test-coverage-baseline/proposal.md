---
kind: code
---

## Why

HRMQ ships zero automated tests of any kind. Verified at HEAD:

- `find . -iname "*test*"` (excluding `node_modules`) returns only two production files
  (`lib/Service/RuleTestDataSeeder.php`, `lib/Command/RulesSeedTestDataCommand.php` — a
  testdata-seeding *feature*, not a test suite). There is no `tests/` directory at all.
- `composer.json`'s `autoload-dev` declares `"OCA\\Hrmq\\Tests\\": "tests/"` (`composer.json:19-21`)
  — a namespace mapped to a directory that does not exist.
- `composer.json`'s `test:unit` / `test:all` scripts run `./vendor/bin/phpunit --colors=always`
  (`composer.json:43-44`) with no `phpunit.xml` anywhere in the repo, and no `tests/` for PHPUnit
  to discover — the command has nothing to execute.
- `package.json`'s `check:manifest` script is `node tests/validate-manifest.js` (`package.json:14`)
  — `tests/validate-manifest.js` does not exist; the script fails outright if invoked.
- No Playwright/Cypress spec, no `.spec.js`, no `phpunit.xml`, no e2e project of any kind exists
  under the app (verified via repo-wide `find`).
- `lib/Standards/RuleEngine.php`'s own class docblock claims "The predicates are side-effect free
  and unit-tested" (`lib/Standards/RuleEngine.php:18`) — a false claim; there is no unit test for
  `RuleEngine`, `RuleCatalogue`, or any of the `lib/Standards/Checks/*` predicate providers anywhere
  in the codebase. This is the exact "phantom green" pattern: the code and its own comments assert
  a safety net that was never built.

This is the most severe instance of `hydra-gate-stub-scan` territory possible — not a stub
implementation, but a *complete absence* of the verification infrastructure the codebase claims
to have, on an app whose stated purpose is labour-law/wage-tax **compliance**. Every
`lib/Standards/Checks/*.php` predicate (EU/US payroll, NL wage-tax filing, etc.) that decides
whether an `Employee`/`EmploymentContract`/`Payslip` is compliant has never been exercised by a
test — a regression in `onOrBefore()`-style date-comparison logic (e.g.
`lib/Standards/Checks/NlWageTaxFilingChecks.php:113-121`,
`lib/Standards/Checks/EuUsPayrollChecks.php:954-962`) would ship silently.

## What Changes

- Correct the false "unit-tested" claim in `lib/Standards/RuleEngine.php`'s class docblock to
  accurately state that unit tests do not yet exist (tracked by this change).
- Scaffold the missing `tests/` directory the codebase already references but never created:
  `phpunit.xml` (Nextcloud app conventions, `OCA\Hrmq\Tests\` namespace matching
  `composer.json`'s existing `autoload-dev` entry).
- Add PHPUnit unit tests for the pure, side-effect-free predicate layer — the highest-value,
  lowest-cost target since these are plain-array-in/`Violation[]`-out pure functions with no
  Nextcloud framework dependencies:
  - `RuleCatalogueTest` — `all()`, `byDomain()`, `byFramework()`, `byJurisdiction()`,
    `machineCheckable()`, `count()`, `version()` return internally-consistent data (e.g.
    `count() === count(all())`, every `machineCheckable()` entry also appears in `all()`).
  - `RuleEngineTest` — `evaluate()` applies jurisdiction scoping (NL-only rule does not fire for a
    `US` object; EU-wide rule fires for every EU member state; `global` rule fires everywhere) per
    the class docblock's own stated contract (`lib/Standards/RuleEngine.php:9-11`); `hasMandatory()`
    returns `true` iff a `mandatory`-severity violation is present; `checkedRuleIds()` only returns
    ids with a registered predicate.
  - At least one `*ChecksTest` for an existing `lib/Standards/Checks/*.php` provider (e.g.
    `NlWageTaxFilingChecksTest` covering `onOrBefore()`'s boundary conditions: date before, on, and
    after the limit, and the `strtotime()` failure path).
- Add a minimal Playwright e2e spec (matching the fleet's Playwright-UI-only convention) that
  drives the real `Timesheets` index page end-to-end: navigate to `/apps/hrmq/timesheets`, assert
  the page renders via the router (not a controller-direct call), confirming the SPA shell +
  manifest-driven route actually resolves in a browser — the one thing no unit test can prove.
- Fix `package.json`'s `check:manifest` script to point at a script that exists (either restore a
  real `tests/validate-manifest.js` using the shared `@conduction/nextcloud-vue` `validateManifest`
  utility per ADR-036, or remove the dangling script reference if manifest validation is intended
  to be provided by a hydra gate instead).

## Capabilities

### New Capabilities
- `hrmq-test-coverage-baseline`: a minimal but real automated test baseline exists — pure
  compliance predicates are unit-tested, and at least one user-facing route is proven to render
  via an actual browser-driven e2e test.

## Impact

- **`lib/Standards/RuleEngine.php`** — docblock correction only (no behavioural change).
- **`tests/`** (new) — `phpunit.xml`, `Unit/Standards/RuleCatalogueTest.php`,
  `Unit/Standards/RuleEngineTest.php`, `Unit/Standards/Checks/NlWageTaxFilingChecksTest.php`.
- **`package.json`** — `check:manifest` script corrected to a script that exists.
- **New Playwright e2e project** (or addition to an existing fleet-shared one, if hrmq already has
  Playwright config elsewhere — verified it does not) covering the `Timesheets` index page.
- No production behavioural change to `RuleEngine`/`RuleCatalogue`/Check providers.
