/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * On a host's board look the hours card's week rows are the board's ruled
 * rows (14px, name and hours at 600, 9px above and below, a 6px bar) and the
 * Actions trigger is 600 (dossiq DqZaak "Uren registreren", r33). Every rule
 * sits wholly in :global(), so none compiles to the host's bare look class.
 *
 * @spec openspec/changes/hours-row-surface/specs/hours-leaf/spec.md#requirement-the-hours-leaf-on-a-row-is-one-hours-field-booked-with-the-rows-note
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const sfc = readFileSync(new URL('../../src/integrations/CnHoursWidget.vue', import.meta.url), 'utf8')
/**
 * The declarations of the rule whose selector is exactly `selector`.
 *
 * @param {string} selector The selector text before the opening brace.
 * @return {string} The rule body, or '' when the rule is missing.
 */
function rule(selector) {
	const at = sfc.indexOf(selector + ' {')
	return at < 0 ? '' : sfc.slice(at, sfc.indexOf('}', at))
}
const B = ':global(.cn-look-board .hq-hours--compact '

test('a week row is a ruled 14px row at 600', () => {
	assert.match(rule(B + '.hq-hours__person)'), /padding: 9px 0/)
	assert.match(rule(B + '.hq-hours__person)'), /border-top: 1px solid/)
	assert.match(rule(B + '.hq-hours__person-line)'), /font-size: 14px/)
	assert.match(rule(B + '.hq-hours__person-line strong)'), /font-weight: 600/)
	assert.match(rule(B + '.hq-hours__bar)'), /height: 6px/)
})

test('the Actions trigger is 600', () => {
	assert.match(rule(B + '.hq-hours__action)'), /font-weight: 600/)
})

test('no board rule is a scoped :global() prefix', () => {
	assert.equal(/:global\(\.cn-look-board\)\s/.test(sfc), false)
})
