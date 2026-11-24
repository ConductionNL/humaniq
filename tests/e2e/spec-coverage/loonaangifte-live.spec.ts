// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The live checks of filings-wage-tax-message (task 3.2) and
 * filings-correction-message (task 3.3), in that order, because a correction
 * needs a sent humaniq message to compare against.
 *
 * Every scenario of both changes keeps its reason-bearing `@e2e exclude`. This
 * spec is the pass on a running instance the two tasks name, over the seed
 * administration ADM-001 and its approved 2026-06 run:
 *
 * 1. A concept wage tax return for ADM-001's 2026-06 is made (this run's own
 *    filing, removed afterwards). Make message renders it from the approved
 *    run with no blocking finding; the stored `messageXml` is a Loonaangifte
 *    message and, where `xmllint` is installed, validates against
 *    `lib/Standards/loonaangifte/Loonaangifte2026v2.0.xsd`. Set ready
 *    (klaarzetten) then passes LoonaangifteMessageGuard.
 * 2. The return is confirmed and sent, Correct opens a linked correction
 *    (`filingType: correctie`, `corrects` = the sent return), and Make message
 *    on it prepares the correction. Nothing changed in between, so it holds no
 *    changed employee and a saldo of zero.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const APP_API = `${NC_URL}/index.php/apps/humaniq/api`
const OBJECTS = `${NC_URL}/index.php/apps/openregister/api/objects`
const OR_BASE = `${OBJECTS}/humaniq`
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const XSD = join(__dirname, '../../../lib/Standards/loonaangifte/Loonaangifte2026v2.0.xsd')

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

test.describe.configure({ mode: 'serial' })

test.describe('a wage tax return made, set ready, sent and corrected', () => {
	let api: APIRequestContext
	let filingId = ''
	let correctionId = ''

	/**
	 * Read one filing.
	 *
	 * @param id The filing.
	 * @return The filing.
	 */
	async function filing(id: string): Promise<Row> {
		const res = await api.get(`${OR_BASE}/LoonaangifteFiling/${id}`, { headers: HEADERS })
		expect(res.ok(), `read filing ${id} (${res.status()})`).toBeTruthy()
		return (await res.json()) as Row
	}

	/**
	 * Move a filing along its lifecycle.
	 *
	 * @param id The filing.
	 * @param action The transition.
	 * @return Nothing.
	 */
	async function transition(id: string, action: string): Promise<void> {
		const res = await api.post(`${OBJECTS}/${id}/transition`, { headers: HEADERS, data: { action } })
		expect(res.ok(), `${action} (${res.status()}): ${await res.text()}`).toBeTruthy()
	}

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
		const made = await api.post(`${OR_BASE}/LoonaangifteFiling`, {
			headers: HEADERS,
			data: {
				period: '2026-06',
				deadline: '2026-07-31',
				jurisdiction: 'NL',
				filingType: 'loonaangifte',
				status: 'concept',
				administrationId: 'ADM-001',
			},
		})
		expect(made.ok(), `create the concept return (${made.status()}): ${await made.text()}`).toBeTruthy()
		filingId = idOf((await made.json()) as Row)
	})

	test.afterAll(async () => {
		for (const id of [correctionId, filingId].filter((value) => value !== '')) {
			await api.delete(`${OR_BASE}/LoonaangifteFiling/${id}`, { headers: HEADERS })
		}
		await api.dispose()
	})

	test('Make message renders a valid return from the approved run, and Set ready passes @spec REQ-LAM-001', async () => {
		const testInfo = test.info()
		const res = await api.post(`${APP_API}/loonaangifte/filings/${filingId}/message`, { headers: HEADERS })
		const outcome = (await res.json()) as Row
		expect(res.ok(), `make the message (${res.status()}): ${JSON.stringify(outcome)}`).toBeTruthy()
		expect(outcome.status).toBe('rendered')
		expect(Number(outcome.blockingFindings)).toBe(0)

		const stored = await filing(filingId)
		const xml = String(stored.messageXml || '')
		expect(xml, 'the message is stored').toContain('<')
		expect(xml).toMatch(/Loonaangifte/)
		expect(String(stored.messageRunId || ''), 'made from the approved run').not.toBe('')
		await testInfo.attach(String(stored.messageFileName || 'loonaangifte-2026-06.xml'), { body: Buffer.from(xml), contentType: 'application/xml' })

		const dir = mkdtempSync(join(tmpdir(), 'loonaangifte-'))
		const file = join(dir, 'message.xml')
		writeFileSync(file, xml)
		const lint = spawnSync('xmllint', ['--noout', '--schema', XSD, file], { encoding: 'utf8' })
		if (lint.error) {
			testInfo.annotations.push({ type: 'xmllint', description: 'xmllint is not installed on this runner; the XSD validation step did not run' })
		} else {
			expect(lint.status, `xmllint --schema: ${lint.stderr}`).toBe(0)
		}

		await transition(filingId, 'klaarzetten')
		expect((await filing(filingId)).status).toBe('klaargezet')
	})

	test('a sent return is corrected through a linked correction filing @spec REQ-LHC-001', async () => {
		await transition(filingId, 'bevestigen')
		await transition(filingId, 'verzenden')
		expect((await filing(filingId)).status).toBe('verzonden')

		const opened = await api.post(`${APP_API}/loonaangifte/filings/${filingId}/correction`, { headers: HEADERS })
		const outcome = (await opened.json()) as Row
		expect(opened.ok(), `open the correction (${opened.status()}): ${JSON.stringify(outcome)}`).toBeTruthy()
		expect(['opened', 'exists']).toContain(String(outcome.status))
		correctionId = String(outcome.filingId || '')

		const correction = await filing(correctionId)
		expect(correction.filingType).toBe('correctie')
		expect(correction.corrects).toBe(filingId)
		expect((await filing(filingId)).status, 'the sent return is never reopened').toBe('verzonden')

		const made = await api.post(`${APP_API}/loonaangifte/filings/${correctionId}/message`, { headers: HEADERS })
		const prepared = (await made.json()) as Row
		expect(made.ok(), `make the correction (${made.status()}): ${JSON.stringify(prepared)}`).toBeTruthy()
		expect(prepared.status).toBe('prepared')

		const after = await filing(correctionId)
		expect((after.correctionLines as Row[] | undefined) ?? [], 'nothing changed since the return was sent').toEqual([])
		expect(Number(after.correctionSaldo || 0)).toBe(0)
	})
})
