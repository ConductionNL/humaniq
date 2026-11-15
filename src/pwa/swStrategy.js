/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the Mijn HR service worker does with a request, as plain functions so
 * they can be tested without a browser (self-service-mobile design.md D2).
 *
 * The rule is narrow on purpose: only the app shell (compiled scripts,
 * styles, fonts and icons) is ever cached. Every API answer and every page
 * goes to the network and is never stored, so no payslip, balance or personal
 * record is left on the phone.
 */

/** Paths whose answers are personal data: always network, never cached. */
export const NETWORK_ONLY_PATHS = ['/apps/humaniq/api/', '/apps/openregister/api/', '/ocs/']

/** File types that make up the app shell. */
const SHELL_EXTENSION = /\.(?:js|mjs|css|png|svg|ico|woff2?|ttf)$/i

/** The roots the app's own static files are served from. */
const SHELL_ROOTS = ['/apps/humaniq/', '/custom_apps/humaniq/', '/apps-extra/humaniq/']

/**
 * The cache the shell lives in, keyed by the build so an update replaces it.
 *
 * @param {string} version The build version.
 * @return {string} The cache name.
 */
export function shellCacheName(version) {
	return 'humaniq-shell-' + (version || 'dev')
}

/**
 * Decide how a request is handled.
 *
 * @param {{method: string, url: string, mode?: string}} request The request.
 * @param {string} origin The worker's origin.
 * @return {'network'|'navigate'|'shell'} `network`: pass through untouched;
 *   `navigate`: a page, network only with an offline notice; `shell`: cache first.
 */
export function classifyRequest(request, origin) {
	if (request.method !== 'GET') {
		return 'network'
	}

	const url = new URL(request.url, origin)
	if (url.origin !== origin) {
		return 'network'
	}

	if (NETWORK_ONLY_PATHS.some((path) => url.pathname.includes(path))) {
		return 'network'
	}

	if (request.mode === 'navigate') {
		return 'navigate'
	}

	const ownFile = SHELL_ROOTS.some((root) => url.pathname.startsWith(root) || url.pathname.includes('/index.php' + root))
	if (ownFile && SHELL_EXTENSION.test(url.pathname) && !url.pathname.endsWith('/service-worker.js')) {
		return 'shell'
	}

	return 'network'
}

/**
 * The page shown when a page cannot be reached: it says the app is offline
 * and shows nothing else, because nothing personal is stored to show.
 *
 * @return {Response} A 503 HTML response.
 */
export function offlineResponse() {
	const body = '<!doctype html><html lang="nl"><head><meta charset="utf-8">'
		+ '<meta name="viewport" content="width=device-width, initial-scale=1">'
		+ '<title>Mijn HR</title></head><body>'
		+ '<main><h1>Mijn HR</h1><p>Je bent offline. Maak verbinding met internet en probeer het opnieuw.</p>'
		+ '<p lang="en">You are offline. Connect to the internet and try again.</p></main>'
		+ '</body></html>'

	return new Response(body, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } })
}

/**
 * Answer a request the worker claimed.
 *
 * @param {Request} request The request.
 * @param {{origin: string, fetch: function(Request): Promise<Response>, openCache: function(): Promise<Cache>}} deps The network and the shell cache.
 * @return {Promise<Response>|null} The response, or null when the worker leaves the request alone.
 */
export function handleFetch(request, deps) {
	const kind = classifyRequest(request, deps.origin)

	if (kind === 'network') {
		return null
	}

	if (kind === 'navigate') {
		return deps.fetch(request).catch(() => offlineResponse())
	}

	return deps.openCache().then((cache) => cache.match(request).then((hit) => {
		if (hit) {
			return hit
		}

		return deps.fetch(request).then((response) => {
			if (response && response.ok) {
				cache.put(request, response.clone())
			}

			return response
		})
	}))
}

/**
 * Turn an openregister web-push payload into notification options, the shape
 * openregister's own worker renders (title, body, icon, badge, tag, url).
 *
 * @param {object|null} payload The decoded push payload.
 * @return {{title: string, options: object}} What to show.
 */
export function notificationFromPayload(payload) {
	const data = payload && typeof payload === 'object' ? payload : {}
	const url = typeof data.url === 'string' && data.url !== '' ? data.url : null

	return {
		title: typeof data.title === 'string' && data.title !== '' ? data.title : 'Mijn HR',
		options: {
			body: typeof data.body === 'string' ? data.body : '',
			icon: data.icon || undefined,
			badge: data.badge || undefined,
			tag: data.tag || undefined,
			renotify: !!data.tag,
			data: { ...(data.data && typeof data.data === 'object' ? data.data : {}), url },
		},
	}
}
