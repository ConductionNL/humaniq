// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The phone check of self-service-mobile (task 3.2), in a phone's viewport:
 * Mijn HR is installable, an employee requests leave, and a receipt is
 * photographed into an expense. The screenshots the task asks for are
 * attached to the test report.
 *
 * Every scenario of the change keeps its reason-bearing `@e2e exclude`; this
 * spec is the device pass the task names, run as the employee, not the admin.
 *
 * 🔴 WHAT A HEADLESS PHONE CANNOT DO. "Add to home screen" is a browser and OS
 * gesture. What the browser checks before it offers it is checkable, and is
 * checked here: the page links the web manifest, the manifest names Mijn HR
 * with a start URL inside its scope, `display: standalone` and icons, and the
 * service worker takes control of the page. Likewise the camera: the receipt
 * field must be a file input that asks for the rear camera
 * (`capture="environment"`, images and PDFs), and a photo handed to it is
 * stored as the expense's receipt file.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext } from '@playwright/test'

import { devices, expect, request, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const APP_URL = `${NC_URL}/index.php/apps/humaniq`
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq`
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const RUN_ID = `e2e-mob-${Date.now()}-${randomUUID().slice(0, 8)}`

/** The employee's account. */
const EMPLOYEE_USER = `${RUN_ID}-uid`
const EMPLOYEE_PASS = `Mob-${randomUUID()}`

/** A 1x1 PNG, standing in for the camera's photo. */
const RECEIPT_PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64')

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

// The browser of a phone, signed in as the employee.
test.use({
	...devices['Pixel 7'],
	httpCredentials: { username: EMPLOYEE_USER, password: EMPLOYEE_PASS },
	storageState: { cookies: [], origins: [] },
})

test.describe.configure({ mode: 'serial' })

test.describe('Mijn HR on a phone', () => {
	let admin: APIRequestContext
	let employee: APIRequestContext
	let employeeId = ''
	const cleanup: Array<{ schema: string; id: string }> = []

	test.beforeAll(async () => {
		admin = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
		const made = await admin.post(`${NC_URL}/ocs/v2.php/cloud/users?format=json`, {
			headers: { 'OCS-APIRequest': 'true' },
			form: { userid: EMPLOYEE_USER, password: EMPLOYEE_PASS, displayName: EMPLOYEE_USER },
		})
		expect(made.ok(), `provision the employee account: ${await made.text()}`).toBeTruthy()

		const emp = await admin.post(`${OR_BASE}/Employee`, {
			headers: HEADERS,
			data: {
				employeeNumber: `${RUN_ID}-nr`,
				firstName: 'Mobiel',
				lastName: `Medewerker-${RUN_ID}`,
				startDate: '2026-01-01',
				nextcloudUserId: EMPLOYEE_USER,
				administrationId: 'ADM-001',
			},
		})
		expect(emp.ok(), `seed the employee: ${emp.status()} ${await emp.text()}`).toBeTruthy()
		employeeId = idOf((await emp.json()) as Row)
		cleanup.push({ schema: 'Employee', id: employeeId })

		employee = await request.newContext({
			httpCredentials: { username: EMPLOYEE_USER, password: EMPLOYEE_PASS },
			storageState: { cookies: [], origins: [] },
		})
	})

	test.afterAll(async () => {
		for (const { schema, id } of cleanup.reverse()) {
			await admin.delete(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS })
		}
		await admin.delete(`${NC_URL}/ocs/v2.php/cloud/users/${EMPLOYEE_USER}?format=json`, { headers: { 'OCS-APIRequest': 'true' } })
		await employee.dispose()
		await admin.dispose()
	})

	test('Mijn HR meets what a phone checks before it offers Add to home screen @spec REQ-MOB-001', async ({ page }, testInfo) => {
		await page.goto(`${APP_URL}/mijn`, { waitUntil: 'domcontentloaded' })

		const href = await page.locator('link[rel="manifest"]').first().getAttribute('href')
		expect(href, 'the page links the web manifest').toBeTruthy()
		const manifest = (await (await page.request.get(new URL(String(href), page.url()).toString())).json()) as Row
		expect(String(manifest.name || manifest.short_name)).toMatch(/Mijn HR/i)
		expect(manifest.display).toBe('standalone')
		expect((manifest.icons as Row[] | undefined)?.length, 'icons for the home screen').toBeGreaterThan(0)
		const scope = new URL(String(manifest.scope || manifest.start_url), page.url()).pathname
		expect(new URL(String(manifest.start_url), page.url()).pathname.startsWith(scope), 'the start URL sits in the scope').toBe(true)

		const controlled = await page.evaluate(async () => {
			const registration = await navigator.serviceWorker.ready
			return Boolean(registration.active)
		})
		expect(controlled, 'the service worker is active').toBe(true)

		await page.locator('main.app-content').first().waitFor({ timeout: 30000 })
		await testInfo.attach('mijn-hr-start', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' })
	})

	test('the employee requests leave and finds it under their own leave @spec REQ-MOB-003', async ({ page }, testInfo) => {
		const res = await employee.post(`${OR_BASE}/LeaveRequest`, {
			headers: HEADERS,
			data: { employeeId, leaveType: 'holiday', startDate: '2026-12-21', endDate: '2026-12-23', status: 'submitted', reason: RUN_ID },
		})
		expect(res.ok(), `request leave as the employee: ${res.status()} ${await res.text()}`).toBeTruthy()
		cleanup.push({ schema: 'LeaveRequest', id: idOf((await res.json()) as Row) })

		await page.goto(`${APP_URL}/leave-requests`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText('2026-12-21').or(page.getByText(/21[-/ ]12[-/ ]2026|21 dec/i)).first()).toBeVisible({ timeout: 30000 })
		await testInfo.attach('mijn-verlof', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' })
	})

	test('a receipt photographed on the phone lands on the expense @spec REQ-MOB-003', async ({ page }, testInfo) => {
		await page.goto(`${APP_URL}/expenses`, { waitUntil: 'domcontentloaded' })
		await page.getByRole('button', { name: /Add Expense|Declaratie toevoegen|Nieuwe declaratie/i }).first().click()
		const dialog = page.getByRole('dialog').last()
		await dialog.getByLabel(/^(Title|Titel)/i).first().fill(`Parkeren ${RUN_ID}`)

		const camera = dialog.locator('input[type="file"][capture]').first()
		await expect(camera, 'the receipt field asks for the rear camera').toHaveAttribute('capture', 'environment')
		expect(String(await camera.getAttribute('accept'))).toMatch(/image\//)
		await camera.setInputFiles({ name: 'bon.png', mimeType: 'image/png', buffer: RECEIPT_PNG })
		await testInfo.attach('declaratie-met-bon', { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' })

		await dialog.getByRole('button', { name: /^(Create|Save|Aanmaken|Opslaan)$/i }).first().click()
		await expect(dialog).toBeHidden({ timeout: 30000 })

		const found = await employee.get(`${OR_BASE}/Expense?${new URLSearchParams({ title: `Parkeren ${RUN_ID}`, _limit: '5' })}`, { headers: HEADERS })
		expect(found.ok()).toBeTruthy()
		const rows = (((await found.json()) as Row).results as Row[]) ?? []
		expect(rows.length, 'the expense was saved').toBe(1)
		cleanup.push({ schema: 'Expense', id: idOf(rows[0]) })
		expect(rows[0].receiptFile, 'the photo is the receipt file').toBeTruthy()
	})
})
