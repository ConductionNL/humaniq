// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Audittrail section on the admin settings page: the newest entries of
 * OpenRegister's readable audit list, narrowed to the humaniq register, each
 * as one sentence. humaniq writes no audit entry itself (design D1).
 *
 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#requirement-the-admin-settings-shall-show-an-audittrail-section-across-the-humaniq-register-req-rah-003
 */

/**
 * How many entries the section shows.
 *
 * @type {number}
 */
export const AUDIT_ENTRY_COUNT = 10

/**
 * The sentence of each audit action; `{person}`, `{what}` and `{time}` are
 * filled in. An action outside this list reads with the fallback.
 *
 * @type {Object<string, string>}
 */
const SENTENCES = {
	read: '{person} viewed {what} at {time}',
	update: '{person} changed {what} at {time}',
	create: '{person} created {what} at {time}',
	delete: '{person} deleted {what} at {time}',
}

/**
 * The OpenRegister addresses the section reads and links to.
 *
 * @param {(path: string) => string} url Nextcloud's generateUrl.
 * @param {string} registerSlug The humaniq register's slug.
 * @return {{register: string, readable: string, page: string, export: string}}
 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#requirement-the-admin-settings-shall-show-an-audittrail-section-across-the-humaniq-register-req-rah-003
 */
export function auditUrls(url, registerSlug) {
	return {
		register: url('/apps/openregister/api/registers/' + encodeURIComponent(registerSlug)),
		readable: url('/apps/openregister/api/audit-trails/readable'),
		page: url('/apps/openregister/audit-trails'),
		export: url('/apps/openregister/api/audit-trails/export'),
	}
}

/**
 * The full trail page and the download, both narrowed to the register.
 *
 * @param {{page: string, export: string}} urls From {@link auditUrls}.
 * @param {number|string} registerId The register's id.
 * @return {{full: string, download: string}}
 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#scenario-the-full-trail-opens-filtered
 */
export function auditLinks(urls, registerId) {
	const register = encodeURIComponent(String(registerId))
	return {
		full: urls.page + '?register=' + register,
		download: urls.export + '?register=' + register + '&format=csv',
	}
}

/**
 * Read the register's id, then its newest readable audit entries.
 *
 * @param {{get: (url: string, config?: object) => Promise<{data: object}>}} http An axios-like client.
 * @param {{register: string, readable: string}} urls From {@link auditUrls}.
 * @return {Promise<{registerId: number|string, entries: Array<object>}>}
 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#requirement-the-admin-settings-shall-show-an-audittrail-section-across-the-humaniq-register-req-rah-003
 */
export async function loadAuditEntries(http, urls) {
	const register = await http.get(urls.register)
	const registerId = register.data?.id
	if (registerId === undefined || registerId === null || registerId === '') {
		throw new Error('The humaniq register has no id.')
	}

	const page = await http.get(urls.readable, { params: { register: registerId, limit: AUDIT_ENTRY_COUNT } })
	const entries = Array.isArray(page.data?.results) ? page.data.results : []
	return { registerId, entries: entries.slice(0, AUDIT_ENTRY_COUNT) }
}

/**
 * Two digits.
 *
 * @param {number} n The number.
 * @return {string}
 */
function pad(n) {
	return String(n).padStart(2, '0')
}

/**
 * When an entry was written: the time on the same day, else the date and the time.
 *
 * @param {string|null|undefined} created The entry's `created`.
 * @param {Date} now Now.
 * @return {string}
 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#scenario-the-newest-entries-read-as-sentences
 */
export function auditTime(created, now) {
	const at = new Date(created ?? '')
	if (Number.isNaN(at.getTime())) {
		return ''
	}

	const time = pad(at.getHours()) + ':' + pad(at.getMinutes())
	if (at.toDateString() === now.toDateString()) {
		return time
	}

	return at.getDate() + '-' + (at.getMonth() + 1) + '-' + at.getFullYear() + ' ' + time
}

/**
 * One entry as a sentence: who, what and when.
 *
 * @param {object} entry A readable audit entry.
 * @param {(text: string, vars?: object) => string} translate Translates an English source string.
 * @param {Date} now Now.
 * @return {string}
 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#scenario-the-newest-entries-read-as-sentences
 */
export function auditSentence(entry, translate, now) {
	const person = String(entry.userName || entry.user || '') || translate('someone')
	const fields = (entry.changed !== null && typeof entry.changed === 'object' && !Array.isArray(entry.changed))
		? Object.keys(entry.changed)
		: []
	const what = fields.length > 0 ? fields.join(', ') : translate('a record')
	const time = auditTime(entry.created, now)
	const sentence = SENTENCES[entry.action]

	if (sentence === undefined) {
		return translate('{person}: {action} on {what} at {time}', { person, action: String(entry.action ?? ''), what, time })
	}

	return translate(sentence, { person, what, time })
}
