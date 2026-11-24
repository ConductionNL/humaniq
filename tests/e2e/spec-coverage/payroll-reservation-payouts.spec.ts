// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The live check of payroll-reservation-payouts (task 5.2): a May run that
 * pays the yearly holiday allowance, and its special-rate line read.
 *
 * Every scenario of the change keeps its reason-bearing `@e2e exclude`, naming
 * the unit test that asserts it. This is the run on a real instance the task
 * asks for: ADM-001 pays the holiday allowance once a year (seeded
 * `holidayAllowancePayout: yearly`, payout month May), so its 2026-05 run pays
 * the reserve as a special payment taxed at the special rate. The screenshot of
 * such a payslip is attached to the report.
 *
 * Until a maintainer verifies nl-2026's `bijzondereBeloningen` table the run
 * holds the payout as special-rate-unverified, and this test skips, naming it.
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. The pack's golden vectors and
 * PayrollRunServiceTest compute the special payment over doubles; whether the
 * running engine stores `specialPayment`, `specialTaxRate` and
 * `specialLoonheffing` on the payslip, and whether the payslip page shows the
 * line, only a run shows.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext } from '@playwright/test'
import type { Created } from '../payroll-live.ts'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS } from '../base-url.ts'
import { calculatedRun, heldBy, idOf, list, removeCreated, screenshotOf } from '../payroll-live.ts'

test.describe.configure({ mode: 'serial' })

test.describe('the May holiday allowance payout', () => {
	let api: APIRequestContext
	const created: Created = []

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
	})

	test.afterAll(async () => {
		await removeCreated(api, created)
		await api.dispose()
	})

	test('the May run pays the holiday allowance as a special payment at the special rate @spec REQ-RSP-001', async ({ page }, testInfo) => {
		const runId = await calculatedRun(api, 'ADM-001', '2026-05', created)
		const slips = await list(api, 'Payslip', { payrollRunId: runId })
		const paidOut = slips.filter((slip) => Number(slip.specialPayment || 0) > 0)
		// Nothing is taxed with a guessed percentage: while nl-2026's
		// bijzondereBeloningen table is unverified the run holds the payout
		// (special-rate-unverified, design D3) and so this check waits.
		const held = [...heldBy.values()].filter((reason) => reason.includes('unverified'))
		test.skip(paidOut.length === 0 && held.length > 0, `the run held the payout: ${held[0]} (the 2026 special-rate table is not verified yet)`)
		expect(paidOut.length, 'at least one payslip in May pays the holiday allowance').toBeGreaterThan(0)

		const slip = paidOut[0]
		expect(Number(slip.specialTaxRate), 'taxed at the special rate').toBeGreaterThan(0)
		expect(Number(slip.specialLoonheffing), 'the special-rate wage tax').toBeGreaterThan(0)
		expect(Number(slip.specialLoonheffing)).toBeLessThan(Number(slip.specialPayment))

		await screenshotOf(page, testInfo, `/payslips/${idOf(slip)}`, 'payslip-may-holiday-allowance')
	})
})
