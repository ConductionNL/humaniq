/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * On a host's board look the hours card's fields and buttons are the board's
 * 40px controls (dossiq DqZaak "Uren registreren", r32: 34px fields with a
 * 2px edge, 15px buttons).
 *
 * @spec openspec/changes/hours-row-surface/specs/hours-leaf/spec.md#requirement-the-hours-leaf-on-a-row-is-one-hours-field-booked-with-the-rows-note
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const sfc = readFileSync(new URL('../../src/integrations/CnHoursWidget.vue', import.meta.url), 'utf8')
const rule = (selector) => {
	const at = sfc.indexOf(selector)
	return at < 0 ? '' : sfc.slice(at, sfc.indexOf('}', at))
}

test('the fields are 40px with a 1px edge on the board look', () => {
	const body = rule(':global(.cn-look-board .hq-hours--compact .hq-hours__field input) {')
	assert.match(body, /height: 40px/)
	assert.match(body, /border: 1px solid var\(--color-border-maxcontrast\)/)
})

test('the record and timer buttons are 40px at 14px on the board look', () => {
	const body = rule(':global(.cn-look-board .hq-hours--compact .hq-hours__timer-start) {')
	assert.match(body, /height: 40px/)
	assert.match(body, /font-size: 14px/)
})

test('the board look rules compile to the hours card only, never to the host\'s root', async () => {
	const { compileStyle } = await import('@vue/compiler-sfc')
	const source = sfc.slice(sfc.indexOf('<style scoped>') + '<style scoped>'.length, sfc.indexOf('</style>', sfc.indexOf('<style scoped>')))
	const { code, errors } = compileStyle({ source, filename: 'CnHoursWidget.vue', id: 'data-v-test', scoped: true })
	assert.deepEqual(errors, [])
	assert.doesNotMatch(code, /(^|\})\s*\.cn-look-board\s*[,{]/m)
	assert.match(code, /\.cn-look-board \.hq-hours--compact \.hq-hours__field input/)
})

