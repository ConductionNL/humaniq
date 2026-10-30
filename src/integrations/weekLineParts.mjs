/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The week line in parts, so the hours on the object can be drawn in bold
 * inside a translated sentence whose word order the language decides.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-week-line-names-the-objects-hours-in-bold
 */

/** What the line is translated with in place of the hours, then split on. */
export const HOURS_MARK = '⁣hours⁣'

/**
 * The line as parts: the text around the mark plain, the hours strong.
 *
 * @param {string} line The translated line, with HOURS_MARK for the hours.
 * @param {string} hours The hours as shown ("11,5 uur").
 * @return {Array<{text: string, strong: boolean}>} The parts, empty ones left out.
 */
export function weekLineParts(line, hours) {
	const text = typeof line === 'string' ? line : ''
	if (text === '') {
		return []
	}
	const at = text.indexOf(HOURS_MARK)
	if (at === -1) {
		return [{ text, strong: false }]
	}
	return [
		{ text: text.slice(0, at), strong: false },
		{ text: hours, strong: true },
		{ text: text.slice(at + HOURS_MARK.length), strong: false },
	].filter((part) => part.text !== '')
}
