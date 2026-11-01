/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What every humaniq leaf shares: rooting its Vue 3 widget at a host-owned
 * element, and finding OpenRegister's integration registry on the page.
 */
import { translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'

/**
 * The mount and unmount pair of a leaf that renders `component`.
 *
 * humaniq is Vue 3 and a consuming host may be Vue 2.7. A Vue-3 SFC handed to
 * such a host is interpreted under the host's incompatible runtime and renders
 * blank, so the host hands over a bare DOM element instead and each side runs
 * its own framework across that neutral boundary.
 *
 * The apps are kept per pair and keyed by ELEMENT, not by leaf id: the same
 * leaf may be mounted several times on one page (a sidebar tab and a
 * detail-page widget at once) and each needs its own instance to unmount
 * independently (openregister#2127).
 *
 * @param {object} component The Vue 3 SFC the leaf renders.
 *
 * @return {{mount: function(Element, object): void, unmount: function(Element): void}} The pair for the descriptor.
 */
export function leafMounting(component) {
	/** @type {Map<Element, import('vue').App>} */
	const mountedApps = new Map()

	return {
		/**
		 * Root the component at `el`. Idempotent per element.
		 *
		 * @param {Element} el    Host-owned container element.
		 * @param {object}  props Forwarded context: { register, schema, objectId, surface, … }.
		 *
		 * @return {void}
		 */
		mount(el, props) {
			if (el === undefined || el === null || mountedApps.has(el) === true) {
				return
			}
			const app = createApp(component, { ...(props || {}) })
			// The SFC calls `this.t(...)`; main.js installs these for the app bundle,
			// and a leaf mounts its own instance, so install them here too (ADR-066).
			app.config.globalProperties.t = t
			app.mount(el)
			mountedApps.set(el, app)
		},

		/**
		 * Destroy the app rooted at `el` and release the map entry.
		 *
		 * @param {Element} el The element previously passed to `mount`.
		 *
		 * @return {void}
		 */
		unmount(el) {
			const app = mountedApps.get(el)
			if (app === undefined) {
				return
			}
			mountedApps.delete(el)
			app.unmount()
		},
	}
}

/**
 * The global a leaf registers on: `globalRef` when given, else `window`.
 *
 * @param {object} [globalRef] Global to attach to.
 *
 * @return {object|null} The global, or null outside a browser.
 */
function hostGlobal(globalRef) {
	return globalRef || (typeof window !== 'undefined' ? window : null)
}

/**
 * OpenRegister's integration registry on the host global.
 *
 * Installs a load-order-safe queue stub when OpenRegister's bundle has not yet
 * installed the real registry, so a humaniq bundle that loads first is not lost.
 *
 * @param {object} [globalRef] Global to attach to (defaults to `window`).
 *
 * @return {object|null} The registry, or null outside a browser.
 */
export function openRegisterIntegrations(globalRef) {
	const target = hostGlobal(globalRef)
	if (target === null) {
		return null
	}

	target.OCA = target.OCA || {}
	target.OCA.OpenRegister = target.OCA.OpenRegister || {}
	target.OCA.OpenRegister.integrations = target.OCA.OpenRegister.integrations || {
		_queue: [],
		register(entry) {
			this._queue.push(entry)
		},
	}

	return target.OCA.OpenRegister.integrations
}
