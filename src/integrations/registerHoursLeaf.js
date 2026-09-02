/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * humaniq's hours leaf: booked hours for ANY object, and the two ways to add
 * more.
 *
 * humaniq owns hours (ADR-107 decision 6), so humaniq renders them. A consuming
 * app places this leaf and passes the object context rather than querying
 * humaniq's register from its own manifest — which is what dossiq used to do,
 * and why its tile rendered `0` on every install without humaniq (ADR-113). A
 * leaf cannot render when its app is absent, so that failure mode does not
 * exist here rather than being handled.
 */
import { translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import CnHoursWidget from './CnHoursWidget.vue'

/**
 * The integration id a consuming app references to place this leaf.
 *
 * @type {string}
 */
export const HOURS_INTEGRATION_ID = 'humaniq-hours'

/**
 * Vue 3 app instances this leaf has mounted, keyed by the host-owned element.
 *
 * Keyed by ELEMENT, not by leaf id: the same leaf may be mounted several times
 * on one page — a sidebar tab and a detail-page widget at once — and each needs
 * its own instance to unmount independently (openregister#2127).
 *
 * @type {Map<Element, import('vue').App>}
 */
const mountedApps = new Map()

/**
 * Every render surface this leaf targets, written out rather than left to the
 * host's default.
 *
 * The list is duplicated verbatim by `RegisterHoursLeafListener::SURFACES` on
 * the server half, and gate-24 compares the two. A half that declares its
 * surfaces by OMISSION is how hermiq's two halves drifted apart unnoticed.
 *
 * @type {string[]}
 */
const SURFACES = ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity']

/**
 * Root a humaniq-owned Vue 3 app at a host-owned element.
 *
 * humaniq is Vue 3 and a consuming host may be Vue 2.7. A Vue-3 SFC handed to
 * such a host is interpreted under the host's incompatible runtime and renders
 * blank, so the host hands over a bare DOM element instead and each side runs
 * its own framework across that neutral boundary. Idempotent per element.
 *
 * @param {Element} el    Host-owned container element.
 * @param {object}  props Forwarded context: { register, schema, objectId, surface, … }.
 *
 * @return {void}
 */
function mount(el, props) {
	if (el === undefined || el === null || mountedApps.has(el) === true) {
		return
	}
	const app = createApp(CnHoursWidget, { ...(props || {}) })
	// The SFC calls `this.t(...)`; main.js installs these for the app bundle,
	// and this leaf mounts its own instance, so install them here too (ADR-066).
	app.config.globalProperties.t = t
	app.mount(el)
	mountedApps.set(el, app)
}

/**
 * Destroy the app rooted at `el` and release the map entry.
 *
 * @param {Element} el The element previously passed to `mount`.
 *
 * @return {void}
 */
function unmount(el) {
	const app = mountedApps.get(el)
	if (app === undefined) {
		return
	}
	mountedApps.delete(el)
	app.unmount()
}

/**
 * The integration descriptor for the hours leaf.
 *
 * @type {object}
 */
export const hoursLeafDescriptor = {
	id: HOURS_INTEGRATION_ID,
	label: t('humaniq', 'Hours'),
	icon: 'ClockOutline',
	requiredApp: 'humaniq',
	order: 40,
	group: 'workflow',
	surfaces: SURFACES,
	// AD-18: a schema property carrying referenceType:'hours' renders this
	// leaf's single-entity surface instead of a plain value.
	referenceType: 'hours',
	renderMode: 'mount',
	mount,
	unmount,
	defaultSize: { w: 4, h: 2 },
}

/**
 * Register the leaf on the shared OpenRegister integration registry.
 *
 * Installs a load-order-safe queue stub when OpenRegister's bundle has not yet
 * installed the real registry, so a humaniq bundle that loads first is not lost.
 * Idempotent under the AD-13 collision policy: the first registration of this id
 * wins, a duplicate warns in production and throws in development.
 *
 * @param {object} [globalRef] Global to attach to (defaults to `window`).
 *
 * @return {void}
 */
export function registerHoursLeaf(globalRef) {
	const target = globalRef || (typeof window !== 'undefined' ? window : null)
	if (target === null) {
		return
	}

	target.OCA = target.OCA || {}
	target.OCA.OpenRegister = target.OCA.OpenRegister || {}
	target.OCA.OpenRegister.integrations = target.OCA.OpenRegister.integrations || {
		_queue: [],
		register(entry) {
			this._queue.push(entry)
		},
	}

	target.OCA.OpenRegister.integrations.register(hoursLeafDescriptor)
}
