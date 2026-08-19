/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/admin.json`.
 * Every spec then reuses that storage state via the `use.storageState`
 * setting in playwright.config.ts, so individual tests start from an
 * authenticated session without each one paying the login cost.
 *
 * It also retires the overlays that would otherwise sit on top of the app and
 * swallow the first click of every spec — nc-vue's support dialog and
 * walkthrough, plus Nextcloud's own first-run wizard — using the shared
 * helpers from `@conduction/nextcloud-vue/testing/playwright` rather than a
 * hand-rolled dismissal loop per app.
 *
 * Ported from docudesk/tests/e2e/global-setup.ts (NC34-safe login
 * selectors + status.php health poll). Pattern reference: ADR-030.
 */

import { chromium, request, type FullConfig } from '@playwright/test'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'
import {
	retireFirstRunWizard,
	seedFirstVisitOverlaysSeen,
} from '@conduction/nextcloud-vue/testing/playwright'
import { ADMIN_CREDENTIALS, resolveBaseURL } from './base-url'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')
const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'hrmq-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/hrmq/`.
 *
 * The shared quality pipeline runs `npm ci` + `npx playwright install`
 * before the spec run, but never `npm run build`. On a fresh CI VM the
 * `js/hrmq-main.js` artefact doesn't exist, so the rendered page loads a
 * 404 script tag and the Vue app never mounts — every selector wait then
 * times out. Locally this is a no-op when the bundle is present.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}
	// eslint-disable-next-line no-console
	console.log(`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

/**
 * Wait until Nextcloud is actually serving requests.
 *
 * An instance is routinely mid-flight: a deploy flips it into maintenance
 * mode, an app version bump sets needsDbUpgrade (which makes NC answer 503 on
 * every route), or the database is still finishing crash recovery. All three
 * are transient but a single-shot check turns them into a hard suite failure.
 * Poll until the instance reports installed, out of maintenance and not
 * awaiting a DB upgrade. Tune with E2E_HEALTH_TIMEOUT_MS (default 10 min).
 *
 * @param baseURL Instance base URL.
 * @return Resolves once healthy; rejects on timeout.
 */
async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const deadline = Date.now() + Number(process.env.E2E_HEALTH_TIMEOUT_MS || 600_000)
	const ctx = await request.newContext()
	let last = 'no response yet'
	try {
		while (Date.now() < deadline) {
			try {
				const res = await ctx.get(`${baseURL}/status.php`, { failOnStatusCode: false })
				if (res.ok()) {
					const body = await res.json().catch(() => ({}))
					if (body && body.installed === true
						&& body.maintenance === false
						&& body.needsDbUpgrade === false) {
						return
					}
					last = `status.php = ${JSON.stringify(body)}`
				} else {
					// 503 while an app upgrade is pending, 500 while the DB recovers.
					last = `status.php returned ${res.status()}`
				}
			} catch (err) {
				last = `request failed: ${(err as Error).message}`
			}
			// eslint-disable-next-line no-await-in-loop
			await new Promise((resolve) => setTimeout(resolve, 5_000))
		}
		throw new Error(
			`Nextcloud at ${baseURL} did not become healthy in time — last seen: ${last}. `
			+ 'Check for a concurrent deploy (occ upgrade), maintenance mode, or a recovering database.',
		)
	} finally {
		await ctx.dispose()
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	// Single source of truth, and it THROWS rather than defaulting to the
	// shared :8080 instance — see ./base-url.ts.
	const baseURL = (config.projects[0]?.use?.baseURL as string | undefined) ?? resolveBaseURL()
	const { username, password } = ADMIN_CREDENTIALS

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(AUTH_DIR, { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })

	// Seed nc-vue's first-visit overlays as already seen BEFORE any page is
	// opened, on the CONTEXT rather than a page: only the context form writes
	// real localStorage entries that survive into `storageState()` below. The
	// page-scoped match-all (`'*'`) form installs a `getItem` shim that cannot
	// serialise, so it would persist nothing at all — the app id must be
	// explicit, and as of 2.1.0-vue3.12 the helper throws rather than silently
	// seeding nothing.
	await seedFirstVisitOverlaysSeen(context, 'hrmq')

	const page = await context.newPage()

	// The instance can flip back into maintenance between the health check and
	// this navigation; re-check health and retry rather than failing the suite.
	for (let attempt = 1; ; attempt++) {
		try {
			await page.goto('/index.php/login')
			break
		} catch (err) {
			if (attempt >= 3) {
				throw err
			}
			await ensureNextcloudReachable(baseURL)
		}
	}
	// Nextcloud's login form is client-rendered and its markup has drifted
	// between releases: on NC 34 the fields carry `id="user"` / `id="password"`
	// but no `name` attribute, so a `input[name="user"]` selector never resolves
	// and globalSetup times out. Match either shape, and wait for the field to
	// be attached first.
	const userField = page.locator('input#user, input[name="user"]').first()
	const passwordField = page.locator('input#password, input[name="password"]').first()
	await userField.waitFor({ state: 'visible', timeout: 30_000 })
	// The login form is a Vue app: the markup exists before its submit handler
	// is attached, so clicking too early silently does nothing and the page
	// simply stays on /login.
	//
	// This used to wait for 'networkidle', which never settles on Nextcloud —
	// background polling keeps the connection count above zero, so the wait ran
	// its full timeout and the `.catch(() => {})` swallowed it. It was not
	// synchronising anything; it was a sleep with an exception handler
	// (ADR-074 rule 4).
	//
	// Wait for the thing actually depended on instead: the submit control being
	// present and enabled is the observable signal that the login bundle has
	// mounted and will handle the click.
	await page.waitForLoadState('domcontentloaded')
	await page.locator('button[type="submit"]').first().waitFor({ state: 'visible', timeout: 30_000 })
	await userField.fill(username)
	await passwordField.fill(password)
	// Bind the navigation wait BEFORE clicking, so a fast redirect cannot be
	// missed between the click returning and the wait starting.
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 }).catch(() => {}),
		page.locator('button[type="submit"]').first().click(),
	])
	// Wait for the authenticated shell. NC 34 no longer guarantees the legacy
	// `#header` / `header.header` markup, so accept any banner-role header and
	// give the instance room to finish the post-login redirect.
	await page.waitForURL((url) => /\/login(\?|$|\/)/.test(url.pathname) === false, { timeout: 60_000 })
	await page.waitForSelector('#header, header.header, header, [role="banner"]', { timeout: 60_000 })
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. `
			+ 'Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin).',
		)
	}

	// Nextcloud's own first-run wizard is a separate overlay from the nc-vue
	// ones seeded above and is retired server-side, per user — so it must
	// happen after login. A 404 (app not installed) counts as cleared.
	const wizard = await retireFirstRunWizard(page)
	if (!wizard.cleared) {
		// eslint-disable-next-line no-console
		console.warn(`[playwright globalSetup] first-run wizard not retired (HTTP ${wizard.status}); it may intercept clicks`)
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
