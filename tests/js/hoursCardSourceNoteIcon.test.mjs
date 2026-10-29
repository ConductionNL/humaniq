/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hours card's source note is an information note as DqZaak "Uren
 * registreren" draws it (r36): an information icon before the text, in the
 * light primary ink of the card's other light-filled controls.
 *
 * @spec openspec/changes/hours-row-surface/specs/hours-leaf/spec.md
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
	const at = sfc.indexOf('\n' + selector + ' {')
	return at < 0 ? '' : sfc.slice(at, sfc.indexOf('}', at))
}

test('the source note opens with an information icon', () => {
	const note = sfc.slice(sfc.indexOf('data-testid="hq-hours-source-note"'), sfc.indexOf('</p>', sfc.indexOf('data-testid="hq-hours-source-note"')))
	assert.match(note, /class="hq-hours__source-note-icon"/)
})

test('the source note is a flex row in the light primary ink', () => {
	const body = rule('.hq-hours__source-note')
	assert.match(body, /display: flex/)
	assert.match(body, /color: var\(--color-primary-element-light-text/)
})
