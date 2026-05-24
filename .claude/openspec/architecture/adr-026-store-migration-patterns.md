# ADR-026: Store Migration Patterns (consumer apps onto `createObjectStore`)

## Status
Accepted

## Date
2026-05-10

## Context

Apps that hand-roll their own Pinia stores around OpenRegister-shaped
data inevitably drift from the lib's `useObjectStore` /
`createObjectStore` API surface as the lib evolves. Decidesk #162
(2026-05-09) was the canonical breakage: consumer code called
`store.fetchObject(id)` against an in-app store that had a custom
fetcher with a different signature, the lib's manifest renderer was
wired to expect the canonical action, and the page rendered with
`fetchObject is not a function` at runtime. The migration's blast
radius is the entire consumer surface — every detail page, every
sidebar tab that runs an OR fetch, every modal that calls
`saveObject`.

`@conduction/nextcloud-vue` now ships a stable factory + plugin
pattern (`createObjectStore('<name>', { plugins: [...] })`) backed by
documented actions (`fetchCollection`, `fetchObject`, `saveObject`,
`uploadFiles`, etc.). Three migration shapes have surfaced across the
fleet, each appropriate to a different starting point. This ADR
codifies them so consumer apps don't have to rediscover the trade-offs
when they start their migration.

## Decision

**Every Conduction app SHOULD route OR-shaped data through
`createObjectStore` from `@conduction/nextcloud-vue/store`.** Apps
choose one of three sanctioned migration patterns based on their
existing store shape:

### Pattern 1 — Drop (decidesk reference)

Replace the custom store entirely with the lib factory. Use when
nothing outside the app's own source imports the store by name.

```js
// store/objectStore.js
import { createObjectStore } from '@conduction/nextcloud-vue/store'

export const useObjectStore = createObjectStore('decidesk-objects', {
  plugins: [filesPlugin, auditTrailsPlugin, lifecyclePlugin],
})
```

Consumer code calls `useObjectStore().fetchObject(id)`,
`saveObject(payload)`, etc. — the actions surface is now the lib's
contract, not the app's.

PR ref: decidesk #163.

### Pattern 2 — Thin-wrap (opencatalogi reference)

Keep an outer Pinia `defineStore('object')` facade so existing imports
(`import { useObjectStore } from '@/store/object'`) keep working;
construct the lib factory **inside** the facade and re-expose its
actions.

```js
// store/object.js
import { defineStore } from 'pinia'
import { createObjectStore } from '@conduction/nextcloud-vue/store'

const inner = createObjectStore('opencatalogi-inner', {
  plugins: [
    filesPlugin,
    auditTrailsPlugin,
    relationsPlugin,
    lifecyclePlugin,
    selectionPlugin,
    liveUpdatesPlugin,
  ],
})

export const useObjectStore = defineStore('object', {
  // re-export inner state + actions
  state: () => inner().$state,
  actions: { /* fetchCollection, fetchObject, saveObject, ... */ },
})
```

Use when the app's UI consumers depend on a specific store ID/shape
that you can't migrate without breaking dozens of import paths in a
single PR. The facade buys time; later PRs can collapse it.

PR ref: opencatalogi #548.

### Pattern 3 — Hybrid (zaakafhandelapp reference)

Register the lib `useObjectStore` for OR-shaped types side-by-side
with legacy controller-backed stores for non-OR types. Use when the
app has dual data layers — e.g. a Nextcloud app with PHP controllers
serving `zaak` / `taak` / `besluit` data not yet migrated to OR — and
both layers need to coexist for the foreseeable future.

```js
// store/store.js
import { useObjectStore } from '@conduction/nextcloud-vue/store'   // OR-shaped
import { useZaakStore } from './zaakStore'                         // PHP controller-backed
import { useTaakStore } from './taakStore'                         // PHP controller-backed

// Manifest pages with OR registers/schemas resolve through useObjectStore.
// Manifest pages with type:'custom' + dataSource:'controller' resolve through legacy.
```

The lib half is the long-term destination; the legacy half phases out
register-by-register as data migrates into OR.

PR ref: zaakafhandelapp #190.

## Plugin catalogue

Every pattern picks plugins from the same catalogue. Consumer apps
include only the plugins they actually use — there's no penalty for
omitting `liveUpdatesPlugin` if the app doesn't use websocket
notifications.

| Plugin | What it adds |
|---|---|
| `filesPlugin` | File relations (read/write to NC Files attached to objects) |
| `auditTrailsPlugin` | Append-only audit trail per object (hash-chained when OR's audit is configured) |
| `relationsPlugin` | Cross-register relation resolution (`fetchUsed`, `fetchUses`) |
| `lifecyclePlugin` | Soft-delete, restore, status transitions; couples with OR's archival workflow |
| `selectionPlugin` | Multi-select state for index views; powers `CnMassActionBar` |
| `liveUpdatesPlugin` | Websocket subscription via `@nextcloud/notify_push`; auto-refreshes collections on remote mutation |
| `searchPlugin` | Magic-table search dispatch (per `search-architecture.md`) |
| `registerMappingPlugin` | IAppConfig-driven register/schema slug resolution; consumer of the `@resolve:` sentinel |

## Picking the right pattern

Decision tree:

1. **Does anything outside this app's source import the store by name?**
   - No → **Drop** (Pattern 1).
   - Yes → **Thin-wrap** (Pattern 2).
2. **Does this app have non-OR backends (PHP controllers, external
   APIs, GraphQL gateways)?**
   - Yes → **Hybrid** (Pattern 3) for the non-OR half. The OR half
     uses Drop or Thin-wrap per question 1.
   - No → already covered by Drop / Thin-wrap.

When in doubt, start with Thin-wrap. It's the highest-cost pattern at
write time but the lowest blast radius at migration time, and a later
PR can always collapse the facade.

## Consequences

- Apps lock their consumer surface to the lib's actions (`fetchCollection`,
  `fetchObject`, `saveObject`, `uploadFiles`, `deleteObject`,
  `restoreObject`, `selectObject`, etc.). The decidesk #162 class of bug
  becomes impossible: the actions are TypeScript-typed and runtime-checked
  by the factory.
- Plugin opt-in is per-app; no app pays for plugins it doesn't use.
- **Cost:** every app must coordinate with lib version bumps. When the
  lib renames an action or changes a signature, every consumer using
  that action breaks at the same time. Mitigation: lib changes go
  through the openspec process and ship changelog entries; the
  fleet-wide rollout pattern from ADR-024 / nextcloud-vue release notes
  applies here too.
- The Hybrid pattern is explicitly transitional. Apps adopting it MUST
  list a migration milestone in their per-app openspec — "phase out
  controller-backed `taakStore` once `taken` register lands in OR" — so
  the legacy half doesn't calcify.
- This ADR governs **OR-shaped data**. App-local UI state (active
  modal, sidebar visibility, etc.) stays in plain `defineStore` Pinia
  — the factory pattern is for OR data only.

## See also

- ADR-004 (Frontend) — the `createObjectStore` recipe is part of the
  app construction patterns.
- ADR-022 (Apps consume OR abstractions) — this ADR is the FE-store
  side of that principle.
- ADR-024 (App manifest) — the manifest renderer expects
  `useObjectStore` actions on every consumer; this ADR is what makes
  that contract reliable.
- `decidesk/openspec/changes/decidesk-store-migration-drop/` —
  Pattern 1 reference (PR #163).
- `opencatalogi/openspec/changes/opencatalogi-store-migration-thin-wrap/` —
  Pattern 2 reference (PR #548).
- `zaakafhandelapp/openspec/changes/zaakafhandelapp-store-migration-hybrid/` —
  Pattern 3 reference (PR #190).
