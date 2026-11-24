// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The AI companion starts on the HR question assistant on Mijn HR routes
// (hr-policy-assistant REQ-HPA-001, self-service-policy-assistant design D3).

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { defaultAgentFor, HR_ASSISTANT_AGENT, isMijnHrRoute } from '../../src/services/hrAssistantAgent.js'

test('the Mijn HR home and the pages below /mijn are Mijn HR routes', () => {
	assert.equal(isMijnHrRoute({ path: '/mijn', name: 'MijnHr' }), true)
	assert.equal(isMijnHrRoute({ path: '/mijn/loonstroken', name: 'MijnLoonstroken' }), true)
})

test('a self-service page with an English path counts by its Mijn page id', () => {
	assert.equal(isMijnHrRoute({ path: '/my-approvals', name: 'MijnGoedkeuringen' }), true)
})

test('HR pages, look-alike paths and a missing route are not Mijn HR', () => {
	assert.equal(isMijnHrRoute({ path: '/employees', name: 'Employees' }), false)
	assert.equal(isMijnHrRoute({ path: '/mijnbouw', name: 'Mining' }), false)
	assert.equal(isMijnHrRoute({ path: '/x', name: Symbol('MijnX') }), false)
	assert.equal(isMijnHrRoute(null), false)
	assert.equal(isMijnHrRoute(undefined), false)
})

test('the companion starts on the HR question assistant only on Mijn HR', () => {
	assert.equal(defaultAgentFor({ path: '/mijn/uren', name: 'MijnUren' }), HR_ASSISTANT_AGENT)
	assert.equal(defaultAgentFor({ path: '/employees', name: 'Employees' }), '')
})

test('the agent name is the name the template is offered to Hermiq under', () => {
	const listener = readFileSync(new URL('../../lib/Listener/OfferHrAssistantListener.php', import.meta.url), 'utf8')
	assert.match(listener, new RegExp(`public const NAME = '${HR_ASSISTANT_AGENT}';`))
})
