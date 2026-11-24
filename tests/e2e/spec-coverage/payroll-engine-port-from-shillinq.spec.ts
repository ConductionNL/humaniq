// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The page-level checks of payroll-engine-port-from-shillinq (task 5.1).
 *
 * The calculation scenarios keep their reason-bearing `@e2e exclude` in the
 * change's specs, each naming the unit test that asserts it. Two scenarios
 * have a step a person sees, and are checked here on a running instance:
 *
 * 1. HR reads a starter's payslip: an employee who starts on 16 June is
 *    calculated in the June run of ADM-001, the payslip carries period factor
 *    0.5, and its page shows the Partial period block with the factor and
 *    the dates.
 * 2. An overlapping participation is refused: a second open participation of
 *    the same employee is not saved, and the refusal names the stored one.
 *
 * Every object the checks make is deleted again. When the June run of
 * ADM-001 is no longer a draft the first check skips, saying so, because an
 * approved run is never recalculated. Base URL and admin credentials from
 * the environment (base-url.ts). Not run when written.
 */

import type { APIRequestContext } from '@playwright/test'
import type { Created, Row } from '../payroll-live.ts'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS } from '../base-url.ts'
import { calculatedRun, HEADERS, idOf, list, OR_BASE, payslipsOf, removeCreated, screenshotOf } from '../payroll-live.ts'

const STAMP = `${Date.now()}`

test.describe.configure({ mode: 'serial' })

test.describe('payroll engine port: partial period and pension participation', () => {
	let api: APIRequestContext
	const created: Created = []

	/**
	 * Create an object and remember it for clean-up.
	 *
	 * @param schema The schema.
	 * @param data The object.
	 * @return The saved object.
	 */
	async function make(schema: string, data: Row): Promise<Row> {
		const res = await api.post(`${OR_BASE}/${schema}`, { headers: HEADERS, data })
		expect(res.ok(), `create ${schema} (${res.status()}): ${await res.text()}`).toBeTruthy()
		const row = (await res.json()) as Row
		created.push({ schema, id: idOf(row) })
		return row
	}

	/**
	 * A payroll-ready employee of ADM-001 with a contract from the start date.
	 *
	 * @param startDate The employment start.
	 * @param salary The monthly salary.
	 * @return The employee id.
	 */
	async function employee(startDate: string, salary: number): Promise<string> {
		const id = idOf(await make('Employee', {
			employeeNumber: `E2E-PEP-${STAMP}-${created.length}`,
			firstName: 'Periode',
			lastName: `Factor ${STAMP}`,
			dateOfBirth: '1990-04-12',
			startDate,
			grossMonthlySalary: salary,
			taxTableColor: 'wit',
			loonheffingskortingToegepast: true,
			bsn: '123456782',
			identityDocumentVerified: true,
			administrationId: 'ADM-001',
		}))
		await make('EmploymentContract', { employeeId: id, type: 'permanent', writtenContract: true, startDate, hoursPerWeek: 36, awfTariff: 'low', administrationId: 'ADM-001' })
		return id
	}

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
	})

	test.afterAll(async () => {
		await removeCreated(api, created)
		await api.dispose()
	})

	// @e2e payroll-partial-period::hr-reads-a-starters-payslip
	test('HR reads a starter\'s payslip @spec REQ-PPR-001 @spec REQ-PPR-002', async ({ page }, testInfo) => {
		const runs = await list(api, 'PayrollRun', { administrationId: 'ADM-001', period: '2026-06' })
		const status = runs.length > 0 ? String(runs[0].status || '') : 'draft'
		test.skip(status !== 'draft', `the 2026-06 run of ADM-001 is ${status}; an approved run is never recalculated`)

		const employeeId = await employee('2026-06-16', 3000)
		const runId = await calculatedRun(api, 'ADM-001', '2026-06', created)
		const slips = await payslipsOf(api, runId, employeeId)
		expect(slips.length, 'the starter has a June payslip').toBe(1)
		const slip = slips[0]
		expect(Number(slip.periodFactor)).toBe(0.5)
		expect(Number(slip.grossPay)).toBe(1500)
		expect(slip.periodFactorFrom).toBe('2026-06-16')
		expect(slip.periodFactorUntil).toBe('2026-06-30')
		created.push({ schema: 'Payslip', id: idOf(slip) })

		await screenshotOf(page, testInfo, `/payslips/${idOf(slip)}`, 'payslip-partial-period')
		const block = page.getByText('Partial period', { exact: false }).first()
		await expect(block).toBeVisible()
		await expect(page.getByText('0.5', { exact: false }).first()).toBeVisible()
	})

	// @e2e payroll-pension-premium::an-overlapping-participation-is-refused
	test('an overlapping participation is refused @spec REQ-PPP-002', async () => {
		const employeeId = await employee('2026-01-01', 4000)
		const first = await make('PensionParticipation', { employeeId, fund: 'pfab', startDate: '2026-01-01', participationFactor: 1 })

		const second = await api.post(`${OR_BASE}/PensionParticipation`, { headers: HEADERS, data: { employeeId, fund: 'pfab', startDate: '2026-06-01', participationFactor: 1 } })
		const text = await second.text()
		if (second.ok()) {
			created.push({ schema: 'PensionParticipation', id: idOf(JSON.parse(text) as Row) })
		}
		expect(second.ok(), 'the second open participation is not saved').toBeFalsy()
		expect(text, 'the refusal names the stored participation').toContain(idOf(first))
	})
})
