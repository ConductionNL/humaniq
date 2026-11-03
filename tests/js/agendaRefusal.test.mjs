/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A refused agenda read says so. readAgenda passes the HTTP status on with its
 * error, and the agenda leaf shows its own line for a 403 instead of the
 * general read error, so a viewer who may not see a person's agenda is not
 * told that something broke.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
 */
import { parse } from '@vue/compiler-sfc'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { register } from 'node:module'
import { join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

register(new URL('./support/nextcloudFakeHooks.mjs', import.meta.url))

const ROOT = fileURLToPath(new URL('../../', import.meta.url))
const BUILD = join(ROOT, 'node_modules/.cache/agenda-refusal')
const hooks = { failure: null }
globalThis.__agendaRefusal = hooks

/**
 * Answer every fetch with one status.
 *
 * @param {number} status The HTTP status.
 * @return {void}
 */
function answerWith(status) {
	globalThis.fetch = async () => ({ ok: status < 400, status, json: async () => ({ entries: [] }) })
}

test('readAgenda passes the status of a refused read on', async () => {
	const { readAgenda } = await import('../../src/integrations/agendaApi.js')

	answerWith(403)
	await assert.rejects(readAgenda('employee', 'emp-1', '2026-09-01', '2026-09-07'), (error) => error.status === 403)

	answerWith(500)
	await assert.rejects(readAgenda('employee', 'emp-1', '2026-09-01', '2026-09-07'), (error) => error.status === 500)

	answerWith(200)
	assert.deepEqual(await readAgenda('employee', 'emp-1', '2026-09-01', '2026-09-07'), [])
})

mkdirSync(BUILD, { recursive: true })
writeFileSync(join(BUILD, 'stubs.mjs'), `const g = globalThis.__agendaRefusal
export const defaultWindow = () => ({ from: '2026-09-01', to: '2026-09-07' })
export const handlerOf = () => null
export const readHostObject = async () => null
export const readAgenda = async () => {
	if (g.failure) throw g.failure
	return []
}
`)
const source = join(ROOT, 'src/integrations/CnAgendaWidget.vue')
const script = parse(readFileSync(source, 'utf8'), { filename: source }).descriptor.script.content
	.replaceAll("from './agendaApi.js'", `from '${pathToFileURL(join(BUILD, 'stubs.mjs')).href}'`)
writeFileSync(join(BUILD, 'CnAgendaWidget.mjs'), script)
const widget = (await import(pathToFileURL(join(BUILD, 'CnAgendaWidget.mjs')).href)).default

/**
 * Run the leaf's load on a plain state with one read failure.
 *
 * @param {Error|null} failure What readAgenda throws, or null for none.
 * @return {Promise<object>} The state after loading.
 */
async function load(failure) {
	hooks.failure = failure
	const ctx = { ...widget.data(), subject: 'emp-1', subjectType: 'employee', t: (_app, text) => text }
	await widget.methods.load.call(ctx)

	return ctx
}

test('the leaf says the viewer may not see the agenda on a 403', async () => {
	const ctx = await load(Object.assign(new Error('refused'), { status: 403 }))

	assert.equal(ctx.error, 'You cannot see this agenda.')
	assert.deepEqual(ctx.entries, [])
	assert.equal(ctx.loading, false)
})

test('any other failure keeps the general read error', async () => {
	assert.equal((await load(Object.assign(new Error('broken'), { status: 500 }))).error, 'The agenda could not be read.')
	assert.equal((await load(new Error('network'))).error, 'The agenda could not be read.')
	assert.equal((await load(null)).error, '')
})
