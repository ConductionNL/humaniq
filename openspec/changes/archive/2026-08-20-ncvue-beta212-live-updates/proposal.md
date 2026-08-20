# Bump nc-vue to beta.212 (live-updates default-on) — no app-side wiring possible

## Why

`@conduction/nextcloud-vue` 1.0.0-beta.212 installs `liveUpdatesPlugin` by
default on every `createObjectStore` store (lazy — inert until the first
`subscribe()` call) and fixes the first-subscription transport. The fleet is
adopting live updates so OpenRegister-backed views refresh without a manual
reload; OpenRegister already pushes the `or-collection-*` / `or-object-*`
events, so adoption is frontend-only.

## What Changes

- Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212`.
- Add the missing ESLint + Stylelint configs (pre-existing gap — `npm run
  lint` and `npm run stylelint` were broken with "no configuration found")
  and apply the two resulting auto-fixes in `ProformaPayslip.vue`.

## Documented skips (no wireable views)

hrmq has no app-local views backed by a `createObjectStore` store, so there is
nothing to subscribe from app code:

- All object list/detail pages are manifest-driven, rendered by the shared
  library (`CnPageRenderer` → `CnIndexPage` / `CnDetailPage`). `CnIndexPage`
  has no subscription support and `CnPageRenderer` does not pass an
  `objectStore` instance to `CnDetailPage` (whose auto-subscribe requires it);
  live updates for manifest pages must land in `nextcloud-vue`, not per-app.
- The only custom view, `ProformaPayslip.vue`, is a stateless calculator that
  POSTs to `/apps/hrmq/api/payroll/proforma` and never touches the object
  store — nothing is persisted, so there is no collection or object scope to
  subscribe to.

Once `nextcloud-vue` subscribes from `CnIndexPage` / the renderer's detail
path, hrmq inherits live updates with no further app change (the plugin is
already default-on after this bump).

## Impact

- Affected specs: none (dependency bump + lint tooling repair)
- Affected code: `package.json`, `package-lock.json`, `.eslintrc.js`,
  `stylelint.config.js`, `src/views/ProformaPayslip.vue` (lint auto-fix only)
