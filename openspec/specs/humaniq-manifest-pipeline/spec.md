---
capability: humaniq-manifest-pipeline
status: in-progress
built_by: openspec/changes/humaniq-manifest-fragment-pipeline
---

# humaniq-manifest-pipeline Specification

**Status**: in-progress
**Scope**: humaniq
**OpenSpec changes**:
- [humaniq-manifest-fragment-pipeline](../../changes/humaniq-manifest-fragment-pipeline/) _(active)_ — adopts the ADR-037/ADR-044 `manifest.d/` + `menu-layout.json` + `buildManifest()` pipeline, splits the 113-page monolith into ~29 domain fragments mirroring `lib/Settings/register.d/`, and introduces `pageTemplates`/`pageInstances` for the index-page and four detail-page shapes, with zero observable change to pages, routes, widgets, or menu structure (kind: code+config; supersedes `humaniq-ia-navigation-alignment`'s pipeline-adoption prerequisite)

## Purpose

humaniq builds its effective frontend manifest — pages, menu, and templated-page expansion — from
modular `src/manifest.d/*.json` fragments and a `src/menu-layout.json` via the shared
`@conduction/nextcloud-vue` `buildManifest()` pipeline (ADR-037/ADR-044), instead of importing one
monolithic `src/manifest.json`, with the effective result observably identical to the pre-existing
monolith.

## Requirements

Detailed requirements are defined in the active change's delta spec —
[`openspec/changes/humaniq-manifest-fragment-pipeline/specs/humaniq-manifest-pipeline/spec.md`](../../changes/humaniq-manifest-fragment-pipeline/specs/humaniq-manifest-pipeline/spec.md)
— and are merged here when the change is archived. The umbrella requirement below anchors the
capability until then.

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
