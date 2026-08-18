/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Host-app SFC pages — ProformaPayslip and AdministrationSwitcher.
 *
 * These are the only two `kind: 'page'` components hrmq registers in
 * src/registry.js, and they exist precisely because no declarative manifest
 * primitive can express what they do (see the `_note` on each registration):
 *
 *   - ProformaPayslip gathers HYPOTHETICAL gross-to-net inputs, POSTs them and
 *     renders a breakdown while persisting nothing. `open-form` would persist
 *     its object and `api-call` only interpolates fixed params.
 *   - AdministrationSwitcher lists the caller's accessible administraties,
 *     POSTs a guarded switch and re-scopes every sibling page. The v2 renderer
 *     chrome exposes no topbar-switcher slot.
 *
 * A hand-written component that the manifest renderer does NOT own is exactly
 * the kind that breaks silently: the manifest keeps validating, every other
 * page keeps rendering, and only these two go blank. That is what gate-26 is
 * asking about, and an e2e test answers it better than a screenshot would —
 * a baseline pins pixels, this pins that the page MOUNTED AND RENDERED ITS OWN
 * CONTENT.
 *
 * Assertions are unconditional per ADR-074: no if-visible-then-assert guards,
 * because a guarded assertion that never runs is indistinguishable from one
 * that passed.
 */

import { expect, test, type Page } from '@playwright/test'

/**
 * The app's base path, resolved the way the APP resolves it — via
 * `OC.generateUrl`, exactly as core-journeys.spec.ts does.
 *
 * NOT by reading the landed pathname after visiting the app root. The root
 * REDIRECTS to the default route, so that reads back
 * `/index.php/apps/hrmq/timesheets` and every route built on it becomes
 * `…/timesheets/payroll/proforma`, which matches nothing, falls through to the
 * catch-all, and lands back on the default route. The first version of this
 * helper did exactly that and the failure looked like a broken PAGE rather
 * than a broken base.
 *
 * The `/index.php/...` form matters too: the shared CI workflow serves
 * Nextcloud from `php -S` with no mod_rewrite, so that is the router's real
 * history base on the instance these specs run against.
 */
let cachedBase: string | null = null
async function appBase(page: Page): Promise<string> {
	if (cachedBase) {
		return cachedBase
	}

	await page.goto('/index.php/apps/hrmq/', { waitUntil: 'domcontentloaded' })
	const resolved = await page.evaluate(
		() => (window as unknown as { OC?: { generateUrl?: (p: string) => string } })
			.OC?.generateUrl?.('/apps/hrmq'),
	)
	if (!resolved) {
		throw new Error(
			'OC.generateUrl is not available on the hrmq page, so the router base cannot '
			+ 'be resolved — every route assertion below would be measuring the wrong URL.',
		)
	}
	cachedBase = resolved.replace(/\/+$/, '')

	return cachedBase
}

/**
 * Navigate to an in-app route and assert the router stayed on it.
 */
async function gotoRoute(page: Page, route: string): Promise<void> {
	const base = await appBase(page)
	await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#app-content, .app-content').first())
		.toBeVisible({ timeout: 15_000 })
	expect(new URL(page.url()).pathname, `router must stay on ${route}`)
		.toContain(route)
}

test.describe('host-app SFC pages', () => {
	test('ProformaPayslip renders its own compute form', async ({ page }) => {
		await gotoRoute(page, '/payroll/proforma')

		const content = page.locator('#app-content, .app-content').first()

		// Its own title, not the shell's. A catch-all fallthrough renders the
		// dashboard, which is visible and non-empty and would satisfy a mere
		// "something rendered" check.
		await expect(content).toContainText(/Simuleer loonstrook|Proforma/i)

		// The page's REASON to exist is that it gathers inputs and computes.
		// A mounted-but-inert component still renders its heading, so assert
		// the interactive surface: at least one field to type a gross amount
		// into, and a control to submit it.
		await expect(content.locator('input, select').first())
			.toBeVisible({ timeout: 15_000 })
		await expect(content.locator('button').first())
			.toBeVisible({ timeout: 15_000 })
	})

	test('AdministrationSwitcher renders the administratie surface', async ({
		page,
	}) => {
		await gotoRoute(page, '/configuratie/administraties')

		const content = page.locator('#app-content, .app-content').first()

		await expect(content).toContainText(/Administratie/i)

		// Either a list of accessible administraties or an explicit empty
		// state — never a blank shell. On a freshly seeded CI instance the
		// seeds create two Administration rows, but this assertion does not
		// depend on that: it refuses only the blank case.
		await expect(content).not.toHaveText(/^\s*$/)
	})
})
