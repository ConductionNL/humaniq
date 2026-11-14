/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Register the Mijn HR service worker (self-service-mobile design.md D1).
 *
 * Registering asks the employee nothing: no notification permission is
 * requested here, only by the opt-in setting on a tap (REQ-MOB-004).
 */

/**
 * Register the worker when the browser supports it.
 *
 * @param {string} appRoot The app's URL root, e.g. `/apps/humaniq` or `/index.php/apps/humaniq`.
 * @param {object} [nav] The navigator (injectable for tests).
 * @return {Promise<object|null>} The registration, or null when unsupported or refused.
 */
export function registerServiceWorker(appRoot, nav = (typeof navigator !== 'undefined' ? navigator : undefined)) {
	if (!nav || !nav.serviceWorker || typeof nav.serviceWorker.register !== 'function') {
		return Promise.resolve(null)
	}

	const root = String(appRoot).replace(/\/+$/, '')

	return nav.serviceWorker
		.register(root + '/service-worker.js', { scope: root + '/' })
		.catch(() => null)
}
