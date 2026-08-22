---
capability: humaniq-manifest-pipeline
status: done
built_by: openspec/changes/archive/2026-08-22-hrmq-manifest-fragment-pipeline
---

# humaniq-manifest-pipeline Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [hrmq-manifest-fragment-pipeline](../../changes/archive/2026-08-22-hrmq-manifest-fragment-pipeline/) _(archived 2026-08-22, merged as humaniq#122)_ — adopts the ADR-037/ADR-044 `manifest.d/` + `menu-layout.json` + `buildManifest()` pipeline, splits the 113-page monolith into ~29 domain fragments mirroring `lib/Settings/register.d/`, and introduces `pageTemplates`/`pageInstances` for the index-page and four detail-page shapes, with zero observable change to pages, routes, widgets, or menu structure (kind: code+config; supersedes `humaniq-ia-navigation-alignment`'s pipeline-adoption prerequisite)

## Purpose

humaniq builds its effective frontend manifest — pages, menu, and templated-page expansion — from
modular `src/manifest.d/*.json` fragments and a `src/menu-layout.json` via the shared
`@conduction/nextcloud-vue` `buildManifest()` pipeline (ADR-037/ADR-044), instead of importing one
monolithic `src/manifest.json`, with the effective result observably identical to the pre-existing
monolith.

## Requirements

The change's delta specs were merged here when it was archived on 2026-08-22; REQ-MFP-000 below
is the umbrella statement of the capability, and the requirements after it are the merged detail.

### Requirement: humaniq's effective manifest is built via the shared fragment pipeline, with no observable change (REQ-MFP-000)

humaniq MUST build its effective frontend manifest via `@conduction/nextcloud-vue`'s
`buildManifest(base, fragments, menuLayout)`, collecting `src/manifest.d/*.json` fragments via
`require.context` (the only app-local step, ADR-044 Decision 1) and applying `src/menu-layout.json`.
The set of `(page.id, page.route)` pairs and the menu tree in the effective manifest MUST remain
identical to the pre-adoption monolithic `src/manifest.json` until a later, separately-proposed
change deliberately restructures them.

#### Scenario: The app boots from the merged manifest and every pre-existing page/route/menu entry resolves

- GIVEN humaniq at HEAD, before this capability's frontend had no `manifest.d/` pipeline
- WHEN `src/main.js` is inspected after this change
- THEN it calls `buildManifest(bundledManifest, fragments, menuLayout)` rather than consuming
  `bundledManifest` directly, and a before/after dump of `(id, route)` pairs and the menu tree
  (computed by the verification script this change adds) is equal
- @e2e exclude humaniq has no e2e suite yet (tracked by the active change `humaniq-test-coverage-baseline`);
  covered by a Node script comparing both git revisions directly, which is a stronger and more
  complete check for 113 pages than any single click-path e2e test would give

### Requirement: humaniq builds its effective manifest via the shared buildManifest pipeline

`src/main.js` SHALL build the manifest handed to `CnAppRoot`/the vue-router route table by calling
`buildManifest(base, fragments, menuLayout)` from `@conduction/nextcloud-vue`, where `fragments` is
collected via `require.context('./manifest.d/', false, /\.json$/)` and `menuLayout` is
`src/menu-layout.json`. `src/main.js` SHALL NOT re-implement fragment merging, menu relocation, or
page-template expansion inline (ADR-044 Decision 1).

**Feature tier**: MVP

#### Scenario: The app boots from the merged manifest, not the monolith

- **GIVEN** `src/manifest.json` no longer declares the full `pages[]`/`menu[]` content on its own
- **WHEN** `src/main.js` runs `buildManifest(bundledManifest, fragments, menuLayout)`
- **THEN** the resulting manifest's `pages[]` and `menu[]` are assembled from `src/manifest.d/*.json`
  plus the base, and this merged manifest — not the raw import of `src/manifest.json` — is what is
  passed to the router and to `CnAppRoot`

@e2e exclude humaniq has no e2e suite yet (tracked by the active change `humaniq-test-coverage-baseline`);
this scenario is a build-time/boot-path assertion better covered by a Node script comparing the
built bundle's route table against the pre-change baseline (see design.md/tasks.md), which this
change adds.

#### Scenario: A fragment declaring an unmerged key is silently ignored, so that key stays in the base

- **GIVEN** `buildManifest()` reads only `pages`, `menu`, `pageTemplates`, `pageInstances`, and
  `sets` from each fragment object
- **WHEN** a fragment under `src/manifest.d/` is authored
- **THEN** `deepLinks`, `runtime`, and `dependencies` MUST NOT be declared in that fragment — they
  are read only from the base `src/manifest.json`, and declaring them in a fragment has no effect
  and MUST NOT be relied upon

@e2e exclude static/structural constraint on authored fragment files, verified by a repo-local
check (grep/schema check), not runtime behaviour worth an e2e test.

### Requirement: The fragment split preserves every page id and route byte-identical

The set of `(page.id, page.route)` pairs in the effective manifest, computed after
`buildManifest()` (including page-template expansion), SHALL be identical to the set present in the
pre-change monolithic `src/manifest.json` — same 113 pairs, no additions, no removals, no route
value changed for an existing id.

**Feature tier**: MVP

#### Scenario: A before/after dump of (id, route) pairs is equal as sets

- **GIVEN** a dump of `pages[].{id,route}` from the pre-change `src/manifest.json` (113 entries)
- **WHEN** the same dump is taken from the post-change effective manifest (base + fragments +
  expanded template instantiations)
- **THEN** the two sets of `(id, route)` pairs are equal — same 113 members, none added, none
  removed, none changed

@e2e exclude no e2e suite exists yet; this is exactly the kind of invariant a Node script (added by
this change, see tasks.md) proves directly and repeatably against both git revisions, which is a
stronger check than any single e2e click-path would give for 113 pages.

#### Scenario: Two routes that share a static/dynamic prefix still resolve to the correct page after reordering

- **GIVEN** `/timesheets/approval` (a static index route) and `/timesheets/:id` (a parameterised
  detail route) are declared in different `manifest.d` fragments, so their relative order in the
  merged `pages[]` array can differ from the pre-change monolith's declaration order
- **WHEN** vue-router 4 resolves `/timesheets/approval`
- **THEN** it matches the `TimesheetApproval` route, not `TimesheetDetail` with `id: "approval"` —
  the same outcome as before the fragment split, for all six such static/dynamic pairs in the
  manifest (`/timesheets/approval`, `/timesheets/team-approval`, `/expenses/approval`,
  `/expenses/team-approval`, `/leave-requests/approval`, `/leave-requests/team-approval`)

@e2e exclude no e2e suite exists yet; covered by a route-resolution assertion in the Node
verification script this change adds (construct the router, resolve each of the six paths, assert
`route.name`), which exercises the actual vue-router matcher without a browser.

### Requirement: All pre-existing menu entries remain reachable after the pipeline adoption

The effective menu tree (after `buildManifest()` merges fragments and applies the empty
`menu-layout.json`) SHALL contain the same 64 navigable entries (`route`/`href`/`action` present),
the same 11 top-level groups, and the same parent/child structure as the pre-change monolith. Since
this change's `menu-layout.json` declares no `relocations` and no `removals`, the menu tree SHALL be
structurally unchanged, not merely non-regressed.

**Feature tier**: MVP

#### Scenario: Menu tree shape is unchanged

- **GIVEN** the pre-change monolith's `menu[]` (11 top-level groups, 64 navigable entries)
- **WHEN** the effective menu is computed via `buildManifest()` with an empty `menu-layout.json`
- **THEN** the effective `menu[]` has the same 11 top-level group ids in the same order, and each
  group's `children[]` contains the same leaf ids in the same order as before

@e2e exclude no e2e suite exists yet; covered by a before/after menu-tree dump comparison in the
Node verification script this change adds.

### Requirement: Widget-duplicate page shapes are expressed as pageTemplates, not repeated JSON

Where multiple concrete pages share one config or widget-layout shape and differ only in
register/schema binding, label, and a small set of field/column/filter values, that shape SHALL be
declared once as a `pageTemplates[]` entry and instantiated per page via `pageInstances[]`
(manifest-entity-scaffold-templating), rather than repeated as separate literal page objects. A page
whose shape differs from every template by more than a small `override` delta SHALL remain a
hand-written concrete page.

**Feature tier**: MVP

#### Scenario: All 60 index pages expand from one template

- **GIVEN** one `pageTemplates[]` entry declaring the shared index-page shape (`register`, `schema`,
  `columns`, `filters`, `sort`, plus optional `filter`, `description`, `allowCreate`,
  `actionToggles`, `defaultFilters`)
- **WHEN** 60 `pageInstances[]` entries reference it, one per existing index page
- **THEN** `expandPageTemplates()` produces 60 concrete pages whose `id`, `route`, and `config`
  content are identical to the pre-change monolith's 60 index pages

@e2e exclude no e2e suite exists yet; covered by the same (id, route) and full-page-content
before/after comparison the Node verification script performs (see the byte-identical requirement
above).

#### Scenario: A page that only 80%-fits a template stays hand-written

- **GIVEN** a detail page whose widget layout differs from every declared `pageTemplate` in more
  than one widget's structure (not just a parameter value)
- **WHEN** the fragment split decides whether to express that page as a `pageInstance` with a large
  `override` or as a concrete page
- **THEN** it is authored as a concrete page in its domain fragment's `pages[]`, not forced into a
  `pageInstance` — an `override` that restates most of the template's structure is worse than no
  template

@e2e exclude authoring-time judgment call, not a runtime behaviour; verified by design-doc review
(the 30 non-templated detail pages are named explicitly in design.md) rather than a test.

### Requirement: Fragment-owning `_note` maintainer prose is relocated, not deleted

Every `_note` string present in the pre-change monolith SHALL still be present after this change —
either unchanged in the domain fragment that now owns its page, or, for a page expressed as a
`pageInstance`, split between the owning `pageTemplate`'s `_note` (rationale shared by every
instance of that template) and an optional per-instance note (content specific to that one page). No
`_note` content SHALL be dropped as a side effect of the fragment split or the templating.

**Feature tier**: Should-have

#### Scenario: A non-templated page's note travels with it

- **GIVEN** `JaaropgaafDetail`'s `_note` in the pre-change monolith
- **WHEN** `JaaropgaafDetail` is moved into `src/manifest.d/hr-documents.json`
- **THEN** the same `_note` text is present on that page definition inside the fragment

@e2e exclude authoring/documentation content, not runtime behaviour; verified by a textual diff
during review, not a test.

#### Scenario: A templated page's shared rationale is not silently lost

- **GIVEN** the 9 pages instantiating the `simpleDetailScaffold` template each currently carry a
  `_note` that references the same shape ("a simple two-panel leaf like X")
- **WHEN** those 9 pages are re-expressed as `pageInstances[]` of one `pageTemplate`
- **THEN** the shared "two-panel leaf, no lifecycle, no child collections" rationale is present once
  on the `pageTemplate`'s `_note`, and whatever was genuinely page-specific in each of the 9
  original notes (e.g. why a given schema has no files widget) is retained as that instance's own
  note — reviewable by diffing the 9 original notes against the template note plus the 9 instance
  notes

@e2e exclude authoring/documentation content; verified by review during implementation (tasks.md),
not a test.

### Requirement: The manifest validator covers the effective manifest, not only the base shell

`tests/validate-manifest.js` SHALL validate the *effective* manifest — base plus every
`manifest.d/*.json` fragment merged, plus `pageTemplates`/`pageInstances` expanded — against the
`app-manifest-v2` schema, and SHALL report the page count it validated. Validating only
`src/manifest.json` in isolation is insufficient once the base no longer carries the bulk of
`pages[]`: an unmodified base-only check would still print a passing result while covering a small
fraction of the actual page surface.

**Feature tier**: MVP

#### Scenario: Ajv validation passes on the effective manifest after the pipeline adoption

- **GIVEN** the fragment split and `pageTemplates`/`pageInstances` are in place, and
  `tests/validate-manifest.js` has been updated to build the effective manifest before validating
- **WHEN** `node tests/validate-manifest.js` runs
- **THEN** it exits 0, reports 0 Ajv errors, and reports validating 113 pages — the same count the
  pre-change monolith reports (`[validate-manifest] pages: 113`) — not the ~4-page base alone

#### Scenario: A validator left pointed at the base alone would under-report, and that is the failure this requirement forbids

- **GIVEN** a hypothetical unmodified `tests/validate-manifest.js` that still reads only
  `src/manifest.json` after the fragment split
- **WHEN** it runs
- **THEN** it would report validating roughly 4 pages and still exit 0 — a passing result that
  covers a small fraction of the manifest's actual page surface, silently, with no error — which is
  exactly the outcome this requirement exists to prevent

@e2e exclude covered directly by the Node script this change modifies; no e2e coverage needed for a
schema-validator's own coverage. The negative scenario is a design constraint verified by code
review of the updated script, not an executable test on its own (the positive scenario's page-count
assertion is the executable guard against silent regression).
