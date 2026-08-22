## Why

humaniq's primary index pages render "No items found" while the data exists, and two of six PHP
quality gates cannot run at all — so nothing else in this refactoring programme can be verified
against a trustworthy signal until both are fixed. This is phase 0: restore the measurement
surface before changing anything it would be asked to measure.

Live-reproduced today (`localhost:8080`, session `admin`, no administratie switched):
`GET /apps/openregister/api/objects/hrmq/Employee?_limit=20&_page=1&administrationId=%40workspace.activeAdministrationId%3F`
→ `200 OK`, `total: 0`. The same register unfiltered → `total: 16`. Every administratie-scoped
index page carries the same shape of clause (44 occurrences per a manifest-wide count), so this is
not one broken page — it is the app's primary navigation surface returning empty pages that are
indistinguishable, over HTTP, from "no data seeded yet".

## What Changes

- **Diagnose and fix the unresolved-sentinel defect at its actual layer.** Traced through
  `src/App.vue`'s `cnWorkspaceContext` provide, `@conduction/nextcloud-vue`'s
  `resolveFilterTokens`/`dropOptionalUnresolved`/`resolveFilterMap` (`src/utils/resolveFilterTokens.js`,
  `src/utils/routeFilters.js`), and `CnIndexPage`'s `useSelfFetchList.js` consumer. **Verdict: the
  defect is neither in humaniq's provide nor in the library's current logic — it is an artefact of a
  stale, unrebuilt production bundle.** humaniq's half of the fix is therefore build hygiene, not new
  application code: get the dependency tree installed at the version the lockfile actually pins,
  rebuild `js/humaniq-main.js` from that tree, and verify the rebuilt bundle behaves as the current
  source predicts. See design.md "Decision 1" for the full evidence trail.
- **Define and detect the dependency drift.** "Installed correctly" means: the version string
  physically present under `node_modules/@conduction/nextcloud-vue/package.json` byte-matches
  `package-lock.json`'s resolved version, and `vendor/conduction/hydra-gates` exists on disk at the
  version `composer.lock` pins, writable by the account that will next run `composer install`. A
  checked-in, side-effect-free script asserts both, so the drift is a loud pre-build failure
  instead of a silent one discovered by a developer three PHP gates deep.
- **A regression guard against silent-empty, at the layer that actually produces the defect.** Not
  a Playwright spec — humaniq has no e2e suite yet (tracked by the active change
  `humaniq-test-coverage-baseline`), and the defect is a pure string-substitution bug with zero DOM
  involvement. Two complementary Node-level checks, both running against the same artefacts a
  browser would hit, neither requiring one:
  1. A manifest-level assertion that every `@workspace.<key>?` filter clause in `src/manifest.json`
     resolves to an ABSENT key (never a literal token string) when the workspace context is empty,
     using the resolver actually installed in `node_modules`.
  2. A bundle-freshness assertion that the shipped `js/humaniq-*.js` artefacts were built from the
     `src/` tree and dependency versions currently on disk — this is the one that would have caught
     today's actual incident, since (1) alone passes today (the source-level logic is already
     correct).
- **Bundle-vs-source staleness detection**, generalising the ad-hoc timestamp comparison used to
  find this bug into a checked mechanism developers and CI can both run. See design.md "Decision 3"
  for why a raw mtime comparison (what caught this bug locally) is not CI-safe, and what replaces
  it.
- **Correct the record**: `openspec/specs/multi-administratie/spec.md`'s REQ-MULTI-004 currently
  reads "**Delivered**" for automatic per-page scoping. That claim is live-false as of this
  measurement. The delta spec below corrects it and ties its "Delivered" status to the new guard
  passing, not to the code having once been written correctly.

## Non-Goals

- **Redesigning the tenant-scoping mechanism.** `administrationId` as a denormalised plain string,
  scoping-as-convenience-not-security-boundary, and the `@workspace.<key>?` grammar itself are all
  out of scope — this change fixes the mechanical defect (the token not resolving/dropping in the
  shipped artefact), not the design those Measured Facts §4/§5 raise. That is later programme work.
- **Running any install.** This change specifies what "installed correctly" means and how to
  detect drift from it; it does not run `npm ci`, `npm install`, or `composer install` — the vendor
  and node_modules trees in this checkout are explicitly not this change's to mutate. The rebuild
  step (bundle from correctly-installed dependencies) is a task for whoever applies this change in
  an environment where the install constraint does not apply.
- **`PageController::manifest()` caching and `src/main.js` prop-reactivity.** That is the active
  change `humaniq-manifest-boot-and-http-cost`'s territory. See "Relationship to other changes" below
  — this change is orthogonal to it, not a supersession or an absorption.
- **Fixing `composer.json`/`package.json`'s own version drift** (`package.json` reports `0.1.0`,
  `appinfo/info.xml` reports `0.2.0`, and the bundle has `0.1.0` baked in via `DefinePlugin`).
  Flagged as a contributing fact for design.md's staleness-detection decision (an `appVersion`-only
  freshness signal is unreliable while these three disagree), not fixed here.
- **The 18 role-lens duplicate index pages, the 3 unauthorised menu groups, or any navigation
  change** (Measured Facts §2/§3). Separate wave-1 changes own the menu/IA surface.

## Relationship to other changes

- **`humaniq-manifest-boot-and-http-cost` (active): orthogonal, not superseded or absorbed.** Its
  scope (manifest-endpoint HTTP caching, non-reactive manifest props) shares no file and no
  requirement with this change. It is, however, **factually stale**: its proposal and one of its
  spec scenarios assert "six pages" / "~12 KB" / "all six manifest pages MUST continue to render",
  where `src/manifest.json` is now 113 pages and 252,561 bytes (verified: `python3 -c` count against
  the current file). This change does not edit that change's artifacts — out of this change's
  write scope — but records the observation here so its next editor re-verifies before implementing
  against stale numbers. Its own use of `appVersion` as an ETag-freshness signal shares this
  change's Decision 3 finding: `appVersion` does not change when only `src/manifest.json` changes,
  so it under-invalidates exactly the class of edit that caused today's incident.
- **`humaniq-ia-navigation-alignment` (active): unaffected.** That change's four-leaf relocation and
  ADR-001 alignment work is unrelated to boot/build integrity.
- **`humaniq-test-coverage-baseline` (active): this change's guards are Node-level, not e2e**,
  specifically because that baseline does not exist yet. When it lands, an e2e smoke assertion that
  `/employees` and `/payslips` return a non-zero row count for a seeded, administratie-scoped user
  is a reasonable *addition* on top of (not a replacement for) the guards specified here — that
  addition belongs in that change, not this one.

## Capabilities

### New Capabilities
- `boot-integrity`: humaniq's dependency trees are verifiably installed at the versions their
  lockfiles pin, the shipped frontend bundle is verifiably built from current source and
  dependencies, and a manifest sentinel filter clause that fails to resolve is caught before it
  ships rather than rendering a silently-empty index page.

### Modified Capabilities
- `multi-administratie`: REQ-MULTI-004's "Delivered" status is corrected — the automatic
  per-page scoping it describes is live-verified NOT to hold against the currently-deployed
  artefact, and its delivered status is redefined to depend on the `boot-integrity` guards passing
  rather than on the source having once been written correctly.

## Impact

- **No PHP or Vue application code changes.** This change's implementation surface is entirely
  tooling: a dependency-drift check (Node + Composer), a manifest-sentinel-resolution check (Node),
  a bundle-freshness check (Node, invoked from `package.json` scripts and/or CI), and a rebuild of
  `js/humaniq-*.js` once the dependency tree is correctly installed by whoever next has install
  permission in this checkout.
- **`openspec/specs/multi-administratie/spec.md`** — REQ-MULTI-004 corrected in place (delta spec
  below), following the same in-file correction precedent REQ-MULTI-005 already set.
- **New files** (exact paths and behaviour specified in design.md/tasks.md): a dependency-drift
  check script, a manifest-sentinel-resolution check script, and a bundle-freshness check script,
  each wired into `package.json`/`composer.json` scripts so they run the same way
  `tests/validate-widget-keys.js` and `tests/validate-manifest.js` already do.
- **`js/humaniq-main.js` and sibling bundle chunks** — rebuilt once dependencies are correctly
  installed (not performed by this change; see Non-Goals).
