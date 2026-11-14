// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Inloggen section on the admin settings page: read and save Nextcloud's
 * own sign-in settings through the sign-in settings API. humaniq stores none
 * of these values; the API writes them in Nextcloud's password policy app.
 *
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */

/**
 * The switches of the password policy, in the order the section shows them.
 *
 * @type {Array<string>}
 */
export const POLICY_SWITCHES = [
	'enforceUpperLowerCase',
	'enforceNumericCharacters',
	'enforceSpecialCharacters',
	'enforceHaveIBeenPwned',
]

/**
 * The number fields of the password policy.
 *
 * @type {Array<string>}
 */
export const POLICY_NUMBERS = ['minLength', 'maximumLoginAttempts']

/**
 * What the section shows before the server answers, or when it cannot be read.
 *
 * @return {{signInMethods: Array<object>, passwordPolicy: {available: boolean, values: object|null}}}
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */
export function emptySignIn() {
	return { signInMethods: [], passwordPolicy: { available: false, values: null } }
}

/**
 * The server's answer, with every part present.
 *
 * @param {object|undefined} data The response body.
 * @return {{signInMethods: Array<object>, passwordPolicy: {available: boolean, values: object|null}}}
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
 */
export function normaliseSignIn(data) {
	const policy = data?.passwordPolicy
	return {
		signInMethods: Array.isArray(data?.signInMethods) ? data.signInMethods : [],
		passwordPolicy: {
			available: policy?.available === true,
			values: policy?.available === true && policy.values ? { ...policy.values } : null,
		},
	}
}

/**
 * The body of a save: the policy values in their types. A number field holds
 * the text the administrator typed; a field left empty or not a whole number
 * is sent as it is, so the server refuses it with its own message.
 *
 * @param {object} values The values in the form.
 * @return {{passwordPolicy: object}}
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-006
 */
export function policyPayload(values) {
	const passwordPolicy = {}
	for (const key of POLICY_NUMBERS) {
		const raw = values?.[key]
		const text = String(raw ?? '').trim()
		passwordPolicy[key] = /^\d+$/.test(text) ? Number(text) : raw
	}
	for (const key of POLICY_SWITCHES) {
		passwordPolicy[key] = values?.[key] === true
	}
	return { passwordPolicy }
}

/**
 * Read the sign-in settings.
 *
 * @param {{get: (url: string) => Promise<{data: object}>}} http An axios-like client.
 * @param {string} url The API url.
 * @return {Promise<{signInMethods: Array<object>, passwordPolicy: {available: boolean, values: object|null}}>}
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
 */
export async function loadSignIn(http, url) {
	const response = await http.get(url)
	return normaliseSignIn(response.data)
}

/**
 * Save the password policy; returns the settings as stored.
 *
 * @param {{put: (url: string, body: object) => Promise<{data: object}>}} http An axios-like client.
 * @param {string} url The API url.
 * @param {object} values The values in the form.
 * @return {Promise<{signInMethods: Array<object>, passwordPolicy: {available: boolean, values: object|null}}>}
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */
export async function saveSignIn(http, url, values) {
	const response = await http.put(url, policyPayload(values))
	return normaliseSignIn(response.data)
}

/**
 * Why a save was refused: `missing-app` (409, the password policy app is off),
 * `invalid` (400, a value the server does not accept) or `failed`.
 *
 * @param {unknown} error The axios error.
 * @return {'missing-app'|'invalid'|'failed'}
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */
export function saveRefusal(error) {
	const status = error?.response?.status
	if (status === 409) {
		return 'missing-app'
	}
	if (status === 400) {
		return 'invalid'
	}
	return 'failed'
}
