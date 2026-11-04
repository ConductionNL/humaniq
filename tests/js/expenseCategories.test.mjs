/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page's expense category switches: what is sent when one switch
 * changes, and that the section is on the admin page.
 *
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { allowedAfterToggle, loadCategories, saveCategories } from '../../src/views/settings/expenseCategories.js'

const switches = [
	{ id: 'travel', allowed: true },
	{ id: 'meals', allowed: false },
	{ id: 'software', allowed: true },
]

test('switching a category off sends the others that are on', () => {
	assert.deepEqual(allowedAfterToggle(switches, 'software', false), ['travel'])
})

test('switching a category on adds it in list order', () => {
	assert.deepEqual(allowedAfterToggle(switches, 'meals', true), ['travel', 'meals', 'software'])
})

test('reading and saving go through the API', async () => {
	const calls = []
	const http = {
		get: async (url) => { calls.push(['get', url]); return { data: { categories: switches } } },
		put: async (url, body) => { calls.push(['put', url, body]); return { data: { categories: [] } } },
	}
	assert.deepEqual(await loadCategories(http, '/api/expense-categories'), switches)
	assert.deepEqual(await saveCategories(http, '/api/expense-categories', ['travel']), [])
	assert.deepEqual(calls, [['get', '/api/expense-categories'], ['put', '/api/expense-categories', { allowed: ['travel'] }]])
	assert.deepEqual(await loadCategories({ get: async () => ({ data: {} }) }, '/x'), [])
})

test('the admin page has the expense categories section with a switch per category', () => {
	const sfc = readFileSync(new URL('../../src/views/settings/AdminSettings.vue', import.meta.url), 'utf8')
	assert.match(sfc, /data-testid="humaniq-admin-expense-categories"/)
	assert.match(sfc, /<NcCheckboxRadioSwitch[\s\S]*?v-for="category in expenseCategories"/)
	assert.match(sfc, /generateUrl\('\/apps\/humaniq\/api\/expense-categories'\)/)
})
