# ADR-024: App Manifest (fleet-wide adoption)

## Status
Superseded by [ADR-036](adr-036-universal-widget-manifest.md) (Universal Widget Manifest, 2026-05-19). V1 manifests remain valid until `@conduction/nextcloud-vue` 3.0 (target 2026-Q3) per the v2 deprecation timeline.

Original status: Accepted (2026-05-10 — decidesk + softwarecatalog + zaakafhandelapp + procest + pipelinq live; opencatalogi awaiting review)

## Date
2026-05-03 (reviewed 2026-05-09; refreshed 2026-05-10 for beta.13 → beta.26 wave)

## Context

`@conduction/nextcloud-vue@1.0.0-beta.12` ships the manifest renderer
end-to-end: schema **v1.2.0**, loader (`useAppManifest`), validator
(`validateManifest`), `CnAppRoot` / `CnAppNav` / `CnPageRenderer`
components, eight built-in page types, four-tier adoption guide.
Decidesk merged its Tier-4 migration (PR #160, 2026-05-09 — 20 pages, manifest content
version 1.0) and serves as the canonical reference. Six other apps
have follow-up PRs in flight (procest #320, pipelinq #330, zaakafhandelapp
#189, softwarecatalog #218, opencatalogi #547, nextcloud-app-template #27).
The lib-side has six merged change capabilities:
`add-json-manifest-renderer`, `manifest-page-type-extensions`,
`manifest-abstract-sidebar`, `manifest-schema-config-defs`,
`manifest-settings-rich-sections`, `manifest-detail-sidebar-config`,
`manifest-config-refs`.

Without a fleet-wide convention, the manifest stays a one-off:

- New apps re-roll their own router config + sidebar + dependency-check
  + page dispatch logic instead of consuming `CnAppRoot`.
- Cross-app admin UIs ("App Builder" — admin tweaks menu order, hides
  pages, overrides locale) have nothing to plug into per-app.
- Consumer apps that *want* the renderer don't know which Tier to start
  at, where the manifest file lives, or what the validation contract is.
- Filename / location drift will set in (every app picks its own path)
  unless the convention is pinned.

This ADR codifies the convention; the renderer itself stays governed by
`add-json-manifest-renderer` in nextcloud-vue.

## Decision

**Every Conduction app SHOULD ship a `src/manifest.json` validated
against the canonical schema. New apps MUST adopt at least Tier 1 from
inception.**

Specifically:

1. **Schema source** — the canonical schema is
   `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`.
   Apps MUST set `$schema` to the published URL of this file (for
   editor auto-validation); they MUST NOT fork or duplicate the schema.
2. **Location** — `src/manifest.json` (next to `main.js` /
   `App.vue`). Bundled into the build; served by webpack as a static
   import.
3. **Loader contract** — every consumer's `main.js` MUST
   `import bundled from './manifest.json'` and pass it to
   `useAppManifest(appId, bundled)`. The bundled-only path is
   CSP-clean; the backend-merge hook is opt-in.
4. **Backend endpoint convention** — apps MAY implement
   `GET /index.php/apps/{appId}/api/manifest` returning a partial
   override blob (admin-customised menu order, hidden pages, locale
   overrides). Apps that don't yet implement it return 404; the loader
   silently falls back. Response shape is a partial of the canonical
   schema (top-level `additionalProperties: false`).
5. **Validation gate** — every consumer MUST add
   `npm run check:manifest` to its `package.json` scripts (calls
   `validateManifest` from the library at build time); CI fails on
   schema errors. Mirror the pattern of nextcloud-vue's `check:docs`.
6. **i18n** — `label` and `title` are translation keys consumed by the
   app's own `t()` function; the manifest itself ships keys, not
   strings. Aligns with ADR-007 and the i18n-* shared specs.
7. **Versioning** — `manifest.version` follows semver of content; the
   library-side schema version is in the schema's `"version"` field.
   Apps set `manifest.version` to `0.x.y` while iterating; bump to
   `1.0.0` when the manifest stabilises.
8. **Tier choice** — adoption is tiered (1 = `useAppManifest` only;
   2 = + `CnPageRenderer`; 3 = + `CnAppNav`; 4 = full `CnAppRoot`
   shell). Each app picks its own Tier and may upgrade incrementally.
9. **Per-app adoption** — each app gets its own openspec change
   referencing this ADR. The recommended name is `{app}-manifest-v1`
   (used by decidesk + the six fleet follow-ups); legacy
   `{app}-adopt-manifest` is also accepted for back-compat with earlier
   drafts. The change MUST include: (1) generated `src/manifest.json`
   from the existing router, (2) an explicit Tier choice, (3) a
   regression test confirming all routes still resolve (typically
   `tests/validate-manifest.js` running Ajv against the lib's schema),
   (4) reviewer sign-off that the manifest does not duplicate or
   contradict the canonical schema. Pre-production apps may admin-merge
   per project convention; production apps (OpenRegister, OpenCatalogi,
   OpenConnector, DocuDesk, nextcloud-vue, hydra) await human review.
10. **Apps that should NOT depend on OpenRegister** — mydash and
    nldesign MUST NOT list `openregister` in `manifest.dependencies`.
    Per `feedback_mydash-no-or-dependency.md`, mydash is a BI surface
    that talks to OR via runtime GraphQL only; nldesign is a theme
    layer. Other apps SHOULD list every cross-app dependency the user
    needs installed for the app to function.

## Consequences

- The `CnAppRoot` shell becomes the default UI shell across the fleet;
  per-app router boilerplate shrinks toward zero.
- Cross-app admin tooling ("App Builder", `/api/manifest` consumers,
  manifest-aware audits) has a stable contract to target.
- Reviewers gain a fleet-wide gate: a PR adding routes that aren't
  reflected in `src/manifest.json` is treated as drift. (Pairs with
  ADR-029 route-reachability gate.)
- Migration order recommendation (cheapest → highest-value):
  `mydash` → `softwarecatalog` → `procest` → `pipelinq` →
  `zaakafhandelapp` → `opencatalogi` → `openregister` → remaining apps.
  **Larpingapp is out of scope** for the manifest pattern — it's a
  server-side PHP app with PHP templates, no Vue Router, no SPA shell.
  Decidesk is the merged reference (PR #160).
- App-manifest extensions (e.g. `theme: { primary, accent, logoUrl }`,
  `roles[]`) are out of scope for v1; revisit in a successor ADR if
  patterns surface during adoption.
- The `type` enum is closed at eleven built-ins as of schema v1.3.0:
  `index | detail | dashboard | logs | settings | chat | files | form | wiki | map | custom`.
  New built-in types require a library-level openspec change in
  nextcloud-vue, not an app-side override. Apps register custom page
  types via the `customComponents` prop on `CnAppRoot`.

  | Type | Purpose | Lib reference |
  |---|---|---|
  | `index` | Schema-driven list view; OR collection backend | `add-json-manifest-renderer` |
  | `detail` | Schema-driven detail+sidebar view; OR object backend | `add-json-manifest-renderer` |
  | `dashboard` | GridStack widget canvas with `widgets[]` config | `add-json-manifest-renderer` |
  | `logs` | Audit/system log viewer (register+schema or external `source`) | `manifest-page-type-extensions` |
  | `settings` | Tabbed/sectioned settings page (in-app, never `/settings` route) | `manifest-page-type-extensions`, `manifest-settings-rich-sections` |
  | `chat` | Conversation surface with `conversationSource` or `postUrl` | `manifest-page-type-extensions` |
  | `files` | NC Files folder browser scoped to a folder path | `manifest-page-type-extensions` |
  | `form` | Standalone form page; submit dispatches via handler-mode or endpoint-mode | nextcloud-vue PR #172 |
  | `wiki` | Markdown / structured-content surface for kennisbank-style apps | nextcloud-vue PR #181 |
  | `map` | Leaflet + markercluster primitive for geo data | nextcloud-vue PR #184 |
  | `custom` | Escape hatch via `customComponents` prop | `add-json-manifest-renderer` |

## Adoption status (2026-05-10)

| App | PR | State | Pages | Notes |
|---|---|---|---|---|
| decidesk | [#160](https://github.com/ConductionNL/decidesk/pull/160) | ✅ Merged Tier 4 | 20 (8 index, 9 detail, 1 dashboard, 1 settings, 1 custom) | Reference Tier 4; canonical store-migration **drop** pattern (#163) |
| pipelinq | [#330](https://github.com/ConductionNL/pipelinq/pull/330) | ✅ Pure manifest | 47 (9 index, 9 detail, 29 custom) | Largest surface; lib-gap customs collapsing as `form` / `wiki` types land |
| procest | [#320](https://github.com/ConductionNL/procest/pull/320) | ✅ Pure routes | 17 | App.vue still has a custom OR-availability guard pending lib feature |
| zaakafhandelapp | [#189](https://github.com/ConductionNL/zaakafhandelapp/pull/189), [#190](https://github.com/ConductionNL/zaakafhandelapp/pull/190), [#191](https://github.com/ConductionNL/zaakafhandelapp/pull/191) | ✅ Pure manifest router | 28 | Hybrid store pattern (#190); legacy `router.ts` cleanup #191 |
| softwarecatalog | [#218](https://github.com/ConductionNL/softwarecatalog/pull/218) | ✅ Pure manifest | 15 | First `@resolve:voorzieningen_register` sentinel consumer |
| opencatalogi | [#547](https://github.com/ConductionNL/opencatalogi/pull/547), [#548](https://github.com/ConductionNL/opencatalogi/pull/548) | 🟡 Awaiting review | 16 | Manifest PR #547 + store-migration **thin-wrap** PR #548 |
| mydash | — | 🟡 Opt-out of OR-guard | — | No OR dependency by design; manifest WILL omit `openregister` from `dependencies[]` (see app-boundaries memory) |
| nextcloud-app-template | [#27](https://github.com/ConductionNL/nextcloud-app-template/pull/27) | Open | 4 | Canonical Tier-4 scaffold; `@nextcloud/router` ^3.1.0 floor |
| larpingapp | — | ❌ N/A | 0 | Server-side PHP; not a manifest candidate |
| openregister | — | Pending | — | Future migration |

## Build-time prerequisites

Migrating an app to manifest v1.x requires four supporting changes
beyond the manifest itself. Document these in each per-app
`{app}-manifest-v1` change so reviewers know what to expect:

1. **Webpack alias for `@nextcloud/axios`** — `@nextcloud/axios@2.5.x`
   ships only `dist/index.cjs` / `.mjs` (no `dist/index.js`). Webpack
   5's CommonJS resolver fails the exports-condition check when
   `@nextcloud/vue`'s CJS bundle calls `require('@nextcloud/axios')`.
   Add an exact-match alias in `webpack.config.js`:
   ```js
   webpackConfig.resolve.alias['@nextcloud/axios$'] =
     path.resolve(__dirname, 'node_modules/@nextcloud/axios/dist/index.cjs')
   ```
2. **`@nextcloud/router` minimum version `^3.1.0`** — `@conduction/nextcloud-vue@1.0.0-beta.12`
   transitively pulls `@nextcloud/vue@8.37`, which imports `getBaseUrl`
   from router 3.x. Apps still pinned to `^2.x` will fail to bundle.
3. **Mount-survivable bootstrap** — `loadTranslations()` from
   `@nextcloud/l10n` rejects with 404 when the dev container's Apache
   doesn't serve `/custom_apps/<app>/l10n/<locale>.json`. Wrap the call
   fire-and-forget; mount Vue unconditionally. Mirror the
   en_US.json file from en.json to silence the locale fetch entirely.
   See `decidesk/src/main.js` (commit `50e4df7c`) for the canonical
   pattern.
4. **Shallow-clone `defaultPageTypes` and `customComponents`** before
   passing as props to `CnAppRoot`. The lib's barrel exports were
   non-extensible until 1.0.0-beta.12 patched the rollup namespace
   freezes; cloning works on any beta. See `decidesk/src/main.js`
   (commit `866ff132`).

## Hybrid data layers

The manifest's `index` / `detail` types assume `register` + `schema`
slugs that resolve through `useObjectStore` against an OpenRegister
backend. Apps with legacy data layers — Pinia stores backed by in-app
PHP controllers, GraphQL gateways, or non-OR REST endpoints — cannot
use those types directly. Three options:

- **Migrate the data layer to OR first** (preferred long-term — see
  ADR-022 for the OR-abstraction rationale).
- **Stay `type: "custom"`** for affected pages until migration completes.
- **Use the `@resolve:` sentinel** when slugs are tenant-configured but
  the data still lives in OR. See
  `nextcloud-vue/openspec/changes/manifest-resolve-sentinel/`. Softwarecatalog
  is the first consumer (12 pages on `@resolve:voorzieningen_register`).

Zaakafhandelapp is the first known hybrid-layer app — its `register` /
`schema` slugs are validated by the manifest schema, but the actual
data fetch goes through the app's own controllers, not OR. The manifest
PR documents this as a runtime open question; full validation lands
when zaakafhandelapp completes its OR-adoption.

## Lib v2 backlog (surfaced during fleet rollout)

The 6 fleet PRs surfaced concrete lib gaps that, when closed, will
absorb most of the `type: "custom"` survivors. None of these block
v1.x adoption — they are queued for follow-up library work:

| Gap | Surfaced by | Effect |
|---|---|---|
| `actions[]` dispatch to consumer modal/dialog handlers | opencatalogi (15 routes blocked) | Largest unlocker; would migrate ~half of opencatalogi's customs |
| `form-builder` page type / widget | pipelinq forms + surveys | 7 pipelinq entries |
| `wiki` page type | pipelinq kennisbank | 5 pipelinq entries |
| `chart` widget primitive (apexcharts) | procest Doorlooptijd, opencatalogi Dashboard | 4+ entries across apps |
| Map widget (Leaflet / WMS / WFS) | procest CaseMapView | 1 entry; Dutch government use cases |
| `cardComponent` config on `type: "index"` | softwarecatalog Organisaties | Bespoke card-grid views |
| `routing-rules` widget | pipelinq queues | 2 pipelinq entries |
| Multi-tab settings orchestration | softwarecatalog (8-tab admin), procest AdminRoot | Settings rich-sections covers basic shape; complex orchestrated tabs need follow-up |
| Custom-component slot inside `widgets[]` | procest Settings/AdminRoot | WorkflowEditor + CaseTypeAdmin shape |
| Named-view sidebar (router-named-view pattern) | opencatalogi Search | Page-specific sidebar host |
| Dynamic per-tenant menu entries | opencatalogi (`v-for catalogus in catalogs`) | Drives backend `/api/manifest` adoption |
| Backend `/api/manifest` endpoint implementation | opencatalogi (per-tenant menu) | Currently opt-in stub; first real consumer requires it |

These 12 items are queued as future library openspec changes.

## Schema evolution

Schema bumps land in nextcloud-vue and apply fleet-wide once a release
ships. v1.x schema versions and what each adds:

| Schema version | Change | Adds |
|---|---|---|
| 1.0 | `add-json-manifest-renderer` | Initial schema: `pages[]` with `index`/`detail`/`dashboard`/`custom`, `menu[]`, `dependencies[]`. |
| 1.1 | `manifest-page-type-extensions` | `pages[].type` extended with `logs`/`settings`/`chat`/`files`. Per-type `config` shape rules (logs: register+schema or source; settings: `sections[]`; chat: conversationSource or postUrl; files: folder). |
| 1.1 (additive) | `manifest-abstract-sidebar` | `index.config.sidebar` for auto-mounting `CnIndexSidebar`. `detail.config.sidebar` Boolean→Object form. Open-enum `sidebarProps.tabs[]` on detail (`{id, label, icon?, widgets?, component?}`). |
| 1.1 (additive) | `manifest-schema-config-defs` | Seven `$defs`: `column`, `action`, `widgetDef`, `layoutItem`, `formField`, `sidebarSection`, `sidebarTab`. Documentation-grade until 1.2. |
| 1.1 (additive) | `manifest-settings-rich-sections` | `settings.config.sections[]` admits `body` of `fields[]` (back-compat), `component: <registry-name>`, OR `widgets: [{type, props?}]` with built-ins `version-info` → `CnVersionInfoCard` and `register-mapping` → `CnRegisterMapping`. |
| 1.1 (additive) | `manifest-detail-sidebar-config` | Per-page top-level `pages[].sidebar.show` (boolean, default true) gates host App's `#sidebar` slot via the `cnPageSidebarVisible` provide/inject channel — works on every page type including `type:"custom"`. `CnDetailPage.sidebar` accepts Boolean (legacy) OR Object. |
| 1.2 | `manifest-config-refs` | `config` blocks now `$ref` the seven `$defs`. `oneOf [string, $ref column]` on columns preserves the legacy shorthand. FE validator messages tighten correspondingly. |
| 1.3 (additive) | beta.13 → beta.26 wave (PRs #171–#184) | `index.config.cardComponent` (#171, REQ-MCI), `pages[].config.actions[].handler` dispatch (#174, REQ-MAD), `dashboard.config.widgets[].type:'chart'` primitive (#175), `settings.config.tabs[]` XOR `sections[]` (#173, REQ-MSO-1..5), `sections[].widgets[].type:'component' + componentName` (#173, REQ-MSO-6), dynamic per-tenant `menu[]` via backend merge / `dynamicSource` (#178), `@resolve:` IAppConfig sentinels under `pages[].config` (#179), `type:'form'` page (#172) with handler-mode + endpoint-mode submit, `type:'wiki'` (#181), `type:'map'` with leaflet+markercluster (#184), named-view sidebar (#182). |

The closed-enum extension (`logs`/`settings`/`chat`/`files`/`form`/`wiki`/`map`) is safe:
v1.0 manifests keep validating against v1.1+ schemas. Existing apps
adopt new types incrementally; the renderer logs a console warning
for unknown types rather than failing.

## Manifest extensions (beta.13 → beta.26 wave)

Today's nextcloud-vue release wave landed eleven additive extensions
that absorb the bulk of the lib-v2 backlog above. None break v1.x
manifests; consumer apps opt in feature-by-feature:

| Extension | Where it lives | Lib PR / spec |
|---|---|---|
| `pages[].config.cardComponent` on `type:'index'` | Bespoke card-grid via `customComponents`; replaces hand-rolled card pages | nextcloud-vue PR #171 (REQ-MCI) |
| `pages[].config.actions[].handler` | Action button dispatches to consumer-provided handler functions registered alongside `customComponents` | nextcloud-vue PR #174 (REQ-MAD) |
| `pages[].config.widgets[].type:'chart'` on dashboard | Chart widget primitive (apexcharts under the hood) | nextcloud-vue PR #175 |
| `pages[].config.tabs[]` on `type:'settings'` | XOR with `sections[]` — multi-tab settings orchestration | nextcloud-vue PR #173 (REQ-MSO-1..5) |
| `pages[].config.sections[].widgets[].type:'component'` + `componentName` | Custom-component slot inside settings widgets, resolved against `customComponents` | nextcloud-vue PR #173 (REQ-MSO-6) |
| `pages[].config.cardComponent` on `type:'index'` | Listed twice in REQ-MCI: bespoke card grid for index pages | nextcloud-vue PR #171 |
| Dynamic per-tenant `menu[]` via backend merge / `dynamicSource` | Backend `/api/manifest` overrides menu order; `dynamicSource` resolves entries from a register query at load time | nextcloud-vue PR #178 |
| `@resolve:` IAppConfig sentinels under `pages[].config` | Tenant-configured slugs resolved server-side via IAppConfig (extends the v1.2 `manifest-resolve-sentinel` to arbitrary config blocks) | nextcloud-vue PR #179 |
| `type:'form'` page | Standalone form with handler-mode (consumer dispatcher) or endpoint-mode (POST URL) submit | nextcloud-vue PR #172 |
| `type:'wiki'` page | Markdown / structured-content surface (pipelinq kennisbank reference) | nextcloud-vue PR #181 |
| `type:'map'` page | Leaflet + markercluster primitive (procest CaseMapView reference) | nextcloud-vue PR #184 |
| Named-view sidebar | Per-page sidebar host via router-named-view; opencatalogi search reference | nextcloud-vue PR #182 |
| `pages[].config.widgets[].dataSource` (stats-block + chart) | Manifest-driven data fetching via OR's GraphQL endpoint | nextcloud-vue PR #186 |

## Manifest data fetching (`dataSource` block)

Added 2026-05-10 with nextcloud-vue v1.0.0-beta.6 (PR #186). Widget definitions on `type: "dashboard"` pages MAY declare a `dataSource` block alongside (or replacing) static `props`. Two forms are supported:

### Shorthand — `{ register, schema, filter?, aggregate: "count" }`

The library builds `{ <schemaSlug>(filter: …) { totalCount } }` and POSTs it to `/index.php/apps/openregister/api/graphql`. `data.value` resolves to `{ count: <number> }`. Decorative: `register` is recorded for symmetry with index/detail pages but the GraphQL field name comes from the schema slug.

```json
{
  "id": "minutes-in-review",
  "type": "stats-block",
  "title": "Notulen ter goedkeuring",
  "props": { "countLabel": "notulen", "variant": "warning" },
  "dataSource": {
    "register": "decidesk",
    "schema": "minutes",
    "filter": { "lifecycle": "review" },
    "aggregate": "count"
  }
}
```

### Raw — `{ graphql: { query, variables?, selectors } }`

For aggregations the shorthand doesn't cover, the manifest carries the full GraphQL document plus a `selectors` map that picks values out of the response (dot-paths with optional `[]` array hops). Useful for chart series, breakdowns, or any future custom aggregate.

```json
"dataSource": {
  "graphql": {
    "query": "query { foo { count } }",
    "selectors": { "values": "foo[].count" }
  }
}
```

### Widget types that consume `dataSource`

- `type: "stats-block"` — reads `data.count` and forwards it to `CnStatsBlock`.
- `type: "chart"` — reads `data.{series, categories, labels}` and overrides the corresponding static props.

Future widget types (heatmap, table, KPI breakdown) SHOULD adopt the same contract so consumers only learn one data-binding pattern.

### Companion server-side work

The `Connection.totalCount` field exists today and powers the shorthand. Richer aggregates — `groupBy`, `sum`, `avg`, `min`, `max` on connection types — are deferred to [openregister #1455](https://github.com/ConductionNL/openregister/issues/1455). Until that lands, chart widgets needing groupBy semantics use the raw form with a hand-authored query.

The companion fix that exposed all org-scoped schemas via GraphQL (so consumer-app dashboards see their own data) is [openregister #1457](https://github.com/ConductionNL/openregister/pull/1457).

### Cross-references

- Lib spec: nextcloud-vue/openspec/changes/add-manifest-datasource (shipped as PR #186).
- Lib composables: [`useGraphQL`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/utilities/composables/use-graph-q-l.md), [`useDataSource`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/utilities/composables/use-data-source.md), `selectByPath`, `buildCountQuery`.
- Generated widgets: [`CnStatsBlockWidget`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/components/cn-stats-block-widget.md), [`CnChartWidget`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/components/cn-chart-widget.md).
- OR GraphQL endpoint: see `openregister/lib/Controller/GraphQLController.php` and the wire format in `docs/Integrations/OpenRegister.md`.

## Schema source

The canonical schema is
`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` —
**currently v1.3.0** (will roll forward as further features land on
the `beta` branch). Apps MUST set `$schema` to the published URL of
the active version and re-run `npm run check:manifest` after each lib
bump.

## Build-time vs runtime validation

`additionalProperties: false` is enforced by **Ajv at build time**
(BE / hydra CI gates on `npm run check:manifest`). The **FE
validator** stays narrow and tolerant of unknown keys to survive
backend version skew — when an app pinned to lib `beta.20` receives a
`/api/manifest` partial from a backend that already knows about
`beta.26` extensions, the renderer ignores unknown fields rather than
failing the page. The CI gate catches drift on the way in; the
runtime gate keeps tenants alive when versions skew apart.

## See also

- `nextcloud-vue/openspec/changes/add-json-manifest-renderer/` — the
  library-side spec the renderer ships against.
- `nextcloud-vue/openspec/changes/manifest-page-type-extensions/` —
  Phase 1 of the page-type expansion (logs / settings / chat / files).
- `nextcloud-vue/openspec/changes/manifest-abstract-sidebar/` —
  manifest-driven sidebar on index + detail.
- `nextcloud-vue/openspec/changes/manifest-schema-config-defs/` —
  the seven `$defs` referenced by every config block from 1.2.
- `nextcloud-vue/openspec/changes/manifest-settings-rich-sections/` —
  rich settings sections (component / widgets[]) so apps stop falling
  back to `type:"custom"` for settings pages mixing fields + widgets.
- `nextcloud-vue/openspec/changes/manifest-detail-sidebar-config/` —
  Object-form `CnDetailPage.sidebar` + per-page `sidebar.show`.
- `nextcloud-vue/openspec/changes/manifest-config-refs/` — schema 1.2
  tightening: `config` blocks reference the `$defs`.
- `decidesk/openspec/changes/decidesk-manifest-v1/` — first fleet-wide
  consumer of the v1.x manifest (18 of 20 pages migrated off
  `type:"custom"`; 2 documented exceptions). **Merged** as PR #160.
- `decidesk/src/manifest.json` — canonical Tier-4 example.
- `nextcloud-app-template/openspec/changes/template-manifest-v1/` —
  scaffold for new apps; the README quickstart now starts with
  "edit `src/manifest.json`".
- `nextcloud-vue/openspec/changes/manifest-resolve-sentinel/` —
  `@resolve:` sentinel for tenant-configured slugs; first production
  consumer is softwarecatalog (PR #218).
- ADR-022 (apps consume OR abstractions) — the manifest is the FE
  side of the same principle.
- ADR-029 (route-reachability gate) — pairs with the manifest's
  `pages[]` declaration.
