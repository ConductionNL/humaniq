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

## 5. Not in this change (the first two have since been DELIVERED elsewhere)
- [x] An analytics endpoint exposing the rate as a time series — **shipped** by
      `hrmq-dashboard-steering-indicators` as the guarded
      `GET /apps/hrmq/api/analytics/trends?metric=absence-rate`
      (`AnalyticsService::absenceRateSeries()`), which calls this change's
      `AbsenceRateService::absenceRate()` once per monthly bucket and carries its
      `percentage` through unmodified — `null` stays `null`.
- [x] A dashboard chart widget — **shipped** by the same change as the Dashboard's
      "Absence rate" `chart` widget, bound to that endpoint through
      `TrendChartWidget.vue` specifically so a `null` bucket is NOT coerced to `0`
      by CnChartWidget's own `Number(value) || 0` mapping.
- [ ] Back-filling `absenceProgression` on existing cases — optional per case; the empty default keeps every existing figure correct
- [ ] Maintaining `currentAbsencePercentage` on write — needs the write path the dossier widget establishes first

## 6. Known environment gaps hit while verifying
- [x] `vendor/conduction/hydra-gates` is declared in `composer.json` and present in `composer.lock` but **not installed**, and `vendor/` is root-owned so `composer install` fails on permissions. Still true of this host. Worked around 2026-08-20 rather than left unmeasured: hrmq's OWN `phpcs.xml`/`phpmd.xml` were run with only their base `<rule ref>` repointed at a sibling app's installed copy of the SAME package version (v1.8.0, matching this repo's lockfile pin), so hrmq's own exclusions still applied. Results: phpcs **0 errors** / 377 warnings across **172 files**; phpmd **0 findings** on both rulesets. Note the first phpmd attempt exited 2 with `Cannot find specified rule-set` — a TOOL ERROR that must not be read as either a pass or a fail, which is exactly why the repointed run was needed. CI runs all of these natively and they are green there.
- [x] `node tests/validate-widget-keys.js` FAILS locally — **and the stated cause here was wrong, twice over.** Re-measured 2026-08-20: `node_modules` holds `2.2.0-vue3.2`, exactly what the lockfile pins, so there is no version drift; and the failure is not a manifest defect either. The gate's layer-3 probe cannot build on this host (`@nextcloud/webpack-vue-config` throws `The "path" argument must be of type string` because the probe entry supplies no appName), and it correctly FAILS CLOSED — an inconclusive probe is never a pass. CI's own `Frontend Check (check:widget-keys)` passes. Separately, and far more important: the gate was green in CI while two of the keys it checks resolved to NOTHING in the running app, because its probe imports `registerDashboardWidgets.js` by path and so supplies the very side effect the real app was missing (ConductionNL/nextcloud-vue#704, fixed app-side in hrmq#111). An instrument that creates the condition it tests for cannot report on it.
