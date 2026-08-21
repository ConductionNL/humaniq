<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Manifest fragments (`manifest.d/`)

Modular frontend-manifest fragments for hrmq (ADR-037 / ADR-044).

Every `*.json` file in this directory is collected by `src/main.js` via webpack
`require.context`, sorted by filename, and merged onto the bundled
`../manifest.json` by the shared `buildManifest(base, fragments, menuLayout)`
from `@conduction/nextcloud-vue`. The merged manifest feeds both the vue-router
route table and the `CnAppRoot` `manifest` prop.

## Merge semantics (`buildManifest`, the single shared implementation)

- `pages[]` merge **by id**: a later declaration replaces an earlier one
  wholesale; new ids are appended.
- `menu[]` entries merge **by id**; the first definition of each listed key
  wins (the base loads first, so its canonical group shells take precedence),
  and `children[]` are unioned recursively by the same rule — a fragment
  extends an existing group by re-declaring only its `id` plus `children`.
- `pageTemplates[]` / `pageInstances[]` are collected from every fragment and
  expanded as the final step (`expandPageTemplates`), so the runtime renderer
  only ever sees concrete pages.
- `sets` objects are shallow-merged.
- **Anything else in a fragment is silently ignored.** `buildManifest()` reads
  only `pages`, `menu`, `pageTemplates`, `pageInstances`, and `sets` from a
  fragment object — `deepLinks`, `runtime`, and `dependencies` MUST stay in
  the base `../manifest.json`; declaring them in a fragment drops that content
  at build time with no error.
- Fragments are applied in sorted filename order — prefix with an ordering
  hint (`00-…`, `05-…`) when order matters.

## Layout of this directory (one-time decomposition, 2026-08-21)

The former 241 KB monolithic `manifest.json` was decomposed by **domain**,
mirroring the backend's `lib/Settings/register.d/` split (the same 28 domains
hold both a domain's OpenAPI schemas and, here, its pages):

- `hr-<domain>.json` — that domain's concrete `pages[]` (maintainer `_note`
  prose intact) and its `pageInstances[]` (templated pages). Pages whose
  schema is only *extended* by `hr-cost-rate.json` (`Employee`,
  `EmploymentContract`, `Timesheet`) live with the file that *originates*
  the schema (`hr-objects`, `hr-timesheet`), exactly as on the backend.
- `00-templates.json` — the four shared `pageTemplates[]`
  (`indexScaffold` ×58, `simpleDetailScaffold` ×17, `dualDataScaffold` ×5,
  `singleDataScaffold` ×3). All other pages are deliberately concrete: forcing
  an 80 %-fit page through a template with a large `override` is worse than
  leaving it explicit.
- `05-menu.json` — every menu group's `children[]`, in canonical order, as a
  **single source**. The children of one group interleave entries from several
  domains (and `buildManifest` appends each fragment's contribution at the end
  of a group), so per-domain menu contributions cannot reproduce the canonical
  order — one file can. The base `../manifest.json` keeps only the top-level
  group shells (`id`/`label`/`icon`/`order`) plus the Dashboard leaf; WHERE
  entries live is decided by `../menu-layout.json` (ADR-044), not here.
- `_placeholder.json` — an empty `{ "pages": [], "menu": [] }` so
  `require.context` always has at least one match (an empty glob throws).
  Keep it tracked.

New feature work does **not** grow these domain files by convention: per
ADR-037 a new change adds its **own** `manifest.d/<change>.json` fragment on
top of this baseline, so concurrent same-app builds never conflict on a
shared file.

## Verification

`node tests/verify-manifest-parity.js` proves the decomposition against the
pre-split baseline (`tests/fixtures/manifest-baseline/`), and
`node tests/validate-manifest.js` schema-validates the **effective** (merged +
expanded) manifest — both must pass after any edit here.
