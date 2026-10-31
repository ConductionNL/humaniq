/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * On a host's board look the compact header is drawn as board DqZaak "Uren
 * registreren": title, week line and a 34px Actions button on one baseline,
 * the body 14px under that row, so the form's first row stands 16px under the
 * title (r41: 11.5 under a 40px centred row).
 *
 * @spec openspec/changes/hours-board-header-baseline/specs/hours-leaf/spec.md#requirement-the-board-look-header-sits-on-one-baseline
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

test('the header and its controls sit on one baseline', () => {
	assert.match(rule('.hq-hours__header'), /align-items: baseline;/)
	assert.match(rule('.hq-hours__controls'), /align-items: baseline;/)
})

test('the Actions button is 34px high', () => {
	const action = rule('.hq-hours__action')
	assert.match(action, /height: 34px;/)
	assert.match(action, /min-height: 34px;/)
})

test('the body starts 14px under the header row', () => {
	assert.match(rule('.hq-hours__body'), /padding: 14px 22px 20px;/)
})
