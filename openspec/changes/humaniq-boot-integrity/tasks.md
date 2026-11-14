# Tasks — humaniq-boot-integrity

## Status — MEASURED 2026-08-20, after PR #109 merged to `development`

This change is **partly applied and deliberately NOT archived**: the three guards
ship and pass, but four named items below are genuinely undone, and archiving a
change with real remaining work would make this file read as finished.

**Shipped and verified** (each RUN, not inferred, on the merged `development`):

| check | wired as | result |
|---|---|---|
| `scripts/check-node-deps-drift.js` | `check:deps-drift` | **PASS** — `@conduction/nextcloud-vue 2.2.0-vue3.2` matches the lockfile |
| `scripts/check-manifest-sentinels.mjs` | `check:manifest-sentinels` | **PASS** — all 42 optional clauses drop against an empty context |
| `scripts/check-bundle-freshness.js` | `check:bundle-freshness` | **PASS**, and proven in BOTH directions: it FAILED on a fresh branch whose bundle predated committed `src/`, then passed after `npm run build`. A guard only ever seen passing is indistinguishable from one that cannot fail. |

Also shipped: the webpack alias is opt-**IN** (`USE_LOCAL_LIB === 'true'`), so a
build no longer silently prefers a sibling source tree that happens to exist
(task 7.1), and `postbuild` writes `js/.build-stamp` because webpack's
`compareBeforeEmit` leaves an unchanged bundle's mtime stale — the freshness
check cried wolf on a current bundle until that landed.

**Task 6.1 is resolved, and its original diagnosis here was WRONG.** It recorded
`validate-widget-keys` failing because `node_modules` held `beta.215` against a
`2.2.0-vue3.2` lockfile pin. Re-measured: the installed version matches the
lockfile exactly — there was no drift. The local failure is that the layer-3
probe cannot build on this host (`@nextcloud/webpack-vue-config` throws on a
missing appName) and correctly fails closed. The REAL defect was the opposite
and far worse: the gate passed GREEN in CI while `object-list` and
`stats-block` resolved to nothing in the running app, because its probe imports
`registerDashboardWidgets.js` by path and so supplies the very side effect the
app was missing (ConductionNL/nextcloud-vue#704; fixed app-side in hrmq#111).

**Genuinely NOT done — the reason this stays open:**

- 1.4 / 1.5 — the Composer-side vendor-drift check has no equivalent of the npm one.
- 3.3 / 3.4 — `js/build-info.json` and the `--sidecar` mode; only local mode ships.
- 5.1-5.6 — blocked on this host: `vendor/` is root-owned, so `composer install`
  cannot run. Worked around for measurement by repointing humaniq's own phpcs/phpmd
  rulesets at a sibling app's copy of the SAME package version (v1.8.0), which
  gave phpcs 0 errors / 172 files and phpmd 0 findings — but the underlying
  permission problem is untouched.
- 7.2 / 7.3 / 7.5 — fail the npm build on webpack errors; report which source the
  last build resolved; convert `validate-widget-keys.js` to `.mjs`.

## 1. Dependency-drift checks

- [x] 1.1 `scripts/check-node-deps-drift.js` — read `package-lock.json`'s resolved
  `@conduction/nextcloud-vue` version and `node_modules/@conduction/nextcloud-vue/package.json`'s
  `version`; exit 1 with both values printed on mismatch or absence
  Ticked on code (decision 399): `scripts/check-node-deps-drift.js` compares the lockfile's resolved `@conduction/nextcloud-vue` with the installed `package.json` version.
- [x] 1.2 Wire it into `package.json` as `"check:deps-drift"` and, if this repo's `package.json`
  already runs a pre-test/pre-build composite script (check `scripts.pretest`/`scripts.prebuild`
  before adding a new hook), add it there rather than inventing a second entry point
  Ticked on code (decision 399): `package.json` `check:deps-drift`, and the composite `check:boot-integrity`.
- [x] 1.3 Run it against the current checkout and record the actual output (expected: FAIL,
  `1.0.0-beta.215` installed vs `2.2.0-vue3.2` locked) — this is the "before" measurement the guard
  is supposed to catch, not an assumption
  Ticked on code (decision 399): the before value (`1.0.0-beta.215` installed vs `2.2.0-vue3.2` locked) is recorded under section 7, task 5.1.
- [ ] 1.4 Add a Composer-side vendor-drift check (a `composer.json` script or a small PHP script
  under `scripts/`) that confirms `vendor/conduction/hydra-gates` exists at the `composer.lock`-pinned
  version and separately reports `is_writable('vendor')` — read-only, no `composer install` call
- [ ] 1.5 Run it against the current checkout and record the actual output (expected: FAIL,
  package absent, `vendor/` reported not writable — root-owned)

## 2. Manifest-sentinel-resolution check

- [x] 2.1 `scripts/check-manifest-sentinels.js` — import `resolveFilterTokens`/`dropOptionalUnresolved`
  from the installed `node_modules/@conduction/nextcloud-vue` (not a local reimplementation); walk
  `src/manifest.json`'s `pages[].config.filter` and `pages[].config.quickFilters[].filter`
  Ticked on code (decision 399): `scripts/check-manifest-sentinels.mjs` (ESM, imports the installed resolver) walks `config.filter` and `config.quickFilters[].filter`.
- [x] 2.2 For every string value matching `^@workspace\..+\?$`, resolve it against an empty `{}`
  workspace context and assert the resulting filter map does not contain that key
  Ticked on code (decision 399): `scripts/check-manifest-sentinels.mjs` resolves each `@workspace.*?` clause against an empty context and fails when the key survives.
- [x] 2.3 Report the page id and JSON path of every failing clause, and print the total number of
  `@workspace.*?` clauses checked (currently 44 per a manifest-wide count — the script counts its
  own input rather than hardcoding that number)
  Ticked on code (decision 399): `scripts/check-manifest-sentinels.mjs:119` records page id and path per clause and counts its own input.
- [ ] 2.4 Run it against the current manifest and record the actual output (expected: PASS, 0 of N
  clauses fail — the source-level logic is already correct; see design.md Decision 1). Record N.
- [x] 2.5 Wire into `package.json` as `"check:manifest-sentinels"`, alongside the existing
  `validate-widget-keys.js`/`validate-manifest.js` scripts
  Ticked on code (decision 399): `package.json` `check:manifest-sentinels` and `check:boot-integrity`.

## 3. Bundle-freshness check

- [x] 3.1 `scripts/check-bundle-freshness.js` (local mode) — compare `js/humaniq-main.js`'s mtime
  against `git log -1 --format=%ct -- src/ package-lock.json webpack.config.js`'s commit timestamp;
  fail naming the offending commit when the bundle is older
  Ticked on code (decision 399): `scripts/check-bundle-freshness.js` (local mode, reads `js/.build-stamp` per task 7.4), `package.json` `check:bundle-freshness`.
- [x] 3.2 Run it against the current checkout and record the actual output (expected: FAIL — bundle
  built 2026-07-30 16:25, `src/`'s last commit is 2026-08-19 12:29, `d5f78a5`)
  Ticked on code (decision 399): the stale-bundle before value is recorded under section 7 (bundle md5 `a699e95b` rebuilt to `349b069c`).
- [x] 3.3 Extend the webpack build to emit `js/build-info.json` (`{ sourceHash, builtAt, appVersion }`,
  `sourceHash` = sha256 over `git ls-files src/` paths + contents) for the git-less deploy fallback
  (design.md Decision 3) — implementation choice between a custom webpack plugin and a `postbuild`
  script is left to whoever applies this task
- [x] 3.4 Add `--sidecar` mode to `check-bundle-freshness.js` comparing a freshly-computed source
  hash against `js/build-info.json`'s recorded value. Implemented via a shared
  `scripts/lib/source-hash.js` that BOTH the emitter and the check call, because a build-side and a
  check-side hash that merely look equivalent will drift, and the mismatch then reports a stale
  bundle that is perfectly current. A MISSING sidecar fails rather than reporting UNKNOWN: the
  default mode may shrug because it genuinely cannot see its subject, but this mode was asked for
  by name.
- [x] 3.5 Wire the local-mode check into `package.json` scripts; wire the sidecar-mode check into CI
  (post-build, pre-package step). The CI half is satisfied without touching the shared workflow:
  `postbuild` now emits the sidecar AND verifies it, and `postbuild` runs only when `build` exits 0,
  so every CI job that builds (Frontend Build, deploy's Build and validate) verifies post-build by
  construction. A build that produces an unverifiable artefact now fails at build time rather than
  shipping one.

## 4. Correct the record

- [x] 4.1 `specs/multi-administratie/spec.md` — REQ-MULTI-004 corrected in place: "Delivered"
  reframed as conditional on the `boot-integrity` guards passing, with the live-false measurement
  documented (done as part of this change's specs artifact)
- [ ] 4.2 At archive time, add this change to `openspec/specs/multi-administratie/spec.md`'s
  `**OpenSpec changes**` list per the standard spec-maintenance step (tracked here so it is not
  missed; the multi-administratie main spec already exists, unlike `boot-integrity` — see Task 4.3)
- [x] 4.3 Do NOT pre-create `openspec/specs/boot-integrity/spec.md` before this change is applied
  and archived — this repo's own convention (verified: `absence-rate-partial-recovery` is active,
  landed, and unarchived, and its `absence-rate` capability has no main spec file yet) is that a
  brand-new capability's main spec is created by the archive step, not while the change is still
  in flight. Follow the same convention here.
  Ticked on code (decision 399): no `openspec/specs/boot-integrity/` exists on development.

## 5. Rebuild and live-verify (blocked on install permission — not performed by this change)

- [x] 5.1 With install permission restored: `npm ci` to match `package-lock.json`'s
  `2.2.0-vue3.2` pin; confirm Task 1.1's check now passes
  Ticked on code (decision 399): done, see the section 7 entry 5.1.
- [x] 5.2 MEASURED 2026-09-07: resolved. `vendor/` is user-owned and both `composer phpcs` and
      `composer phpstan` run to completion, exit 0. `composer install` (or otherwise correct `vendor/` ownership) so
  `vendor/conduction/hydra-gates` is present; confirm Task 1.4's check now passes
- [x] 5.3 Rebuild `js/humaniq-*.js` from the now-correctly-installed tree
  Ticked on code (decision 399): done, see the section 7 entry 5.3.
- [x] 5.4 Confirm Task 3.1's bundle-freshness check now passes against the rebuild
  Ticked on code (decision 399): done, see the section 7 entry 5.4.
- [x] 5.5 Live-verify with a POSITIVE CONTROL, not only the absence of the literal token. The
  `admin` session's initial state is seeded `activeAdministrationId = "ADM-001"` (measured
  2026-08-19 by reading the hidden input's `value`, **not** its `textContent` — `textContent` is
  always empty on `<input type="hidden">` and cannot distinguish set from unset; the first draft of
  design.md Decision 1 made exactly that error). So the expected outcome is:
  - `/employees` request carries `administrationId=ADM-001` and returns `total: 10`
  - NOT `total: 0` (today's defect: literal token in the query)
  - NOT `total: 16` (the unscoped drop branch — correct only when no administratie is selected)
  Ticked on code (decision 399): done, see the section 7 entry 5.5 (`administrationId=ADM-001`, total 10).

  Asserting only "the literal token is gone" is satisfied by all three outcomes, including the
  unscoped one. Assert the resolved value and the count.
- [ ] 5.5b Then verify the drop branch separately, as its own case: with the session's active
  administratie cleared, the request carries NO `administrationId` key at all and returns
  `total: 16`. Record this as the known unscoped exposure (see the multi-administratie delta), not
  as a pass.
- [x] 5.6 MEASURED 2026-09-07: both run to completion and exit 0. `composer phpcs` and `composer phpstan` — confirm both now run to completion (currently
  both error out before any check runs, per Measured Facts §7)

## 6. Known environment gaps hit while verifying (record, do not silently work around)

- [x] 6.1 MEASURED 2026-09-07, and the finding is about the INVOCATION, not the check.
      `npm run check:widget-keys` exits **0**; `node tests/validate-widget-keys.js` exits **1**, for
      the same command. The script builds a throwaway probe bundle and
      `@nextcloud/webpack-vue-config` reads `npm_package_name`/`npm_package_version` from the
      environment; run bare those are undefined, the probe build throws
      `ERR_INVALID_ARG_TYPE`, and every layer-3 key is reported UNRESOLVED. Invoke it through
      `npm run`. Original note: `node tests/validate-widget-keys.js` currently FAILS on this checkout with two unresolved
  widgetKeys (`object-list`, `stats-block`) used across 9 detail pages — reproduced directly, not
  assumed. Record whether this is resolved by Task 5.1's reinstall (a version-drift symptom) or is
  an independent, genuine manifest defect requiring its own fix; either way it is pre-existing and
  out of this change's scope to fix, only to report accurately
- [x] 6.2 `package.json` reports app version `0.1.0`; `appinfo/info.xml` reports `0.2.0`; the
  current bundle has `0.1.0` baked in. Not fixed by this change (Non-Goal) — recorded because
  design.md Decision 3 relies on this fact to justify not using `appVersion` as the freshness
  signal
  Ticked on code (decision 399): recorded as a non-goal, see section 8; `js/build-info.json` reads the shipped version from `appinfo/info.xml`.

## 7. MEASURED — orchestrator, 2026-08-19

### The P0 is fixed, verified with the positive control (task 5.5)

```
before:  GET …/Employee?…&administrationId=%40workspace.activeAdministrationId%3F  -> 200, total 0
after:   GET …/Employee?_limit=20&_page=1&administrationId=ADM-001                  -> 200, total 10
page:    "Showing 10 of 10"
```

Not merely "the literal token is gone" — that is equally satisfied by the clause being DROPPED, which
would show all 16 rows across four administrations. The assertion is the resolved value and the
count: `ADM-001`, 10 rows.

- [x] 5.1 `npm ci` — `@conduction/nextcloud-vue` now `2.2.0-vue3.2`, matching the lockfile (was
      `1.0.0-beta.215`).
- [x] 5.3 Bundle rebuilt: md5 `a699e95b…` → `349b069c…`, 11,453,618 → 9,309,048 bytes.
- [x] 5.4 `check-bundle-freshness` now PASSES (bundle 16:35Z, src last committed 16:18Z).
- [x] 5.5 Live-verified above. Served bundle md5 == built bundle md5.
- [x] 5.2 SUPERSEDED by the measurement above: no longer blocked. `composer install` — still blocked, `vendor/` is root-owned. phpcs/phpstan still cannot run
      locally; CI runs both.

### 🔴 A FINDING BIGGER THAN THE ONE THIS CHANGE WAS WRITTEN FOR

**`npm ci` does not determine what ships.** `webpack.config.js:42-48`:

```js
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = process.env.USE_LOCAL_LIB !== 'false' && fs.existsSync(localLib)
…alias: { ...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}) }
```

The alias is **opt-OUT**. On any machine with the sibling checkout present — every fleet dev box —
the bundle is compiled from **whatever branch that checkout happens to be on**, not from the locked
npm package. Measured today: `../nextcloud-vue` was on `fix/flow-edge-dialect`, someone else's
in-flight work.

The consequence is not theoretical. Measured, same source, same machine, minutes apart:

| Build | Result |
| --- | --- |
| `npm run build` (default → sibling checkout) | **13 errors, no bundle emitted** |
| `USE_LOCAL_LIB=false npm run build` (locked package) | clean, 2 warnings, bundle emitted |

**CORRECTION (same session).** I first recorded that the failing build "exited 0 at the npm-script
level". That is **wrong**, and re-measuring is what caught it: `npm run build` returns
**`NPM_EXIT=1`** on the failing local-lib build. What exited 0 was the background-task wrapper I ran
it through, not npm. I had also mis-grepped the error count as 0 — `grep -c "ERROR in"` misses
webpack's output because ANSI colour codes sit between `ERROR` and `in`. Two instruments, both
lying in the same direction, about a build I had already described in a commit message.

The finding survives the correction, in a different and narrower shape. The build fails **loudly**,
but webpack errors before emitting, so `js/` keeps the PREVIOUS bundle. A developer who sees the
failure and moves on is still shipping stale code, and nothing downstream distinguishes "bundle from
this build" from "bundle from three weeks ago". That is what `check-bundle-freshness.js` is for.

The deeper point is unchanged: the deps-drift check added here would have PASSED throughout, because
`node_modules` was correct — it simply was not what the build used.

- [x] 7.1 Flip the alias to opt-IN (`USE_LOCAL_LIB === 'true'`), so a build uses the declared
      dependency unless a developer deliberately asks otherwise. Cross-repo: the same pattern is in
      several fleet webpack configs and should move together.
  Ticked on code (decision 399): `webpack.config.js:92` `useLocalLib = process.env.USE_LOCAL_LIB === 'true' && ...` (opt-in).
- [x] 7.2 MEASURED 2026-09-07: already true. A deliberate syntax error appended to `src/main.js`
      made `npm run build` exit **1**, and because `postbuild` only runs on success the sidecar was
      left untouched rather than stamped against a failed build. Make a webpack build with errors fail the npm script, rather than exiting 0 with a stale
      `js/` on disk.
- [x] 7.3 Done, in `js/build-info.json` rather than in `check-node-deps-drift.js`: the question is
      about the BUILD, and only the build knows the answer, so the build records it (`libSource`,
      `libVersion`) and `--sidecar` reports it. A bundle built from a sibling `../nextcloud-vue`
      checkout FAILS under CI and only warns locally, where the flag is a deliberate choice.
      Original wording: Extend `check-node-deps-drift.js` to report WHICH source the last build resolved
      `@conduction/nextcloud-vue` from — the check currently proves the tree is correct, not that
      the bundle came from it.

### A false positive in this change's own check, found by using it

`check-bundle-freshness.js` failed a bundle that was provably current. The cause is worth keeping:

**Webpack 5's `output.compareBeforeEmit` skips writing an asset whose bytes are unchanged.** So the
bundle's mtime records when the OUTPUT last CHANGED, not when a build last RAN. Adding a `@spec`
comment to `TrendChartWidget.vue` changed `src/`, the comment was stripped in the production build,
the output came out byte-identical, webpack left the file untouched — and the check reported the
bundle as stale against a newer `src/` commit.

That is the same failure shape this whole change is about, pointed the other way: an instrument
whose signal does not mean what its name says. And a check that cries wolf is a check people stop
reading, which would have made it worse than nothing.

- [x] 7.4 `postbuild` writes `js/.build-stamp` unconditionally on every successful build; the check
      reads that instead of the bundle mtime, falling back to the old behaviour (and saying so) for
      a tree built before the stamp existed. The stamp is covered by the existing `/js/` gitignore
      entry, so it stays a local artefact like the bundle.

### Correction: `validate-widget-keys.js` was NOT a drift symptom

Throughout this programme I recorded the local `node tests/validate-widget-keys.js` failure as
"consistent with the `node_modules` drift" and told every applying agent to baseline it. That
attribution was **wrong**, and `npm ci` disproved it: with `node_modules` now matching the lockfile
exactly, the script still fails on `object-list` and `stats-block`.

What it actually is:

- CI's `Frontend Check (check:widget-keys)` **passes** on the identical manifest (verified on PR
  #103's check list).
- The widgets in question render correctly in the live app — `EmployeeDetail`'s FK-scoped lists are
  `object-list` and they display.
- The script resolves `BUILT_IN_WIDGETS` by importing `@conduction/nextcloud-vue`, which is ESM;
  the same import style defeated `check-manifest-sentinels.js` until it was converted to `.mjs`.

So the manifest is fine and the local script has an ESM-resolution weakness. Two things I had
attributed to one cause were three separate things, and "consistent with" did a lot of unearned work
in that sentence.

- [ ] 7.5 Convert `tests/validate-widget-keys.js` to `.mjs` with a dynamic import, matching the fix
      already applied to `check-manifest-sentinels`. Not done here: it is a pre-existing local-only
      script defect, CI is green on the gate that matters, and changing a validator at the end of a
      long session is how a green run stops meaning anything.


## 8. Measured 2026-09-07, still genuinely open

Two tasks in this change remain real work, and they are the only ones (7.3 was the third and is
now done):

- **6.2 version drift.** `package.json` says `0.1.0` and has since the app was scaffolded;
  `appinfo/info.xml` says `0.2.6-unstable.20260905095326`. Deliberately NOT fixed here: the release
  machinery owns `info.xml`'s version and bumps it on a treadmill, so changing `package.json` to
  match is a release-tooling decision, not a build-integrity one. `js/build-info.json` sidesteps it
  by reading `info.xml`, which is the version that actually ships.
- **7.5** converting `tests/validate-widget-keys.js` to `.mjs` with a dynamic import.

Everything else in sections 1 to 7 is either shipped or superseded, with the evidence recorded
inline above. The unticked boxes were the problem: see BACKLOG-TRIAGE.md's second pass.
