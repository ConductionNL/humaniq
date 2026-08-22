## 1. Correct the false "unit-tested" claim (code)

- [ ] 1.1 In `lib/Standards/RuleEngine.php`'s class docblock, remove "The predicates are
      side-effect free and unit-tested" and replace with an accurate statement referencing this
      change's test suite (or, if 2.x below lands first, a real cross-reference to the test files).

## 2. Scaffold the test infrastructure the codebase already references

- [ ] 2.1 Create `tests/` with `phpunit.xml` (Nextcloud app conventions: bootstrap, `Unit` suite,
      matching the `OCA\Humaniq\Tests\` namespace already declared in `composer.json`'s
      `autoload-dev`).
- [ ] 2.2 Confirm `composer test:unit` / `composer test:all` (currently silently no-op with no
      `tests/` to discover) actually run the new suite and exit non-zero on a failing assertion.

## 3. Unit-test the pure compliance-predicate layer

- [ ] 3.1 `tests/Unit/Standards/RuleCatalogueTest.php` — `count() === count(all())`; every entry in
      `machineCheckable()` also appears in `all()`; `byDomain()`/`byFramework()`/`byJurisdiction()`
      each return a strict subset of `all()` filtered correctly; `version()` returns a non-empty
      string.
- [ ] 3.2 `tests/Unit/Standards/RuleEngineTest.php` — jurisdiction scoping per the class docblock's
      own contract (`lib/Standards/RuleEngine.php:9-11`): an NL-only rule does not fire for a `US`
      object; an EU-wide rule fires for every `EU_MEMBER_STATES` entry; a `global` rule fires
      everywhere. `hasMandatory()` returns `true` iff at least one returned violation has
      `severity === 'mandatory'`, `false` otherwise. `checkedRuleIds()` only returns ids that have a
      registered predicate (cross-check against `RuleCatalogue::machineCheckable()`).
- [ ] 3.3 `tests/Unit/Standards/Checks/NlWageTaxFilingChecksTest.php` — `onOrBefore()` boundary
      cases: date strictly before the limit (true), date equal to the limit (true, per `<=`), date
      after the limit (false), and an unparseable date/limit string (both `strtotime()` calls
      return `false` — confirm the documented "cannot compare, treat as not-yet-violated" behaviour
      that `lib/Standards/Checks/NlWageTaxFilingChecks.php:113-121` implements).

## 4. Prove one real user path with Playwright (e2e reality)

- [ ] 4.1 Add a Playwright spec (following the fleet's UI-only e2e convention — no API-direct
      assertions) that navigates to `/apps/humaniq/timesheets`, waits for the manifest-driven
      `Timesheets` index page to render (e.g. asserts the page title/table renders), and confirms
      navigation via `TimesheetsGroup` in the app nav reaches it — proving the router + manifest +
      `CnIndexPage` wiring works end-to-end, not just that `RuleEngine` returns the right array in
      isolation.
- [ ] 4.2 Wire the new Playwright project into whatever fleet-shared e2e runner convention the
      sibling apps (pipelinq/procest) use, rather than inventing a bespoke one-off harness.

## 5. Fix the dangling `check:manifest` script

- [ ] 5.1 `package.json`'s `check:manifest` currently runs `node tests/validate-manifest.js`, which
      does not exist (`package.json:14`). Either add a real script using
      `@conduction/nextcloud-vue`'s `validateManifest` utility against `src/manifest.json`, or
      remove the dangling reference if manifest validation is intended to come from a hydra gate —
      do not leave a script that fails on first invocation.

## 6. Verify

- [ ] 6.1 Run `composer test:unit` — confirm it discovers and runs the new tests (not the previous
      silent "Tests require Nextcloud environment, skipping..." fallback with zero tests found).
- [ ] 6.2 Run the new Playwright spec against a local dev instance — confirm it fails if the
      `Timesheets` route is broken (sanity-check the test actually asserts something, not a
      no-op).
- [ ] 6.3 Run `npm run check:manifest` — confirm it no longer errors on a missing file.
