/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Stand-ins for `@nextcloud/axios`, `@nextcloud/dialogs` and
 * `@nextcloud/router` that log every call to `nextcloudFake.events`. A POST
 * to a URL listed in `nextcloudFake.failUrls` rejects.
 *
 * @spec exclude test support, not product behaviour
 */

export const nextcloudFake = { events: [], failUrls: [] }

export default {
	/**
	 * Record a POST; reject when its URL is listed as failing.
	 *
	 * @param {string} url The URL.
	 * @param {object} body The request body.
	 * @return {Promise<object>} The fake response.
	 */
	async post(url, body) {
		nextcloudFake.events.push({ call: 'post', url, body })
		if (nextcloudFake.failUrls.includes(url)) {
			throw new Error('request failed')
		}
		return { data: {} }
	},
}

/**
 * Record a success toast.
 *
 * @param {string} text The message.
 * @return {void}
 */
export function showSuccess(text) {
	nextcloudFake.events.push({ call: 'showSuccess', text })
}

/**
 * Record an error toast.
 *
 * @param {string} text The message.
 * @return {void}
 */
export function showError(text) {
	nextcloudFake.events.push({ call: 'showError', text })
}

/**
 * Fill `{name}` placeholders the way the real router does.
 *
 * @param {string} url The URL template.
 * @param {object} params The placeholder values.
 * @return {string} The URL.
 */
export function generateUrl(url, params) {
	return '/index.php' + url.replace(/\{(\w+)\}/g, (_, key) => encodeURIComponent(String(params[key])))
}
