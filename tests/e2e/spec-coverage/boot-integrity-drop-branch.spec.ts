// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * humaniq-boot-integrity task 5.5b: the drop branch of the workspace filter,
 * checked on its own, as the known unscoped exposure it is, not as a pass.
 *
 * 5.5 verified the positive control: a session whose active administratie is
 * ADM-001 sends `administrationId=ADM-001` and gets ADM-001's employees only.
 * The optional token `@workspace.activeAdministrationId?` drops its clause
 * when nothing is selected. This spec shows what that means: an HR user who
 * never chose an administratie opens Employees, the list request carries NO
 * `administrationId` key at all (and no unresolved `@workspace` token), and
 * the answer is every employee the register holds, across administraties.
 * The multi-administratie delta records this as the exposure it is.
 *
 * The user is provisioned fresh, in `humaniq-hr`, so no earlier switch can
 * have left a selection behind. Base URL and admin credentials from the
 * environment (base-url.ts). Not run when written.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq`
const OCS = { 'OCS-APIRequest': 'true' }
const RUN_ID = `e2e-bid-${Date.now()}-${randomUUID().slice(0, 8)}`
const HR_USER = `${RUN_ID}-hr`
const HR_PASS = `Bid-${randomUUID()}`

test.describe('an HR user with no administratie selected', () => {
	let admin: APIRequestContext

	test.beforeAll(async () => {
		admin = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
		const made = await admin.post(`${NC_URL}/ocs/v2.php/cloud/users?format=json`, {
			headers: OCS,
			form: { userid: HR_USER, password: HR_PASS, displayName: HR_USER },
		})
		expect(made.ok(), `provision the HR user: ${await made.text()}`).toBeTruthy()
		const grouped = await admin.post(`${NC_URL}/ocs/v2.php/cloud/users/${HR_USER}/groups?format=json`, {
			headers: OCS,
			form: { groupid: 'humaniq-hr' },
		})
		expect(grouped.ok(), `add the user to humaniq-hr: ${await grouped.text()}`).toBeTruthy()
	})

	test.afterAll(async () => {
		await admin.delete(`${NC_URL}/ocs/v2.php/cloud/users/${HR_USER}?format=json`, { headers: OCS })
		await admin.dispose()
	})

	// @e2e multi-administratie::an-administratie-scoped-index-page-never-sends-its-filter-token-unresolved
	test('the Employees list drops the administratie clause and reads every employee (known unscoped exposure) @spec REQ-MULTI-004', async ({ browser }) => {
		const all = await admin.get(`${OR_BASE}/Employee?_limit=1`, { headers: { ...OCS, Accept: 'application/json' } })
		expect(all.ok()).toBeTruthy()
		const everyone = Number(((await all.json()) as Record<string, unknown>).total)
		expect(everyone, 'the register holds employees (load the example data first)').toBeGreaterThan(0)

		const context = await browser.newContext({ httpCredentials: { username: HR_USER, password: HR_PASS }, storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		const listed = page.waitForResponse((res) => /\/openregister\/api\/objects\/[^/]+\/employee(\?|$)/i.test(res.url()) && res.request().method() === 'GET', { timeout: 60000 })
		await page.goto(`${NC_URL}/index.php/apps/humaniq/employees`, { waitUntil: 'domcontentloaded' })
		const res = await listed

		const url = new URL(res.url())
		expect(url.searchParams.has('administrationId'), `no administratie clause in ${url.search}`).toBe(false)
		expect(decodeURIComponent(url.search), 'no unresolved token reaches the API').not.toContain('@workspace')
		expect(res.ok()).toBeTruthy()
		const body = (await res.json()) as Record<string, unknown>
		expect(Number(body.total), 'every employee, across administraties: the unscoped exposure').toBe(everyone)
		test.info().annotations.push({ type: 'exposure', description: `unscoped list: ${everyone} employees for a user with no administratie selected` })

		await context.close()
	})
})
