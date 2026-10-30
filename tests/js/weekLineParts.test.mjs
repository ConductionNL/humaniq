/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The week line names the hours on the object in bold, as board DqZaak
 * "Uren registreren" draws "Week 41 · 5 tot en met 11 okt · **11,5 uur** op deze zaak".
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-week-line-names-the-objects-hours-in-bold
 */
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { HOURS_MARK, weekLineParts } from '../../src/integrations/weekLineParts.mjs'

test('splits the translated line around the hours, the hours strong', () => {
	const line = `Week 41 · 5 okt tot en met 11 okt · ${HOURS_MARK} op deze zaak`
	assert.deepEqual(weekLineParts(line, '11,5 uur'), [
		{ text: 'Week 41 · 5 okt tot en met 11 okt · ', strong: false },
		{ text: '11,5 uur', strong: true },
		{ text: ' op deze zaak', strong: false },
	])
})

test('keeps a line without the mark as one plain part, and an empty line as none', () => {
	assert.deepEqual(weekLineParts('Week 41', '2 h'), [{ text: 'Week 41', strong: false }])
	assert.deepEqual(weekLineParts('', '2 h'), [])
})
