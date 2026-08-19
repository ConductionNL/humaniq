# Tasks — absence-rate-partial-recovery

## 1. Schema
- [x] Add `absenceProgression` (array of `{effectiveFrom, absencePercentage}`) to `SickLeaveCase` in `lib/Settings/register.d/hr-verzuim.json`, with no free-text property (REQ-ABSRATE-003)
- [x] Add `currentAbsencePercentage` (number, nullable) as a stored projection
- [x] Bump the `SickLeaveCase` schema `version` 0.3.0 → 0.4.0
- [x] Document the empty-means-full-absence default on the field itself, so the no-regression contract lives where a reader finds it
- [x] Re-argue REQ-VWP-002 in the field description: the AP beleidsregels permit recording the extent of resumption, never the cause

## 2. Calculation
- [x] Add `lib/Service/AbsenceRateService.php` — dependency-free, no container, no clock (REQ-ABSRATE-001)
- [x] Clip the case window on `recoveredDate` only while `status` is `hersteld` (REQ-ABSRATE-002)
- [x] Sort steps, clip early steps onto `firstSickDay`, skip malformed entries, fall back to full absence — never to zero
- [x] Exclude and count absences with no covering contract (REQ-ABSRATE-004)
- [x] Return `null` for `percentage` when availability is zero
- [x] Make the full-time week a parameter, defaulting to 40

## 3. Tests
- [x] `tests/Unit/Service/AbsenceRateServiceTest.php` — 14 tests
- [x] Include the whole-day CONTROL first: without it, a partial-day assertion cannot distinguish "the progression was applied" from "the progression was ignored and the number matched anyway"
- [x] Verify the tests bite: mutate `normalisedSteps()` to ignore `absenceProgression` and confirm exactly the four partial-counting tests fail, then revert
- [x] Full suite green (1102 tests, 4398 assertions)
- [x] psalm clean on the new service; phpcs clean under hrmq's effective ruleset

## 4. Manifest
- [x] Add the "Work resumption" data widget to `SickLeaveCaseDetail`, full width (REQ-ABSRATE-005)
- [x] Exclude both new fields from the "Case" widget so they render once
- [x] Shift the files widget down 3 grid rows
- [x] Use a registered icon (`Timeline`) — an unregistered name silently renders a help-circle
- [x] `node tests/validate-manifest.js` — Ajv PASS, 0 errors

## 5. Not in this change
- [ ] An analytics endpoint exposing the rate as a time series — belongs with the dashboard rebuild
- [ ] A dashboard chart widget — same
- [ ] Back-filling `absenceProgression` on existing cases — optional per case; the empty default keeps every existing figure correct
- [ ] Maintaining `currentAbsencePercentage` on write — needs the write path the dossier widget establishes first

## 6. Known environment gaps hit while verifying
- [ ] `vendor/conduction/hydra-gates` is declared in `composer.json` and present in `composer.lock` but **not installed**, and `vendor/` is root-owned so `composer install` fails on permissions. phpcs and phpstan both reference its config and cannot run from their own `composer` scripts. phpcs was run against a sibling app's copy of the ruleset with hrmq's own excludes applied; **phpstan was not run at all**.
- [ ] `node tests/validate-widget-keys.js` FAILS — verified against a clean stash that it fails identically **before** this change. Pre-existing, and consistent with `node_modules` holding `@conduction/nextcloud-vue 1.0.0-beta.215` while the lockfile pins `2.2.0-vue3.2`.
