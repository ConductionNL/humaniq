/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hours leaf on a host's row (`surface: "row"`, dossiq's "Close out your
 * day"): one "Hours" field beside the host's note, booked when the host
 * records that row's note.
 */

/**
 * The document event a host's note rows send when a row's note is recorded
 * (nextcloud-vue CnRecordNoteRows). `detail` holds the row's `objectId` and
 * the `note`.
 *
 * @type {string}
 */
export const ROW_RECORDED_EVENT = 'cn-record-notes:recorded'

/**
 * The hours typed in the row field as a number, or null when it is empty or
 * not a positive number of at most 24. A decimal comma is read as a point
 * ("1,5" is 1.5).
 *
 * @param {string|number|null|undefined} text The field's value.
 * @return {number|null} The hours.
 */
export function parseHours(text) {
	const value = String(text ?? '').trim().replace(',', '.')
	if (value === '' || /^\d*\.?\d+$/.test(value) === false) {
		return null
	}
	const hours = Number(value)
	return hours > 0 && hours <= 24 ? hours : null
}

/**
 * Whether a recorded-note event is for this leaf's object.
 *
 * @param {object|null|undefined} detail The event's detail.
 * @param {string} objectId The leaf's object.
 * @return {boolean}
 */
export function isForObject(detail, objectId) {
	return objectId !== '' && detail !== null && typeof detail === 'object' && String(detail.objectId ?? '') === objectId
}
