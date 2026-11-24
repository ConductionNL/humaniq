// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The live check of payroll-period-edge-cases (task 5.2): a four-weekly
 * period 13 that takes in week 53, and an annual statement that adds the
 * opening balance of a year that started elsewhere.
 *
 * openspec/changes/payroll-period-edge-cases/specs (every scenario keeps its
 * reason-bearing `@e2e exclude`: each outcome is asserted by the unit test it
 * names). This spec is the one run on a real instance the task asks for: the
 * seeded four-weekly administration (ADM-007, `extend-period-13`, employee
 * EMP-0701) and the seeded opening balance of Wilma de Groot (EMP-0006, ADM-001,
 * six periods of 2026 paid elsewhere). The screenshots the task asks for are
 * attached to the test report.
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. PayrollRunServiceTest calculates the
 * extended period 13 over a gateway double, and HrDocumentServiceTest adds the
 * balance to a statement over another. Neither shows OpenRegister storing the
 * `week53` block on the payslip, nor the statement aggregate stamped
 * `includesOpeningBalance` on a running instance, nor the pages showing them.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext, Page, TestInfo } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const APP_URL = `${NC_URL}/index.php/apps/humaniq`
const APP_API = `${APP_URL}/api`
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq`
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

type Row = Record<string, unknown>

/**
 * The id of an object as the objects API returns it.
 *
 * @param row The object.
 * @return Its id.
 */
function idOf(row: Row | undefined): string {
	const self = (row?.['@self'] || {}) as Row
	return String(self.id || row?.id || '')
}

/**
 * Attach a full-page screenshot to the report.
 *
 * @param page The page.
 * @param testInfo The running test.
 * @param name The attachment name.
 * @return Nothing.
 */
async function snap(page: Page, testInfo: TestInfo, name: string): Promise<void> {
	await testInfo.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' })
}

test.describe.configure({ mode: 'serial' })

test.describe('period 13 with week 53, and a statement with an opening balance', () => {
	let api: APIRequestContext
	const created: Array<{ schema: string; id: string }> = []

	/**
	 * List objects of a schema.
	 *
	 * @param schema The schema.
	 * @param filter The filter.
	 * @return The rows.
	 */
	async function list(schema: string, filter: Record<string, string>): Promise<Row[]> {
		const res = await api.get(`${OR_BASE}/${schema}?${new URLSearchParams({ ...filter, _limit: '100' })}`, { headers: HEADERS })
		expect(res.ok(), `${schema} list (${res.status()})`).toBeTruthy()
		const body = (await res.json()) as Row | Row[]
		return Array.isArray(body) ? body : ((body.results as Row[]) ?? [])
	}

	/**
	 * The id of the one seeded employee with this number.
	 *
	 * @param employeeNumber The employee number.
	 * @return The id.
	 */
	async function employeeId(employeeNumber: string): Promise<string> {
		const rows = await list('Employee', { employeeNumber })
		expect(rows.length, `the seeded employee ${employeeNumber} (load the example data first)`).toBeGreaterThan(0)
		return idOf(rows[0])
	}

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
	})

	test.afterAll(async () => {
		for (const { schema, id } of created.reverse()) {
			await api.delete(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS })
		}
		await api.dispose()
	})

	test('2026-P13 of the four-weekly administration carries week 53 on the payslip @spec REQ-PPE-002', async ({ page }, testInfo) => {
		const employee = await employeeId('EMP-0701')

		// Reuse a draft run another pass left; a run past draft is read as it stands.
		const existing = await list('PayrollRun', { administrationId: 'ADM-007', period: '2026-P13' })
		let runId = existing.length > 0 ? idOf(existing[0]) : ''
		const status = existing.length > 0 ? String(existing[0].status || '') : 'draft'
		if (runId === '') {
			const made = await api.post(`${OR_BASE}/PayrollRun`, {
				headers: HEADERS,
				data: { period: '2026-P13', administrationId: 'ADM-007', status: 'draft' },
			})
			expect(made.ok(), `create the 2026-P13 run (${made.status()}): ${await made.text()}`).toBeTruthy()
			runId = idOf((await made.json()) as Row)
			created.push({ schema: 'PayrollRun', id: runId })
		}
		if (status === 'draft') {
			const calc = await api.post(`${APP_API}/payroll/calculate`, { headers: HEADERS, data: { runId } })
			expect(calc.ok(), `calculate 2026-P13 (${calc.status()}): ${await calc.text()}`).toBeTruthy()
		}

		const slips = await list('Payslip', { payrollRunId: runId, employeeId: employee })
		expect(slips.length, 'one payslip for EMP-0701 in 2026-P13').toBe(1)
		const slip = slips[0]
		expect(slip.period).toBe('2026-P13')
		const week53 = (slip.week53 || {}) as Row
		expect(Object.keys(week53).length, 'the extended period 13 shows its week-53 part').toBeGreaterThan(0)
		expect(Number(slip.grossPay), 'four weeks plus one week of 3250 x 12 / 13').toBeGreaterThan(3000)

		await page.goto(`${APP_URL}/payslips/${idOf(slip)}`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText('2026-P13').first()).toBeVisible({ timeout: 30000 })
		await snap(page, testInfo, 'payslip-2026-P13')
	})

	test('the 2026 statement of an employee with an opening balance adds that balance @spec REQ-PPE-003', async ({ page }, testInfo) => {
		const employee = await employeeId('EMP-0006')
		const balances = await list('PayrollOpeningBalance', { employeeId: employee, year: '2026' })
		expect(balances.length, 'the seeded opening balance of de Groot for 2026').toBe(1)

		const existing = await list('Jaaropgaaf', { employeeId: employee, year: '2026' })
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

		await page.goto(`${APP_URL}/jaaropgaven/${statementId}`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText('2026').first()).toBeVisible({ timeout: 30000 })
		await snap(page, testInfo, 'jaaropgaaf-2026-opening-balance')
	})
})
