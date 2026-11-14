/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hours leaf's host contract (REQ-HLD-003): a host passes a register
 * slug, a schema slug and a uuid. With all three the card reads and books
 * against that object; with fewer it names the gap and draws no total, no
 * controls and no booking dialog.
 *
 * @spec openspec/changes/hours-leaf-loads-on-a-consuming-page/specs/hours-leaf-delivery/spec.md#requirement-the-leaf-states-what-a-host-page-must-pass-req-hld-003
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { HOST_PROPS, isResolvableHost, missingHostProps } from '../../src/integrations/hoursHost.mjs'

const sfc = readFileSync(new URL('../../src/integrations/CnHoursWidget.vue', import.meta.url), 'utf8')
const COMPLETE = { register: 'dossiq', schema: 'case', objectId: '7c1e2b9a-0b5f-4c39-9a51-2f0f6f3c8e11' }

test('a complete host resolves and misses nothing', () => {
	assert.deepEqual(missingHostProps(COMPLETE), [])
	assert.equal(isResolvableHost(COMPLETE), true)
})

test('each incomplete host is named by what it left out', () => {
	for (const name of HOST_PROPS) {
		const host = { ...COMPLETE, [name]: '' }
		assert.deepEqual(missingHostProps(host), [name], name)
		assert.equal(isResolvableHost(host), false, name)
	}
	assert.deepEqual(missingHostProps({ register: 'dossiq' }), ['schema', 'objectId'])
	assert.deepEqual(missingHostProps(undefined), HOST_PROPS)
})

test('blank and non-string values count as missing', () => {
	assert.deepEqual(missingHostProps({ ...COMPLETE, objectId: '   ' }), ['objectId'])
	assert.deepEqual(missingHostProps({ ...COMPLETE, schema: 7 }), ['schema'])
	assert.deepEqual(missingHostProps({ ...COMPLETE, register: null }), ['register'])
})

test('the card names the missing host before it would draw any figure', () => {
	const noHost = sfc.indexOf('<p v-if="!hostResolvable"')
	const running = sfc.indexOf('<div v-else-if="runningHere" class="hq-hours__figures"')
	const week = sfc.indexOf('<div v-else class="hq-hours__week-body">')

	assert.ok(noHost > 0, 'the no-host line is in the template')
	assert.ok(noHost < running && running < week, 'figures and the week are the else branches of the no-host line')
	assert.match(sfc.slice(noHost, running), /data-testid="hq-hours-no-host"/)
	assert.match(sfc.slice(noHost, running), /t\('humaniq', 'This card cannot show hours: the page did not say which item it is on\.'\)/)
})

test('without a host there are no controls and no booking dialog', () => {
	assert.match(sfc, /<div v-if="hostResolvable" class="hq-hours__controls">/)
	assert.match(sfc, /<HoursBookingDialog\s+v-if="showBooking && hostResolvable"/)
})

test('the card decides with the shared host rule, not a copy of it', () => {
	assert.match(sfc, /import \{ isResolvableHost \} from '\.\/hoursHost\.mjs'/)
	assert.match(sfc, /isResolvableHost\(\{ register: this\.register, schema: this\.schema, objectId: this\.objectId \}\)/)
})
