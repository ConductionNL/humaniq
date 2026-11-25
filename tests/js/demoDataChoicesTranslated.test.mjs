/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The setup wizard's example-data step shows the cards DemoDataService::listChoices()
 * returns and translates each label and description by a literal catalogue lookup,
 * so every one of them needs a Dutch entry or a Dutch administrator reads English
 * (found on the live pass of setup-wizard-six-steps 5.2).
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#REQ-FTS-001
 */
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8')

/**
 * The label and description strings of listChoices(), adjacent literals joined.
 *
 * @param {string} php The service source.
 * @return {string[]} The strings.
 */
function choiceStrings(php) {
	const start = php.indexOf('public function listChoices()')
	const body = php.slice(start, php.indexOf('}//end listChoices()', start))
	const strings = []
	for (const match of body.matchAll(/'(?:label|description)'\s*=>\s*\(?((?:\s*'(?:[^'\\]|\\.)*'\s*\.?)+)/g)) {
		strings.push([...match[1].matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((piece) => piece[1]).join(''))
	}
	return strings
}

test('every example-data card string has a Dutch translation', async () => {
	const strings = choiceStrings(await read('lib/Service/DemoDataService.php'))
	const nl = JSON.parse(await read('l10n/nl.json')).translations
	const en = JSON.parse(await read('l10n/en.json')).translations

	assert.equal(strings.length, 4, 'two cards, a label and a description each')
	for (const text of strings) {
		assert.equal(en[text], text, `en.json carries "${text}"`)
		assert.equal(typeof nl[text], 'string', `nl.json carries "${text}"`)
		assert.notEqual(nl[text], text, `nl.json translates "${text}"`)
	}
})
