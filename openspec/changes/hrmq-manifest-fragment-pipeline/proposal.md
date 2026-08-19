---
kind: code+config
---

## Why

`src/manifest.json` is 252,991 bytes — 113 pages, 64 navigable menu entries, 260 widgets, all in one
file, with no `src/manifest.d/` and no `src/menu-layout.json`. `src/main.js` imports it directly and
builds routes from it; it never calls `@conduction/nextcloud-vue`'s shared `buildManifest(base,
fragments, menuLayout)`. Nine-plus other apps (pipelinq, procest, openregister, decidesk,
openconnector, opencatalogi, softwarecatalog, larpingapp, doriath) already run this pipeline
(ADR-044 Consequences); hrmq is the one app that hasn't, and ADR-044 Rule 6 makes adopting it a
hard prerequisite before hrmq can touch its navigation at all: "An app whose manifest is a single
monolithic `src/manifest.json`... MUST first adopt the ADR-037 fragment pipeline... before it can
adopt `buildManifest` and this ADR."

hrmq's own backend already runs the equivalent pattern for its OpenAPI register — 32
`lib/Settings/register.d/*.json` domain fragments deep-merged by `SettingsService::loadConfiguration()`
(`lib/Repair/InitializeRegister.php`) — so the frontend gap is the odd one out, not a new idea for
this codebase.

This matters now for two concrete reasons beyond "match the fleet pattern":

1. **ADR-097 (navigation budget, proposed 2026-08-19) names hrmq as the measurement that started
   it** — 11 top-level groups against a 6-entry ceiling, 18 of 64 entries that are the same query
   four times over (role-lens duplication, ADR-097 §5), three unauthorised groups that are a record
   type, a lifecycle stage, and a single tool (ADR-097 §4). ADR-097's own Related section names
   the fragment pipeline as "the prerequisite for most per-app budget work." Nobody can fix hrmq's
   menu without it.
2. **`openspec/changes/hrmq-ia-navigation-alignment`** (open, unapplied) already proposes exactly
   this pipeline as step 1 of a change that then relocates four leaves under two ADR-001 groups.
   That change predates ADR-097; its relocation target (ADR-001's frozen 9) is itself now stale
   next to ADR-097's stricter 6-entry ceiling and role-lens-collapse guidance. Re-doing the
   prerequisite here, cleanly and without bundling a relocation decision that will be redone
   anyway, is cheaper than carrying two half-finished attempts.

Splitting the monolith is also the only way to make the 22.6% of its bytes that is `_note`
maintainer prose (measured directly — see Design; the brief's 28.4% figure does not reproduce)
navigable: today one person's note about `JaaropgaafDetail`'s widget shape sits 60,000 bytes from
the note about the schema it says it resembles.

**This change is deliberately a pure refactor.** It introduces the mechanism a menu restructure
needs; it does not restructure the menu. Every one of the 113 page ids and routes, and all 64 menu
entries, must resolve identically before and after.

## What Changes

- **Adopt the ADR-037/ADR-044 fragment pipeline.** Introduce `src/manifest.d/` and
  `src/menu-layout.json`; switch `src/main.js` from consuming `bundledManifest` directly to
  `buildManifest(base, fragments, menuLayout)`, mirroring pipelinq's `main.js` (`require.context`
  fragment collection is the only app-local step, per ADR-044 Decision 1).
- **Split the monolith into ~29 domain fragments**, mirroring `lib/Settings/register.d/`'s 32-domain
  split rather than inventing a new grouping (see Design for the exact page→domain assignment and
  the 3 schemas whose owning fragment required a judgment call).
- **Introduce `pageTemplates[]`/`pageInstances[]`** for the two tiers of genuine duplication: one
  template for all 60 index pages (uniform `register/schema/columns/filters/sort` shape, zero
  widgets), and four templates for the four most common detail-page widget-layout signatures,
  covering 19 of 49 detail pages. The remaining 34 pages (2 dashboard, 2 custom, 30 detail — including
  the 17-widget `EmployeeDetail`) stay hand-written concrete pages; forcing them through a template
  would cost more in `override` deltas than it saves.
- **Relocate `_note` maintainer prose** with the pages it documents (into the domain fragment that
  now owns each page) rather than deleting or centralising it. For templated pages, the rationale
  shared across instances of one template moves to the `pageTemplate`'s own `_note`; content that is
  genuinely instance-specific stays as a per-instance note. `menu-layout.json` gets its own
  `_navigationRationale` block (the pipelinq/openconnector convention) for future menu-structure
  decisions — empty in this change, since none are made here.
- **`deepLinks[]`, `runtime`, and `dependencies` stay in the base `src/manifest.json`.**
  `buildManifest()` reads only `pages`/`menu`/`pageTemplates`/`pageInstances`/`sets` from a
  fragment (confirmed by reading `nextcloud-vue/src/utils/buildManifest.js`); any other key placed
  in a fragment is silently dropped. This is stated as a hard constraint in Design, not a choice.
- **Supersede `openspec/changes/hrmq-ia-navigation-alignment`.** That change proposed the same
  pipeline adoption as its own prerequisite step, then bundled a specific relocation (Timesheets/
  TimesheetApproval → Verlof & verzuim; Expenses/ExpenseApproval → Declaraties & assets) that this
  change does not make. The orchestrator should retire/archive that change without applying it once
  this one lands — its relocation content is left for a later, ADR-097-scoped change to redo
  correctly (see Non-goals below and design.md).
- No page gains or loses a route. No widget is added, removed, or reconfigured. No menu entry moves,
  is renamed, or is removed. `node tests/validate-manifest.js` must still pass Ajv with 0 errors.

### Explicitly out of scope (non-goals)

- **The menu restructure itself** — collapsing hrmq's 11 top-level groups toward ADR-097's ceiling,
  fixing the 18 role-lens duplicate index pages via `menu[].query`/`authorization` (ADR-097 §5),
  retiring the three unauthorised groups (`Uren`, `Planning`, `Simuleer loonstrook`), or moving
  `Configuratie` out of the main menu (ADR-079 Decision 5). This change makes relocation *possible*
  through `menu-layout.json`; a later change decides the target structure. Bundling that decision
  here would force this change to re-litigate ADR-097 tradeoffs that are still marked WARN-only
  fleet-wide.
- **Access control** — 0 of 64 menu entries and 0 of 55 schemas declare `permission`/`authorization`.
  Real, and out of scope: adding either changes who can see or do something, which is not a
  byte-identical refactor by definition.
- **The tenant-boundary defect and the `@workspace.activeAdministrationId?` unsubstituted-sentinel
  P0** — separate, code-level defects, not manifest-structure ones.

## Capabilities

### New Capabilities
- `hrmq-manifest-pipeline`: hrmq builds its effective frontend manifest via the shared ADR-037/
  ADR-044 fragment + `buildManifest` + page-template-expansion pipeline instead of a monolithic
  `src/manifest.json`, with zero observable change to pages, routes, widgets, or menu structure.

### Modified Capabilities
(none — `hrmq-expenses` and `hrmq-timesheet-approval` are untouched; their menu-group wording
corrections belong to whichever later change actually relocates them, not this one)

## Impact

- **`src/manifest.json`** — shrinks to the shell: `$schema`, `version`, `dependencies`, `deepLinks`,
  `runtime`, top-level `menu[]` group shells (id/label/icon/order, no children), and the 4
  schema-less pages (`Dashboard`, `ProformaPayslip`, `MijnGebruikelijkLoon`) that have no natural
  domain fragment.
- **`src/manifest.d/`** (new) — ~29 domain fragment files (`hr-<domain>.json`, mirroring
  `lib/Settings/register.d/`'s naming) plus `_placeholder.json` and a `README.md`, each contributing
  its slice of `pages[]` and its menu-group `children[]`.
- **`src/menu-layout.json`** (new) — `_meta`, empty `relocations`/`removals`, `settingsSection: []`
  with a note on why it is empty, empty `_navigationRationale`.
- **`src/main.js`** — `buildManifest(bundledManifest, fragments, menuLayout)` replaces direct
  `bundledManifest` consumption; `require.context('./manifest.d/', false, /\.json$/)` added.
- **`tests/validate-manifest.js`** — MUST change. It currently reads `src/manifest.json` directly
  and validates only that file — true today because the base *is* the whole manifest. After the
  fragment split the base shrinks to ~4 pages; left unmodified, this script would still print
  `PASS (0 errors)` while validating roughly 4% of the page surface, silently losing the coverage
  the brief warns against ("a filter on a property that does not exist returns HTTP 200... reads as
  success"). Confirmed this is not just an hrmq gap: `pipelinq/tests/validate-manifest.js` and
  `openconnector/tests/validate-manifest.js` have the identical gap — neither builds the effective
  manifest before validating, they only read the base file. This change fixes hrmq's copy (build the
  effective manifest — fragments + `buildManifest()` — before validating) rather than inheriting the
  fleet's gap; it does not fix the other apps' copies (out of scope, flagged in design.md).
- **`tests/validate-widget-keys.js`** — baselined RED at clean HEAD (drifted `node_modules`
  `@conduction/nextcloud-vue` build failure, unrelated to this change — see design.md Risks). Not
  this change's job to fix.
- **`openspec/changes/hrmq-ia-navigation-alignment/`** — superseded; flagged for the orchestrator to
  archive without applying (this change does not touch that directory itself, per the write-scope
  constraint).
- No PHP, route, schema, or widget-behaviour change. No `composer install`/`npm install` required to
  author this change (validated with the existing `node_modules` tree via `tests/validate-manifest.js`).
