## Context

See proposal.md "Why" for the incident. This section is the evidence trail behind each design
decision — all of it gathered against this checkout and the live app at `localhost:8080` on
2026-08-19, not restated from the shared brief.

**Files read to trace the sentinel path:**
`src/App.vue` (SPA root, `cnWorkspaceContext` provide) →
`node_modules/@conduction/nextcloud-vue/src/components/CnIndexPage/useSelfFetchList.js` (injects
`cnWorkspaceContext`, builds `fixedFilters`) →
`node_modules/@conduction/nextcloud-vue/src/utils/resolveFilterTokens.js`
(`resolveFilterValue`/`dropOptionalUnresolved`) → `useObjectStore.js` (`buildQueryString`, no
further token handling — whatever the filter map contains at that point is what gets sent).

## Goals / Non-Goals

**Goals:**
- Pin down, with live evidence, which of the three candidate layers (hrmq's provide / the
  library's resolver / the deployed bundle) the sentinel defect lives in.
- Specify mechanical, read-only checks for dependency drift, bundle staleness, and unresolved
  manifest sentinels — each runnable without a browser, a server, or a mutation to `node_modules`/
  `vendor/`.
- Make the CI-safety of the bundle-freshness check explicit, since the mechanism that found this
  bug (mtime comparison) does not survive a fresh clone.

**Non-Goals:**
- Redesigning `resolveFilterTokens`/`resolveFilterMap` or any nextcloud-vue internals — they are
  already correct (Decision 1). This change adds checks around them, not changes to them.
- Choosing hrmq's next release-versioning scheme. Decision 3 notes `package.json` vs
  `appinfo/info.xml` disagreement as a contributing fact, not something this change fixes.
- Implementing the rebuild itself (Non-Goal in proposal.md — install permission blocks it in this
  checkout).

## Decisions

### Decision 1: The sentinel defect is a stale-bundle artefact, not a source defect — verdict (c)

**Evidence, gathered in order:**

1. **hrmq's provide (`src/App.vue:74-80`) is correct.** `setup()` reads
   `loadState('hrmq', 'activeAdministrationId', '')`; when empty it provides
   `cnWorkspaceContext = ref({})` — an object with NO `activeAdministrationId` key, not an object
   with an empty-string value. This matters: `resolveFilterValue`'s workspace branch checks
   `ctx.workspace[key] !== undefined && !== null && !== ''` before using it, so either shape (key
   absent, or key present but empty) correctly fails that check and leaves the token unresolved for
   `dropOptionalUnresolved` to then drop.

2. **The library's current resolver (both the checked-out `../nextcloud-vue` repo and the
   currently-installed `node_modules/@conduction/nextcloud-vue`, version `1.0.0-beta.215`) is
   correct.** `useSelfFetchList.js`'s `fixedFilters` calls
   `resolveFilterMap(props.filter, params, ctx)` (checked-out source: via the shared
   `utils/routeFilters.js`; installed beta.215: an inline copy of the identical function — both
   read, byte-compared for logic, not literal text), which is
   `dropOptionalUnresolved(resolveFilterTokens(out, ctx))`. `dropOptionalUnresolved` removes any key
   whose value still starts with `@workspace.` and ends with `?` after resolution. Read directly:
   `node_modules/@conduction/nextcloud-vue/src/components/CnIndexPage/useSelfFetchList.js:32-41` and
   `.../src/utils/resolveFilterTokens.js:164-204`.

3. **Live reproduction, today, against `localhost:8080`:** navigated to `/apps/hrmq/employees` as
   `admin`. Network capture shows
   `GET /apps/openregister/api/objects/hrmq/Employee?_limit=20&_page=1&administrationId=%40workspace.activeAdministrationId%3F`
   → `200`, response body `{"total":0,...,"query":{"administrationId":"@workspace.activeAdministrationId?",...}}`.
   An unfiltered call to the same endpoint (`_limit=5&_page=1`, no `administrationId`) returns
   `total: 16`.

   **CORRECTED 2026-08-19 (orchestrator review) — the original evidence for this item read the
   wrong DOM property, and the correction strengthens the conclusion while reversing what it
   predicts about the post-fix state.** The first draft asserted "no administratie ever switched",
   evidenced by
   `document.getElementById('initial-state-hrmq-activeAdministrationId').textContent === ''`.
   Nextcloud renders initial state as `<input type="hidden" … value="<base64>">`, so `textContent`
   is **always** the empty string on that element regardless of what was stamped — the read cannot
   distinguish "unset" from "set". Re-measured against `value`:

   ```
   <input type="hidden" id="initial-state-hrmq-activeAdministrationId"   value="IkFETS0wMDEi">      → "ADM-001"
   <input type="hidden" id="initial-state-hrmq-activeAdministrationMode" value="InN0YW5kYXJkIg=="> → "standard"
   ```

   `PageController::index()` seeds both keys correctly, and `AdministrationController::context`
   independently agrees (`activeAdministrationId: "ADM-001"`, 5 administraties). There is no
   initial-state defect.

   The contradiction in the paragraph below therefore holds in a **different and cleaner** form:
   with a POPULATED context, the current source predicts the request carries
   `administrationId=ADM-001` (resolved), not that the clause is dropped. The shipped bundle sends
   the literal token instead. Same verdict (c), stronger proof — the observed behaviour matches
   neither branch of the current resolver, resolved or dropped.

4. **`js/` is git-ignored** (`.gitignore:13`) — the bundle is a build artefact, never a tracked
   file. Its mtime is therefore never touched by a `git checkout`/rebase/pull, only by an actual
   build. That makes the mtime comparison below a trustworthy "when was this last built" signal
   rather than checkout noise (it is only checkout-safe for THIS file, though — see Decision 3 for
   why the same trust does not extend to `src/`'s own working-tree mtimes).

**The contradiction that proves it's the bundle, not the source:** given (1), (2) and the corrected
(3), the current source has exactly two possible observable outcomes and the running app produces
neither. With the context POPULATED (`ADM-001`, as measured) the request must carry
`administrationId=ADM-001` and return `total: 10`. With the context EMPTY the optional-token-drop
path must make the key **absent from the request entirely**, returning an unfiltered `total: 16`.
The running app sends the literal, unresolved token and returns `total: 0` — which is not the
resolved branch and not the dropped branch. The code on disk, in both repos, cannot produce what
the running app actually does. The only place that gap can live is between "code
on disk" and "code actually served" — i.e. the built artefact. Corroborating, using the git-native
signal Decision 3 settles on rather than a raw working-tree mtime (which the brief's original "four
hours" figure was, and which this checkout's own concurrent activity has since moved — re-measured
here and corrected per the brief's own instruction to fix stale figures): `js/hrmq-main.js`'s mtime
(2026-07-30 16:25:38, trustworthy per point 4 above since the file is git-ignored) is **19 days**
older than `src/manifest.json`'s last commit (`git log -1 --format=%ci -- src/manifest.json` →
2026-08-19 12:29:19, commit `d5f78a5`, the `absence-rate-partial-recovery` change). And
node_modules currently holds `1.0.0-beta.215` while `package-lock.json` pins `2.2.0-vue3.2` — i.e.
the tree that would be used for a rebuild today is *itself* not what the lockfile specifies, so
even "the bundle is just old" undersells it: nobody can currently produce a *verified-correct*
rebuild without first resolving Decision 2.

**Verdict: (c) — an artefact of the stale bundle, compounded by (the currently-uninstallable
correct version of) the dependency drift in §7.** Not (a): hrmq's provide is correct as written.
Not (b): the library's resolver is correct as written, in both the checked-out repo and the
installed copy. hrmq's fix scope is therefore: get the dependency tree installed at the pinned
version (outside this change's own capability, see proposal Non-Goals), rebuild, and use the new
guards (Decisions 2 and 4) to verify the rebuilt bundle actually behaves as source predicts —
closing exactly the gap that let this incident happen invisibly.

**Alternative considered and rejected:** patch around the defect locally in hrmq (e.g. resolve
`@workspace.activeAdministrationId?` manually in `App.vue` before passing `props.filter` down, or
strip literal `@workspace.*` tokens client-side as a defensive filter-sanitisation step in a new
hrmq utility). Rejected: the mechanism that resolves this correctly already exists and already ships
in the dependency hrmq already uses; a local workaround would duplicate it, diverge from it over
time, and mask the actual defect (a stale build) instead of fixing the thing actually broken. This
is the exact anti-pattern `openspec/specs/multi-administratie/spec.md` REQ-MULTI-005 already
documents hrmq falling into once before (inventing `@administration` instead of using
`@workspace.<key>` that already existed).

### Decision 2: Dependency-drift checks are read-only version-string comparisons, not install wrappers

Two small Node scripts (no new runtime dependency — both read JSON files already on disk):

- `scripts/check-node-deps-drift.js`: reads `package-lock.json`, resolves the entry for
  `@conduction/nextcloud-vue` (`packages["node_modules/@conduction/nextcloud-vue"].version` in npm
  v7+ lockfile format — verified this checkout's `package-lock.json` uses that format), reads
  `node_modules/@conduction/nextcloud-vue/package.json`'s `version`, and exits 1 with both values
  printed on any mismatch or on the package being absent entirely.
- `scripts/check-vendor-drift.php` (or a `composer.json` script running a short inline check,
  matching the fleet's existing pattern of Composer scripts doing small PHP-native checks rather
  than shelling out): reads `composer.lock`'s `packages`/`packages-dev` entries for
  `conduction/hydra-gates`, checks `vendor/conduction/hydra-gates/composer.json` exists and its
  `version`/`extra` matches, and separately calls `is_writable('vendor')` (a read-only probe, not a
  write) to distinguish "not installed" from "installed but the directory can't be written to by a
  future `composer install`" — the exact ownership problem this checkout has today
  (`vendor/` is `root:root`).

**Alternative considered and rejected:** a single combined `check-deps-drift` script spanning both
ecosystems. Rejected: Node and Composer already have separate script registries
(`package.json`/`composer.json`) and separate CI steps in this fleet; a cross-language script adds
an extra runtime dependency (either PHP shelling to Node or vice versa) for no benefit over two
small scripts each wired into their own ecosystem's existing `scripts` block.

### Decision 3: Bundle-freshness uses mtime locally and a content hash in CI — not `appVersion`

**Why not `appVersion` (already embedded via `webpack.DefinePlugin`, `webpack.config.js:88`,
sourced from `npm_package_version`):** verified today that three independent version signals
disagree — `package.json` reports `0.1.0`, `appinfo/info.xml` reports `0.2.0`, and the string baked
into the current `js/hrmq-main.js` is `0.1.0` (grepped directly out of the built file). None of the
three is bumped when only `src/manifest.json` changes — exactly the edit that caused this incident.
An `appVersion`-based freshness signal would not have caught it and will not catch the next one of
its kind. (This same weakness sits under `hrmq-manifest-boot-and-http-cost`'s proposed ETag scheme,
which also keys off `appVersion` — noted for that change's own next editor, not fixed here; see
proposal.md "Relationship to other changes".)

**Primary mechanism — compare the bundle's mtime against `src/`'s last COMMIT time, not its
working-tree mtime.** `js/` is git-ignored (Decision 1, point 4), so its mtime is untouched by
checkout/rebase and reflects the true last-build instant. `src/`'s *working-tree* mtimes are not
similarly trustworthy — a fresh CI clone stamps every tracked file with the checkout instant,
which is exactly why a raw `js/` -vs- `src/`-mtime comparison (the ad-hoc check that originally
found this bug, safe only in a long-lived local working tree) is not CI-safe. `git log -1
--format=%ct -- src/ package-lock.json webpack.config.js`, by contrast, reads the commit
timestamp out of git's object database — unaffected by when or how many times the tree has been
checked out, in CI or locally. `scripts/check-bundle-freshness.js` compares
`fs.statSync('js/hrmq-main.js').mtimeMs` against that commit timestamp × 1000 and fails, naming
the offending commit, when the bundle predates it. This needs no webpack build-process change and
is exactly the comparison that diagnosed Decision 1's incident, made checkout-invariant.

**Fallback for a git-less deploy context** (a stripped production image with no `.git` directory,
where the check would have nothing to compare against): a source content-hash sidecar. Extend the
build (a small custom webpack plugin, or a `postbuild` npm script — an implementation choice for
tasks.md) to emit `js/build-info.json`: `{ sourceHash, builtAt, appVersion }`, where `sourceHash` is
a `sha256` over the sorted list of `git ls-files src/` paths + contents, computed at build time
(while `.git` is still available) and carried in the deployed artefact itself so the running
container can self-report what it was built from without needing `.git` at runtime.
`scripts/check-bundle-freshness.js --sidecar` compares a freshly-computed hash (in an environment
that does have `.git`, e.g. CI before packaging) against `js/build-info.json`'s recorded value.

**Alternative considered and rejected:** git-commit-SHA-based staleness comparing `git rev-parse
HEAD` at build time to current `HEAD` (rather than scoping to `src/`'s own last-touching commit).
Rejected: this would false-positive on every commit that touches anything OTHER than `src/` (e.g. a
`README.md` edit), which is exactly the kind of noisy gate this fleet's memory already flags as a
lesson learned elsewhere (a check that fails on unrelated changes gets silenced, not fixed). Scoping
`git log` to the actual build-input paths avoids that.

### Decision 4: The manifest-sentinel-resolution check imports the installed library's resolver rather than reimplementing it

`scripts/check-manifest-sentinels.js` requires `resolveFilterTokens`/`dropOptionalUnresolved`
directly from `node_modules/@conduction/nextcloud-vue`, walks `src/manifest.json`'s
`pages[].config.filter` and `pages[].config.quickFilters[].filter`, and for every string value
matching `^@workspace\..+\?$` resolves it against `{}` and asserts the key is absent from the
result. This deliberately reuses the exact function the running app calls (once Decision 2's drift
check confirms the installed copy is the pinned one) rather than hand-rolling a parallel regex — the
house rule ("prefer the mechanism that already ships") applies as much to a test script as to
application code: a hand-rolled check that drifts from the library's actual grammar
(`SENTINEL_TOKEN_PATTERNS` in `sentinelTokens.js`) is worse than no check, because it looks like
coverage.

**Honest limitation, stated in the spec:** this check passes *today*, because the source-level logic
is already correct (Decision 1). It guards against a *future* regression in manifest authoring or a
library downgrade — it would not, by itself, have caught the actual 2026-08-19 incident, which was
purely a stale-build problem. Decision 3's bundle-freshness check is the one that would have.

## Risks / Trade-offs

- **[Risk] The content-hash sidecar (Decision 3, mechanism 2) requires a webpack build-process
  change, which is implementation work this change specifies but does not perform (no install
  permission).** → Mitigation: tasks.md scopes this as a task for whoever applies the change with
  install access; the spec requirement is behavioural (a stale bundle SHALL be detectable in CI),
  not the literal plugin code, so the apply-time implementer has freedom in exactly how the hash is
  embedded (custom webpack plugin vs. postbuild script) as long as the scenario in
  `specs/boot-integrity/spec.md` passes.
- **[Risk] The vendor-drift check's `is_writable('vendor')` probe reports "writable" in a container
  filesystem with different UID mapping than the host, giving a false sense of safety.** →
  Mitigation: the check reports the raw owner UID/GID alongside the writability boolean, so a human
  reading the failure has the fact needed to reason about their own environment rather than trusting
  a single boolean.
- **[Risk] Two new dependency-ecosystem checks (Node + Composer) are one more thing to keep wired
  into CI as scripts get renamed/moved over time.** → Mitigation: both follow the exact existing
  pattern of `tests/validate-widget-keys.js`/`tests/validate-manifest.js` (already wired into
  `package.json` scripts and presumably CI), so no new wiring pattern is introduced — the pattern
  already survives the fleet's existing script churn.

## Migration Plan

1. Land the three check scripts and their `package.json`/`composer.json` script entries (no
   behavioural change to the app — pure addition).
2. Whoever next has install permission in a non-locked-down checkout: run the checks (expect the
   node-deps-drift and vendor-drift checks to FAIL, reproducing today's exact measurements),
   correct the installs, re-run (expect PASS), rebuild `js/hrmq-*.js`, run the manifest-sentinel and
   bundle-freshness checks against the rebuild (expect PASS), then live-verify `/employees` and
   `/payslips` return non-zero rows for a seeded, no-administratie-selected session (closing
   Decision 1's incident).
3. Wire all three checks into CI (mirroring how `tests/validate-widget-keys.js` is already invoked)
   so a future regression on any of the three axes fails a PR rather than silently shipping.
4. No rollback concern — these are additive, read-only checks; disabling them (reverting this
   change) returns to today's status quo, not to a worse state.

## Open Questions

- Whether the content-hash sidecar (`js/build-info.json`) should also feed
  `hrmq-manifest-boot-and-http-cost`'s proposed ETag (replacing its `appVersion`-derived ETag with
  the same `sourceHash`, which actually changes on every manifest edit). Left open because it
  touches that change's own artifacts, which are out of this change's write scope — flagged for
  that change's next editor in proposal.md, not decided here.
