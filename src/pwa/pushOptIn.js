/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Turn notifications on this device on and off (self-service-mobile design.md D5).
 *
 * openregister owns delivery: it holds the VAPID key and the subscriptions
 * (`/apps/openregister/webpush/*`) and sends to them through its `web-push`
 * channel. This module only asks the browser for a subscription and hands it
 * to openregister, or takes it back. Reading the state asks the employee
 * nothing; the permission prompt comes from `turnOn()` only, which the
 * setting calls from a tap (REQ-MOB-004).
 */

/** Where openregister keeps the web-push endpoints, under the instance root. */
export const WEB_PUSH_PATH = '/apps/openregister/webpush'

/**
 * The states the setting shows.
 *
 * - `on`: this device has a subscription and the permission.
 * - `off`: it could have one; the employee has not turned it on.
 * - `blocked`: the browser refuses notifications for this site.
 * - `needs-install`: an iPhone or iPad delivers web push only to an app on the home screen.
 * - `unsupported`: this browser cannot receive web push.
 * - `unavailable`: the server has no push key configured.
 */
export const PUSH_STATES = ['on', 'off', 'blocked', 'needs-install', 'unsupported', 'unavailable']

/**
 * The browser objects this module uses, read from the page.
 *
 * @return {object} The navigator and window, either undefined outside a browser.
 */
export function browserEnv() {
	return {
		nav: typeof navigator !== 'undefined' ? navigator : undefined,
		win: typeof window !== 'undefined' ? window : undefined,
	}
}

/**
 * Whether this browser can take a push subscription, and if not, why.
 *
 * @param {object} env The navigator and window.
 * @param {object} [env.nav] The navigator.
 * @param {object} [env.win] The window.
 * @return {string} `ready`, `needs-install` or `unsupported`.
 */
export function pushSupport({ nav, win }) {
	const capable = Boolean(
		nav && nav.serviceWorker
		&& win && win.PushManager && win.Notification,
	)
	if (capable) {
		return 'ready'
	}

	const appleMobile = Boolean(nav && /iPhone|iPad|iPod/.test(String(nav.userAgent || '')))
	const standalone = Boolean(nav && nav.standalone === true)
		|| Boolean(win && typeof win.matchMedia === 'function' && win.matchMedia('(display-mode: standalone)').matches)

	return appleMobile && !standalone ? 'needs-install' : 'unsupported'
}

/**
 * The state of this device, without prompting.
 *
 * @param {object} env The navigator and window.
 * @return {Promise<string>} One of PUSH_STATES, never `unavailable`.
 */
export async function currentState(env) {
	const support = pushSupport(env)
	if (support !== 'ready') {
		return support
	}

	if (env.win.Notification.permission === 'denied') {
		return 'blocked'
	}

	const registration = await env.nav.serviceWorker.getRegistration()
	const subscription = registration ? await registration.pushManager.getSubscription() : null

	return subscription && env.win.Notification.permission === 'granted' ? 'on' : 'off'
}

/**
 * A VAPID key as the bytes `pushManager.subscribe()` takes.
 *
 * @param {string} base64Url The key, base64url without padding.
 * @return {Uint8Array} The key bytes.
 */
export function keyBytes(base64Url) {
	const padded = String(base64Url).replace(/-/g, '+').replace(/_/g, '/')
		+ '='.repeat((4 - (String(base64Url).length % 4)) % 4)
	const binary = atob(padded)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}
	return bytes
}

/**
 * Turn notifications on for this device. Call it from a tap only.
 *
 * @param {object} env The navigator and window.
 * @param {object} http An axios-like client with get and post.
 * @param {function(string): string} url Builds an instance URL from a path (generateUrl).
 * @return {Promise<string>} The state afterwards: `on`, `blocked`, `unavailable` or a support state.
 */
export async function turnOn(env, http, url) {
	const support = pushSupport(env)
	if (support !== 'ready') {
		return support
	}

	const { data } = await http.get(url(WEB_PUSH_PATH + '/vapid-public-key'))
	if (!data || data.configured !== true || !data.publicKey) {
		return 'unavailable'
	}

	const permission = await env.win.Notification.requestPermission()
	if (permission !== 'granted') {
		return permission === 'denied' ? 'blocked' : 'off'
	}

	const registration = await env.nav.serviceWorker.ready
	const subscription = await registration.pushManager.subscribe({
		userVisibleOnly: true,
		applicationServerKey: keyBytes(data.publicKey),
	})
	const body = subscription.toJSON()
	await http.post(url(WEB_PUSH_PATH + '/subscription'), { endpoint: body.endpoint, keys: body.keys })

	return 'on'
}

/**
 * Turn notifications off for this device: openregister forgets the
 * subscription first, then the browser drops it.
 *
 * @param {object} env The navigator and window.
 * @param {object} http An axios-like client with delete.
 * @param {function(string): string} url Builds an instance URL from a path (generateUrl).
 * @return {Promise<string>} `off`, or a support state.
 */
export async function turnOff(env, http, url) {
	const support = pushSupport(env)
	if (support !== 'ready') {
		return support
	}

	const registration = await env.nav.serviceWorker.getRegistration()
	const subscription = registration ? await registration.pushManager.getSubscription() : null
	if (subscription) {
		await http.delete(url(WEB_PUSH_PATH + '/subscription'), { data: { endpoint: subscription.endpoint } })
		await subscription.unsubscribe()
	}

	return 'off'
}
