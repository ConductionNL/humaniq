// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The live check of payroll-special-regimes (task 5.2): one run with the
 * seeded RVU benefit and the seeded alderman, and both payslips read.
 *
 * Every scenario of the change keeps its reason-bearing `@e2e exclude`, naming
 * the unit test that asserts it. This is the run on a real instance the task
 * asks for: ADM-001's 2026-07 run, the first month of `benefit-degroot-rvu`
 * (2000.00 a month to Wilma de Groot, EMP-0006), and the alderman
 * `employee-wethouder` (EMP-0040) on `contract-wethouder-ambt`. The screenshots
 * of both payslips are attached to the report.
 *
 * Until a maintainer verifies the `specialRegimes` leaves of nl-2026 the run
 * skips both people as regime-unverified, and so do these tests, naming that.
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. PayrollRunServiceTest writes the benefit
 * payslip and the alderman's insurance-free payslip over a gateway double;
 * whether OpenRegister keeps a second payslip of one person in one run apart
 * by `benefitEntitlementId`, and whether the pages show it, only a run shows.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext } from '@playwright/test'
import type { Created } from '../payroll-live.ts'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS } from '../base-url.ts'
import { calculatedRun, idOf, payslipsOf, removeCreated, screenshotOf, seededEmployee, skippedBy } from '../payroll-live.ts'

/**
 * Skip while the run refuses the person because the regime's table leaves
 * are unverified (payroll-special-regimes D2): every `specialRegimes` leaf of
 * nl-2026 is `verified: false` until a maintainer confirms the figures, and
 * the run then pays nothing rather than a guess. Once they are verified the
 * assertions below run.
 *
 * @param employeeId The employee.
 * @return Nothing.
 */
function skipWhileUnverified(employeeId: string): void {
	const reason = skippedBy.get(employeeId) || ''
	test.skip(reason.includes('unverified'), `the run skipped this person: ${reason} (nl-2026 specialRegimes leaves are not verified yet)`)
}

test.describe.configure({ mode: 'serial' })

test.describe('a run with an RVU benefit and an alderman', () => {
	let api: APIRequestContext
	let runId = ''
	const created: Created = []

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
		runId = await calculatedRun(api, 'ADM-001', '2026-07', created)
	})

	test.afterAll(async () => {
		await removeCreated(api, created)
		await api.dispose()
	})

	test('the RVU is a benefit payslip of 2000.00 of its own @spec REQ-PSR-001', async ({ page }, testInfo) => {
		const employee = await seededEmployee(api, 'EMP-0006')
		skipWhileUnverified(employee)
		const slips = await payslipsOf(api, runId, employee)
		const benefit = slips.filter((slip) => String(slip.benefitEntitlementId || '') !== '')
		expect(benefit.length, 'one benefit payslip for de Groot in 2026-07').toBe(1)
		expect(Number(benefit[0].grossPay)).toBe(2000)

		await screenshotOf(page, testInfo, `/payslips/${idOf(benefit[0])}`, 'payslip-rvu-2026-07')
	})

	test("the alderman's payslip carries no employee insurance @spec REQ-PSR-004", async ({ page }, testInfo) => {
		const employee = await seededEmployee(api, 'EMP-0040')
		skipWhileUnverified(employee)
		const slips = await payslipsOf(api, runId, employee)
		const salary = slips.filter((slip) => String(slip.benefitEntitlementId || '') === '')
		expect(salary.length, "one payslip for the alderman in 2026-07").toBe(1)
		expect(Number(salary[0].grossPay)).toBeGreaterThan(0)
		expect(Number(salary[0].werknemersverzekeringen || 0), 'no WW, WIA or ZW').toBe(0)

		await screenshotOf(page, testInfo, `/payslips/${idOf(salary[0])}`, 'payslip-wethouder-2026-07')
	})
})
