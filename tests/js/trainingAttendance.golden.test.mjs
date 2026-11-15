/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the Trainings list's bulk attendance actions: every POST
 * they send, every toast they show and the counts they return, for empty,
 * odd and partly failing selections. Each case is compared with
 * tests/js/fixtures/trainingAttendance.golden.json. Set RECORD_GOLDEN=1 to
 * rewrite that file.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
 */
import assert from 'node:assert/strict'
import fs from 'node:fs'
import { register } from 'node:module'
import { test } from 'node:test'

register(new URL('./support/nextcloudFakeHooks.mjs', import.meta.url))
const { nextcloudFake } = await import('./support/nextcloudFakes.mjs')
const dialogs = await import('../../src/dialogs/registerTrainingAttendance.js')

const GOLDEN = new URL('./fixtures/trainingAttendance.golden.json', import.meta.url)
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD || !fs.existsSync(GOLDEN) ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
const results = {}

const url = (id) => `/index.php/apps/openregister/api/objects/${encodeURIComponent(String(id))}/transition`

const SCOPES = {
	'no scope': undefined,
	'null scope': null,
	'no selectedIds': {},
	'selectedIds not an array': { selectedIds: 'a' },
	'empty selection': { selectedIds: [] },
	'one record': { selectedIds: ['t1'] },
	'numbers and duplicates': { selectedIds: [7, 7, 'x/y'] },
	'all fail': { selectedIds: ['f1', 'f2'], fail: ['f1', 'f2'] },
	'some fail': { selectedIds: ['a', 'f1', 'b'], fail: ['f1'] },
}

const ACTIONS = {
	'registerTrainingAttendance custom action': (scope) => dialogs.registerTrainingAttendance(scope, 'custom'),
	'registerTrainingAttendance no action': (scope) => dialogs.registerTrainingAttendance(scope),
	registerTrainingAttended: (scope) => dialogs.registerTrainingAttended(scope),
	registerTrainingNotAttended: (scope) => dialogs.registerTrainingNotAttended(scope),
}

for (const [actionName, act] of Object.entries(ACTIONS)) {
	for (const [scopeName, scope] of Object.entries(SCOPES)) {
		const key = `${actionName}: ${scopeName}`
		test(key, async () => {
			nextcloudFake.events.length = 0
			nextcloudFake.failUrls = (scope?.fail || []).map(url)
			const returned = await act(scope)
			results[key] = { returned, events: JSON.parse(JSON.stringify(nextcloudFake.events)) }
			if (!RECORD) {
				assert.deepEqual(results[key], recorded[key])
			}
		})
	}
}

test('every call returns a fresh counts object', async () => {
	nextcloudFake.failUrls = []
	const a = await dialogs.registerTrainingAttended({ selectedIds: [] })
	const b = await dialogs.registerTrainingAttended({ selectedIds: [] })
	assert.notEqual(a, b)
})

test('the posts run one after another', async () => {
	nextcloudFake.events.length = 0
	nextcloudFake.failUrls = []
	const pending = dialogs.registerTrainingAttended({ selectedIds: ['s1', 's2'] })
	assert.equal(nextcloudFake.events.filter((e) => e.call === 'post').length, 1)
	await pending
	assert.equal(nextcloudFake.events.filter((e) => e.call === 'post').length, 2)
})

test.after(() => {
	if (RECORD) {
		fs.mkdirSync(new URL('./fixtures/', import.meta.url), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(results, null, '\t') + '\n')
	}
})
