// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The live check of payroll-period-edge-cases (task 5.2): a four-weekly
 * period 13 that takes in week 53, and an annual statement that adds the
 * opening balance of a year that started elsewhere.
 *
 * Every scenario of the change keeps its reason-bearing `@e2e exclude`: each
 * outcome is asserted by the unit test it names. This spec is the one run on a
 * real instance the task asks for, over the seeded four-weekly administration
 * (ADM-007, `extend-period-13`, employee EMP-0701) and the seeded opening
 * balance of Wilma de Groot (EMP-0006, ADM-001, six periods of 2026 paid
 * elsewhere). The screenshots the task asks for are attached to the report.
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. PayrollRunServiceTest calculates the
 * extended period 13 over a gateway double, and HrDocumentServiceTest adds the
 * balance to a statement over another. Neither shows OpenRegister storing the
 * `week53` block on the payslip, nor the statement stamped
 * `includesOpeningBalance` on a running instance, nor the pages showing them.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext } from '@playwright/test'
import type { Created, Row } from '../payroll-live.ts'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS } from '../base-url.ts'
import { APP_API, calculatedRun, HEADERS, idOf, list, OR_BASE, payslipsOf, removeCreated, screenshotOf, seededEmployee } from '../payroll-live.ts'

test.describe.configure({ mode: 'serial' })

test.describe('period 13 with week 53, and a statement with an opening balance', () => {
	let api: APIRequestContext
	const created: Created = []

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
	})

	test.afterAll(async () => {
		await removeCreated(api, created)
		await api.dispose()
	})

	test('2026-P13 of the four-weekly administration carries week 53 on the payslip @spec REQ-PPE-002', async ({ page }, testInfo) => {
		const employee = await seededEmployee(api, 'EMP-0701')
		const runId = await calculatedRun(api, 'ADM-007', '2026-P13', created)

		const slips = await payslipsOf(api, runId, employee)
		expect(slips.length, 'one payslip for EMP-0701 in 2026-P13').toBe(1)
		const slip = slips[0]
		expect(slip.period).toBe('2026-P13')
		expect(Object.keys((slip.week53 || {}) as Row).length, 'the extended period 13 shows its week-53 part').toBeGreaterThan(0)
		expect(Number(slip.grossPay), 'four weeks plus one week of 3250 x 12 / 13').toBeGreaterThan(3000)

		await screenshotOf(page, testInfo, `/payslips/${idOf(slip)}`, 'payslip-2026-P13')
		await expect(page.getByText('2026-P13').first()).toBeVisible()
	})

	test('the 2026 statement of an employee with an opening balance adds that balance @spec REQ-PPE-003', async ({ page }, testInfo) => {
		const employee = await seededEmployee(api, 'EMP-0006')
		const balances = await list(api, 'PayrollOpeningBalance', { employeeId: employee, year: '2026' })
		expect(balances.length, 'the seeded opening balance of de Groot for 2026').toBe(1)

		const existing = await list(api, 'Jaaropgaaf', { employeeId: employee, year: '2026' })
		let statementId = existing.length > 0 ? idOf(existing[0]) : ''
		if (statementId === '') {
			const made = await api.post(`${OR_BASE}/Jaaropgaaf`, { headers: HEADERS, data: { employeeId: employee, year: 2026 } })
			expect(made.ok(), `create the 2026 statement (${made.status()}): ${await made.text()}`).toBeTruthy()
			statementId = idOf((await made.json()) as Row)
			created.push({ schema: 'Jaaropgaaf', id: statementId })
		}

		const gen = await api.post(`${APP_API}/documents/generate`, {
			headers: HEADERS,
			data: { documentType: 'jaaropgaaf', jaaropgaafId: statementId },
		})
		expect(gen.status(), `generate the statement: ${await gen.text()}`).toBeLessThan(500)

		const res = await api.get(`${OR_BASE}/Jaaropgaaf/${statementId}`, { headers: HEADERS })
		expect(res.ok()).toBeTruthy()
		const statement = (await res.json()) as Row
		expect(statement.includesOpeningBalance, 'the statement says it includes the opening balance').toBe(true)
		expect(String(statement.openingBalanceSource || ''), 'and names its source').not.toBe('')

		await screenshotOf(page, testInfo, `/jaaropgaven/${statementId}`, 'jaaropgaaf-2026-opening-balance')
	})
})
