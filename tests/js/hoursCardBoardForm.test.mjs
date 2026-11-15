/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * On a host's board look the booking form and the week line are drawn as board
 * DqZaak "Uren registreren": labels 14px at 600 on the normal line, 6px above
 * their field, 12px between the fields; the week line 14px.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-board-look-booking-form-reads-as-drawn
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const sfc = readFileSync(new URL('../../src/integrations/CnHoursWidget.vue', import.meta.url), 'utf8')

/**
 * The declarations of one board-look rule.
 *
 * @param {string} selector The selector inside `:global()`.
 * @return {string} The block, or ''.
 */
function rule(selector) {
	const at = sfc.indexOf(`:global(.cn-look-board .hq-hours--compact ${selector}) {`)
	return at === -1 ? '' : sfc.slice(at, sfc.indexOf('}', at))
}

test('the labels are 14px at 600 on the normal line, 6px above the field', () => {
	const field = rule('.hq-hours__field')
	assert.match(field, /font-size: 14px;/)
	assert.match(field, /gap: 6px;/)
	assert.match(field, /line-height: normal;/)
	assert.match(rule('.hq-hours__field > span'), /font-weight: 600;/)
})

test('the fields are 12px apart, and the week line is 14px', () => {
	assert.match(rule('.hq-hours__form'), /gap: 12px;/)
	assert.match(rule('.hq-hours__form-row'), /gap: 12px;/)
	assert.match(rule('.hq-hours__week'), /font-size: 14px;/)
})
