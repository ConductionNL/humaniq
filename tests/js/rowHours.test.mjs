/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hours leaf on a host's row reads the typed hours and books them when
 * the host records that row's note (DqDagAfsluiten "Uren").
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-leaf-on-a-row-is-one-hours-field-booked-with-the-rows-note
 */
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { isForObject, parseHours, ROW_RECORDED_EVENT } from '../../src/integrations/rowHours.mjs'

test('reads the hours with a decimal comma or point', () => {
	assert.equal(parseHours('1,5'), 1.5)
	assert.equal(parseHours('0.25'), 0.25)
	assert.equal(parseHours(' 2 '), 2)
})

test('reads nothing for an empty, zero, negative, too large or non-numeric value', () => {
	for (const value of ['', null, undefined, '0', '-1', '25', 'een', '1,5,2']) {
		assert.equal(parseHours(value), null, String(value))
	}
})

test('books only for its own object, on the host\'s recorded-note event', () => {
	assert.equal(ROW_RECORDED_EVENT, 'cn-record-notes:recorded')
	assert.equal(isForObject({ objectId: 't1', note: 'x' }, 't1'), true)
	assert.equal(isForObject({ objectId: 't2' }, 't1'), false)
	assert.equal(isForObject({ objectId: 't1' }, ''), false)
	assert.equal(isForObject(null, 't1'), false)
})

test('the row field is 40px, over Nextcloud\'s input rule, as the host\'s fields beside it', async () => {
	const { readFileSync } = await import('node:fs')
	const vue = readFileSync(new URL('../../src/integrations/CnHoursWidget.vue', import.meta.url), 'utf8')
	const rule = vue.match(/\n\.hq-hours__row-input \{([^}]*)\}/)
	assert.ok(rule)
	assert.match(rule[1], /height: 40px !important/)
})
