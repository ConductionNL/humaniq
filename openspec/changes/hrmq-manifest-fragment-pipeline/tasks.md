## 1. Capture the pre-change baseline (must happen before any manifest edit)

- [x] 1.1 Dump `pages[].{id,route}` from the current (pre-change) `src/manifest.json` (113 entries)
      to a fixture file used by the verification script in section 7. Do this first — once
      `src/manifest.json` is edited there is no other source for the "before" state.
- [x] 1.2 Dump the current `menu[]` tree (11 top-level groups, 64 navigable entries, full
      parent/child structure) to a second fixture file, same reason.
- [x] 1.3 Record `node tests/validate-manifest.js` output verbatim (page count, error count) and
      `node tests/validate-widget-keys.js` output verbatim (baseline the pre-existing FAIL — do not
      attempt to fix it here; design.md/Non-Goals).

## 2. Scaffold the fragment pipeline (additive — does not touch `src/manifest.json`/`src/main.js` yet)

- [x] 2.1 Create `src/manifest.d/` with `_placeholder.json` (`{"pages":[],"menu":[]}`) and a
      `README.md` (adapt the pipelinq/openconnector README: merge semantics, ordering, why the
      placeholder file exists and must stay tracked).
- [x] 2.2 Create `src/menu-layout.json`: `_meta` (spdx-license/copyright + description matching the
      pipelinq/openconnector convention), empty `relocations: {}`, empty `removals: []`,
      `settingsSection: []` with a `_settingsSectionNote` explaining it is empty on purpose (ADR-079
      Decision 5 names hrmq's `Configuratie` top-level entry as a violation; relocating it is a
      menu-structure decision this change does not make — see proposal.md Non-goals), and an empty
      `_navigationRationale` object with a one-line comment on what it is for (design.md Decision 4).

## 3. Split the monolith into 29 domain fragments (design.md Decision 2)

- [x] 3.1 For each of the 28 schema-mapped domains, create `src/manifest.d/hr-<domain>.json`
      containing that domain's pages (moved verbatim from `src/manifest.json`, `_note` intact) and a
      `menu` entry re-declaring the owning top-level group by `id` only, with that domain's leaf
      entries as `children[]` (per `buildManifest.js`'s `mergeMenuItems` — "the first definition of
      each listed key wins... fragments may extend an existing group by re-declaring only its `id`
      plus their own `children`"). Use the design.md Decision 2 table for the page→fragment mapping;
      `Employee`/`EmploymentContract`/`Timesheet` pages go to their *origin* file
      (`hr-objects.json`/`hr-objects.json`/`hr-timesheet.json`), not `hr-cost-rate.json`.
- [x] 3.2 Create `src/manifest.d/hr-administratie.json` for the one content-mapped page
      (`Administraties`).
- [x] 3.3 Reduce `src/manifest.json` to the shell: `$schema`, `version`, `dependencies`,
      `deepLinks` (all 39, unchanged), `runtime` (unchanged), the 11 top-level `menu[]` group shells
      (`id`/`label`/`icon`/`order`, no `children` — fragments supply those), and the 3 schema-less
      base pages (`Dashboard`, `ProformaPayslip`, `MijnGebruikelijkLoon` — design.md Decision 2;
      `Administraties` is NOT one of these, it moved to `hr-administratie.json` in 3.2). Confirm no
      page ends up in both the base and a fragment.
- [x] 3.4 Grep every file under `src/manifest.d/` for `"deepLinks"`, `"runtime"`, `"dependencies"` —
      MUST find zero matches (spec.md "silently ignored key" requirement). Any hit is a bug: that
      content would be dropped at build time with no error.

## 4. Introduce `pageTemplates`/`pageInstances` (design.md Decision 3)

- [x] 4.1 Author the index-page template (`indexScaffold`) in a shared location (either
      `src/manifest.json` base or its own `src/manifest.d/00-templates.json` — pick one and be
      consistent; a dedicated templates fragment avoids the base growing back). Declared params:
      `register`, `schema` (shortcuts), `columns`, `filters`, `sort` (required), `filter`,
      `description`, `allowCreate`, `actionToggles`, `defaultFilters` (optional).
- [x] 4.2 Convert all 60 index pages to `pageInstances[]` entries referencing `indexScaffold`, moved
      into the `pageInstances[]` array of whichever domain fragment already owns that page (keeps
      each domain's content together rather than centralising all instances in one file). Verify
      each instance's substituted-and-expanded output is byte-identical to the original page object
      (task 7.2 covers this for all 60 at once).
- [x] 4.3 Author the four detail templates (`simpleDetailScaffold`,
      `simpleDetailScaffoldWithLifecycle`, `singleDataScaffold`, `actionedDetailScaffold`) per the
      design.md Decision 3 table (widget-key sequence, grid coordinates, which parts are fixed vs.
      `{{param}}`).
- [x] 4.4 Convert the 19 covered detail pages to `pageInstances[]` in their owning domain fragments.
- [x] 4.5 Confirm the other 30 detail pages (including `EmployeeDetail`) and the 4 base pages remain
      concrete, hand-written pages — do not force them into any of the four templates (design.md
      Decision 3, "80%-fit" caution). No task needed beyond confirming none of them was accidentally
      converted in 4.2/4.4.

## 5. Relocate `_note` prose (design.md Decision 4)

- [x] 5.1 For each of the 94 non-templated pages, confirm its `_note` moved unchanged into the
      domain fragment now holding it (part of task 3.1's page move — this is a verification pass,
      not new authoring).
- [x] 5.2 For each of the four detail templates, write one `_note` on the `pageTemplate` capturing
      the rationale shared by its instances (e.g. template A: "two-panel leaf — one outbound
      reference resolved by `related`, no lifecycle, no child collections").
- [x] 5.3 For each of the 19 templated pages' original `_note`, extract whatever is genuinely
      instance-specific (not already said by the template's note) into that `pageInstance`'s own
      `_note`/params note. Do not silently drop content that isn't obviously redundant — when in
      doubt, keep it on the instance.
- [x] 5.4 Diff-review: every `_note` string present in the pre-change `src/manifest.json` is
      findable (verbatim, or split between exactly one template note + one instance note) somewhere
      in the post-change fragments/templates. None dropped.

## 6. Wire `src/main.js` to `buildManifest()`

- [x] 6.1 Add the webpack `require.context('./manifest.d/', false, /\.json$/)` fragment collection
      (mirror pipelinq's `src/main.js`, including its `/* global require */` comment explaining why
      this is not a `no-undef` lint bug).
- [x] 6.2 Replace the direct `bundledManifest` consumption with
      `buildManifest(bundledManifest, fragments, menuLayout)`, importing `menuLayout` from
      `./menu-layout.json` and `buildManifest` from `@conduction/nextcloud-vue`.
- [x] 6.3 Confirm `routesFromManifest()` and the router catch-all (`redirect: '/timesheets'`) are
      otherwise unchanged — this change does not port pipelinq's explicit static-before-dynamic route
      sort (design.md Risks: verified unnecessary for hrmq's vue-router version/usage; adding it
      would be unstated scope creep).

## 7. Prove the no-functionality-loss invariant (the actual acceptance test, not an assertion)

- [x] 7.1 Write a Node verification script (`tests/verify-manifest-parity.js` or similar) that: loads
      the pre-change fixtures from task 1, computes the post-change effective manifest (fragments +
      `buildManifest()` + template expansion), and asserts the `(id, route)` pair sets are equal
      (spec.md "byte-identical" requirement) and the menu trees are structurally equal (spec.md
      "menu entries remain reachable" requirement).
- [x] 7.2 Extend the same script (or a second one) to assert every expanded `pageInstance`'s full
      page content (not just id/route) is deep-equal to the corresponding pre-change page object,
      for all 60 index instantiations and all 19 detail instantiations.
- [x] 7.3 Add a route-resolution check for the six static/dynamic collision pairs
      (`/timesheets/approval` vs `/timesheets/:id`, and the five siblings — spec.md scenario) by
      constructing the router from the post-change manifest and resolving each static path, asserting
      `route.name` is the static page's id.
- [x] 7.4 Run all three checks; fix any discrepancy by correcting the fragment/template content (not
      by loosening the check).

## 8. Fix the manifest validator's coverage (design.md Decision 6)

- [x] 8.1 Update `tests/validate-manifest.js` to build the effective manifest (require fragments,
      run `buildManifest()` + template expansion — reuse the logic from task 7's script rather than
      writing a third implementation) before running Ajv, and to print the page count validated.
- [x] 8.2 Run `node tests/validate-manifest.js`; confirm it reports validating 113 pages (not ~4) and
      exits 0 with 0 Ajv errors.
- [x] 8.3 Re-run `node tests/validate-widget-keys.js`; confirm it fails identically to the task 1.3
      baseline (same `object-list`/`stats-block` unresolved keys, same drifted-`node_modules` root
      cause) — i.e. this change introduced no new widget-key regression, and did not (and was not
      expected to) fix the pre-existing one.

## 9. Cleanup and orchestrator hand-off

- [x] 9.1 Run `openspec validate --strict` (or equivalent) against this change's artifacts.
- [x] 9.2 Flag to the orchestrator: `openspec/changes/hrmq-ia-navigation-alignment/` is superseded by
      this change's pipeline adoption and should be archived without being applied — do not apply it
      after this change lands, its prerequisite step would double-build the same pipeline and its
      relocation content targets a menu structure (ADR-001's frozen 9) that ADR-097 has since
      superseded.
- [x] 9.3 Flag to the orchestrator (informational, no action required by this change): the identical
      base-only validator gap found in `pipelinq/tests/validate-manifest.js` and
      `openconnector/tests/validate-manifest.js` (design.md Decision 6) as a candidate fleet-wide
      follow-up — out of this change's write-scope (different repos).
