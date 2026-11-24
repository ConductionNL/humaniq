// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Shared steps of the payroll live checks: find a seeded employee, take the
 * run of an administration and period (reusing one another pass left, so a
 * shared instance never gets a second run for the same period), calculate it
 * while it is a draft, read its payslips, and attach a screenshot.
 */

import type { APIRequestContext, Page, TestInfo } from '@playwright/test'

import { expect } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

export const NC_URL = resolveBaseURL()
export const APP_URL = `${NC_URL}/index.php/apps/humaniq`
export const APP_API = `${APP_URL}/api`
export const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq`
export const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

export type Row = Record<string, unknown>

/** Objects a check created, to delete again afterwards. */
export type Created = Array<{ schema: string; id: string }>

/**
 * The id of an object as the objects API returns it.
 *
 * @param row The object.
 * @return Its id.
 */
export function idOf(row: Row | undefined): string {
	const self = (row?.['@self'] || {}) as Row
	return String(self.id || row?.id || '')
}

/**
 * List objects of a schema.
 *
 * @param api The admin context.
 * @param schema The schema.
 * @param filter The filter.
 * @return The rows.
 */
export async function list(api: APIRequestContext, schema: string, filter: Record<string, string>): Promise<Row[]> {
	const res = await api.get(`${OR_BASE}/${schema}?${new URLSearchParams({ ...filter, _limit: '100' })}`, { headers: HEADERS })
	expect(res.ok(), `${schema} list (${res.status()})`).toBeTruthy()
	const body = (await res.json()) as Row | Row[]
	return Array.isArray(body) ? body : ((body.results as Row[]) ?? [])
}

/**
 * The id of the one seeded employee with this number.
 *
 * @param api The admin context.
 * @param employeeNumber The employee number.
 * @return The id.
 */
export async function seededEmployee(api: APIRequestContext, employeeNumber: string): Promise<string> {
	const rows = await list(api, 'Employee', { employeeNumber })
	expect(rows.length, `the seeded employee ${employeeNumber} (load the example data first)`).toBeGreaterThan(0)
	return idOf(rows[0])
}

/** Who the last calculation skipped, by employee id, with the reason. */
export const skippedBy: Map<string, string> = new Map()

/** Whose special-rate money the last calculation held, by employee id, with the reason. */
export const heldBy: Map<string, string> = new Map()

/**
 * The calculated run of an administration and period: an existing run is
 * reused and calculated only while it is a draft; otherwise a draft is made,
 * remembered in `created`, and calculated. The employees the calculation
 * skipped land in `skippedBy`, and those whose special-rate money it held in
 * `heldBy`.
 *
 * @param api The admin context.
 * @param administrationId The administration key.
 * @param period The pay period id.
 * @param created Where a new run is recorded for clean-up.
 * @return The run id.
 */
export async function calculatedRun(api: APIRequestContext, administrationId: string, period: string, created: Created): Promise<string> {
	const existing = await list(api, 'PayrollRun', { administrationId, period })
	let runId = existing.length > 0 ? idOf(existing[0]) : ''
	const status = existing.length > 0 ? String(existing[0].status || '') : 'draft'
	if (runId === '') {
		const made = await api.post(`${OR_BASE}/PayrollRun`, { headers: HEADERS, data: { period, administrationId, status: 'draft' } })
		expect(made.ok(), `create the ${period} run of ${administrationId} (${made.status()}): ${await made.text()}`).toBeTruthy()
		runId = idOf((await made.json()) as Row)
		created.push({ schema: 'PayrollRun', id: runId })
	}
	if (status === 'draft') {
		const calc = await api.post(`${APP_API}/payroll/calculate`, { headers: HEADERS, data: { runId } })
		expect(calc.ok(), `calculate ${period} of ${administrationId} (${calc.status()}): ${await calc.text()}`).toBeTruthy()
		const outcome = (await calc.json()) as Row
		for (const skip of (outcome.skipped as Row[]) ?? []) {
			skippedBy.set(String(skip.employeeId || ''), String(skip.reason || ''))
		}
		for (const held of (outcome.heldPayouts as Row[]) ?? []) {
			heldBy.set(String(held.employeeId || ''), String(held.reason || ''))
		}
	}
	return runId
}

/**
 * The payslips one employee has in one run.
 *
 * @param api The admin context.
 * @param runId The run.
 * @param employeeId The employee.
 * @return The payslips.
 */
export async function payslipsOf(api: APIRequestContext, runId: string, employeeId: string): Promise<Row[]> {
	return list(api, 'Payslip', { payrollRunId: runId, employeeId })
}

/**
 * Open a page of the app and attach a full-page screenshot to the report.
 *
 * @param page The page.
 * @param testInfo The running test.
 * @param path The app path, such as `/payslips/<id>`.
 * @param name The attachment name.
 * @return Nothing.
 */
export async function screenshotOf(page: Page, testInfo: TestInfo, path: string, name: string): Promise<void> {
	await page.goto(`${APP_URL}${path}`, { waitUntil: 'domcontentloaded' })
	// Never networkidle: Nextcloud keeps polling, so it never settles (ADR-074 rule 4).
	await page.locator('main.app-content').first().waitFor({ timeout: 30000 })
	await testInfo.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' })
}

/**
 * Delete what a check created, newest first.
 *
 * @param api The admin context.
 * @param created The objects.
 * @return Nothing.
 */
export async function removeCreated(api: APIRequestContext, created: Created): Promise<void> {
	for (const { schema, id } of [...created].reverse()) {
		await api.delete(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS })
	}
}
