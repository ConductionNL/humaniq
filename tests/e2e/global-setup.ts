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

	// `globalSetup` builds its OWN browser, context and page, so NOTHING in
	// playwright.config.ts's `use` block reaches it — `navigationTimeout:
	// 60_000` and `actionTimeout: 20_000` apply to tests only. This page was
	// silently running on Playwright's 30s/no-limit defaults instead, which is
	// why setup could fail with "Timeout 30000ms exceeded" on an instance the
	// config had already been told to allow 60s for. Mirror the config here
	// rather than leaving the two to disagree.
	page.setDefaultNavigationTimeout(60_000)
	page.setDefaultTimeout(20_000)

	// The instance can flip back into maintenance between the health check and
	// this navigation; re-check health and retry rather than failing the suite.
	for (let attempt = 1; ; attempt++) {
		try {
			// `domcontentloaded`, not the default `load`. The next statement
			// after this loop is already `waitForLoadState('domcontentloaded')`,
			// so that was always the condition this setup depends on — the
			// default was simply never revisited.
			//
			// It matters on a real instance: against NC 34.0.0 with a large app
			// set the login HTML returns 200 in ~3.8s, while the full `load`
			// event (every stylesheet, icon and app bundle the login chrome
			// pulls) took long enough that globalSetup threw before a single
			// spec ran. Waiting for the document rather than for every
			// subresource is both faster and the condition actually required to
			// fill the form.
			//
			// HONESTY NOTE: that run was on a host at ~4x CPU oversubscription,
			// so the absolute timings are not a clean measurement of the
			// instance. The change stands on the logic above, not on those
			// numbers.
			await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
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
	//
	// Playwright's click has its own built-in "wait for scheduled navigations
	// to finish" step that runs AFTER the click lands. Observed against a live
	// NC 34.0.0 instance: the call log reaches `click action done` and then
	// sits on `waiting for scheduled navigations to finish` until the action
	// timeout, throwing from globalSetup — while the login has in fact
	// SUCCEEDED (navigating manually afterwards lands authenticated). The
	// `.catch(() => {})` on the sibling waitForNavigation cannot save it,
	// because it is the CLICK that throws, not the wait.
	//
	// HONESTY NOTE: that observation was made on a host running at ~4x CPU
	// oversubscription, so it is NOT established that the hang is inherent to
	// NC 34 rather than a symptom of a slow machine. This opt-out is kept
	// because it is correct either way — the explicit `waitForNavigation`
	// above and the `waitForURL` / `waitForSelector` below are what should
	// decide whether login worked, not a click's implicit side-wait — but it
	// should not be cited as a proven NC 34 defect until it is reproduced on
	// an idle host.
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 }).catch(() => {}),
		page.locator('button[type="submit"]').first().click({ noWaitAfter: true }),
	])
	// Wait for the authenticated shell. NC 34 no longer guarantees the legacy
	// `#header` / `header.header` markup, so accept any banner-role header and
	// give the instance room to finish the post-login redirect.
	// `waitForURL` defaults to `waitUntil: 'load'`, and that is the same trap
	// this file already documents for `networkidle` a few lines up: on
	// Nextcloud the post-login page keeps enough in flight that the wait runs
	// its full timeout and throws `waiting for navigation until "load"` — even
	// though the URL condition itself was satisfied almost immediately. The
	// thing being waited on here is the REDIRECT OFF /login, which the
	// predicate expresses; whether every subresource of the destination has
	// finished is a different question, and `waitForSelector` on the next line
	// is what actually establishes the authenticated shell is up.
	await page.waitForURL(
		(url) => /\/login(\?|$|\/)/.test(url.pathname) === false,
		{ timeout: 60_000, waitUntil: 'domcontentloaded' },
	)
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
