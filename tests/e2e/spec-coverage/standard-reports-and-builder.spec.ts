// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Ready-made reports and own reports (reporting-standard-reports-and-builder 3.1).
 *
 * openspec/specs/hr-reports/spec.md
 *   Scenario: A controller opens the wage cost report
 *   Scenario: HR sees whose leave expires
 *   Scenario: HR builds a report of contracts ending this quarter
 *   Scenario: An export leaves out fields the user may not read
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. check:manifest proves the four report pages
 * and their cards are declared, ZuiddrechtSharedReportTest proves the seeded
 * public view's query, and ListExportDeclarationTest proves which schemas are
 * exportable. None of them shows that a card opens its report with nothing to
 * set up, that the charts read the seeded runs and balances, that a view one
 * user shares shows under "My reports" for another, or that OpenRegister's
 * field rights leave a salary out of the file a manager downloads. Those sit
 * between humaniq, OpenRegister and the library, so they are here.
 *
 * 🔴 THE COLLEAGUE IS NOT THE ADMIN. The shared report is saved by the admin and
 * looked up by an account this file provisions, from an empty storage state,
 * so the admin's own views cannot ride along. The manager of the last test is
 * a second provisioned account outside `humaniq-hr` and `humaniq-payroll`, the
 * groups that may read `grossMonthlySalary`.
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
const APP_URL = `${NC_URL}/index.php/apps/humaniq`
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq`
const VIEWS = `${NC_URL}/index.php/apps/openregister/api/views`
const RUN_ID = `e2e-hrp-${Date.now()}-${randomUUID().slice(0, 8)}`
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

/** The colleague who finds the shared report. */
const COLLEAGUE = { username: `${RUN_ID}-col`, password: `Hrp-${randomUUID()}` }

/** The manager who exports employees without salary rights. */
const MANAGER = { username: `${RUN_ID}-mgr`, password: `Hrp-${randomUUID()}` }

/** A salary no other row on the instance carries, so the file can be searched for it. */
const MARKER_SALARY = 7919.37

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
 * An ISO date a number of days from today.
 *
 * @param days Days from today, negative for the past.
 * @return The date as YYYY-MM-DD.
 */
function isoDay(days: number): string {
	const day = new Date()
	day.setUTCDate(day.getUTCDate() + days)
	return day.toISOString().slice(0, 10)
}

/**
 * Log in as an account from an empty storage state.
 *
 * @param browser The browser.
 * @param account The account.
 * @param account.username The user id.
 * @param account.password The password.
 * @return The logged-in page.
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

/**
 * Open the list's Export menu and download one format.
 *
 * @param page The page showing the list.
 * @param format The export entry, `csv` or `excel`.
 * @return The download.
 */
async function exportAs(page: Page, format: 'csv' | 'excel'): Promise<Download> {
	const menu = page.getByTestId('cn-index-export-menu')
	await expect(menu, 'the list shows the Export menu').toBeVisible({ timeout: 30_000 })
	await menu.getByRole('button').first().click()
	const [download] = await Promise.all([
		page.waitForEvent('download', { timeout: 60_000 }),
		page.getByTestId(`cn-index-export-${format}`).click(),
	])
	return download
}

/**
 * Open a ready-made report from its card on the Reports page.
 *
 * @param page The page.
 * @param label The card's label.
 * @param route The report's route below the app.
 * @return Nothing.
 */
async function openReportCard(page: Page, label: string, route: string): Promise<void> {
	await page.goto(`${APP_URL}/reports`, { waitUntil: 'domcontentloaded' })
	const card = page.getByText(label, { exact: true }).first()
	await expect(card, `the Reports page offers "${label}"`).toBeVisible({ timeout: 30_000 })
	await Promise.all([
		page.waitForURL((url) => url.pathname.endsWith(route), { timeout: 30_000 }),
		card.click(),
	])
}

test.describe.configure({ mode: 'serial' })

test.describe('ready-made and own reports', () => {
	let admin: APIRequestContext
	let viewId = ''
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
		for (const account of [COLLEAGUE, MANAGER]) {
			const made = await admin.post(`${NC_URL}/ocs/v2.php/cloud/users?format=json`, {
				headers: { 'OCS-APIRequest': 'true' },
				form: { userid: account.username, password: account.password, displayName: account.username },
			})
			expect(made.ok(), `provision ${account.username}: ${await made.text()}`).toBeTruthy()
		}
	})

	test.afterAll(async () => {
		if (viewId !== '') {
			await admin.delete(`${VIEWS}/${encodeURIComponent(viewId)}`, { headers: HEADERS })
		}
		for (const { schema, id } of cleanup.reverse()) {
			await admin.delete(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS })
		}
		for (const account of [COLLEAGUE, MANAGER]) {
			await admin.delete(`${NC_URL}/ocs/v2.php/cloud/users/${account.username}?format=json`, { headers: { 'OCS-APIRequest': 'true' } })
		}
		await admin?.dispose()
	})

	// @e2e hr-reports::a-controller-opens-the-wage-cost-report
	test('A controller opens the wage cost report', async ({ page }) => {
		const runs = await admin.get(`${OR_BASE}/PayrollRun?_limit=1`, { headers: HEADERS })
		expect(runs.ok(), `read the payroll runs: ${runs.status()}`).toBeTruthy()
		test.skip(Number((await runs.json())?.total || 0) === 0, 'needs payroll runs; load the example data first')

		await openReportCard(page, 'Wage costs', '/reports/wage-costs')

		// Nothing to set up: the stats and the per-period charts render at once.
		await expect(page.getByText('Gross wages, all runs'), 'the gross wage total is shown').toBeVisible({ timeout: 30_000 })
		await expect(page.getByText('Employer charges, all runs'), 'the employer charges are shown').toBeVisible()
		await expect(page.getByText('Payroll tax, all runs'), 'the payroll tax is shown').toBeVisible()
		for (const chart of ['Gross wages per period', 'Employer charges per period', 'Payroll tax per period', 'Net wages per period']) {
			await expect(page.getByText(chart), `the "${chart}" chart is shown`).toBeVisible()
		}
		const perRun = page.getByRole('table').last()
		await expect(perRun, 'the costs per run table lists the runs').toBeVisible()
		await expect(perRun.getByRole('row'), 'the table has at least one run').not.toHaveCount(1)
	})

	// @e2e hr-reports::hr-sees-whose-leave-expires
	test('HR sees whose leave expires', async ({ page }) => {
		const balances = await admin.get(`${OR_BASE}/LeaveBalance?_limit=1`, { headers: HEADERS })
		expect(balances.ok(), `read the leave balances: ${balances.status()}`).toBeTruthy()
		test.skip(Number((await balances.json())?.total || 0) === 0, 'needs leave balances; load the example data first')

		await openReportCard(page, 'Leave balances', '/reports/leave-balances')

		await expect(page.getByText('Balances by expiry date'), 'the expiry table is shown').toBeVisible({ timeout: 30_000 })
		const table = page.getByRole('table').last()
		const header = (await table.getByRole('row').first().innerText()).toLowerCase()
		expect(header, 'the table names the employee').toMatch(/employee/)
		expect(header, 'the table names the leave type').toMatch(/leave type|type/)
		expect(header, 'the table shows the expiry date').toMatch(/expir/)
		await expect(table.getByRole('row'), 'the table lists balances').not.toHaveCount(1)
	})

	// @e2e hr-reports::hr-builds-a-report-of-contracts-ending-this-quarter
	test('HR builds a report of contracts ending this quarter', async ({ browser, page }) => {
		const employeeId = await seed('Employee', {
			employeeNumber: `${RUN_ID}-e`,
			firstName: 'Report',
			lastName: `Einde-${RUN_ID}`,
			startDate: '2025-01-01',
			administrationId: 'ADM-001',
		})
		await seed('EmploymentContract', {
			employeeId,
			title: `Contract ending ${RUN_ID}`,
			contractType: 'fixed-term',
			startDate: '2025-01-01',
			endDate: isoDay(30),
			administrationId: 'ADM-001',
		})

		// The admin saves the filtered list as a shared report, as the saved
		// views menu does (useSavedViewsApi.createView).
		const made = await admin.post(VIEWS, {
			headers: HEADERS,
			data: {
				name: `Contracts ending ${RUN_ID}`,
				description: 'Saved by the e2e run.',
				isPublic: true,
				query: {
					registers: ['humaniq'],
					schemas: ['EmploymentContract'],
					filters: { 'endDate[gte]': isoDay(0), 'endDate[lte]': isoDay(91) },
					search: RUN_ID,
					sort: [{ key: 'endDate', order: 'asc' }],
				},
			},
		})
		expect(made.ok(), `save the view: ${made.status()} ${await made.text()}`).toBeTruthy()
		const view = (await made.json())?.view || {}
		viewId = String(view.uuid || view.id || '')
		expect(viewId, 'the view has an id').not.toBe('')

		// The export from the filtered list holds the contract.
		await page.goto(`${APP_URL}/contracts?search=${encodeURIComponent(RUN_ID)}`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText(`Contract ending ${RUN_ID}`).first(), 'the list shows the contract').toBeVisible({ timeout: 30_000 })
		const download = await exportAs(page, 'excel')
		expect(download.suggestedFilename(), 'the export is an Excel file').toMatch(/\.xlsx$/)
		const file = await readFile(String(await download.path()))
		expect(file.length, 'the Excel file is not empty').toBeGreaterThan(0)

		// The colleague finds it under My reports.
		const colleague = await loginAs(browser, COLLEAGUE)
		await colleague.goto(`${APP_URL}/reports/mine`, { waitUntil: 'domcontentloaded' })
		await expect(colleague.getByText(`Contracts ending ${RUN_ID}`), 'the colleague sees the shared report').toBeVisible({ timeout: 30_000 })
		await colleague.context().close()
	})

	// @e2e hr-reports::an-export-leaves-out-fields-the-user-may-not-read
	test('An export leaves out fields the user may not read', async ({ browser }) => {
		await seed('Employee', {
			employeeNumber: `${RUN_ID}-s`,
			firstName: 'Salaris',
			lastName: `Verborgen-${RUN_ID}`,
			startDate: '2025-01-01',
			grossMonthlySalary: MARKER_SALARY,
			administrationId: 'ADM-001',
		})

		const manager = await loginAs(browser, MANAGER)
		await manager.goto(`${APP_URL}/employees?search=${encodeURIComponent(RUN_ID)}`, { waitUntil: 'domcontentloaded' })
		const download = await exportAs(manager, 'csv')
		const text = await readFile(String(await download.path()), 'utf8')
		const header = text.split(/\r?\n/)[0] || ''
		expect(header, 'the file has no salary column').not.toMatch(/grossMonthlySalary|salary|salaris/i)
		expect(text, 'the file holds no salary value').not.toContain(String(MARKER_SALARY))
		await manager.context().close()
	})
})
