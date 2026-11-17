/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page's Inloggen section: what is read, what a save sends, why a
 * save was refused, and that the section is on the admin page.
 *
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { emptySignIn, loadSignIn, normaliseSignIn, policyPayload, saveRefusal, saveSignIn } from '../../src/views/settings/signInSettings.js'

const POLICY = {
	minLength: 10,
	enforceUpperLowerCase: false,
	enforceNumericCharacters: true,
	enforceSpecialCharacters: false,
	enforceHaveIBeenPwned: true,
	maximumLoginAttempts: 0,
}

const SAML = { app: 'user_saml', protocol: 'saml', section: 'saml', enabled: true, providers: ['Gemeente Zuiddrecht'] }

test('a save sends the typed numbers as numbers and every switch as a boolean', () => {
	assert.deepEqual(policyPayload({ ...POLICY, minLength: ' 12 ', maximumLoginAttempts: '5', enforceSpecialCharacters: 'yes' }), {
		passwordPolicy: { ...POLICY, minLength: 12, maximumLoginAttempts: 5 },
	})
})

test('a number that is not whole is sent as typed, for the server to refuse', () => {
	const sent = policyPayload({ minLength: '12.5', maximumLoginAttempts: '' }).passwordPolicy
	assert.equal(sent.minLength, '12.5')
	assert.equal(sent.maximumLoginAttempts, '')
	assert.equal(sent.enforceHaveIBeenPwned, false)
})

test('the policy is only shown when its app is available', () => {
	assert.deepEqual(normaliseSignIn({ signInMethods: [SAML], passwordPolicy: { available: true, values: POLICY } }), {
		signInMethods: [SAML],
		passwordPolicy: { available: true, values: POLICY },
	})
	assert.deepEqual(normaliseSignIn({ passwordPolicy: { available: false, values: POLICY } }), emptySignIn())
	assert.deepEqual(normaliseSignIn(undefined), emptySignIn())
})

test('reading and saving go through the API', async () => {
	const calls = []
	const http = {
		get: async (url) => { calls.push(['get', url]); return { data: { signInMethods: [SAML], passwordPolicy: { available: true, values: POLICY } } } },
		put: async (url, body) => { calls.push(['put', url, body]); return { data: { signInMethods: [], passwordPolicy: { available: true, values: body.passwordPolicy } } } },
	}
	assert.deepEqual((await loadSignIn(http, '/api/settings/sign-in')).signInMethods, [SAML])
	const saved = await saveSignIn(http, '/api/settings/sign-in', { ...POLICY, minLength: '12' })
	assert.equal(saved.passwordPolicy.values.minLength, 12)
	assert.deepEqual(calls, [
		['get', '/api/settings/sign-in'],
		['put', '/api/settings/sign-in', { passwordPolicy: { ...POLICY, minLength: 12 } }],
	])
})

test('a refused save names why', () => {
	assert.equal(saveRefusal({ response: { status: 409 } }), 'missing-app')
	assert.equal(saveRefusal({ response: { status: 400 } }), 'invalid')
	assert.equal(saveRefusal({ response: { status: 500 } }), 'failed')
	assert.equal(saveRefusal(new Error('network')), 'failed')
})

test('the admin page carries the Inloggen section with the missing-app message', () => {
	const page = readFileSync(new URL('../../src/views/settings/AdminSettings.vue', import.meta.url), 'utf8')
	assert.match(page, /data-testid="humaniq-admin-sign-in"/)
	assert.match(page, /data-testid="humaniq-admin-sign-in-missing"/)
	assert.match(page, /The password policy needs the Nextcloud password policy app\./)
	assert.match(page, /\/apps\/humaniq\/api\/settings\/sign-in/)
})
