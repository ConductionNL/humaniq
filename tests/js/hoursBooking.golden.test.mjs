/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the two booking paths of the hours leaf: the booking form
 * (recordHours) and the row field booked with the host's recorded note
 * (onRowRecorded). The leaf's script is compiled from its SFC with stand-ins
 * for the hours API and l10n, and each method runs on a plain component
 * state. Every booking request, the state after the call and the reloads it
 * ran are compared with tests/js/fixtures/hoursBooking.golden.json. Set
 * RECORD_GOLDEN=1 to rewrite that file.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
 */
import { parse } from '@vue/compiler-sfc'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = fileURLToPath(new URL('../../', import.meta.url))
const GOLDEN = fileURLToPath(new URL('./fixtures/hoursBooking.golden.json', import.meta.url))
const BUILD = join(ROOT, 'node_modules/.cache/hours-booking-golden')
const RECORD = process.env.RECORD_GOLDEN === '1'

const hooks = { calls: [], answer: null }
globalThis.__hoursBookingGolden = hooks

mkdirSync(BUILD, { recursive: true })
writeFileSync(join(BUILD, 'stubs.mjs'), `const g = globalThis.__hoursBookingGolden
export const translate = (_app, text) => text
export const getLanguage = () => 'en'
export default {}
export const bookHours = async (entry) => {
	g.calls.push(['bookHours', JSON.parse(JSON.stringify(entry))])
	if (g.answer instanceof Error) throw g.answer
}
export const administrationUrl = () => ''
export const fetchEstimate = async () => null
export const fetchRunningTimer = async () => null
export const fetchWeek = async () => []
export const startTimer = async () => null
export const stopTimer = async () => null
`)

const source = join(ROOT, 'src/integrations/CnHoursWidget.vue')
const stubs = pathToFileURL(join(BUILD, 'stubs.mjs')).href
let script = parse(readFileSync(source, 'utf8'), { filename: source }).descriptor.script.content
script = script
	.replaceAll("from '@nextcloud/l10n'", `from '${stubs}'`)
	.replace(/from '\.\.\/dialogs\/[^']+\.vue'/, `from '${stubs}'`)
	.replaceAll("from './hoursApi.js'", `from '${stubs}'`)
	.replace(/from '\.\/(\w+\.mjs)'/g, (_m, file) => `from '${pathToFileURL(join(ROOT, 'src/integrations', file)).href}'`)
writeFileSync(join(BUILD, 'CnHoursWidget.mjs'), script)
const component = (await import(pathToFileURL(join(BUILD, 'CnHoursWidget.mjs')).href)).default

const recorded = {}
const expected = RECORD ? {} : JSON.parse(readFileSync(GOLDEN, 'utf8'))

/**
 * A component state: the data fields the booking paths read and write, the
 * computed values as plain fields, and reloads that record their calls.
 *
 * @param {object} fields Fields to override.
 * @return {object} The state.
 */
function state(fields) {
	const ctx = {
		domainObjectType: 'dossiq:case',
		objectId: 'case-1',
		saving: false,
		error: 'stale',
		rowHours: '2,5',
		rowHoursValue: 2.5,
		formComplete: true,
		form: { date: '2026-03-04', hours: '1.5', description: 'Calls', extra: 'kept' },
		...fields,
	}
	ctx.load = async () => {
		hooks.calls.push(['load', ctx.saving])
		if (ctx.failLoad === 'load') throw new Error('load failed')
	}
	ctx.loadEstimate = async () => {
		hooks.calls.push(['loadEstimate', ctx.saving])
	}
	return ctx
}

const failure = (data) => Object.assign(new Error('x'), { response: { data } })
const ANSWERS = {
	ok: null,
	message: failure({ message: 'Too many hours.', error: 'ignored' }),
	error: failure({ error: 'Closed week.' }),
	bare: new Error('network'),
}
const SNAPSHOT = ['saving', 'error', 'rowHours', 'form']

/**
 * Run one method on one state and record what it did.
 *
 * @param {string} key The case key.
 * @param {string} method The method name.
 * @param {object} fields The state overrides.
 * @param {string} answer The key into ANSWERS.
 * @param {Array} args The method arguments.
 */
async function runCase(key, method, fields, answer, args = []) {
	hooks.calls = []
	hooks.answer = ANSWERS[answer]
	const ctx = state(fields)
	await component.methods[method].call(ctx, ...args)
	const today = new Date().toISOString().slice(0, 10)
	recorded[key] = JSON.parse(JSON.stringify({ calls: hooks.calls, state: Object.fromEntries(SNAPSHOT.map((k) => [k, ctx[k]])) }).replaceAll(today, '(today)'))
	if (!RECORD) {
		assert.deepEqual(recorded[key], expected[key])
	}
}

const recordedNote = (detail) => ({ detail })

for (const answer of Object.keys(ANSWERS)) {
	test(`recordHours, ${answer}`, () => runCase(`recordHours/${answer}`, 'recordHours', {}, answer))
	test(`onRowRecorded, ${answer}`, () => runCase(`onRowRecorded/${answer}`, 'onRowRecorded', {}, answer, [recordedNote({ objectId: 'case-1', note: 'Dag afgesloten' })]))
}

test('recordHours guards', async () => {
	await runCase('recordHours/incomplete', 'recordHours', { formComplete: false }, 'ok')
	await runCase('recordHours/saving', 'recordHours', { saving: true }, 'ok')
	await runCase('recordHours/load fails', 'recordHours', { failLoad: 'load' }, 'ok')
})

test('onRowRecorded guards', async () => {
	await runCase('onRowRecorded/other object', 'onRowRecorded', {}, 'ok', [recordedNote({ objectId: 'case-2', note: 'x' })])
	await runCase('onRowRecorded/no detail', 'onRowRecorded', {}, 'ok', [{}])
	await runCase('onRowRecorded/no hours', 'onRowRecorded', { rowHoursValue: null }, 'ok', [recordedNote({ objectId: 'case-1' })])
	await runCase('onRowRecorded/saving', 'onRowRecorded', { saving: true }, 'ok', [recordedNote({ objectId: 'case-1' })])
	await runCase('onRowRecorded/no note', 'onRowRecorded', {}, 'ok', [recordedNote({ objectId: 'case-1', note: null })])
})

test('write the golden file when recording', () => {
	if (RECORD) {
		writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
