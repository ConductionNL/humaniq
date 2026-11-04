/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the compensation cycle run dialog: the text after a
 * confirmed step (doneText) for every step and result shape, and the dry-run
 * preview (runPreview) for every step, with and without a cycle, answered,
 * refused with a message or an error, and failed without one. The dialog's
 * script is compiled from its SFC with stand-ins for axios, routing, l10n
 * and the component library, and each case runs on a plain component state.
 * Every answer is compared with
 * tests/js/fixtures/compCycleRunDialog.golden.json. Set RECORD_GOLDEN=1 to
 * rewrite that file.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */
import { parse } from '@vue/compiler-sfc'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = fileURLToPath(new URL('../../', import.meta.url))
const GOLDEN = fileURLToPath(new URL('./fixtures/compCycleRunDialog.golden.json', import.meta.url))
const BUILD = join(ROOT, 'node_modules/.cache/comp-cycle-run-golden')
const RECORD = process.env.RECORD_GOLDEN === '1'

const hooks = { calls: [], answer: null }
globalThis.__compCycleRunGolden = hooks

mkdirSync(BUILD, { recursive: true })
writeFileSync(join(BUILD, 'stubs.mjs'), `const g = globalThis.__compCycleRunGolden
export const translate = (_app, text, vars = {}) => text.replace(/{(\\w+)}/g, (_m, k) => '<' + k + '=' + vars[k] + '>')
export const generateUrl = (path) => '/index.php' + path
export const dispatchAction = async () => ({ ok: true })
export const NcButton = {}
export const NcDialog = {}
export const NcLoadingIcon = {}
export const NcNoteCard = {}
export const NcSelect = {}
export const NcTextField = {}
export default {
	get: async () => ({ data: [] }),
	post: async (url, body) => {
		g.calls.push(['post', url, JSON.parse(JSON.stringify(body))])
		if (g.answer instanceof Error) throw g.answer
		return { data: g.answer }
	},
}
`)

const source = join(ROOT, 'src/dialogs/CompCycleRunDialog.vue')
const stubs = pathToFileURL(join(BUILD, 'stubs.mjs')).href
let script = parse(readFileSync(source, 'utf8'), { filename: source }).descriptor.script.content
script = script.replace(/from '[^']+'/g, `from '${stubs}'`)
writeFileSync(join(BUILD, 'CompCycleRunDialog.mjs'), script)
const component = (await import(pathToFileURL(join(BUILD, 'CompCycleRunDialog.mjs')).href)).default

const recorded = {}
const expected = RECORD ? {} : JSON.parse(readFileSync(GOLDEN, 'utf8'))

/**
 * A component state with the data, props and computed values as plain fields
 * and the methods bound to it.
 *
 * @param {object} fields Fields to override.
 * @return {object} The state.
 */
function state(fields) {
	const ctx = { loading: false, error: 'stale', preview: 'stale', done: null, reason: '', selectedIds: [], cycleId: 'cyc-1', chosenCycle: null, ...fields }
	ctx.picksCycle = ctx.selectedIds.length > 0 && ctx.cycleId === ''
	ctx.targetCycleId = fields.targetCycleId ?? ctx.cycleId
	for (const [name, method] of Object.entries(component.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * Record one value and compare it with the golden file.
 *
 * @param {string} key The case key.
 * @param {*} value The value.
 */
function check(key, value) {
	recorded[key] = JSON.parse(JSON.stringify(value))
	if (!RECORD) {
		assert.deepEqual(recorded[key], expected[key], key)
	}
}

const failure = (data) => Object.assign(new Error('x'), { response: { data } })
const ANSWERS = {
	answered: { wouldCreate: 3, counts: { 'would-apply': 2 } },
	message: failure({ message: 'Cycle closed.', error: 'ignored' }),
	error: failure({ error: 'No scope.' }),
	bare: new Error('network'),
}

test('doneText for every step and result', () => {
	const results = { none: null, empty: {}, full: { approved: 4, refusedSelfApproval: 1, failed: 2, counts: { applied: 7 }, created: 9 }, partial: { approved: 1, created: 0 } }
	for (const step of ['approve', 'effectuate', 'propose', 'other']) {
		for (const [name, done] of Object.entries(results)) {
			check(`doneText/${step}/${name}`, component.computed.doneText.call(state({ step, done })))
		}
	}
})

test('runPreview for every step, cycle and answer', async () => {
	for (const step of ['approve', 'effectuate', 'propose']) {
		for (const target of ['cyc-1', '']) {
			for (const [answer, value] of Object.entries(ANSWERS)) {
				hooks.calls = []
				hooks.answer = value
				const ctx = state({ step, targetCycleId: target, cycleId: target })
				await component.methods.runPreview.call(ctx)
				check(`runPreview/${step}/${target || 'no cycle'}/${answer}`, { calls: hooks.calls, preview: ctx.preview, error: ctx.error, loading: ctx.loading })
			}
		}
	}
	hooks.calls = []
	hooks.answer = ANSWERS.answered
	const selection = state({ step: 'propose', cycleId: '', selectedIds: ['e1', 'e2'], targetCycleId: 'cyc-9' })
	await component.methods.runPreview.call(selection)
	check('runPreview/propose/selection', { calls: hooks.calls, preview: selection.preview, error: selection.error })
})

test.after(() => {
	if (RECORD) {
		writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
