/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * On a host's row (dossiq DqDagAfsluiten, per-row "Uren") the hours leaf is
 * the label over one field. The card chrome the leaf draws for itself (a
 * hairline edge, a 12px corner, its own ground, clipping) does not apply
 * there: it drew a box around the label and the field.
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

test('the row surface draws no card around the field', () => {
	const body = rule(".hq-hours[data-surface='row'] {")
	assert.match(body, /border: 0/)
	assert.match(body, /border-radius: 0/)
	assert.match(body, /background-color: transparent/)
	assert.match(body, /overflow: visible/)
	assert.match(body, /height: auto/)
})
