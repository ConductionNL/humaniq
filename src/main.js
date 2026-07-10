// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	CnPageRenderer,
	buildManifest,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import registry from './registry.js'
import appIcons from './icons.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

// Register the app's schema/menu icons + lib translations once at bootstrap.
// Without the icon registration every manifest `icon` name fails the CnIcon
// registry lookup and falls back to a help-circle.
registerIcons(appIcons)
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[hrmq] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow the
// JS/CSS allowlist through Apache — /custom_apps/<app>/l10n/<locale>.json
// 404s in those environments. Wrapping mount in the callback means silent
// boot failure. Strings fall back to their English source on miss.
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

// Shallow-clone CnPageRenderer to give Vue Router an extensible component
// object — lib barrel exports are non-extensible (webpack ESM module records)
// and Vue 2's Vue.extend() adds an internal _Ctor cache entry.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route name IS page.id (per the lib's manifest contract).
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to the first page.
	routes.push({ path: '*', redirect: '/timesheets' })
	return routes
}

// Assemble the effective manifest from the bundled base + modular
// manifest.d/*.json fragments (ADR-037) laid out per menu-layout.json
// (ADR-044). require.context is resolved by webpack at build time; the shared
// buildManifest() merges pages/menu and applies the canonical relocations that
// nest the four leaf pages under their frozen ADR-001 top-level groups.
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
// Object.freeze is Vue 2's documented escape hatch from observe()'s recursive
// reactive-conversion walk: the manifest is a static, never-mutated artifact,
// so freezing it before it becomes a prop avoids dozens of needless
// getter/setter installs on every app boot (see hrmq-manifest-boot-and-http-cost).
const effectiveManifest = Object.freeze(buildManifest(bundledManifest, fragments, menuLayout))

const router = new VueRouter({
	mode: 'history',
	base: generateUrl('/apps/hrmq'),
	routes: routesFromManifest(effectiveManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and our `registry`) as frozen module objects in some
// bundle shapes — Vue 2's `Vue.extend()` mutates component definitions to
// attach an internal `_Ctor` cache, which throws against a frozen source map.
// Cloning yields extensible objects without altering resolved values.
// Freeze the shallow clones too — they are static registry maps the app never
// mutates after boot, so Vue should not deep-reactive-convert them either
// (hrmq-manifest-boot-and-http-cost). Freezing the object's own properties does
// not stop Vue reacting to the prop *reference* changing, only the per-property
// conversion of the static contents.
const pageTypesProp = Object.freeze({ ...defaultPageTypes })
const registryProp = Object.freeze({ ...registry })

// eslint-disable-next-line no-new
new Vue({
	pinia,
	router,
	render: (h) => h(App, {
		props: {
			manifest: effectiveManifest,
			registry: registryProp,
			pageTypes: pageTypesProp,
		},
	}),
}).$mount('#content')
