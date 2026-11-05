/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The flow pages use the canonical form: an index over the `flows` source whose
 * rows open a page of type `flow`, on the addresses links already point at.
 *
 * @spec exclude Structural invariant of the shipped manifest; no behavioural spec.
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { createMemoryHistory, createRouter } from 'vue-router'

const { pages } = JSON.parse(readFileSync(new URL('../../src/manifest.effective.json', import.meta.url), 'utf8'))
const byId = new Map(pages.map((page) => [page.id, page]))
const router = createRouter({
	history: createMemoryHistory(),
	routes: pages.map((page) => ({ name: page.id, path: page.route, component: {} })),
})

test('no page names a deprecated flow page type', () => {
	assert.deepEqual(pages.filter((page) => ['flows', 'flow-detail'].includes(page.type)).map((page) => page.id), [])
})

test('the flow list is an index over the flows source, scoped to this app', () => {
	const { type, config } = byId.get('Flows')
	assert.equal(type, 'index')
	assert.equal(config.entitySource, 'flows')
	assert.equal(config.app, 'humaniq')
})

test('a row opens a flow page', () => {
	assert.equal(byId.get(byId.get('Flows').config.rowRoute).type, 'flow')
})

test('the flow addresses still land on the flow pages', () => {
	assert.equal(router.resolve('/flows').name, 'Flows')
	assert.equal(router.resolve('/flows/new').name, 'FlowDetail')
	assert.equal(router.resolve('/flows/abc').name, 'FlowDetail')
})
