/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the hours and agenda leaves: their descriptors, how they
 * register on OpenRegister's integration registry (the load-order queue stub,
 * an already installed registry, what an existing OCA namespace keeps, the
 * window fallback), and how their mount and unmount root a Vue app per element.
 * Each case is compared with tests/js/fixtures/integrationLeafRegistration.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite that file.
 *
 * @spec openspec/specs/hours-leaf/spec.md
 */
import { translate } from '@nextcloud/l10n'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import { register } from 'node:module'
import { test } from 'node:test'

register(new URL('./support/vueStubHooks.mjs', import.meta.url))
const { vueFake } = await import('./support/vueFake.mjs')

const GOLDEN = new URL('./fixtures/integrationLeafRegistration.golden.json', import.meta.url)
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD || !fs.existsSync(GOLDEN) ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
const results = {}

const LEAVES = {
	hours: await import('../../src/integrations/registerHoursLeaf.js').then((m) => ({ fn: m.registerHoursLeaf, descriptor: m.hoursLeafDescriptor })),
	agenda: await import('../../src/integrations/registerAgendaLeaf.js').then((m) => ({ fn: m.registerAgendaLeaf, descriptor: m.agendaLeafDescriptor })),
}

/**
 * A JSON-safe picture of a global after registration: every key path, with
 * the leaf descriptor shown by its id and functions by their name.
 *
 * @param {*} value The value to describe.
 * @param {object} descriptor The leaf descriptor under test.
 * @return {*} The description.
 */
function describe(value, descriptor) {
	if (value === descriptor) {
		return `<descriptor ${descriptor.id}>`
	}
	if (typeof value === 'function') {
		return `<function ${value.name}>`
	}
	if (Array.isArray(value)) {
		return value.map((v) => describe(v, descriptor))
	}
	if (value !== null && typeof value === 'object') {
		return Object.fromEntries(Object.keys(value).map((k) => [k, describe(value[k], descriptor)]))
	}
	return value === undefined ? '<undefined>' : value
}

/**
 * A registry stand-in that records every call and its `this`.
 *
 * @return {object} The registry, with a `calls` list.
 */
function fakeRegistry() {
	const registry = { calls: [] }
	registry.register = function register(entry) {
		registry.calls.push({ entry, thisIsRegistry: this === registry })
	}
	return registry
}

const CASES = {
	'empty global gets the queue stub': () => ({ ref: {} }),
	'existing OCA keeps its keys': () => ({ ref: { OCA: { Files: { a: 1 } } } }),
	'existing OpenRegister keeps its keys': () => ({ ref: { OCA: { OpenRegister: { version: 3 } } } }),
	'installed registry is used, not replaced': () => ({ ref: { OCA: { OpenRegister: { integrations: fakeRegistry() } } } }),
	'existing queue stub is reused': () => ({ ref: { OCA: { OpenRegister: { integrations: { _queue: ['earlier'], register(e) { this._queue.push(e) } } } } } }),
	'twice on the stub queues twice': () => ({ ref: {}, times: 2 }),
	'no ref and no window does nothing': () => ({ ref: undefined, window: undefined }),
	'no ref falls back to window': () => ({ ref: undefined, window: {} }),
	'null ref falls back to window': () => ({ ref: null, window: { OCA: { kept: true } } }),
	'falsy ref falls back to window': () => ({ ref: 0, window: {} }),
	'empty string ref with no window does nothing': () => ({ ref: '', window: undefined }),
}

/**
 * Run one case for one leaf and describe what changed.
 *
 * @param {object} leaf The leaf's register function and descriptor.
 * @param {Function} make The case factory.
 * @return {object} The observation.
 */
function run(leaf, make) {
	const c = make()
	const hadWindow = 'window' in globalThis
	const oldWindow = globalThis.window
	if ('window' in c && c.window === undefined) {
		delete globalThis.window
	} else if ('window' in c) {
		globalThis.window = c.window
	}
	const returns = []
	try {
		for (let i = 0; i < (c.times || 1); i++) {
			returns.push(describe(leaf.fn(c.ref), leaf.descriptor))
		}
		return { returns, ref: describe(c.ref, leaf.descriptor), window: describe(globalThis.window, leaf.descriptor), windowDefined: typeof globalThis.window !== 'undefined' }
	} finally {
		if (hadWindow) {
			globalThis.window = oldWindow
		} else {
			delete globalThis.window
		}
	}
}

for (const [leafName, leaf] of Object.entries(LEAVES)) {
	for (const [caseName, make] of Object.entries(CASES)) {
		const key = `${leafName}: ${caseName}`
		test(key, () => {
			results[key] = run(leaf, make)
			if (!RECORD) {
				assert.deepEqual(results[key], recorded[key])
			}
		})
	}
}

/**
 * Mount and unmount a leaf through a fixed sequence of hosts and describe
 * every app it creates, mounts and unmounts.
 *
 * @param {object} descriptor The leaf descriptor.
 * @return {object} The observation.
 */
function mountScenario(descriptor) {
	vueFake.events.length = 0
	vueFake.apps.length = 0
	const steps = []
	const step = (label, fn) => {
		const from = vueFake.events.length
		let threw = null
		try {
			fn()
		} catch (e) {
			threw = e.message
		}
		steps.push({ label, threw, events: vueFake.events.slice(from) })
	}
	const el1 = { name: 'el1' }
	const el2 = { name: 'el2' }
	const el3 = { name: 'el3' }
	const fail = { name: 'fail', failMount: true }
	const props = { register: 'r', schema: 's', objectId: 'o1', surface: 'detail-page' }
	step('mount null', () => descriptor.mount(null, props))
	step('mount undefined', () => descriptor.mount(undefined, props))
	step('mount el1', () => descriptor.mount(el1, props))
	step('mount el1 again', () => descriptor.mount(el1, { objectId: 'other' }))
	step('mount el2 without props', () => descriptor.mount(el2))
	step('mount el3 with null props', () => descriptor.mount(el3, null))
	step('unmount unknown', () => descriptor.unmount({ name: 'unknown' }))
	step('unmount el1', () => descriptor.unmount(el1))
	step('unmount el1 again', () => descriptor.unmount(el1))
	step('mount el1 after unmount', () => descriptor.mount(el1, { objectId: 'o2' }))
	step('mount failing', () => descriptor.mount(fail, props))
	step('mount failing again', () => descriptor.mount(fail, props))
	step('unmount failing', () => descriptor.unmount(fail))
	step('unmount el2', () => descriptor.unmount(el2))
	step('unmount el3', () => descriptor.unmount(el3))
	step('unmount el1 last', () => descriptor.unmount(el1))
	return {
		steps,
		propsAreCopies: vueFake.apps.map((app) => app.props !== props),
		tIsTranslate: vueFake.apps.map((app) => app.config.globalProperties.t === translate),
	}
}

for (const [leafName, leaf] of Object.entries(LEAVES)) {
	for (const [key, make] of Object.entries({
		[`${leafName}: descriptor`]: () => Object.fromEntries(Object.entries(leaf.descriptor).map(([k, v]) => [k, describe(v, null)])),
		[`${leafName}: mount and unmount`]: () => mountScenario(leaf.descriptor),
	})) {
		test(key, () => {
			results[key] = make()
			if (!RECORD) {
				assert.deepEqual(results[key], recorded[key])
			}
		})
	}
}

test('the queue stub queues the exported descriptor object itself', () => {
	for (const leaf of Object.values(LEAVES)) {
		const ref = {}
		leaf.fn(ref)
		assert.equal(ref.OCA.OpenRegister.integrations._queue[0], leaf.descriptor)
	}
})

test.after(() => {
	if (RECORD) {
		fs.mkdirSync(new URL('./fixtures/', import.meta.url), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(results, null, '\t') + '\n')
	}
})
