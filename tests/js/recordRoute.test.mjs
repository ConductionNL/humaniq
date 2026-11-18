// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// recordRoute: each Compliance check row opens the record it is about
// (REQ-RULE-010, "a violation that opens the contract").

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { recordRoute } from '../../src/widgets/recordRoute.js'

const routes = { EmploymentContract: 'EmploymentContractDetail', Payslip: 'PayslipDetail' }

test('a contract violation opens the contract', () => {
	assert.deepEqual(
		recordRoute(routes, 'schema', { id: 'ct-1', schema: 'EmploymentContract' }),
		{ name: 'EmploymentContractDetail', params: { id: 'ct-1' } },
	)
})

test('the schema field defaults to schema and @self.id is read', () => {
	assert.deepEqual(
		recordRoute(routes, undefined, { '@self': { id: 'ps-1' }, schema: 'Payslip' }),
		{ name: 'PayslipDetail', params: { id: 'ps-1' } },
	)
})

test('a row without a detail page or id opens nothing', () => {
	assert.equal(recordRoute(routes, 'schema', { id: 'lb-1', schema: 'LeaveBalance' }), null)
	assert.equal(recordRoute(routes, 'schema', { schema: 'Payslip' }), null)
	assert.equal(recordRoute(null, 'schema', { id: 'x', schema: 'Payslip' }), null)
	assert.equal(recordRoute(routes, 'schema', null), null)
})
