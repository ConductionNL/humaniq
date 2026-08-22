---
kind: code
---

## Why

Two independent, verifiable boot/request-cost gaps in how Humaniq handles its own static manifest:

**1. `PageController::manifest()` re-reads and re-decodes the manifest file on every request, with
no HTTP caching.**

`lib/Controller/PageController.php:106-117` reads `src/manifest.json` from disk via
`file_get_contents()` and `json_decode()`s it fresh on every single call, and the `JSONResponse` it
returns sets no `Cache-Control`/`ETag`/`Last-Modified` header at all. The manifest is a build-time,
versioned, immutable-per-deploy artifact (per ADR-024, the bundled-manifest pattern), yet the
endpoint is wired as if it changed per-request. Per ADR-024's backlog notes (§"Lib v2 backlog":
"Backend `/api/manifest` endpoint implementation ... Currently opt-in stub; first real consumer
requires it"), this route is scaffolded ahead of humaniq's own frontend consuming it — verified:
`grep -rn "api/manifest" src/` returns nothing, `src/main.js` imports the manifest statically
(`import bundledManifest from './manifest.json'`) instead of fetching this route. That does not
make the route inert: it is registered in `appinfo/routes.php` and reachable by any authenticated
client that calls it directly (external tooling, a future runtime-manifest consumer per ADR-024,
or simple probing), and today every such call pays a disk read + JSON parse with zero caching.

**2. The bundled manifest is passed into Vue as a plain, non-frozen object, making a static
~12 KB nested JSON blob deep-reactive on every app boot.**

`src/main.js:104` passes `manifest: bundledManifest` (the statically-imported `src/manifest.json`)
as a prop into the root `App` component, which forwards it unchanged to `CnAppRoot`
(`src/App.vue:15`). Neither `main.js` nor `@conduction/nextcloud-vue`'s `CnAppRoot`
(`nextcloud-vue/src/components/CnAppRoot/CnAppRoot.vue` — verified no `markRaw`/`Object.freeze`
call on the `manifest` prop) marks this object non-reactive. Vue 2's `observe()` walks every plain
object/array it receives as a prop and installs getter/setter pairs recursively — for
`bundledManifest`'s six pages, nested `menu[]`, `config`, `widgets[]`, and `deepLinks[]` arrays,
that is dozens of unnecessary reactive conversions on every single app boot for data that is never
mutated after import (it is a `require()`'d/`import`ed static JSON module, not a runtime object the
app writes to).

Neither issue causes user-visible breakage; both are small, concrete, fully avoidable per-boot /
per-request costs on an app whose entire raison d'être is a manifest-driven, no-bespoke-code
frontend (`src/registry.js` is deliberately empty) — the manifest *is* humaniq's frontend, so its
handling cost is disproportionately relevant here versus apps with substantial bespoke Vue code.

## What Changes

- `PageController::manifest()`: cache the decoded manifest in memory for the request lifecycle
  (trivial, avoids re-parsing per call within a request) and set `Cache-Control` + `ETag` headers
  on the `JSONResponse` so repeat client calls (and any future runtime-manifest consumer) can
  short-circuit with a `304 Not Modified` instead of re-downloading the full payload. The ETag
  SHOULD be derived from the app version (`appVersion`, already available via the `DefinePlugin` in
  `webpack.config.js`) or a file hash, so it changes correctly on deploy.
- `src/main.js`: wrap `bundledManifest` (and, per the existing comment's own established pattern
  for `defaultPageTypes`/`registry`, the derived `pageTypesProp`/`registryProp`) with Vue 2's
  `Vue.observable`-bypass — i.e. mark it non-reactive before passing it as a prop — since it is a
  static, never-mutated import. Use whichever mechanism `@conduction/nextcloud-vue` already
  recommends for this (check for an existing `markRaw`-equivalent helper before hand-rolling one,
  to stay consistent with the fleet).

## Capabilities

### New Capabilities
- `humaniq-manifest-boot-and-http-cost`: the bundled-manifest HTTP endpoint is cacheable, and the
  client-side manifest object does not pay Vue's reactivity-conversion cost for static data.

## Impact

- **`lib/Controller/PageController.php`** — `manifest()` gains caching headers; no change to the
  JSON payload shape or route contract.
- **`src/main.js`** — manifest/registry/pageTypes props marked non-reactive before mount; no
  change to what data is passed or how routes/menu are built.
- No schema, route signature, or frontend-visible behavioural change — this is pure cost reduction.
