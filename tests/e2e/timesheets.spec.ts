/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * hrmq Timesheets index e2e — proves the router + manifest + CnIndexPage wiring
 * resolves in a real browser (UI-only, per the fleet's Playwright convention; no
 * API-direct assertions). This is the one thing the PHPUnit predicate suite
 * cannot prove: that the manifest-driven SPA shell actually mounts and reaches
 * the Timesheets route.
 */
import { test, expect } from '@playwright/test'

test.describe('hrmq Timesheets', () => {

	test('app shell loads without server errors', async ({ page }) => {
		await page.goto('/index.php/apps/hrmq/timesheets')
		await expect(page).toHaveURL(/.*hrmq/)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		await expect(page.locator('body')).not.toContainText('not installed')
	})

	test('Timesheets index renders via the manifest router', async ({ page }) => {
		await page.goto('/index.php/apps/hrmq/timesheets')
		// The Vue app mounts into #content; the app nav must render.
		const nav = page.locator('nav').first()
		await expect(nav).toBeVisible({ timeout: 15000 })
		// The manifest-driven page title ("Timesheets") must appear once the
		// CnIndexPage resolves through the router — not a controller-direct call.
		await expect(page.getByText('Timesheets', { exact: false }).first())
			.toBeVisible({ timeout: 15000 })
	})

	test('navigating to the Timesheets menu entry reaches the index route', async ({ page }) => {
		await page.goto('/index.php/apps/hrmq')
		const nav = page.locator('nav').first()
		await expect(nav).toBeVisible({ timeout: 15000 })
		await nav.getByText('Timesheets', { exact: false }).first().click()
		await expect(page).toHaveURL(/timesheets/, { timeout: 15000 })
	})
})
