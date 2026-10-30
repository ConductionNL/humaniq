/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * On a host's board look the compact hours card has the board's frame and
 * buttons (dossiq DqZaak "Uren registreren", r34): the title on the normal
 * line, the content 22px in and 20px above the card's foot, "Record hours"
 * primary even while it waits for the form, "Start timer" 14px in.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-leaf-on-a-row-is-one-hours-field-booked-with-the-rows-note
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const sfc = readFileSync(new URL('../../src/integrations/CnHoursWidget.vue', import.meta.url), 'utf8')
/**
 * The declarations of every rule whose selector ends with `selector`.
 *
 * @param {string} selector The selector text before the opening brace.
 * @return {string} The rule bodies joined, or '' when there is none.
 */
function rule(selector) {
	const bodies = []
	for (let at = sfc.indexOf(selector + ' {'); at >= 0; at = sfc.indexOf(selector + ' {', at + 1)) {
		bodies.push(sfc.slice(at, sfc.indexOf('}', at)))
	}
	return bodies.join('\n')
}
const B = ':global(.cn-look-board .hq-hours--compact '

test('the title reads on the normal line', () => {
	assert.match(rule(B + '.hq-hours__caption)'), /line-height: normal/)
})

test('the content is 22px in and 20px above the foot', () => {
	assert.match(rule(B + '.hq-hours__body)'), /padding: 2px 22px 20px/)
})

test('Record hours is primary, also while disabled', () => {
	const body = rule(B + '.hq-hours__submit),\n' + B + '.hq-hours__submit:disabled)')
	assert.match(body, /background: var\(--color-primary-element\)/)
	assert.match(body, /color: var\(--color-primary-element-text\)/)
})

test('Start timer is 14px in', () => {
	assert.match(rule(B + '.hq-hours__timer-start)'), /padding: 0 14px/)
})
