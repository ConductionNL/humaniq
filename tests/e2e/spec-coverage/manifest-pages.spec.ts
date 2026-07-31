/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Manifest-driven page smoke test (gate-19 spec coverage).
 *
 * hrmq is the fleet's manifest-purity flagship: 113 pages come straight
 * out of `src/manifest.json` (60 index, 49 detail, 2 dashboard, 2
 * custom), rendered by @conduction/nextcloud-vue's CnAppRoot with
 * hash-mode routing. This spec is generated FROM the manifest at spec
 * load time — add a page to the manifest and it is automatically smoke
 * tested; no hand-maintained route list to drift.
 *
 * For every NON-parameterised page route it navigates to the hash-form
 * URL (`/apps/hrmq/#<route>`) and asserts:
 *   - the SPA shell mounts (`#app-content` is visible)
 *   - the rendered page contains real content (innerHTML > 100 chars)
 *   - no app-origin console errors fire during initial mount
 *
 * `:id`-parameterised detail routes are excluded here — a detail page
 * needs a real object; one seeded detail journey lives in
 * core-journeys.spec.ts.
 *
 * Read-only: these tests do NOT create / mutate data.
 *
 * Pattern reference:
 *   openconnector/tests/e2e/regression/manifest-pages.spec.ts
 */

import { test, expect, type Page, type ConsoleMessage } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

/* --------------------------------------------------------------------- *
 *  Manifest loading (+ optional manifest.d fragments)
 * --------------------------------------------------------------------- */

interface ManifestPage {
	id: string
	route: string
	type: string
	title?: string
	config?: { register?: string, schema?: string }
}

interface Manifest {
	pages: ManifestPage[]
	menu: unknown[]
}

/**
 * Read `src/manifest.json` and merge any `src/manifest.d/*.json`
 * fragments (fragments may contribute additional `pages` / `menu`
 * entries; the directory does not currently exist, but the merge keeps
 * this spec correct the day it does).
 */
function loadManifest(): Manifest {
	const srcDir = path.resolve(__dirname, '..', '..', '..', 'src')
	const manifest = JSON.parse(fs.readFileSync(path.join(srcDir, 'manifest.json'), 'utf-8')) as Manifest
	const fragmentsDir = path.join(srcDir, 'manifest.d')
	if (fs.existsSync(fragmentsDir)) {
		for (const file of fs.readdirSync(fragmentsDir).filter((f) => f.endsWith('.json')).sort()) {
			const fragment = JSON.parse(fs.readFileSync(path.join(fragmentsDir, file), 'utf-8')) as Partial<Manifest>
			if (Array.isArray(fragment.pages)) {
				manifest.pages.push(...fragment.pages)
			}
			if (Array.isArray(fragment.menu)) {
				manifest.menu.push(...(fragment.menu as unknown[]))
			}
		}
	}
	return manifest
}

const MANIFEST = loadManifest()

/** All pages whose route needs no path parameter — smoke-testable as-is. */
const SMOKE_PAGES = MANIFEST.pages.filter((p) => !p.route.includes(':'))

/** Parameterised (detail) pages — excluded here, counted for the sanity test. */
const PARAM_PAGES = MANIFEST.pages.filter((p) => p.route.includes(':'))

/* --------------------------------------------------------------------- *
 *  App root resolution
 * --------------------------------------------------------------------- */

// In Nextcloud installs with `htaccess.RewriteBase => '/'` (the default
// for the apache-served dev container) `generateUrl` returns `/apps/hrmq`
// and any `/index.php/`-prefixed URL sits outside the router base. In
// CI's php -S install (no htaccess processing) the inverse is true and
// only the `/index.php/...` form works. Resolve at runtime via a probe.
const ROOT_CANDIDATES = ['/apps/hrmq', '/index.php/apps/hrmq']
let _root: string | null = null
async function rootUrl(page: Page): Promise<string> {
	if (_root) return _root
	for (const candidate of ROOT_CANDIDATES) {
		const res = await page.request.get(`${candidate}/`, { failOnStatusCode: false })
		if (res.ok() && (await res.text()).includes('hrmq-main.js')) {
			_root = candidate
			return candidate
		}
	}
	throw new Error('Neither /apps nor /index.php form serves the hrmq SPA shell')
}

/* --------------------------------------------------------------------- *
 *  Console noise filter
 * --------------------------------------------------------------------- */

/**
 * Errors we ignore — these come from Nextcloud's own bootstrap or the
 * shared dev instance's known platform quirks, not from hrmq.
 */
const IGNORED_CONSOLE_PATTERNS: RegExp[] = [
	/Deprecation/i,
	/Slow network is detected/i,
	/favicon/i,
	/the resource at .* was preloaded using link preload but not used/i,
	// The user_status app 500s on dev instances with a PostgreSQL collation
	// version mismatch — pre-existing platform noise unrelated to hrmq.
	/Failed to load user status/i,
	/user_status/i,
	/the server responded with a status of 500/i,
	// Missing avatars / previews on a fresh instance log 404 resource errors.
	/Failed to load resource:.*Not Found/i,
	// NC theming: when the active theme's token CSS is briefly unavailable
	// mid-run it serves the 404 HTML page, tripping a MIME-type refusal.
	/Refused to apply style/i,
	/is not a supported stylesheet MIME type/i,
]

function attachConsoleSpy(page: Page): { errors: string[] } {
	const errors: string[] = []
	page.on('console', (msg: ConsoleMessage) => {
		const text = msg.text()
		if (IGNORED_CONSOLE_PATTERNS.some((rx) => rx.test(text))) {
			return
		}
		if (msg.type() === 'error') {
			errors.push(text.slice(0, 300))
		}
	})
	page.on('pageerror', (err) => {
		errors.push(`pageerror: ${err.message}`)
	})
	return { errors }
}

/* --------------------------------------------------------------------- *
 *  Parametrized smoke tests
 * --------------------------------------------------------------------- */

test.describe('manifest pages — schema-driven render', () => {

	test('manifest sanity: page partition covers every declared page', () => {
		// If this fails the manifest changed shape and the smoke loop below
		// is silently under-covering — fail loudly instead.
		expect(MANIFEST.pages.length, 'manifest declares pages').toBeGreaterThan(0)
		expect(SMOKE_PAGES.length + PARAM_PAGES.length).toBe(MANIFEST.pages.length)
		expect(SMOKE_PAGES.length, 'non-parameterised pages to smoke').toBeGreaterThan(0)
	})

	for (const pg of SMOKE_PAGES) {
		test(`[${pg.type}] ${pg.id} mounts at ${pg.route}`, async ({ page }) => {
			const { errors } = attachConsoleSpy(page)

			const root = await rootUrl(page)
			// The in-app router runs in HISTORY mode (`mode: 'history'`,
			// src/main.js:83) — unlike openconnector's hash router. The route
			// must therefore be PATH-form (`/apps/hrmq/timesheets`); a
			// hash-form deep-link (`/apps/hrmq/#/timesheets`) is ignored by
			// the router and silently lands on the default page, so every
			// page would be smoke-tested against the same fallback component
			// (observed live 2026-07-26: all hash routes rendered the
			// "Mijn uren" Timesheet index). Use `domcontentloaded` rather
			// than `networkidle` — NC's notification poll keeps the network
			// busy indefinitely.
			await page.goto(`${root}${pg.route}`, { waitUntil: 'domcontentloaded', timeout: 30_000 })

			// The Nextcloud SPA shell mounts inside #app-content.
			await expect(page.locator('#app-content, [data-cy=app-content], .app-content').first()).toBeVisible({ timeout: 15_000 })

			// Route identity: the router must still be ON the requested route.
			// A redirect back to the default page (the greenwash mode above)
			// changes the pathname and must fail the smoke test.
			expect(new URL(page.url()).pathname, `${pg.id} was redirected away from ${pg.route}`).toContain(pg.route)

			// CnAppRoot should have resolved the route to *some* page component
			// (CnIndexPage, CnDashboardPage, …). Verify that anything rendered
			// inside the app-content area beyond the loading spinner.
			const renderedContent = await page.locator('#app-content, .app-content').first().innerHTML()
			expect(renderedContent.length, `${pg.id} (${pg.route}) rendered no content inside app-content`).toBeGreaterThan(100)

			// No fatal console errors during initial mount.
			expect(errors, `${pg.id} (${pg.route}) emitted console errors: ${errors.join(' | ')}`).toEqual([])
		})
	}

})
