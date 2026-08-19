## Purpose

Give hrmq a trustworthy measurement surface — a way to know that its installed dependencies match
what their lockfiles pin, that its shipped frontend bundle matches the source and dependencies it
was supposedly built from, and that a manifest filter clause which fails to resolve is caught
before it ships an index page that is silently empty.

## ADDED Requirements

### Requirement: Dependency installation drift SHALL be mechanically detectable

A checked-in, side-effect-free check SHALL compare the version string recorded in
`node_modules/@conduction/nextcloud-vue/package.json` against the version `package-lock.json`
resolves for that package, and SHALL fail with both values printed when they disagree.

A second check SHALL verify that `vendor/conduction/hydra-gates` exists on disk at the version
`composer.lock` pins, and SHALL report — distinctly from "missing" — when the `vendor/` directory
exists but is not writable by the invoking process, since that is a different failure (install
permission) requiring a different fix than "run the install".

Neither check SHALL run `npm install`, `npm ci`, `composer install`, or any other command that
mutates `node_modules/` or `vendor/`. Detection is read-only by construction, so it is safe to run
in read-only CI stages and in a developer's shell without side effects.

#### Scenario: A drifted node_modules install fails loudly
@e2e exclude Node-level static check; no browser or server needed — verified directly against this checkout's node_modules and package-lock.json, which today disagree (`1.0.0-beta.215` installed vs `2.2.0-vue3.2` locked)
- **GIVEN** `package-lock.json` resolves `@conduction/nextcloud-vue` to `2.2.0-vue3.2`
- **AND** `node_modules/@conduction/nextcloud-vue/package.json` reports `1.0.0-beta.215`
- **WHEN** the dependency-drift check runs
- **THEN** it exits non-zero and prints both version strings and the two file paths it read them from

#### Scenario: A correctly installed tree passes
@e2e exclude Node-level static check; no browser or server needed
- **GIVEN** the installed version under `node_modules/@conduction/nextcloud-vue/package.json`
  equals the version `package-lock.json` resolves for that package
- **WHEN** the dependency-drift check runs
- **THEN** it exits zero

#### Scenario: A missing vendor package is distinguished from a permission failure
@e2e exclude Node/Composer-level static check; no browser or server needed — verified directly against this checkout, where `vendor/conduction/hydra-gates` is absent and `vendor/` is root-owned
- **GIVEN** `composer.lock` pins `conduction/hydra-gates` at a version
- **AND** `vendor/conduction/hydra-gates` does not exist on disk
- **WHEN** the vendor-drift check runs
- **THEN** it reports "not installed" (not "wrong version"), and separately reports whether `vendor/`
  itself is writable by the current process

### Requirement: The shipped frontend bundle SHALL be verifiably built from current source and dependencies

A checked-in check SHALL be able to assert that every file under `js/` claiming to be built from
`src/` was in fact produced after the newest change to `src/`, `package-lock.json`, and
`webpack.config.js` — the same comparison that found this defect (`src/manifest.json` changed at
2026-08-19 12:43, `js/hrmq-main.js` was last built 2026-07-30 16:25) — via a mechanism suitable for
CI, where a fresh checkout gives every source file the same mtime and a raw timestamp comparison
therefore cannot be used (see design.md "Decision 3" for the chosen mechanism and why a plain mtime
check does not survive a CI clone).

A stale bundle SHALL be detectable without starting a browser, a Nextcloud instance, or the app
itself — the check operates on files on disk only.

#### Scenario: A bundle older than its declared source is flagged
@e2e exclude Node-level static/hash check; no browser or server needed — reproduced directly against this checkout's actual mtimes today
- **GIVEN** `src/manifest.json`'s last-modified time is newer than `js/hrmq-main.js`'s
- **WHEN** the bundle-freshness check runs in a context where file mtimes are meaningful (a local
  working tree, not a fresh CI clone)
- **THEN** it exits non-zero and names the specific source file(s) newer than the bundle

#### Scenario: A CI clone (uniform mtimes) still detects staleness
@e2e exclude Node-level static/hash check; no browser or server needed — this scenario specifies behaviour a raw mtime comparison cannot provide, motivating design.md's chosen mechanism
- **GIVEN** a fresh clone where every file's mtime equals the checkout time
- **AND** the bundle was built from a source tree whose content differs from the current `src/` tree
- **WHEN** the bundle-freshness check runs
- **THEN** it exits non-zero, using a signal derived from git history (the last commit touching the
  build-input paths) or source content — never the checked-out working tree's own filesystem mtimes,
  which a fresh clone makes uniform and therefore meaningless for this comparison

### Requirement: A manifest workspace-scoped filter clause SHALL never reach the API unresolved

For every `@workspace.<key>?` (optional) filter clause anywhere under `pages[].config.filter` or a
quick-filter tab's `filter` in `src/manifest.json`, resolving that clause against an EMPTY
workspace context (`{}`) using the filter-resolution functions actually present in the installed
`@conduction/nextcloud-vue` SHALL produce a filter map with that key ABSENT — never a filter map
whose value is still the literal `@workspace.<key>?` string.

This check SHALL enumerate every occurrence in the current manifest (44 as of this measurement; the
check counts its own input rather than hardcoding that number, since the manifest changes
independently of this change) and SHALL report the page id and JSON path of any clause that fails
to drop.

#### Scenario: An unresolved optional workspace token in the manifest is caught before it ships
@e2e exclude Node-level static check over manifest.json + the installed library's resolver functions; no browser needed — this is a string-substitution defect, not a rendering defect, and reproduces identically without one
- **GIVEN** `src/manifest.json`'s `Employees` page carries
  `filter: { administrationId: "@workspace.activeAdministrationId?" }`
- **WHEN** the manifest-sentinel-resolution check resolves that clause against an empty workspace
  context using the installed resolver
- **THEN** the resulting filter map does not contain an `administrationId` key at all

#### Scenario: The check's own baseline is honest about what it does and does not prove
@e2e exclude design-level statement, not independently testable as a scenario; documented for the record per design.md Decision 1
- **GIVEN** this checkout's current source (both hrmq's `App.vue` and the installed
  `@conduction/nextcloud-vue` resolver) already implements the drop-on-empty behaviour correctly
- **WHEN** this check is run against today's checkout
- **THEN** it passes today, which proves the source-level logic is correct but does NOT by itself
  prove the deployed bundle matches that logic — that guarantee is the bundle-freshness
  requirement's job, not this one's
