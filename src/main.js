// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import { loadTranslations, translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp, h, markRaw } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import appIcons from './icons.js'
import bundledManifest from './manifest.json'
import pinia from './pinia.js'
import registry from './registry.js'

// Dashboard widget catalog — the side-effect import the PUBLISHED package drops.
//
// `CnWidgetGrid` resolves a `widgetKey` in three steps: the consumer registry
// (src/registry.js), then `BUILT_IN_WIDGETS`, then the dashboard widget
// catalog via `getWidgetTypeEntry()`. Six keys live ONLY in that third
// catalog — `object-list`, `stats-block`, `chart`, `map`, `table`, `related` —
// and they get there by SELF-REGISTRATION: importing
// `CnWidgetGrid/registerDashboardWidgets.js` for its side effects is what
// populates the registry.
//
// The library's own `src/index.js` does exactly that on line 13. Its BUILT
// entrypoint does not:
//
//   src/index.js       line  13:  import '.../registerDashboardWidgets.js'      <-- side effect
//   src/index.js       line 442:  export { registerBuiltinDashboardWidgets } …
//   dist/esm/index.js  line   2:  export { registerBuiltinDashboardWidgets } …  <-- ONLY this
//
// `package.json`'s `module` field points at `dist/esm/index.js`, so every app
// consuming the published package — which is every app, and CI — gets the
// re-export without the side effect. A re-export does not execute the module
// unless the exported binding is used, and nothing uses it. The catalog stays
// empty and all six keys resolve to nothing.
//
// Measured on this instance before the fix: `EmployeeDetail` rendered
// COMPLETELY BLANK, with 13 `[CnWidgetGrid] Unknown widgetKey` warnings
// (9x object-list, 4x stats-block). Nine detail pages use these keys
// (EmployeeDetail, PayrollRunDetail, CompReviewCycleDetail, ApplicationDetail,
// OrgUnitDetail, AssetDetail, ReviewCycleDetail, ObjectiveDetail,
// RosterDetail). The Dashboard was unaffected only because src/registry.js
// overrides `chart` and `stat` locally, at layer 1.
//
// Filed upstream against @conduction/nextcloud-vue. This explicit import is
// the leaf-app workaround and is safe to keep afterwards: the module is
// idempotent, and `sideEffects` in the package already lists it, so webpack
// will not drop it.
import '@conduction/nextcloud-vue/dist/esm/components/CnWidgetGrid/registerDashboardWidgets.js'
// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
// gridstack is a REQUIRED peer of @conduction/nextcloud-vue that no consumer
// declares, and its stylesheet is the silent half: hrmq ships two
// `type: "dashboard"` pages, and gridstack v12 sizes every item with
// `width: var(--gs-column-width)`. Without this import each dashboard item
// renders 0 px wide with NO console error — heights still look right (those
// come from JS) while widths collapse. nc-vue's own `css/index.css` does not
// bundle it (verified: no `--gs-column-width` anywhere in that file).
import 'gridstack/dist/gridstack.min.css'
// Global (unscoped) app styles
import './assets/app.css'

// Register the app's schema/menu icons + lib translations once at bootstrap.
// Without the icon registration every manifest `icon` name fails the CnIcon
// registry lookup and falls back to a help-circle.
registerIcons(appIcons)
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	 
	console.warn('[hrmq] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow the
// JS/CSS allowlist through Apache — /custom_apps/<app>/l10n/<locale>.json
// 404s in those environments. Wrapping mount in the callback means silent
// boot failure. Strings fall back to their English source on miss.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('hrmq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer before handing it to vue-router. The library's
// barrel exports are frozen module records (nc-vue API friction, playbook
// §4.1) and `markRaw` writes a `__v_skip` marker through
// `Object.defineProperty`, which throws on a frozen object. Cloning yields an
// extensible object with the same resolved definition; `markRaw` then keeps Vue
// from making the component definition reactive inside the route record.
const RoutePageRenderer = markRaw({ ...CnPageRenderer })

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route name IS page.id (per the lib's manifest contract).
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 4 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// hrmq-asset-fleet-merge (design.md D6): the retired Vehicle/CarAssignment
	// pages' URL patterns redirect to their merged Asset/AssetAssignment
	// detail routes, so a stale bookmark or external link resolves instead of
	// 404ing. Manifest v2's page `type` enum has no redirect variant, so these
	// are hand-written routes (the catch-all below is the existing precedent
	// for a hand-written route living outside the manifest), added BEFORE the
	// catch-all so they take priority over it.
	routes.push({ path: '/vehicles/:id', redirect: (to) => '/assets/' + to.params.id })
	routes.push({ path: '/car-assignments/:id', redirect: (to) => '/asset-assignments/' + to.params.id })

	// Catch-all redirect to the first page. vue-router 4 REMOVED the bare
	// `path: '*'` wildcard: it matches nothing and raises no error, so any
	// unmatched route renders the shell with an empty <main>. The named
	// catch-all param below is its v4 replacement.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/timesheets' })
	return routes
}

const router = createRouter({
	history: createWebHistory(generateUrl('/apps/hrmq')),
	routes: routesFromManifest(bundledManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot — the library exports
// `defaultPageTypes` (and our `registry`) as frozen module objects in some
// bundle shapes, so anything downstream that annotates them would throw against
// a frozen source map. Cloning yields extensible objects without altering
// resolved values.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }

// Vue 3: `createApp(...).mount()` replaces `new Vue(...).$mount()`, and the
// render function passes props as a FLAT second argument to `h()` — the Vue 2
// `{ props: { … } }` nesting is silently ignored, which would leave CnAppRoot
// with no manifest at all.
const app = createApp({
	render: () => h(App, {
		manifest: bundledManifest,
		registry: registryProp,
		pageTypes: pageTypesProp,
	}),
})

// `t` / `n` are used bare in templates and as `this.t(…)` in methods. Vue 3 has
// no global `Vue.mixin`; a mixin is registered per app instance. Pinia is
// likewise a normal plugin now — `PiniaVuePlugin` was Vue-2 only.
app.mixin({ methods: { t, n } })
app.use(pinia)
app.use(router)
// Mount on the app's OWN host element (templates/index.php), never `#content`:
// Nextcloud core's layout.user.php already owns a `<div id="content">` that
// this template renders inside, and Vue 3 `mount()` renders INSIDE the match
// (Vue 2 `$mount()` REPLACED it). Selecting `#content` therefore resolved to
// core's wrapper, not ours. See the comment in templates/index.php.
app.mount('#hrmq-app')
