/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * On a host's board look the hours card stands where board DqZaak "Uren
 * registreren" draws it: its three buttons carry none of Nextcloud's 3px
 * button margin (which put the header 3px low and the form 6px low), the week
 * rows touch (one hairline between them, 47px apart), and the source note
 * stands 14px under the card's last row.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-leaf-on-a-row-is-one-hours-field-booked-with-the-rows-note
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const sfc = readFileSync(new URL('../../src/integrations/CnHoursWidget.vue', import.meta.url), 'utf8')
/**
 * The declarations of the rule whose selector list starts with `selector`.
 *
 * @param {string} selector The first selector, without the opening brace.
 * @return {string} The rule body, or '' when the rule is missing.
 */
function rule(selector) {
	const at = sfc.indexOf(selector)
	return at < 0 ? '' : sfc.slice(sfc.indexOf('{', at), sfc.indexOf('}', at))
}
const B = ':global(.cn-look-board .hq-hours--compact '

test('the Actions, Record hours and Start timer buttons have no margin', () => {
	const body = rule(B + '.hq-hours__action),\n' + B + '.hq-hours__submit),\n' + B + '.hq-hours__timer-start)')
	assert.match(body, /margin: 0;/)
})

test('the week rows touch', () => {
	assert.match(rule(B + '.hq-hours__people) {'), /gap: 0;/)
})

test('the source note stands 14px under the last row (6px plus the body gap)', () => {
	assert.match(rule(B + '.hq-hours__source-note) {'), /margin-top: 6px;/)
})
