/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Mijn HR service worker: it caches the app shell only, never answers an
 * API request from cache, says it is offline when a page cannot be reached,
 * shows what openregister's web push sends, and registering it asks the
 * employee nothing.
 *
 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-the-app-shall-keep-no-personal-data-on-the-phone-req-mob-002
 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-notifications-shall-reach-the-installed-app-only-after-the-employee-turns-them-on-req-mob-004
 */
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { registerServiceWorker } from '../../src/pwa/registerServiceWorker.js'
import { classifyRequest, handleFetch, notificationFromPayload, shellCacheName } from '../../src/pwa/swStrategy.js'

const origin = 'https://cloud.example.org'

/**
 * A fake shell cache and network that record what happened.
 *
 * @param {object} [options] What the cache holds and how the network behaves.
 * @return {object} The deps handleFetch takes, plus the records.
 */
function harness({ cached = new Map(), online = true } = {}) {
	const fetched = []
	const stored = []
	const cache = {
		match: async (request) => cached.get(request.url),
		put: async (request) => { stored.push(request.url) },
	}
	return {
		fetched,
		stored,
		deps: {
			origin,
			openCache: async () => cache,
			fetch: async (request) => {
				fetched.push(request.url)
				if (!online) {
					throw new TypeError('Failed to fetch')
				}
				return new Response('network:' + request.url, { status: 200 })
			},
		},
	}
}

test('an humaniq API request is never answered from cache, and the worker leaves it to the network', async () => {
	const url = origin + '/index.php/apps/humaniq/api/payslips/mine'
	const { deps, fetched, stored } = harness({ cached: new Map([[url, new Response('stale payslip')]]) })

	assert.equal(classifyRequest({ method: 'GET', url }, origin), 'network')
	assert.equal(handleFetch({ method: 'GET', url }, deps), null)
	assert.deepEqual(fetched, [])
	assert.deepEqual(stored, [])
})

test('an API answer that looks like a file, such as a receipt image, is still never cached', async () => {
	const url = origin + '/apps/humaniq/api/expenses/7/receipt.png'
	const { deps, fetched, stored } = harness({ cached: new Map([[url, new Response('old receipt')]]) })

	assert.equal(classifyRequest({ method: 'GET', url }, origin), 'network')
	assert.equal(handleFetch({ method: 'GET', url }, deps), null)
	assert.deepEqual(fetched, [])
	assert.deepEqual(stored, [])
})

test('openregister and OCS answers are personal data too and are never claimed', () => {
	for (const path of ['/apps/openregister/api/objects/humaniq/Payslip', '/ocs/v2.php/apps/notifications/api/v2/notifications', '/apps/humaniq/api/manifest']) {
		assert.equal(classifyRequest({ method: 'GET', url: origin + path }, origin), 'network', path)
	}
})

test('writes and other origins pass through untouched', () => {
	assert.equal(classifyRequest({ method: 'POST', url: origin + '/custom_apps/humaniq/js/humaniq-main.js' }, origin), 'network')
	assert.equal(classifyRequest({ method: 'GET', url: 'https://cdn.example.com/app.js' }, origin), 'network')
})

test('a shell file is served from cache when cached, without touching the network', async () => {
	const url = origin + '/custom_apps/humaniq/js/humaniq-main.js'
	const { deps, fetched } = harness({ cached: new Map([[url, new Response('cached bundle')]]) })

	assert.equal(classifyRequest({ method: 'GET', url }, origin), 'shell')
	const response = await handleFetch({ method: 'GET', url }, deps)
	assert.equal(await response.text(), 'cached bundle')
	assert.deepEqual(fetched, [])
})

test('an uncached shell file is fetched and then cached', async () => {
	const url = origin + '/apps/humaniq/img/pwa-icon-192.png'
	const { deps, fetched, stored } = harness()

	const response = await handleFetch({ method: 'GET', url }, deps)
	assert.equal(await response.text(), 'network:' + url)
	assert.deepEqual(fetched, [url])
	assert.deepEqual(stored, [url])
})

test('the worker script itself is never cached', () => {
	assert.equal(classifyRequest({ method: 'GET', url: origin + '/apps/humaniq/service-worker.js' }, origin), 'network')
})

test('a page goes to the network and is not stored', async () => {
	const url = origin + '/apps/humaniq/mijn'
	const { deps, fetched, stored } = harness()

	const response = await handleFetch({ method: 'GET', url, mode: 'navigate' }, deps)
	assert.equal(response.status, 200)
	assert.deepEqual(fetched, [url])
	assert.deepEqual(stored, [])
})

test('offline, a page says so and shows nothing else', async () => {
	const url = origin + '/apps/humaniq/payslips/42'
	const { deps } = harness({ online: false, cached: new Map([[url, new Response('payslip 42')]]) })

	const response = await handleFetch({ method: 'GET', url, mode: 'navigate' }, deps)
	const body = await response.text()
	assert.equal(response.status, 503)
	assert.match(body, /offline/)
	assert.doesNotMatch(body, /payslip 42/)
})

test('the shell cache is keyed by the build', () => {
	assert.equal(shellCacheName('1.4.0'), 'humaniq-shell-1.4.0')
	assert.notEqual(shellCacheName('1.4.0'), shellCacheName('1.4.1'))
})

test('a web-push payload becomes the notification, with its link kept for the tap', () => {
	assert.deepEqual(
		notificationFromPayload({ title: 'Verlof goedgekeurd', body: '3 t/m 5 mei', tag: 'leave-7', url: '/apps/humaniq/leave-requests/7' }),
		{
			title: 'Verlof goedgekeurd',
			options: { body: '3 t/m 5 mei', icon: undefined, badge: undefined, tag: 'leave-7', renotify: true, data: { url: '/apps/humaniq/leave-requests/7' } },
		},
	)
	assert.equal(notificationFromPayload(null).title, 'Mijn HR')
	assert.equal(notificationFromPayload({}).options.data.url, null)
})

test('registering the worker scopes it to the app and asks for no permission', async () => {
	const calls = []
	const nav = { serviceWorker: { register: async (url, options) => { calls.push([url, options]); return { scope: options.scope } } } }
	let asked = false
	globalThis.Notification = { requestPermission: () => { asked = true } }

	assert.deepEqual(await registerServiceWorker('/index.php/apps/humaniq', nav), { scope: '/index.php/apps/humaniq/' })
	assert.deepEqual(calls, [['/index.php/apps/humaniq/service-worker.js', { scope: '/index.php/apps/humaniq/' }]])
	assert.equal(asked, false)
	delete globalThis.Notification
})

test('a browser without service workers, or one that refuses, is no error', async () => {
	assert.equal(await registerServiceWorker('/apps/humaniq', {}), null)
	assert.equal(await registerServiceWorker('/apps/humaniq', { serviceWorker: { register: () => Promise.reject(new Error('insecure')) } }), null)
})
