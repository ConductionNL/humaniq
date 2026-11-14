/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Mijn HR service worker (self-service-mobile design.md D2, D5).
 *
 * Built as its own webpack entry and served by PwaController at
 * /apps/humaniq/service-worker.js with the app as its scope. It caches the
 * app shell only, sends every API request and page to the network, and shows
 * the web-push notifications openregister sends once the employee has turned
 * them on.
 */

import { handleFetch, notificationFromPayload, shellCacheName } from './pwa/swStrategy.js'

// Keyed by the webpack compilation hash, so every new build replaces the
// cached shell on its next start (design.md D2); the version is the fallback.
// eslint-disable-next-line no-undef
const BUILD = typeof __webpack_hash__ === 'string' ? __webpack_hash__ : (typeof appVersion === 'string' ? appVersion : '')
const CACHE = shellCacheName(BUILD)

self.addEventListener('install', () => {
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches.keys()
			.then((keys) => Promise.all(keys
				.filter((key) => key.startsWith('humaniq-shell-') && key !== CACHE)
				.map((key) => caches.delete(key))))
			.then(() => self.clients.claim()),
	)
})

self.addEventListener('fetch', (event) => {
	const answer = handleFetch(event.request, {
		origin: self.location.origin,
		fetch: (request) => fetch(request),
		openCache: () => caches.open(CACHE),
	})

	if (answer !== null) {
		event.respondWith(answer)
	}
})

self.addEventListener('push', (event) => {
	let payload
	try {
		payload = event.data ? event.data.json() : {}
	} catch {
		// A payload that is not JSON degrades to a plain text body.
		payload = { body: event.data ? event.data.text() : '' }
	}

	const { title, options } = notificationFromPayload(payload)
	event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
	event.notification.close()
	const url = (event.notification.data && event.notification.data.url) || self.registration.scope

	event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
		for (const client of clients) {
			if ('focus' in client && new URL(client.url).origin === self.location.origin) {
				return client.navigate(url).then(() => client.focus())
			}
		}

		return self.clients.openWindow(url)
	}))
})
