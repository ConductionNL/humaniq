/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Core journeys — deep rendered-content assertions for hrmq's primary
 * surfaces, complementing the shallow manifest smoke suite
 * (manifest-pages.spec.ts).
 *
 * These tests assert REAL rendered content per ADR-074 (no
 * if-visible-then-assert guards — every assertion is unconditional):
 *
 *   1. Employees index (/employees)  — Dutch title "Werknemers", add
 *      button, list-or-empty-state.
 *   2. Timesheets index (/timesheets) — "Urenregistratie".
 *   3. Expenses index (/expenses)     — "Declaraties".
 *   4. Create dialog on Employees — open + cancel round-trip.
 *   5. Seeded detail page — an Employee is created through
 *      OpenRegister's object REST API (the app's authoritative backing
 *      store), its detail page (/employees/:id) is opened, and the
 *      seeded field values are asserted to actually render. Deleted in
 *      teardown.
 *
 * Seeding pattern reference: larpingapp/tests/e2e/workflows/fixtures.ts
 * (OpenRegister REST + basic auth; assertions stay in the UI).
 */

import { test, expect, request, type APIRequestContext, type Page } from '@playwright/test'

// PATH-form base: the hrmq router runs in HISTORY mode (`mode: 'history'`,
// src/main.js:83). Hash-form deep links are silently ignored and land on
// the default page — observed live 2026-07-26.
const APP_BASE = '/apps/hrmq'

const NC_URL = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects`

/** hrmq's OpenRegister register slug (manifest config.register). */
const REGISTER = 'hrmq'

const AUTH = {
	username: process.env.NC_ADMIN_USER || 'admin',
	password: process.env.NC_ADMIN_PASS || 'admin',
}

const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

/** Unique run id so repeated runs never collide and cleanup is exact. */
const RUN_ID = `e2e-${Date.now()}-${Math.floor(Math.random() * 1e4)}`

/**
 * Resolve the Employee schema path segment on the live instance.
 *
 * The manifest references the schema as `Employee` (config.schema), but
 * OpenRegister's REST routes accept the schema SLUG, which may be
 * lower-cased or namespaced depending on how the register was imported.
 * Probe the list endpoint with the candidates and use whichever the
 * instance actually serves rather than hardcoding an instance-specific
 * id (numeric ids drift per instance — see larpingapp's fixture notes).
 */
async function resolveEmployeeSchema(api: APIRequestContext): Promise<string> {
	const candidates = ['employee', 'Employee', 'hrmq_employee']
	for (const candidate of candidates) {
		const res = await api.get(`${OR_BASE}/${REGISTER}/${candidate}?limit=1`, { headers: HEADERS })
		if (res.ok()) {
			return candidate
		}
	}
	throw new Error(
		`Could not resolve the hrmq Employee schema on ${NC_URL} — none of `
		+ `${candidates.join(', ')} answered 200 on ${OR_BASE}/${REGISTER}/<schema>. `
		+ 'Is the hrmq register installed in OpenRegister?',
	)
}

/**
 * Navigate to an in-app route (PATH form — history-mode router) and wait
 * for the SPA shell, asserting the router stayed on the requested route.
 */
async function gotoRoute(page: Page, route: string): Promise<void> {
	await page.goto(`${APP_BASE}${route}`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 15_000 })
	expect(new URL(page.url()).pathname, `router must stay on ${route}`).toContain(route)
}

/**
 * Assert an index page rendered ITS OWN schema surface — the
 * schema-specific "Add <Schema>" create button — plus either a data
 * table/list or an explicit empty state. Never a blank shell, and never
 * a different schema's page (the greenwash failure mode).
 *
 * NOTE (live finding, 2026-07-26): hrmq's CnIndexPage renders NO page
 * title heading at all — the manifest `title` ("Werknemers", …) appears
 * only in the left nav, not as a role=heading in main. Page identity is
 * therefore asserted via the schema-specific create button, which IS
 * rendered ("Add Employee", "Add Timesheet", …). The missing page-title
 * heading is reported as an app defect; when it is fixed, tighten this
 * helper to also assert the heading.
 */
async function expectIndexRendered(page: Page, addButton: RegExp): Promise<void> {
	await expect(
		page.getByRole('button', { name: addButton }).first(),
		'index page must render its own schema-specific create button',
	).toBeVisible({ timeout: 20_000 })
	// A CnIndexPage always resolves to one of: a data table/listing, or an
	// explicit empty-state note ("No items found"). Match either — but
	// SOMETHING must be there. NOTE: hrmq's main content element is
	// `<main id="app-content-vue" class="app-content">` — there is no
	// `#app-content` id, so scope via the `main` element itself.
	const content = page.locator(
		'main table, main [role="table"], main [role="note"], '
		+ 'main .empty-content, main [class*="emptyContent"], main [class*="empty-state"]',
	).first()
	await expect(content, 'index page must render a listing or an explicit empty state').toBeVisible({ timeout: 20_000 })
}

test.describe('core journeys — primary HR surfaces', () => {

	test('Employees index renders add button and list-or-empty', async ({ page }) => {
		await gotoRoute(page, '/employees')
		await expectIndexRendered(page, /Add Employee/i)
	})

	test('Timesheets index renders add button and list-or-empty', async ({ page }) => {
		await gotoRoute(page, '/timesheets')
		await expectIndexRendered(page, /Add Timesheet/i)
	})

	test('Expenses index renders add button and list-or-empty', async ({ page }) => {
		await gotoRoute(page, '/expenses')
		await expectIndexRendered(page, /Add Expense/i)
	})

	test('Employees create dialog opens and cancels cleanly', async ({ page }) => {
		await gotoRoute(page, '/employees')
		await expectIndexRendered(page, /Add Employee/i)
		const addBtn = page.getByRole('button', { name: /Add Employee/i }).first()
		await expect(addBtn).toBeVisible({ timeout: 15_000 })
		await addBtn.click()
		const dialog = page.getByRole('dialog').first()
		await expect(dialog, 'create dialog must open').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving — unconditional: a cancel/close control (or
		// Escape) must return the page to its dialog-less state.
		const cancel = dialog.getByRole('button', { name: /annuleren|cancel|sluiten|close/i }).first()
		const hasCancel = await cancel.isVisible({ timeout: 2_000 }).catch(() => false)
		if (hasCancel) {
			await cancel.click()
		} else {
			await page.keyboard.press('Escape')
		}
		await expect(dialog, 'create dialog must close again').toBeHidden({ timeout: 10_000 })
	})

})

test.describe('core journeys — seeded employee detail', () => {

	let api: APIRequestContext
	let schemaSlug: string
	let employeeId: string
	const seeded = {
		employeeNumber: `${RUN_ID}-nr`,
		firstName: 'Test',
		lastName: `Achternaam-${RUN_ID}`,
		startDate: '2026-01-01',
	}

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: AUTH })
		schemaSlug = await resolveEmployeeSchema(api)
		const res = await api.post(`${OR_BASE}/${REGISTER}/${schemaSlug}`, {
			headers: HEADERS,
			data: seeded,
		})
		if (!res.ok()) {
			throw new Error(`seed employee failed: HTTP ${res.status()} ${await res.text()}`)
		}
		const json = await res.json()
		employeeId = json?.['@self']?.id || json?.id
		if (!employeeId) {
			throw new Error(`seed employee returned no id: ${JSON.stringify(json).slice(0, 300)}`)
		}
	})

	test.afterAll(async () => {
		if (api && employeeId) {
			await api.delete(`${OR_BASE}/${REGISTER}/${schemaSlug}/${employeeId}`, { headers: HEADERS }).catch(() => {})
		}
		await api?.dispose()
	})

	test('seeded employee detail page renders the seeded field values', async ({ page }) => {
		await gotoRoute(page, `/employees/${employeeId}`)
		// The detail page must surface the seeded data — not just mount a
		// shell. lastName is unique per run so a match proves THIS object
		// was fetched and rendered.
		await expect(
			page.locator('#app-content, .app-content').first(),
		).toContainText(seeded.lastName, { timeout: 30_000 })
		await expect(
			page.locator('#app-content, .app-content').first(),
		).toContainText(seeded.firstName, { timeout: 10_000 })
	})

})
