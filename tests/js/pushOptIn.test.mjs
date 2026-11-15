/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Notifications on this device: reading the state asks nothing, the
 * permission prompt comes from turning the switch on, and the subscription
 * goes to openregister's web-push endpoint and comes back from it on off.
 *
 * @spec openspec/changes/self-service-mobile/specs/self-service-mobile/spec.md#requirement-notifications-shall-reach-the-installed-app-only-after-the-employee-turns-them-on-req-mob-004
 */
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { currentState, keyBytes, pushSupport, turnOff, turnOn, WEB_PUSH_PATH } from '../../src/pwa/pushOptIn.js'

/**
 * A browser with a service worker, the Push API and a recorded log.
 *
 * @param {object} [options] The permission, an existing subscription, the answer to the prompt.
 * @return {object} The env, the log, and the subscription the push manager hands out.
 */
function browser({ permission = 'default', subscribed = false, answer = 'granted' } = {}) {
	const log = []
	const created = {
		endpoint: 'https://push.example.org/sub/1',
		toJSON: () => ({ endpoint: 'https://push.example.org/sub/1', keys: { p256dh: 'P', auth: 'A' }, expirationTime: null }),
		unsubscribe: async () => { log.push('browser unsubscribe') },
	}
	let current = subscribed ? created : null
	const pushManager = {
		getSubscription: async () => current,
		subscribe: async (options) => {
			log.push(['subscribe', options.userVisibleOnly, Array.from(options.applicationServerKey)])
			current = created
			return created
		},
	}
	const registration = { pushManager }
	const Notification = {
		permission,
		requestPermission: async () => {
			log.push('prompt')
			Notification.permission = answer
			return answer
		},
	}
	return {
		log,
		env: {
			nav: { userAgent: 'Mozilla/5.0 (X11; Linux x86_64)', serviceWorker: { getRegistration: async () => registration, ready: Promise.resolve(registration) } },
			win: { PushManager: function() {}, Notification },
		},
	}
}

/**
 * An axios-like client answering the VAPID key and recording the calls.
 *
 * @param {object} log Where calls are recorded.
 * @param {object} [key] The vapid-public-key answer.
 * @return {object} The client.
 */
function http(log, key = { publicKey: 'AQID', configured: true }) {
	return {
		get: async (url) => { log.push(['GET', url]); return { data: key } },
		post: async (url, body) => { log.push(['POST', url, body]); return { data: {} } },
		delete: async (url, config) => { log.push(['DELETE', url, config.data]); return { data: { deleted: 1 } } },
	}
}

const url = (path) => '/index.php' + path

test('reading the state never prompts', async () => {
	const fresh = browser()
	assert.equal(await currentState(fresh.env), 'off')
	assert.deepEqual(fresh.log, [])

	assert.equal(await currentState(browser({ permission: 'granted', subscribed: true }).env), 'on')
	assert.equal(await currentState(browser({ permission: 'default', subscribed: true }).env), 'off')
	assert.equal(await currentState(browser({ permission: 'denied' }).env), 'blocked')
})

test('turning on fetches the key, prompts, subscribes with the key bytes and registers with openregister', async () => {
	const { env, log } = browser()

	assert.equal(await turnOn(env, http(log), url), 'on')

	assert.deepEqual(log, [
		['GET', '/index.php/apps/openregister/webpush/vapid-public-key'],
		'prompt',
		['subscribe', true, [1, 2, 3]],
		['POST', '/index.php/apps/openregister/webpush/subscription', { endpoint: 'https://push.example.org/sub/1', keys: { p256dh: 'P', auth: 'A' } }],
	])
	assert.equal(WEB_PUSH_PATH, '/apps/openregister/webpush')
})

test('a server without a push key is reported before anything is asked', async () => {
	const { env, log } = browser()

	assert.equal(await turnOn(env, http(log, { publicKey: '', configured: false }), url), 'unavailable')
	assert.deepEqual(log, [['GET', '/index.php/apps/openregister/webpush/vapid-public-key']])
})

test('a refused or dismissed prompt subscribes nothing', async () => {
	const denied = browser({ answer: 'denied' })
	assert.equal(await turnOn(denied.env, http(denied.log), url), 'blocked')
	assert.equal(denied.log.some((entry) => Array.isArray(entry) && entry[0] === 'POST'), false)

	const dismissed = browser({ answer: 'default' })
	assert.equal(await turnOn(dismissed.env, http(dismissed.log), url), 'off')
	assert.equal(dismissed.log.some((entry) => Array.isArray(entry) && entry[0] === 'subscribe'), false)
})

test('turning off removes the subscription at openregister, then in the browser', async () => {
	const { env, log } = browser({ permission: 'granted', subscribed: true })

	assert.equal(await turnOff(env, http(log), url), 'off')
	assert.deepEqual(log, [
		['DELETE', '/index.php/apps/openregister/webpush/subscription', { endpoint: 'https://push.example.org/sub/1' }],
		'browser unsubscribe',
	])

	const none = browser()
	assert.equal(await turnOff(none.env, http(none.log), url), 'off')
	assert.deepEqual(none.log, [])
})

test('a browser without the Push API says why, and an iPhone outside the home screen is told to install', async () => {
	const iphone = { nav: { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', serviceWorker: {} }, win: { matchMedia: () => ({ matches: false }) } }
	assert.equal(pushSupport(iphone), 'needs-install')
	assert.equal(await currentState(iphone), 'needs-install')

	const installed = { nav: { ...iphone.nav, standalone: true }, win: iphone.win }
	assert.equal(pushSupport(installed), 'unsupported')

	const old = { nav: { userAgent: 'Mozilla/5.0 (X11; Linux x86_64)' }, win: {} }
	const log = []
	assert.equal(await turnOn(old, http(log), url), 'unsupported')
	assert.deepEqual(log, [])

	assert.equal(pushSupport(browser().env), 'ready')
})

test('a base64url VAPID key becomes its bytes', () => {
	assert.deepEqual(Array.from(keyBytes('AQID')), [1, 2, 3])
	assert.deepEqual(Array.from(keyBytes('-_8')), [251, 255])
})
