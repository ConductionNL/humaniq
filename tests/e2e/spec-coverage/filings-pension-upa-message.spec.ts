// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * filings-pension-upa-message: checking a pension filing on a real instance.
 *
 * Built and asserted here: "A filing that cannot be delivered stays open"
 * (REQ-PUM-001). The seeded on-call worker Ahmed El Idrissi (EMP-0032) has a
 * contract without hours per week. He is given a pfab participation and a
 * payslip on the seeded approved 2026-06 run of ADM-001, and a June pfab
 * filing on that run is checked (`controleren`): PensionMessageGuard refuses
 * and names him. Without his participation the same filing passes, which shows
 * the guard counts only employees in the fund's scheme.
 *
 * Not yet assertable, kept as `test.fixme` with the reason: the UPA file of
 * "A payroll officer produces the UPA delivery" and the APG file of "A
 * municipality's ABP delivery" (REQ-PUM-002). Both wait on tasks 2.1 and 2.3
 * (the fund formats and their builders).
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext } from '@playwright/test'
import type { Created, Row } from '../payroll-live.ts'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS } from '../base-url.ts'
import { HEADERS, idOf, list, NC_URL, OR_BASE, removeCreated, seededEmployee } from '../payroll-live.ts'

/**
 * Create an object and remember it for clean-up.
 *
 * @param api The admin context.
 * @param schema The schema.
 * @param data The object.
 * @param created Where it is recorded.
 * @return Its id.
 */
async function make(api: APIRequestContext, schema: string, data: Row, created: Created): Promise<string> {
	const res = await api.post(`${OR_BASE}/${schema}`, { headers: HEADERS, data })
	expect(res.ok(), `create ${schema} (${res.status()}): ${await res.text()}`).toBeTruthy()
	const id = idOf((await res.json()) as Row)
	created.push({ schema, id })
	return id
}

/**
 * Apply a lifecycle transition.
 *
 * @param api The admin context.
 * @param id The object.
 * @param action The transition.
 * @return The status and the body text.
 */
async function transition(api: APIRequestContext, id: string, action: string): Promise<{ ok: boolean; text: string }> {
	const res = await api.post(`${NC_URL}/index.php/apps/openregister/api/objects/${id}/transition`, { headers: HEADERS, data: { action } })
	return { ok: res.ok(), text: await res.text() }
}

test.describe.configure({ mode: 'serial' })

test.describe('checking a pension filing', () => {
	let api: APIRequestContext
	let runId = ''
	let employeeId = ''
	const created: Created = []

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
		const runs = await list(api, 'PayrollRun', { administrationId: 'ADM-001', period: '2026-06' })
		expect(runs.length, 'the seeded approved 2026-06 run of ADM-001 (load the example data first)').toBeGreaterThan(0)
		runId = idOf(runs[0])
		employeeId = await seededEmployee(api, 'EMP-0032')
		await make(api, 'Payslip', { payrollRunId: runId, employeeId, period: '2026-06', grossPay: 900, administrationId: 'ADM-001' }, created)
	})

	test.afterAll(async () => {
		await removeCreated(api, created)
		await api.dispose()
	})

	test('an employee outside the scheme does not hold the filing up @spec REQ-PUM-001', async () => {
		const filing = await make(api, 'PensionFiling', { payrollRunId: runId, period: '2026-06', fund: 'pfab', deadline: '2026-07-31', administrationId: 'ADM-001' }, created)

		const checked = await transition(api, filing, 'controleren')
		expect(checked.ok, `controleren without a pfab participation: ${checked.text}`).toBeTruthy()
	})

	test('an employee in the scheme without hours per week keeps the filing open @spec REQ-PUM-001', async () => {
		await make(api, 'PensionParticipation', { employeeId, fund: 'pfab', startDate: '2026-06-01', endDate: '2026-06-30', participationFactor: 1, administrationId: 'ADM-001' }, created)
		const filing = await make(api, 'PensionFiling', { payrollRunId: runId, period: '2026-06', fund: 'pfab', deadline: '2026-07-31', administrationId: 'ADM-001' }, created)

		const checked = await transition(api, filing, 'controleren')
		expect(checked.ok, 'controleren must be refused').toBeFalsy()
		expect(checked.text).toContain('Ahmed El Idrissi')
		expect(checked.text).toContain('deeltijdfactor')

		const res = await api.get(`${OR_BASE}/PensionFiling/${filing}`, { headers: HEADERS })
		expect(String(((await res.json()) as Row).status || '')).toBe('concept')
	})

	test.fixme('a payroll officer produces the UPA delivery @spec REQ-PUM-001', async () => {
		// Waits on tasks 2.1 and 2.3: the UPA format schema and UpaMessageBuilder.
	})

	test.fixme("a municipality's ABP delivery @spec REQ-PUM-002", async () => {
		// Waits on tasks 2.1 and 2.3: APG's delivery format and ApgMessageBuilder.
	})
})
