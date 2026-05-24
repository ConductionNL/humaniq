# ADR-036: Universal Widget Manifest (v2)

## Status
Proposed

## Date
2026-05-19

## Context

ADR-024 (App Manifest, Accepted 2026-05-10) brought every Conduction frontend onto a shared manifest schema. By release v1.3.0 of `@conduction/nextcloud-vue` (the 2026-05-10 beta.26 wave), the schema had landed **eleven page types** (`index | detail | dashboard | logs | settings | chat | files | form | wiki | map | custom`), nine additive extensions (`cardComponent`, `actions[].handler`, `dataSource`, `tabs[]` on settings, dynamic per-tenant `menu[]`, `@resolve:` sentinels, named-view sidebar, chart primitive, plus `form`/`wiki`/`map` page types), and was running in production across **five apps**: decidesk (Tier-4 reference), plus pipelinq #330, procest #320, zaakafhandelapp #189-191, softwarecatalog #218 (opencatalogi #547 awaiting review). V1 is a successful, accepted convention.

ADR-024's "Lib v2 backlog" section explicitly anticipates a second-generation manifest closing twelve remaining gaps surfaced by the fleet rollout. The largest are: `actions[]` dispatch to consumer modal handlers, custom-component slot inside `widgets[]`, multi-tab settings orchestration, named-view sidebar generalisation, dynamic per-tenant menus, and a first-class backend `/api/manifest` endpoint loader.

The architectural fragmentation that motivates v2 is *also* documented in ADR-024: each page type uses a different widget shape.

- **Dashboard** pages use `widgets[]` paired with a separate `layout[]` for grid placement
- **Detail** pages bury widgets inside `sidebarTabs[].widgets`
- **Settings** pages use `sections[].widgets[]` with rich-section semantics, or `tabs[]` with section content
- **Index** pages have no widget support at all — only `columns[]` + optional `cardComponent`
- The escape hatch `type: "custom"` is used wherever the model doesn't fit — pipelinq carries 29 custom pages, mydash has no manifest at all because user-built dashboards have nowhere to live

The result: OpenBuilt cannot introspect manifest UI uniformly. Tooling cannot migrate manifests automatically. Users cannot move widgets across surfaces because each surface speaks a different dialect.

## Decision

**V2 collapses the four widget shapes into ONE.** Every page type has a single `widgets[]` array with grid coordinates and a `kind`-discriminated component registry reference. Custom widgets are first-class. Modals follow the same registry path. Index pages gain widget support. `type: "custom"` shrinks to a rare escape hatch. The runtime-manifest pattern (loaded from `/api/manifest/{appId}`) becomes a supported peer of bundled manifests.

**The eleven v1.3.0 page types carry forward unchanged.** The additive 1.3.0 extensions (`dataSource`, `@resolve:`, `cardComponent`, `tabs[]`, dynamic menus, named-view sidebar) carry forward unchanged. The fragmentation is removed; the maturity is preserved.

### Ten decisions

#### 1. Unified `widgets[]` on every page type

Every page (dashboard, index, detail, settings, logs, chat, files, form, wiki, map, custom) MAY declare a `widgets[]` array. Each widget entry carries its own positional info. No more parallel `layout[]`. No more `sidebarTabs[].widgets`.

- **Detail** sidebar widgets flatten to `widgets[]` with `slot: "sidebar"` and an optional `tabGroup: "<id>"` metadata field that preserves v1 tab grouping.
- **Settings** sections/tabs flatten to top-level `widgets[]` with `slot: "tab:<id>"` (or `slot: "section:<id>"` for non-tab section layouts).
- **Body-content** page types (`form`, `wiki`, `map`) get their primary content as a built-in widget (`type: "form-renderer"`, `type: "wiki-renderer"`, `type: "map-viewer"`) placed in the body slot. Apps may add more widgets alongside.
- **Data-flow** page types (`logs`, `chat`, `files`): widgets are optional, primarily for sidebar / header-actions content. The primary view (log list, chat surface, files browser) is the built-in default.
- **`type: "custom"`**: `widgets[]` is permitted but discouraged. The goal is to use built-in page types with custom widgets, not to wrap the whole page.

**Single-widget dashboard anti-pattern (forbidden).** A page with `type: "dashboard"` SHALL NOT contain exactly one widget that (a) covers the full body grid (`slot: "body"`, `gridX: 0`, `gridY: 0`, `gridWidth: 12`, `gridHeight: 12`) AND (b) references a custom registry component (any `widgetKey` not in the library built-in set: `object-table`, `card-grid`, `form-renderer`, `wiki-renderer`, `map-viewer`, `chart`, `stats-block`). The shape is always a custom page in disguise — the wrapping `CnDashboardPage` adds visible nesting on top of the custom view (which is typically itself a `CnDashboardPage`-based component), producing dashboard-in-widget-in-dashboard rendering.

pipelinq#521 documents six concrete occurrences in a single manifest (`Dashboard`, `MyWork`, `Rapportage`, `ChannelAnalyticsView`, `AgentPerformanceView`, `SurveyAnalyticsView`); only the genuinely-multi-widget `Kennisbank` (a tree + an index) was a legitimate `type: "dashboard"`. The defect surfaces uniformly at compose time and has no legitimate use case, because both better alternatives already exist:

- **Alternative A** — declare the page as `type: "custom"` with `component: "<widgetKey>"` and register the component with `kind: "page"` (Decision 7). This is the correct shape when a page truly is a one-off custom surface.
- **Alternative B** — split the page into N>1 widgets when the page genuinely needs the dashboard grid (the Kennisbank pattern).

`gate-manifest-validates` enforces this rule. The canonical error message authored by the gate lives in the `gate-manifest-validates` spec; the validator-side implementation is tracked as a follow-up issue in `ConductionNL/nextcloud-vue` and may use either a JSON Schema `if/then` construct against an enumerated built-in widget list OR a complementary programmatic check in `validateManifest()` against the live registry.

#### 2. Per-slot `gridColumns` conventions

Different slots have different layout semantics. Rather than forcing a single grid, v2 defines per-slot conventions:

| Slot | gridColumns | Width constraint |
|---|---|---|
| `body` (dashboard, index, detail, form, wiki, map) | 12 | `1 ≤ gridWidth ≤ 12` |
| `sidebar` | 1 | `gridWidth` SHALL be exactly 1; `gridHeight` is intrinsic |
| `header-actions` | 12 | `gridY` SHALL be 0; `gridWidth` is button span |
| `footer` | 12 | `1 ≤ gridWidth ≤ 12` |
| `modal` | 12 | When a modal hosts widgets internally |
| `tab:<id>` | 12 | Same semantics as `body` |
| `section:<id>` | 12 | Same semantics as `body` |

#### 3. Five-kind unified component registry

Apps SHALL declare custom components in a single `registry` prop on `CnAppRoot`, replacing today's separate `customComponents` (page components) and integration-widget registries. Each registration declares a `kind`:

| kind | Purpose | Required extra fields |
|---|---|---|
| `widget` | Placeable in any allowed slot via grid coords | `defaultSize`, `minSize`, `maxSize`, `allowedSlots`, `propsSchema` |
| `modal` | Opened by action reference; not gridded externally | `propsSchema` |
| `page` | Full-page custom (escape hatch; goal: near-zero) | *(none beyond `component`)* |
| `form-field` | Custom property editor | `appliesTo.format` or `appliesTo.property` |
| `cell-renderer` | Custom table-cell rendering | `appliesTo.schema` + `appliesTo.property` |

The registry is a single map: `{ "<key>": { kind, component, ...metadata } }`. `CnAppRoot` validates each entry against its kind's schema at initialisation.

#### 4. Custom widgets are first-class

Apps reference both built-in and custom widgets from the manifest by registry key. There is no syntactic distinction between built-in and custom at the manifest level — both are referenced via `widgetKey`. A built-in like `object-table` and a custom like `case-timeline` are placed identically in the manifest.

#### 5. Actions: unified `actions[]` with `type` discriminator

V2 unifies v1.3.0's `actions[].handler` dispatch (PR #174) with the new declarative modal/page/navigate dispatch under a single `actions[]` array. Each action declares a `type`:

- `type: "handler"` — dispatches to a consumer-provided function (v1.3.0 behavior; carries forward unchanged)
- `type: "open-modal"` — opens a modal registered with `kind: "modal"`, passing optional `props`
- `type: "open-page"` — navigates to another manifest page by `id`
- `type: "navigate"` — navigates to an arbitrary URL or route

Action declaration shape:

```json
{
  "id": "archive",
  "label": "Archive",
  "type": "open-modal",
  "target": "confirm-archive",
  "props": { "title": "Archive case?" }
}
```

V1.3.0 `actions[].handler` continues to work — it's the subset where `type` is omitted (interpreted as `"handler"`). V2 codifies the union; the codemod adds `type` explicitly where it was implicit.

#### 6. Index pages gain widget support

V1.3.0 index pages have `columns[]` + optional `cardComponent`. V2 treats the object table as a built-in widget (`type: "object-table"`) placed in the body slot at full width by default. Custom widgets may appear alongside — above the table for filters/KPIs, below for footers, or in the sidebar (which v1.3.0 already supports via `index.config.sidebar`). `cardComponent` continues to work via a sibling built-in widget (`type: "card-grid"`); the codemod migrates `cardComponent` into a `card-grid` widget entry.

#### 7. `type: "custom"` shrinks to a rare escape hatch

V1.3.0 saw `type: "custom"` proliferate as the path of least resistance for any page that didn't fit the built-in types (pipelinq's 29 custom pages, opencatalogi's 15 awaiting library features). V2's expanded primitives (widgets-everywhere, the five-kind registry, unified `actions[]`) close most legitimate uses. The migration goal is to convert the majority of customs to typed pages (`dashboard | index | detail | form | wiki | map`) with a mix of built-in and custom widgets.

Where a page genuinely cannot decompose (e.g. a wholly bespoke surface that doesn't fit any built-in page type), `type: "custom"` remains valid — but the manifest entry MUST include a `_note` field documenting why decomposition was not feasible. The hydra gate (`gate-manifest-validates`, see companion spec) flags undocumented customs.

#### 8. Runtime-manifest API contract

Apps MAY resolve their manifest from `GET /api/manifest/{appId}` at runtime instead of bundling. The response is a valid v2 manifest JSON object. The loader (`useRuntimeManifest`) replaces the bundled stub entirely; it does not merge. Empty / 404 response falls back to the bundled stub if present.

This is the supported pattern for **mydash** (per-user dashboards stored in OpenRegister, no bundled home page) and for future **OpenBuilt**-built apps where the manifest IS the user-edited artifact. V2 schema is identical for bundled and runtime; only the loader differs.

#### 9. V1 deprecation timeline

| Phase | Library version | Behavior |
|---|---|---|
| V2 launch | nc-vue 2.x (current) | V1 valid; no warning unless `$schema` absent |
| Deprecation warning | nc-vue 2.x ≥ next minor after this ADR merges | V1 manifests warn once per load: "v1 — upgrade to v2 before nc-vue 3.0" |
| V1 removed | nc-vue 3.0 (target 2026-Q3) | V1 manifests throw `ManifestSchemaError` |

V1.3.0's `dataSource`, `@resolve:`, `cardComponent`, `tabs[]`, dynamic menus, named-view sidebar, and all eleven page types carry forward into v2 with identical semantics.

#### 10. Codemod CLI

`@conduction/manifest-migrate` handles bulk v1 → v2 mechanical conversion. Invoked as:

```
npx @conduction/manifest-migrate --input src/manifest.json --output src/manifest.json
```

Transformations:
- Merge `pages[*].widgets[]` + `pages[*].layout[]` → unified `widgets[]` with `gridX/gridY/gridWidth/gridHeight` on each entry
- Lift `pages[*].sidebarTabs[*].widgets[]` → `widgets[]` with `slot: "sidebar"` + `tabGroup: "<tabId>"`
- Flatten `pages[*].config.sections[*].widgets[]` (settings) → `widgets[]` with `slot: "section:<id>"`
- Flatten `pages[*].config.tabs[*]` (settings) → `widgets[]` with `slot: "tab:<id>"`
- Convert trivially-shaped `type: "custom"` (component-ref only) → `kind: "page"` registry entry + retain `type: "custom"` with `_note: "auto-converted"` until consumer chooses a typed migration
- Flag non-trivial customs with TODO comments and a per-page report
- Migrate `cardComponent` (index) → `type: "card-grid"` widget entry
- Migrate `actions[].handler` (v1.3.0) → `actions[]` with `type: "handler"` made explicit
- Carry forward `dataSource`, `@resolve:` sentinels, `tabs[]` (when used outside settings layout), `menu[].dynamicSource`, named-view sidebar — unchanged
- Insert `$schema` field pointing to v2 schema URL
- Output passes ajv validation against v2 schema; codemod exits non-zero if any input fails to convert cleanly

Expected manual touch-up rate: 30-50% of `type: "custom"` pages need follow-up attention (the cases the codemod cannot mechanically decompose).

### Mapping ADR-024's "Lib v2 backlog" to v2 decisions

ADR-024's "Lib v2 backlog" lists twelve items as anticipated v2 work. Each maps to its v2 home:

| ADR-024 backlog item | V2 home | Treatment |
|---|---|---|
| `actions[]` dispatch to consumer modal/dialog handlers | Decision 5 | `type: "open-modal"` + `target` resolved via registry |
| `form-builder` page type / widget | Decisions 1 + 6 | `type: "form"` (already in 1.3.0 PR #172) with built-in `form-renderer` widget; v2 adds widget composition around it |
| `wiki` page type | Carried forward (v1.3.0 PR #181) | No change |
| `chart` widget primitive | Carried forward (v1.3.0 PR #175) | No change; consumed via unified `widgets[]` |
| Map widget (Leaflet / WMS / WFS) | Carried forward (v1.3.0 PR #184) | `type: "map"` page or `type: "map-viewer"` widget |
| `cardComponent` config on `type: "index"` | Decision 6 | Becomes `type: "card-grid"` widget; codemod handles |
| `routing-rules` widget | Decision 4 (registry) | Custom `kind: "widget"` registration |
| Multi-tab settings orchestration | Decisions 1 + 2 | Top-level `widgets[]` with `slot: "tab:<id>"` |
| Custom-component slot inside `widgets[]` | Decision 4 | Built into the registry: any `kind: "widget"` is referenceable |
| Named-view sidebar (router-named-view pattern) | Carried forward (v1.3.0 PR #182) | No change; sidebar slot now accepts widgets uniformly |
| Dynamic per-tenant menu entries | Carried forward (v1.3.0 PR #178) | No change |
| Backend `/api/manifest` endpoint implementation | Decision 8 | First-class loader path; `useRuntimeManifest` for full-runtime apps like mydash |

V2 subsumes or carries forward every item in the backlog. No further library work is required after `manifest-v2-library` lands.

## Consequences

### Positive

- Single conceptual model for UI extension across all page types. OpenBuilt can introspect and modify any app's UI through one contract.
- Tooling parity: `@conduction/manifest-migrate` handles 50-70% of fleet migration mechanically; remaining work is per-page audit.
- Mydash and future OpenBuilt-built apps gain a supported runtime-manifest loader without forking the schema.
- The five-kind registry collapses three existing extension surfaces (`customComponents`, integration widgets, ad-hoc event-bus modals) into one declarable, OpenBuilt-friendly registry.
- ADR-024's "Lib v2 backlog" is fully retired in one cut.

### Negative

- Per-app migration churn: ~15 apps with 10-50 page entries each = manual review of 150-750 entries across the fleet. Mitigated by codemod.
- 30-50% of `type: "custom"` pages need manual touch-up. Mitigated by per-page `_note` requirement + per-app opsx changes scoped to migration.
- nc-vue ships a v1 + v2 dual validator for a release window (nc-vue 2.x → 3.0, target ~6 months). Slightly increased library surface for that window.
- Apps that don't migrate before nc-vue 3.0 break. Mitigated by deprecation warnings in nc-vue 2.x post-merge and by the hydra gate surfacing non-v2 manifests as warnings.

### Migration

1. **This change** (hydra): ADR-036 + cross-app spec + hydra gate spec. No code. Merges first.
2. **`manifest-v2-library`** (nextcloud-vue, targets `beta` branch): v2 JSON schema, dual validator, codemod CLI, `useRuntimeManifest`, updated `CnAppRoot` `registry` prop. Existing v1 manifests continue to validate (no breaking change).
3. **`scaffold-v2`** (nextcloud-app-template): updated scaffold ships v2 manifest with example registrations for each kind.
4. **Reference migrations** (parallel): **procest** (typed pages migration) and **mydash** (runtime-manifest migration). Procest validates the codemod against a moderately-complex v1 manifest; mydash proves the runtime path.
5. **Fleet rollout** via `opsx-pipeline`: per-app opsx changes apply the codemod, address TODO comments, register custom widgets/modals, and shrink `type: "custom"` count toward zero.
6. **Single-widget dashboard sweep** (per-app, driven by `gate-manifest-validates`): the gate flags pipelinq's six known instances (pipelinq#521) plus any others lurking in the fleet; each app converts to Alternative A (`type: "custom"` with `kind: "page"` registry entry) or Alternative B (split into N>1 real widgets) per Decision 1.
7. **nc-vue 3.0** (target 2026-Q3): removes v1 support.

## See also

- **ADR-018** (Widget Header Actions) — `header-actions` slot carries forward as a canonical v2 slot
- **ADR-019** (Integration Registry) — integration widgets are expressed as `kind: "widget"` registry entries
- **ADR-024** (App Manifest) — Superseded by this ADR; v1 manifests valid until nc-vue 3.0
- **ADR-031** (Schema-Declarative Business Logic) — orthogonal; v2 is UI declarative, ADR-031 is OR object behavior declarative
- **ADR-032** (Spec Sizing and Chaining) — this ADR is `kind: config` and the head of a chain (`manifest-v2-library` → `scaffold-v2` → `procest-manifest-v2` + `mydash-runtime-manifest` → fleet rollout)
- `hydra/openspec/changes/adopt-app-manifest/` — still-active v1 fleet adoption change; its target shifts from "v1 everywhere" to "v1 everywhere, then v2 everywhere" once v2 library ships

## Alternatives considered

1. **Keep v1; add the missing primitives additively (continue the v1.3.0 pattern).** Rejected for the unified widgets array specifically: every additive primitive perpetuates the per-page-type widget shape fragmentation. Closing the four-shape divergence requires a schema break.
2. **Reuse v1's `customComponents` registry for everything.** Rejected — `customComponents` was page-component-only; expanding it to cover modals / form-fields / cell-renderers requires breaking the prop shape, which IS a v2 cut.
3. **Split `widgets[]` and `layout[]` per page type (status quo).** Rejected — that's v1; the whole motivation is to remove the per-page shape fragmentation.
4. **Drop runtime manifests; require all apps to bundle.** Rejected — mydash and OpenBuilt-built apps fundamentally cannot bundle because the manifest is user-state.
5. **Land v2 as ADR-024 amendments rather than a new ADR.** Rejected — v2 is a schema break (the unified widget array is not back-compat at the JSON level). A separate ADR + Superseded marker on 024 makes the cut explicit and trackable.
