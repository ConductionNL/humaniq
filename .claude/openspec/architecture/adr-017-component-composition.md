# ADR-017: Component Composition Rules

## Status
Accepted

## Date
2026-04-14

## Context

Conduction apps share a Vue component library (`@conduction/nextcloud-vue`) that provides self-contained, higher-level components like `CnObjectDataWidget`, `CnStatsPanel`, `CnDetailPage`, and `CnTimelineStages`. These components internally render their own card wrappers (`CnDetailCard`), headers, and layout containers.

Developers have been wrapping these self-contained components inside additional layout containers (e.g. `CnDetailCard` wrapping `CnObjectDataWidget`), producing a "card-in-card" visual artifact where headers and borders are doubled. This was found across Procest, Pipelinq, and earlier OpenCatalogi iterations.

The same principle applies to `CnDetailPage` which renders its own `NcAppContent` wrapper — apps must not add another `NcAppContent` around it.

## Decision

### Self-contained components render their own container

The following components are **self-contained** and MUST NOT be wrapped in `CnDetailCard`, `NcAppContent`, or other layout containers:

| Component | Renders its own | Use directly inside |
|---|---|---|
| `CnObjectDataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnObjectMetadataWidget` | `CnDetailCard` | `CnDetailPage` slot, `<div>`, or grid cell |
| `CnStatsPanel` | Sections with headers | `CnDetailPage` slot or `<div>` |
| `CnDetailPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnDashboardPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnIndexPage` | `NcAppContent`-level layout | Directly in `<router-view>` |
| `CnTimelineStages` | Standalone timeline | Inside `CnDetailCard` or any container (no own card) |

### How to identify self-contained components

A component is self-contained if its template root is a card, panel, or page-level wrapper. Check the component source: if it starts with `<CnDetailCard>`, `<div class="cn-*-card">`, or similar, it manages its own container.

### Correct patterns

```vue
<!-- CORRECT: CnObjectDataWidget renders its own card -->
<CnObjectDataWidget
  :schema="schema"
  :object-data="data"
  title="Case Information" />

<!-- CORRECT: CnTimelineStages is NOT self-contained, wrap it -->
<CnDetailCard :title="t('app', 'Status')">
  <CnTimelineStages :stages="stages" :current-stage="current" />
</CnDetailCard>
```

### Anti-patterns

```vue
<!-- WRONG: Double card wrapping -->
<CnDetailCard :title="t('app', 'Case Information')">
  <CnObjectDataWidget :schema="schema" :object-data="data" />
</CnDetailCard>

<!-- WRONG: Double page wrapping -->
<NcAppContent>
  <CnDetailPage :title="title">...</CnDetailPage>
</NcAppContent>
```

#### Dashboard-as-widget-of-dashboard

A `pageType: "dashboard"` route in an app's `manifest.json` MUST NOT load a widget whose body component itself renders `<CnDashboardPage>`. The manifest renderer already wraps the route in `<CnDashboardPage>`; a nested instance produces stacked dashboard headers, doubled page chrome, and an `objectSidebarState` injection collision (two `CnDashboardPage` instances `provide` the same key).

```json
// WRONG — manifest.json fragment
{
  "routes": [
    {
      "path": "/dashboard",
      "pageType": "dashboard",
      "widgets": [
        {
          "type": "custom",
          "size": { "w": 12, "h": 12 },
          "body": { "component": "DashboardView" }
        }
      ]
    }
  ]
}
```

```vue
<!-- WRONG — DashboardView.vue body imported by the manifest widget above -->
<template>
  <CnDashboardPage :title="t('app', 'Dashboard')">
    <!-- ... -->
  </CnDashboardPage>
</template>
```

Rendering result: `CnDashboardPage` (manifest) → `CnWidgetWrapper` → `DashboardView` → `CnDashboardPage` (nested). Visible as three nested "Dashboard" headings on every load.

##### Correct alternatives

Two valid shapes. Pick (1) when the page is a single bespoke component; pick (2) when it is genuinely a grid of distinct widgets.

```json
// CORRECT (1) — route type "custom" lets the component own its own page chrome
{
  "path": "/dashboard",
  "pageType": "custom",
  "component": "DashboardView"
}
```

```json
// CORRECT (2) — declarative multi-widget dashboard, no custom wrapper
{
  "path": "/dashboard",
  "pageType": "dashboard",
  "widgets": [
    { "type": "stats-block", "size": { "w": 4, "h": 2 }, "dataSource": { "register": "...", "schema": "...", "aggregate": "count" } },
    { "type": "chart",       "size": { "w": 8, "h": 4 }, "dataSource": { "graphql": { "query": "...", "selectors": { "x": "...", "y": "..." } } } },
    { "type": "object-list", "size": { "w": 12, "h": 6 }, "dataSource": { "register": "...", "schema": "..." } }
  ]
}
```

In shape (1) the imported `DashboardView` continues to render `<CnDashboardPage>` as its template root — that is correct because the manifest no longer wraps it. In shape (2) the manifest is the sole owner of the page chrome and each widget is a small declarative cell with no `CnDashboardPage` nesting.

Cross-references: ConductionNL/hydra#318 (companion change adding the same rule to `manifest.schema.json`); ConductionNL/pipelinq#521 (evidence trail — six pipelinq routes exhibit this pattern).

### External sidebar pattern

Components like `CnDetailPage` that support sidebars communicate with a parent-provided `objectSidebarState` via Vue's `provide`/`inject`. The sidebar component (`CnObjectSidebar`) MUST be rendered at the `NcContent` level in `App.vue`, NOT inside `NcAppContent`:

```vue
<!-- App.vue -->
<NcContent app-name="myapp">
  <MainMenu />
  <NcAppContent>
    <router-view />
  </NcAppContent>
  <CnObjectSidebar v-if="objectSidebarState.active" ... />
</NcContent>
```

## Consequences

- Developers must check if a shared component is self-contained before wrapping it
- The component library documents which components are self-contained in their JSDoc headers
- Code reviews should flag card-in-card nesting as a pattern violation
- Existing violations should be fixed when encountered (per ADR-015 pre-existing issues rule)
