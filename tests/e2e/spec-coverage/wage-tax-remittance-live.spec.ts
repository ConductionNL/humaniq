// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The live check of payroll-wage-tax-remittance-shillinq (task 3.4): a
 * confirmed wage tax return handed to shillinq as a draft payable.
 *
 * Every scenario of the change keeps its reason-bearing `@e2e exclude`. This
 * spec is the pass on an instance with shillinq the task names. It skips,
 * saying so, where shillinq is not enabled.
 *
 * 1. A Belastingdienst payee is created in shillinq and humaniq's
 *    `wagetax_payee_id` is pointed at it (the previous value is restored after).
 * 2. `occ humaniq:wagetax:remit --period 2026-06` runs for the seed's
 *    confirmed 2026-06 return of ADM-001 (payment reference
 *    1234000000006060, from the approved 2026-06 run).
 * 3. humaniq's WageTaxRemittance for that run reads `created` and names the
 *    payable, and shillinq holds it as a draft APTransaction to that payee.
 *    A second run of the command writes nothing new (once only, design D5).
 *
 * occ is reached through `occPrefix()` (shared-instance.ts): HUMANIQ_E2E_OCC,
 * a container name, or `php occ` from the server root (NEXTCLOUD_ROOT, else
 * two directories above this app). Base URL and admin credentials from the
 * environment (base-url.ts). Not run when written.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { spawnSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { join } from 'node:path'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'
import { occPrefix } from '../shared-instance.ts'

const NC_URL = resolveBaseURL()
const OBJECTS = `${NC_URL}/index.php/apps/openregister/api/objects`
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const RUN_ID = `e2e-wtr-${Date.now()}-${randomUUID().slice(0, 8)}`
const SERVER_ROOT = process.env.NEXTCLOUD_ROOT || join(__dirname, '../../../../..')

type Row = Record<string, unknown>

/**
 * Run occ on the instance under test.
 *
 * @param args The occ arguments.
 * @return Exit status and output.
 */
function occ(args: string[]): { status: number; out: string } {
	const [command, ...prefix] = occPrefix()
	const run = spawnSync(command, [...prefix, ...args], { cwd: SERVER_ROOT, encoding: 'utf8' })
	return { status: run.status ?? (run.error ? 127 : 1), out: `${run.stdout || ''}${run.stderr || ''}${run.error ? String(run.error) : ''}` }
}

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

test.describe.configure({ mode: 'serial' })

test.describe('a confirmed wage tax return becomes a draft payable in shillinq', () => {
	let api: APIRequestContext
	let payeeId = ''
	let previousPayee = ''
	let remittanceBefore = false

	/**
	 * List objects of a register's schema.
	 *
	 * @param register The register.
	 * @param schema The schema.
	 * @param filter The filter.
	 * @return The rows.
	 */
	async function list(register: string, schema: string, filter: Record<string, string>): Promise<Row[]> {
		const res = await api.get(`${OBJECTS}/${register}/${schema}?${new URLSearchParams({ ...filter, _limit: '50' })}`, { headers: HEADERS })
		expect(res.ok(), `${register}/${schema} list (${res.status()})`).toBeTruthy()
		const body = (await res.json()) as Row | Row[]
		return Array.isArray(body) ? body : ((body.results as Row[]) ?? [])
	}

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })

		const apps = occ(['app:list', '--output=json'])
		expect(apps.status, `occ app:list: ${apps.out}`).toBe(0)
		const enabled = Object.keys(((JSON.parse(apps.out) as Row).enabled || {}) as Row)
		test.skip(!enabled.includes('shillinq'), 'needs shillinq enabled on the instance under test')

		const payee = await api.post(`${OBJECTS}/shillinq/Payee`, {
			headers: HEADERS,
			data: { name: `Belastingdienst ${RUN_ID}`, iban: 'NL86INGB0002445588' },
		})
		expect(payee.ok(), `create the Belastingdienst payee in shillinq (${payee.status()}): ${await payee.text()}`).toBeTruthy()
		payeeId = idOf((await payee.json()) as Row)

		const before = occ(['config:app:get', 'humaniq', 'wagetax_payee_id'])
		previousPayee = before.status === 0 ? before.out.trim() : ''
		const set = occ(['config:app:set', 'humaniq', 'wagetax_payee_id', '--value', payeeId])
		expect(set.status, `set wagetax_payee_id: ${set.out}`).toBe(0)

		remittanceBefore = (await list('humaniq', 'WageTaxRemittance', { period: '2026-06', administrationId: 'ADM-001' })).length > 0
	})

	test.afterAll(async () => {
		if (previousPayee !== '') {
			occ(['config:app:set', 'humaniq', 'wagetax_payee_id', '--value', previousPayee])
		} else if (payeeId !== '') {
			occ(['config:app:delete', 'humaniq', 'wagetax_payee_id'])
		}
		if (payeeId !== '' && remittanceBefore === false) {
			for (const record of await list('humaniq', 'WageTaxRemittance', { period: '2026-06', administrationId: 'ADM-001' })) {
				const payable = String(record.shillinqPayableRef || '')
				if (payable !== '') {
					await api.delete(`${OBJECTS}/shillinq/APTransaction/${payable}`, { headers: HEADERS })
				}
				await api.delete(`${OBJECTS}/humaniq/WageTaxRemittance/${idOf(record)}`, { headers: HEADERS })
			}
		}
		if (payeeId !== '') {
			await api.delete(`${OBJECTS}/shillinq/Payee/${payeeId}`, { headers: HEADERS })
		}
		await api.dispose()
	})

	test('occ humaniq:wagetax:remit writes one draft payable to the Belastingdienst @spec REQ-PWR-001', async () => {
		test.skip(remittanceBefore, 'the 2026-06 return was handed over before this run; the once-only rule keeps it, so this run cannot show a fresh hand-off')

		const remit = occ(['humaniq:wagetax:remit', '--period', '2026-06'])
		expect(remit.status, `occ humaniq:wagetax:remit: ${remit.out}`).toBe(0)

		const records = await list('humaniq', 'WageTaxRemittance', { period: '2026-06', administrationId: 'ADM-001' })
		expect(records.length, 'one hand-off record for the 2026-06 run').toBe(1)
		const record = records[0]
		expect(record.status, `the hand-off: ${String(record.errorMessage || '')}`).toBe('created')
		expect(String(record.paymentReference)).toBe('1234000000006060')
		const payableId = String(record.shillinqPayableRef || '')
		expect(payableId, 'the payable is named').not.toBe('')

		const res = await api.get(`${OBJECTS}/shillinq/APTransaction/${payableId}`, { headers: HEADERS })
		expect(res.ok(), `the payable in shillinq (${res.status()})`).toBeTruthy()
		const payable = (await res.json()) as Row
		expect(payable.vendorId, 'addressed to the Belastingdienst payee').toBe(payeeId)
		expect(payable.state, 'a draft: humaniq pays nothing').toBe('draft')

		const again = occ(['humaniq:wagetax:remit', '--period', '2026-06'])
		expect(again.status, `the second run: ${again.out}`).toBe(0)
		expect((await list('humaniq', 'WageTaxRemittance', { period: '2026-06', administrationId: 'ADM-001' })).length, 'once only').toBe(1)
	})
})
