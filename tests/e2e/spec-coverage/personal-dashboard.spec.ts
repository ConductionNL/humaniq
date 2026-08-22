/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Personal-dashboard journeys (gate-19 spec coverage) for the OpenSpec change
 * `humaniq-personal-dashboard` — the caller-scoped `MijnHr` dashboard at `/mijn`,
 * the `Mijn HR` menu group routing to it while keeping its children, and the
 * `/mijn-hr/gebruikelijk-loon` → `/mijn/gebruikelijk-loon` redirect.
 *
 * Every NON-excluded scenario of the change's spec deltas is referenced here
 * by its verbatim name (the excluded ones carry a reason-bearing
 * `@e2e exclude` in the spec files themselves):
 *
 * openspec/changes/humaniq-personal-dashboard/specs/humaniq-personal-dashboard/spec.md
 *   Scenario: Widgets show only the caller's rows
 *   Scenario: The pending-approvals tile renders for every caller and routes to the queue
 *   Scenario: Group title navigates; chevron still folds
 *
 * openspec/changes/humaniq-personal-dashboard/specs/mijn-hr-self-service/spec.md
 *   Scenario: Balances without userId never leak onto the personal dashboard
 *   Scenario: Menu order matches ADR-001
 *   Scenario: Mijn HR is a routed group, not a bare parent
 *   Scenario: The legacy path redirects
 *
 * (The `leave-accrual-job` delta's three scenarios are all `@e2e exclude` —
 * a BackgroundJob write path with no UI surface, pinned by
 * tests/Unit/BackgroundJob/LeaveAccrualJobTest.php instead.)
 *
 * PRECONDITIONS (provisioned by tests/e2e/ci-seed.sh, which refuses :8080 by
 * design):
 *  - the hrmq register + hr-seed.json objects are imported. Of the three
 *    seeded 2026 holiday LeaveBalances, ONLY jansen's carries
 *    `userId: "admin"`; devries' and bakker's are deliberately left unstamped
 *    so the fail-closed exclusion is demonstrable rather than assumed;
 *  - the admin user's ACTIVE administration is ADM-001.
 *
 * WHAT THIS FILE ASSERTS ON, AND WHY (tasks.md 4.2)
 * -------------------------------------------------
 * Manifest ids and routes, not Dutch display strings — the instance renders
 * Dutch today and `humaniq-i18n-locale-completeness` lands next, so a spec keyed
 * on visible copy would go red on a change that alters nothing it tests.
 * Three stable, measured hooks carry that:
 *   - `li[data-testid="cn-nav-entry-<menu id>"]` and its `data-cn-route`
 *     attribute (CnAppNav emits both; `data-cn-route` is present ONLY when the
 *     entry actually carries a route — the exact fact REQ-PDB-003 is about);
 *   - `[data-testid-page-id="<page id>"]` (CnPageRenderer);
 *   - `[role="group"][aria-label="<widget id>"]` on each dashboard grid cell —
 *     CnDashboardGrid labels every cell with the manifest widget id, which is
 *     the only per-widget identity the dashboard grid exposes.
 *
 * This spec is READ-ONLY: it renders seeded state and navigates. It creates
 * and mutates nothing, so it needs no RUN_ID marker and no cleanup, and it is
 * safe to run alongside the parallel render-only specs.
 */

import type { Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

// PATH-form base — the humaniq router runs in HISTORY mode; resolve the base
// from the running app via OC.generateUrl (see core-journeys.spec.ts for the
// measured failure mode of hardcoding it).
let _appBase: string | null = null
async function appBase(page: Page): Promise<string> {
	if (_appBase) return _appBase
	await page.goto('/index.php/apps/humaniq/', { waitUntil: 'domcontentloaded' })
	const resolved = await page.evaluate(
		() => (window as unknown as { OC?: { generateUrl?: (_p: string) => string } }).OC?.generateUrl?.('/apps/humaniq'),
	)
	if (!resolved) {
		throw new Error('OC.generateUrl unavailable — cannot resolve the humaniq router base.')
	}
	_appBase = resolved.replace(/\/+$/, '')
	return _appBase
}

async function gotoRoute(page: Page, route: string): Promise<void> {
	const base = await appBase(page)
	await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 15_000 })
}

const NC_URL = resolveBaseURL()
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects`
const REGISTER = 'hrmq'
const AUTH = ADMIN_CREDENTIALS
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

/** The six manifest widget ids of the MijnHr page, in layout order. */
const WIDGET_IDS = ['uren-maand', 'declaraties', 'te-beoordelen', 'urenstaten', 'verlofsaldo', 'loonstroken'] as const

/** One dashboard grid cell, addressed by its manifest widget id. */
function widget(page: Page, id: string) {
	return page.locator(`[role="group"][aria-label="${id}"]`)
}

/** The top-level nav entry for a menu-item id. */
function navEntry(page: Page, menuId: string) {
	return page.locator(`li[data-testid="cn-nav-entry-${menuId}"]`)
}

test.describe('personal dashboard — Mijn HR routes to a caller-scoped dashboard', () => {

	// Scenario: Group title navigates; chevron still folds
	// Scenario: Mijn HR is a routed group, not a bare parent
	// Scenario: Menu order matches ADR-001
	// @e2e humaniq-personal-dashboard::group-title-navigates-chevron-still-folds
	// @e2e mijn-hr-self-service::mijn-hr-is-a-routed-group-not-a-bare-parent
	// @e2e mijn-hr-self-service::menu-order-matches-adr-001
	// (humaniq-personal-dashboard REQ-PDB-003 + mijn-hr-self-service REQ-MHS-003 —
	// the two ADR-097 Decision 3 conditions in one journey: the group TITLE
	// navigates to the dashboard, AND the children stay reachable behind the
	// chevron. Asserted through the nav a user actually clicks, never from the
	// manifest.)
	test('the Mijn HR group title navigates to /mijn and the six widgets render', async ({ page }) => {
		await page.goto('/index.php/apps/humaniq/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 20_000 })

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		const topLevel = nav.locator('li[data-testid^="cn-nav-entry-"]')

		// Menu order (REQ-MHS-003): Dashboard first, Mijn HR second — read off
		// the rendered nav, in DOM order, by manifest id.
		const firstTwo = await topLevel.evaluateAll(
			(els) => els.slice(0, 2).map((el) => el.getAttribute('data-testid')),
		)
		expect(firstTwo, 'Dashboard is the first entry and Mijn HR the second').toEqual([
			'cn-nav-entry-Dashboard',
			'cn-nav-entry-MijnHrGroup',
		])

		// The group is ROUTED, not a bare parent. `data-cn-route` is emitted
		// only for an entry that carries a route, so its presence IS the claim;
		// a sibling group with children and no route is the in-nav control.
		const group = navEntry(page, 'MijnHrGroup')
		await expect(group, 'the personal group carries a route').toHaveAttribute('data-cn-route', 'MijnHr')
		await expect(
			navEntry(page, 'EmployeesGroup'),
			'control: a route-less group with children carries no data-cn-route',
		).not.toHaveAttribute('data-cn-route', /.*/)

		// Clicking the TITLE navigates (not merely toggles).
		await group.locator('> div > a.app-navigation-entry-link').click()
		await expect(page, 'the group title lands on the personal dashboard').toHaveURL(/\/apps\/humaniq\/mijn$/, { timeout: 15_000 })
		await expect(page.locator('[data-testid-page-id="MijnHr"]')).toBeVisible({ timeout: 15_000 })

		// All six widgets are placed and rendered.
		for (const id of WIDGET_IDS) {
			await expect(widget(page, id), `widget "${id}" must render`).toBeVisible({ timeout: 15_000 })
		}
	})

	// Scenario: Group title navigates; chevron still folds
	// @e2e humaniq-personal-dashboard::group-title-navigates-chevron-still-folds
	// (the second half of the same scenario, as its own journey: the chevron
	// toggles the children WITHOUT navigating. A fresh CI session starts with
	// the nav groups COLLAPSED, so the first click here EXPANDS.)
	test('the Mijn HR chevron toggles the children without navigating', async ({ page }) => {
		await gotoRoute(page, '/timesheets')
		const urlBefore = page.url()

		const group = navEntry(page, 'MijnHrGroup')
		const child = group.locator('a[href$="/mijn/uren"]')
		const chevron = group.locator('button.icon-collapse').first()

		const startedVisible = await child.isVisible()
		await chevron.click()
		if (startedVisible) {
			await expect(child, 'the chevron folds the children away').toBeHidden({ timeout: 10_000 })
			await chevron.click()
		}
		await expect(child, 'the chevron reveals the nine children').toBeVisible({ timeout: 10_000 })

		// The chevron is not a navigation affordance.
		expect(page.url(), 'toggling the group must not navigate').toBe(urlBefore)

		// And the children are still real links, not decoration.
		await child.click()
		await expect(page).toHaveURL(/\/mijn\/uren$/, { timeout: 15_000 })
	})

	// Scenario: Widgets show only the caller's rows
	// Scenario: Balances without userId never leak onto the personal dashboard
	// @e2e humaniq-personal-dashboard::widgets-show-only-the-callers-rows
	// @e2e mijn-hr-self-service::balances-without-userid-never-leak-onto-the-personal-dashboard
	// (humaniq-personal-dashboard REQ-PDB-002 + mijn-hr-self-service REQ-MHS-002 —
	// the fail-closed contract. The register is read first so the assertion is
	// "one of N", not "one": a table showing a single row because only one row
	// EXISTS would prove nothing about filtering.)
	test('the leave-balance widget lists only the caller-linked balance', async ({ page }) => {
		const api = await request.newContext({ httpCredentials: AUTH })
		try {
			const res = await api.get(`${OR_BASE}/${REGISTER}/LeaveBalance?_limit=200&year=2026`, { headers: HEADERS })
			expect(res.ok(), `LeaveBalance collection must be readable (${res.status()})`).toBeTruthy()
			const body = await res.json()
			const rows: Array<Record<string, unknown>> = Array.isArray(body) ? body : (body.results || [])
			const stamped = rows.filter((r) => r.userId === 'admin')
			expect(rows.length, 'the fixture must hold more 2026 balances than the caller owns').toBeGreaterThan(stamped.length)
			expect(stamped.length, 'exactly one seeded 2026 balance is linked to the acting account').toBe(1)

			await gotoRoute(page, '/mijn')
			const verlofsaldo = widget(page, 'verlofsaldo')
			await expect(verlofsaldo).toBeVisible({ timeout: 15_000 })
			// The unstamped rows are not merely last — they are absent.
			await expect(
				verlofsaldo.locator('tbody tr'),
				'only the caller-linked balance is listed; unstamped rows never appear',
			).toHaveCount(stamped.length, { timeout: 15_000 })
		} finally {
			await api.dispose()
		}
	})

	// Scenario: The pending-approvals tile renders for every caller and routes to the queue
	// @e2e humaniq-personal-dashboard::the-pending-approvals-tile-renders-for-every-caller-and-routes-to-the-queue
	// (humaniq-personal-dashboard REQ-PDB-002 / design D4 — the widget grammar has
	// no conditional-visibility primitive for stat tiles, so the tile ALWAYS
	// renders; zero is a legitimate value and is asserted as such rather than
	// pinned to a seeded count.)
	test('the pending-approvals tile always renders a number and opens the approval queue', async ({ page }) => {
		await gotoRoute(page, '/mijn')

		const tile = widget(page, 'te-beoordelen')
		await expect(tile).toBeVisible({ timeout: 15_000 })
		const value = tile.locator('.cn-stat-widget__value')
		await expect(value, 'the tile renders even for a caller who manages nobody').toBeVisible({ timeout: 15_000 })
		await expect(value, 'the tile shows a number, never a placeholder').toHaveText(/^\d+$/)

		// The whole tile is the click target (widgetLink → router-link).
		await tile.locator('a.cn-stat-widget--linked').click()
		await expect(page, 'the tile opens TeamUrengoedkeuring').toHaveURL(/\/timesheets\/team-approval$/, { timeout: 15_000 })
	})

	// Scenario: The legacy path redirects
	// @e2e mijn-hr-self-service::the-legacy-path-redirects
	// (mijn-hr-self-service REQ-MHS-007 — the hand-written redirect route in
	// src/main.js, asserted through a real browser navigation to the OLD path.
	// The page itself is `visibleIf`-gated in the MENU only; the ROUTE stays
	// reachable by URL in every administration mode.)
	test('the legacy gebruikelijk-loon path redirects under the /mijn prefix', async ({ page }) => {
		const base = await appBase(page)
		await page.goto(`${base}/mijn-hr/gebruikelijk-loon`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 20_000 })

		await expect(page, 'the stale path resolves instead of falling through to the catch-all')
			.toHaveURL(/\/apps\/humaniq\/mijn\/gebruikelijk-loon$/, { timeout: 15_000 })
		await expect(
			page.locator('[data-testid-page-id="MijnGebruikelijkLoon"]'),
			'and the gebruikelijk-loon dashboard actually renders',
		).toBeVisible({ timeout: 15_000 })
	})
})
