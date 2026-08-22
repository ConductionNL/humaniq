/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Core journeys — deep rendered-content assertions for humaniq's primary
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

import type { APIRequestContext, Page } from '@playwright/test';

import { appDialog } from '@conduction/nextcloud-vue/testing/playwright'
import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

// PATH-form base: the humaniq router runs in HISTORY mode (`createWebHistory`,
// src/main.js). Hash-form deep links are silently ignored and land on
// the default page — observed live 2026-07-26.
//
// ⚠️ The base is NOT a constant. `src/main.js` builds the router with
// `createWebHistory(generateUrl('/apps/humaniq'))`, which returns `/apps/humaniq` when
// the front controller is inactive and `/index.php/apps/humaniq` when it is. This
// file used to hardcode `/apps/humaniq`; on CI `generateUrl` returns the
// `/index.php` form, so every `page.goto` here addressed a path OUTSIDE the
// router base, matched nothing, and fell through main.js's `/:pathMatch(.*)*`
// catch-all to its default page. Measured on run 30919961510 (job 92028085860):
// every route assertion failed with `Received string:
// "/index.php/apps/humaniq/timesheets"` — including `/employees`, whose page is
// fine. Resolve it from the running app instead, per page.
let _appBase: string | null = null
async function appBase(page: Page): Promise<string> {
	if (_appBase) return _appBase
	await page.goto('/index.php/apps/humaniq/', { waitUntil: 'domcontentloaded' })
	const resolved = await page.evaluate(
		() => (window as unknown as { OC?: { generateUrl?: (_p: string) => string } }).OC?.generateUrl?.('/apps/humaniq'),
	)
	if (!resolved) {
		throw new Error(
			'OC.generateUrl is not available on the humaniq page, so the router base cannot be '
			+ 'resolved — every route assertion below would be measuring the wrong URL.',
		)
	}
	_appBase = resolved.replace(/\/+$/, '')
	return _appBase
}

// This spec SEEDS AND DELETES OpenRegister objects, so its absolute base URL
// must be the same instance the relative `page.goto`s below hit. It previously
// recomputed `process.env.NEXTCLOUD_URL || 'http://localhost:8080'` locally,
// which pointed those writes at the SHARED dev container whenever the env var
// was unset. One resolver, no fallback — see ../base-url.ts.
const NC_URL = resolveBaseURL()
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects`

/** humaniq's OpenRegister register slug (manifest config.register). */
const REGISTER = 'hrmq'

const AUTH = ADMIN_CREDENTIALS

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
	const base = await appBase(page)
	await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 15_000 })
	expect(new URL(page.url()).pathname, `router must stay on ${route}`).toContain(route)
}

/**
 * Assert an index page rendered ITS OWN schema surface — the
 * schema-specific "Add <Schema>" create button — plus either a data
 * table/list or an explicit empty state. Never a blank shell, and never
 * a different schema's page (the greenwash failure mode).
 *
 * NOTE (live finding, 2026-07-26): humaniq's CnIndexPage renders NO page
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
	// SOMETHING must be there. NOTE: humaniq's main content element is
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

	test('Timesheets index renders read-only list without an add button', async ({ page }) => {
		// humaniq-hours-process-redesign (design.md Decision 8): timesheets are
		// server-created period aggregates of TimeEntry bookings, so the Add
		// button is deliberately DISABLED here (actionToggles.showAdd: false).
		// Page identity can no longer be asserted via its create button —
		// assert the ABSENCE of Add plus a rendered listing/empty state, and
		// keep the positive add-button case on TimeEntries below.
		await gotoRoute(page, '/timesheets')
		const content = page.locator(
			'main table, main [role="table"], main [role="note"], '
			+ 'main .empty-content, main [class*="emptyContent"], main [class*="empty-state"]',
		).first()
		await expect(content, 'index page must render a listing or an explicit empty state').toBeVisible({ timeout: 20_000 })
		await expect(
			page.getByRole('button', { name: /Add Timesheet/i }),
			'timesheets are server-created — the Add button must be gone',
		).toHaveCount(0)
	})

	test('TimeEntries index renders add button and list-or-empty', async ({ page }) => {
		// The positive create-affordance case that /timesheets used to carry:
		// the HR booking surface (humaniq-hours-process-redesign) offers Add.
		await gotoRoute(page, '/time-entries')
		await expectIndexRendered(page, /Add Time entry/i)
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
		// `appDialog()` excludes the `[role="dialog"]` nodes that are NC / nc-vue
		// CHROME (support dialog, walkthrough, first-run wizard) rather than the
		// app's own modal — a bare `getByRole('dialog').first()` can latch onto
		// one of those and pass without the create dialog ever opening.
		const dialog = appDialog(page)
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
