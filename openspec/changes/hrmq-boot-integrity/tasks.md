# Tasks — hrmq-boot-integrity

## 1. Dependency-drift checks

- [ ] 1.1 `scripts/check-node-deps-drift.js` — read `package-lock.json`'s resolved
  `@conduction/nextcloud-vue` version and `node_modules/@conduction/nextcloud-vue/package.json`'s
  `version`; exit 1 with both values printed on mismatch or absence
- [ ] 1.2 Wire it into `package.json` as `"check:deps-drift"` and, if this repo's `package.json`
  already runs a pre-test/pre-build composite script (check `scripts.pretest`/`scripts.prebuild`
  before adding a new hook), add it there rather than inventing a second entry point
- [ ] 1.3 Run it against the current checkout and record the actual output (expected: FAIL,
  `1.0.0-beta.215` installed vs `2.2.0-vue3.2` locked) — this is the "before" measurement the guard
  is supposed to catch, not an assumption
- [ ] 1.4 Add a Composer-side vendor-drift check (a `composer.json` script or a small PHP script
  under `scripts/`) that confirms `vendor/conduction/hydra-gates` exists at the `composer.lock`-pinned
  version and separately reports `is_writable('vendor')` — read-only, no `composer install` call
- [ ] 1.5 Run it against the current checkout and record the actual output (expected: FAIL,
  package absent, `vendor/` reported not writable — root-owned)

## 2. Manifest-sentinel-resolution check

- [ ] 2.1 `scripts/check-manifest-sentinels.js` — import `resolveFilterTokens`/`dropOptionalUnresolved`
  from the installed `node_modules/@conduction/nextcloud-vue` (not a local reimplementation); walk
  `src/manifest.json`'s `pages[].config.filter` and `pages[].config.quickFilters[].filter`
- [ ] 2.2 For every string value matching `^@workspace\..+\?$`, resolve it against an empty `{}`
  workspace context and assert the resulting filter map does not contain that key
- [ ] 2.3 Report the page id and JSON path of every failing clause, and print the total number of
  `@workspace.*?` clauses checked (currently 44 per a manifest-wide count — the script counts its
  own input rather than hardcoding that number)
- [ ] 2.4 Run it against the current manifest and record the actual output (expected: PASS, 0 of N
  clauses fail — the source-level logic is already correct; see design.md Decision 1). Record N.
- [ ] 2.5 Wire into `package.json` as `"check:manifest-sentinels"`, alongside the existing
  `validate-widget-keys.js`/`validate-manifest.js` scripts

## 3. Bundle-freshness check

- [ ] 3.1 `scripts/check-bundle-freshness.js` (local mode) — compare `js/hrmq-main.js`'s mtime
  against `git log -1 --format=%ct -- src/ package-lock.json webpack.config.js`'s commit timestamp;
  fail naming the offending commit when the bundle is older
- [ ] 3.2 Run it against the current checkout and record the actual output (expected: FAIL — bundle
  built 2026-07-30 16:25, `src/`'s last commit is 2026-08-19 12:29, `d5f78a5`)
- [ ] 3.3 Extend the webpack build to emit `js/build-info.json` (`{ sourceHash, builtAt, appVersion }`,
  `sourceHash` = sha256 over `git ls-files src/` paths + contents) for the git-less deploy fallback
  (design.md Decision 3) — implementation choice between a custom webpack plugin and a `postbuild`
  script is left to whoever applies this task
- [ ] 3.4 Add `--sidecar` mode to `check-bundle-freshness.js` comparing a freshly-computed source
  hash against `js/build-info.json`'s recorded value
- [ ] 3.5 Wire the local-mode check into `package.json` scripts; wire the sidecar-mode check into CI
  (post-build, pre-package step)

## 4. Correct the record

- [x] 4.1 `specs/multi-administratie/spec.md` — REQ-MULTI-004 corrected in place: "Delivered"
  reframed as conditional on the `boot-integrity` guards passing, with the live-false measurement
  documented (done as part of this change's specs artifact)
- [ ] 4.2 At archive time, add this change to `openspec/specs/multi-administratie/spec.md`'s
  `**OpenSpec changes**` list per the standard spec-maintenance step (tracked here so it is not
  missed; the multi-administratie main spec already exists, unlike `boot-integrity` — see Task 4.3)
- [ ] 4.3 Do NOT pre-create `openspec/specs/boot-integrity/spec.md` before this change is applied
  and archived — this repo's own convention (verified: `absence-rate-partial-recovery` is active,
  landed, and unarchived, and its `absence-rate` capability has no main spec file yet) is that a
  brand-new capability's main spec is created by the archive step, not while the change is still
  in flight. Follow the same convention here.

## 5. Rebuild and live-verify (blocked on install permission — not performed by this change)

- [ ] 5.1 With install permission restored: `npm ci` to match `package-lock.json`'s
  `2.2.0-vue3.2` pin; confirm Task 1.1's check now passes
- [ ] 5.2 `composer install` (or otherwise correct `vendor/` ownership) so
  `vendor/conduction/hydra-gates` is present; confirm Task 1.4's check now passes
- [ ] 5.3 Rebuild `js/hrmq-*.js` from the now-correctly-installed tree
- [ ] 5.4 Confirm Task 3.1's bundle-freshness check now passes against the rebuild
- [ ] 5.5 Live-verify with a POSITIVE CONTROL, not only the absence of the literal token. The
  `admin` session's initial state is seeded `activeAdministrationId = "ADM-001"` (measured
  2026-08-19 by reading the hidden input's `value`, **not** its `textContent` — `textContent` is
  always empty on `<input type="hidden">` and cannot distinguish set from unset; the first draft of
  design.md Decision 1 made exactly that error). So the expected outcome is:
  - `/employees` request carries `administrationId=ADM-001` and returns `total: 10`
  - NOT `total: 0` (today's defect: literal token in the query)
  - NOT `total: 16` (the unscoped drop branch — correct only when no administratie is selected)

  Asserting only "the literal token is gone" is satisfied by all three outcomes, including the
  unscoped one. Assert the resolved value and the count.
- [ ] 5.5b Then verify the drop branch separately, as its own case: with the session's active
  administratie cleared, the request carries NO `administrationId` key at all and returns
  `total: 16`. Record this as the known unscoped exposure (see the multi-administratie delta), not
  as a pass.
- [ ] 5.6 `composer phpcs` and `composer phpstan` — confirm both now run to completion (currently
  both error out before any check runs, per Measured Facts §7)

## 6. Known environment gaps hit while verifying (record, do not silently work around)

- [ ] 6.1 `node tests/validate-widget-keys.js` currently FAILS on this checkout with two unresolved
  widgetKeys (`object-list`, `stats-block`) used across 9 detail pages — reproduced directly, not
  assumed. Record whether this is resolved by Task 5.1's reinstall (a version-drift symptom) or is
  an independent, genuine manifest defect requiring its own fix; either way it is pre-existing and
  out of this change's scope to fix, only to report accurately
- [ ] 6.2 `package.json` reports app version `0.1.0`; `appinfo/info.xml` reports `0.2.0`; the
  current bundle has `0.1.0` baked in. Not fixed by this change (Non-Goal) — recorded because
  design.md Decision 3 relies on this fact to justify not using `appVersion` as the freshness
  signal
