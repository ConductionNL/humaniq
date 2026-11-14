/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page's Audittrail section: entries read as sentences, the list is
 * narrowed to the humaniq register, and the links carry the same filter.
 *
 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#requirement-the-admin-settings-shall-show-an-audittrail-section-across-the-humaniq-register-req-rah-003
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { AUDIT_ENTRY_COUNT, auditLinks, auditSentence, auditTime, auditUrls, loadAuditEntries } from '../../src/views/settings/auditTrail.js'

/** Fill `{name}` placeholders, like @nextcloud/l10n's translate does. */
const translate = (text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match))
const now = new Date(2026, 10, 14, 16, 0)
const url = (path) => '/index.php' + path

test('a read entry reads as a sentence with who and when', () => {
	const entry = { action: 'read', userName: 'Ruud Visser', user: 'ruud', created: new Date(2026, 10, 14, 10, 12).toISOString(), changed: [] }
	assert.equal(auditSentence(entry, translate, now), 'Ruud Visser viewed a record at 10:12')
})

test('a change entry names the changed fields', () => {
	const entry = { action: 'update', user: 'ruud', created: new Date(2026, 10, 13, 9, 5).toISOString(), changed: { salary: { old: 1, new: 2 }, iban: { old: 'a', new: 'b' } } }
	assert.equal(auditSentence(entry, translate, now), 'ruud changed salary, iban at 13-11-2026 09:05')
})

test('create, delete and an unknown action read too, and nobody reads as someone', () => {
	const at = new Date(2026, 10, 14, 8, 0).toISOString()
	assert.equal(auditSentence({ action: 'create', userName: 'Femke', created: at }, translate, now), 'Femke created a record at 08:00')
	assert.equal(auditSentence({ action: 'delete', created: at }, translate, now), 'someone deleted a record at 08:00')
	assert.equal(auditSentence({ action: 'lock', userName: 'Femke', created: at }, translate, now), 'Femke: lock on a record at 08:00')
	assert.equal(auditTime('not a date', now), '')
})

test('the list is read for the humaniq register, ten at most', async () => {
	const urls = auditUrls(url, 'humaniq')
	const calls = []
	const results = Array.from({ length: 12 }, (_, i) => ({ uuid: 'a' + i }))
	const http = {
		get: async (address, config) => {
			calls.push([address, config])
			return address === urls.register ? { data: { id: 7, slug: 'humaniq' } } : { data: { results } }
		},
	}
	const loaded = await loadAuditEntries(http, urls)
	assert.equal(loaded.registerId, 7)
	assert.equal(loaded.entries.length, AUDIT_ENTRY_COUNT)
	assert.deepEqual(calls, [
		['/index.php/apps/openregister/api/registers/humaniq', undefined],
		['/index.php/apps/openregister/api/audit-trails/readable', { params: { register: 7, limit: 10 } }],
	])
	await assert.rejects(loadAuditEntries({ get: async () => ({ data: {} }) }, urls), /no id/)
})

test('the full trail and the download carry the register filter', () => {
	assert.deepEqual(auditLinks(auditUrls(url, 'humaniq'), 7), {
		full: '/index.php/apps/openregister/audit-trails?register=7',
		download: '/index.php/apps/openregister/api/audit-trails/export?register=7&format=csv',
	})
})

test('the section is on the admin page', () => {
	const page = readFileSync(new URL('../../src/views/settings/AdminSettings.vue', import.meta.url), 'utf8')
	assert.match(page, /data-testid="humaniq-admin-audit-trail"/)
	assert.match(page, /loadAuditEntries/)
})
