// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Exporting a humaniq list (reporting-full-list-export 3.1).
 *
 * openspec/changes/reporting-full-list-export/specs/hr-list-export/spec.md
 *   Scenario: HR exports all active employees
 *   Scenario: A self-service list exports only the user's own rows
 *   Scenario: The sick leave list has no export
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. ListExportDeclarationTest proves which
 * schemas are flagged exportable and tests/js/indexExport.test.mjs proves that
 * every index page asks for the Export menu. Neither proves that the menu
 * appears, that the file it downloads follows the table's search and filter
 * rather than the fetched page, or that OpenRegister's row rights apply to the
 * file an employee downloads. Those sit between the library, OpenRegister and
 * the browser, so they are here.
 *
 * MORE ROWS THAN ONE PAGE. The first test seeds 25 matching employees, more than
 * the list fetches for its first page, plus 3 that the filter leaves out. A file
 * that held only the fetched page would come up short, and one that ignored the
 * filter would hold 28.
 *
 * THE FILTER. The Employees list declares one facet, `taxTableColor`, and no
 * "active" filter (an active employee is one without an `endDate`). The test
 * filters on that facet through the route query, which the list reads as its
 * facet filters, and narrows to this run with the search box. What it proves is
 * the requirement's: the file holds every row the table is filtered to.
 *
 * 🔴 THE EMPLOYEE IS NOT THE ADMIN. The self-service test signs in as an account
 * this file provisions, from an empty storage state, so the admin's session and
 * the admin's wider read rights cannot ride along.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext, Browser, Download, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq`
const RUN_ID = `e2e-hle-${Date.now()}-${randomUUID().slice(0, 8)}`
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

/** The self-service employee's account. */
const EMPLOYEE_USER = `${RUN_ID}-uid`
const EMPLOYEE_PASS = `Hle-${randomUUID()}`

/** How many seeded employees match the filter, and how many it leaves out. */
const MATCHING = 25
const LEFT_OUT = 3

/**
 * Read an object's id out of either payload shape OpenRegister returns.
 *
 * @param row The object as returned.
 * @return The id, or an empty string.
 */
function idOf(row: Record<string, unknown> | undefined): string {
	const self = (row?.['@self'] || {}) as Record<string, unknown>
	return String(self.id || row?.id || '')
}

/**
 * The lines of a downloaded CSV that carry this run's marker.
 *
 * @param download The finished download.
 * @return The matching data lines.
 */
async function runLines(download: Download): Promise<string[]> {
	const path = await download.path()
	expect(path, 'the export produced a file').toBeTruthy()
	const text = await readFile(String(path), 'utf8')
	return text.split(/\r?\n/).filter((line) => line.includes(RUN_ID))
}

/**
 * Open the list's Export menu and download the CSV.
 *
 * @param page The page showing the list.
 * @return The download.
 */
async function exportCsv(page: Page): Promise<Download> {
	const menu = page.getByTestId('cn-index-export-menu')
	await expect(menu, 'the list shows the Export menu').toBeVisible({ timeout: 30_000 })
	await menu.getByRole('button').first().click()
	const [download] = await Promise.all([
		page.waitForEvent('download', { timeout: 60_000 }),
		page.getByTestId('cn-index-export-csv').click(),
	])
	return download
}

/**
 * Sign in as an account from an empty storage state.
 *
 * @param browser The browser.
 * @param account The account.
 * @param account.username The user id.
 * @param account.password The password.
 * @return The signed-in page.
 */
async function loginAs(browser: Browser, account: { username: string; password: string }): Promise<Page> {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: NC_URL, acceptDownloads: true })
	const page = await context.newPage()
	await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
	await page.locator('input#user, input[name="user"]').first().fill(account.username)
	await page.locator('input#password, input[name="password"]').first().fill(account.password)
	await Promise.all([
		page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 60_000 }),
		page.locator('button[type="submit"]').first().click({ noWaitAfter: true }),
	])
	return page
}

test.describe.configure({ mode: 'serial' })

test.describe('exporting a humaniq list', () => {
	let admin: APIRequestContext
	let employeeId = ''
	const cleanup: Array<{ schema: string; id: string }> = []

	/**
	 * Create one object as the admin and remember it for clean-up.
	 *
	 * @param schema The schema slug.
	 * @param data The object.
	 * @return The new object's id.
	 */
	async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
		const made = await admin.post(`${OR_BASE}/${schema}`, { headers: HEADERS, data })
		expect(made.ok(), `seed ${schema}: ${made.status()} ${await made.text()}`).toBeTruthy()
		const id = idOf(await made.json())
		cleanup.push({ schema, id })
		return id
	}

	test.beforeAll(async () => {
		admin = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })

		const made = await admin.post(`${NC_URL}/ocs/v2.php/cloud/users?format=json`, {
			headers: { 'OCS-APIRequest': 'true' },
			form: { userid: EMPLOYEE_USER, password: EMPLOYEE_PASS, displayName: EMPLOYEE_USER },
		})
		expect(made.ok(), `provision the employee account: ${await made.text()}`).toBeTruthy()

		for (let i = 0; i < MATCHING; i++) {
			await seed('Employee', {
				employeeNumber: `${RUN_ID}-w${i}`,
				firstName: 'Export',
				lastName: `Wit-${RUN_ID}-${i}`,
				startDate: '2026-01-01',
				taxTableColor: 'white',
				administrationId: 'ADM-001',
			})
		}
		for (let i = 0; i < LEFT_OUT; i++) {
			await seed('Employee', {
				employeeNumber: `${RUN_ID}-g${i}`,
				firstName: 'Export',
				lastName: `Groen-${RUN_ID}-${i}`,
				startDate: '2026-01-01',
				taxTableColor: 'green',
				administrationId: 'ADM-001',
			})
		}

		employeeId = await seed('Employee', {
			employeeNumber: `${RUN_ID}-self`,
			firstName: 'Declarant',
			lastName: `Zelf-${RUN_ID}`,
			startDate: '2026-01-01',
			taxTableColor: 'green',
			nextcloudUserId: EMPLOYEE_USER,
			administrationId: 'ADM-001',
		})

		// Two claims of the employee's own, and one of someone else's that the
		// file must not hold.
		for (const n of [1, 2]) {
			await seed('Expense', {
				employeeId,
				userId: EMPLOYEE_USER,
				title: `Own claim ${n} ${RUN_ID}`,
				status: 'draft',
				amount: 10 * n,
				currency: 'EUR',
				administrationId: 'ADM-001',
			})
		}
		await seed('Expense', {
			employeeId,
			userId: ADMIN_CREDENTIALS.username,
			title: `Other claim ${RUN_ID}`,
			status: 'draft',
			amount: 99,
			currency: 'EUR',
			administrationId: 'ADM-001',
		})
	})

	test.afterAll(async () => {
		for (const { schema, id } of cleanup.reverse()) {
			await admin.delete(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS }).catch(() => undefined)
		}
		await admin.delete(`${NC_URL}/ocs/v2.php/cloud/users/${EMPLOYEE_USER}?format=json`, {
			headers: { 'OCS-APIRequest': 'true' },
		}).catch(() => undefined)
		await admin.dispose()
	})

	// @e2e hr-list-export::hr-exports-all-active-employees
	test('the export holds every row the Employees list is filtered to, not only the fetched page @spec REQ-HLE-001', async ({ page }) => {
		await page.goto('/index.php/apps/humaniq/employees?taxTableColor=white', { waitUntil: 'domcontentloaded' })
		const search = page.getByRole('searchbox').or(page.getByPlaceholder(/search|zoek/i)).first()
		await search.fill(RUN_ID)
		await expect(page.getByText(`Wit-${RUN_ID}-0`).first(), 'the table shows the run\'s rows').toBeVisible({ timeout: 30_000 })

		const lines = await runLines(await exportCsv(page))
		expect(lines.filter((line) => line.includes(`Wit-${RUN_ID}`)), 'every matching employee is in the file').toHaveLength(MATCHING)
		expect(lines.filter((line) => line.includes(`Groen-${RUN_ID}`)), 'the filter leaves the green-table employees out').toHaveLength(0)
	})

	// @e2e hr-list-export::a-self-service-list-exports-only-the-users-own-rows
	test('an employee exporting their own expenses gets only their own claims @spec REQ-HLE-001', async ({ browser }) => {
		const page = await loginAs(browser, { username: EMPLOYEE_USER, password: EMPLOYEE_PASS })
		await page.goto('/index.php/apps/humaniq/expenses?userId=@me', { waitUntil: 'domcontentloaded' })
		await expect(page.getByText(`Own claim 1 ${RUN_ID}`).first(), 'the list shows the employee\'s claims').toBeVisible({ timeout: 30_000 })

		const lines = await runLines(await exportCsv(page))
		expect(lines.filter((line) => line.includes('Own claim')), 'both own claims are in the file').toHaveLength(2)
		expect(lines.filter((line) => line.includes('Other claim')), 'someone else\'s claim is not').toHaveLength(0)
		await page.context().close()
	})

	// @e2e hr-list-export::the-sick-leave-list-has-no-export
	test('the sick leave list shows no Export menu, while the Employees list does @spec REQ-HLE-002', async ({ page }) => {
		// The positive control first, so an absent menu below cannot pass because
		// the page never rendered its action bar.
		await page.goto('/index.php/apps/humaniq/employees', { waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('cn-index-export-menu'), 'the Employees list offers Export').toBeVisible({ timeout: 30_000 })

		await page.goto('/index.php/apps/humaniq/sick-leave', { waitUntil: 'domcontentloaded' })
		await expect(page.getByRole('heading').first(), 'the sick leave list rendered').toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId('cn-index-export-menu'), 'the sick leave list offers no Export').toHaveCount(0)
	})
})
