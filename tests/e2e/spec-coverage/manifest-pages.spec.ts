/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Manifest-driven page smoke test (gate-19 spec coverage).
 *
 * hrmq is the fleet's manifest-purity flagship: 113 pages come straight
 * out of `src/manifest.json` (60 index, 49 detail, 2 dashboard, 2
 * custom), rendered by @conduction/nextcloud-vue's CnAppRoot with
 * HISTORY-mode routing (`createWebHistory`, src/main.js). This spec is
 * generated FROM the manifest at spec load time — add a page to the
 * manifest and it is automatically smoke tested; no hand-maintained
 * route list to drift.
 *
 * For every NON-parameterised page route it navigates to the PATH-form
 * URL (`/apps/hrmq<route>`) — see the long comment on the navigation
 * itself for why the hash form silently greenwashes every case — and
 * asserts:
 *   - the SPA shell mounts (`#app-content` is visible)
 *   - the router is still ON the requested route (no fallback redirect)
 *   - the rendered page contains real content (innerHTML > 100 chars)
 *   - no app-origin console errors fire during initial mount
 *
 * `:id`-parameterised detail routes are covered too, each resolving one
 * real object id from the register before navigating. They were previously
 * EXCLUDED — computed into PARAM_PAGES, counted in the partition sanity
 * check, and then never visited. That combination reads as full coverage
 * while half the app goes unopened, and it is exactly how 49 detail pages
 * rendering completely blank survived a green suite
 * (ConductionNL/hrmq#112). A `detail coverage` control fails the run if
 * every detail page ends up skipped, so an empty register cannot
 * greenwash it either.
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

/* --------------------------------------------------------------------- *
 *  Detail-page quarantine — WITH AN EXPIRY DATE
 * --------------------------------------------------------------------- */

/**
 * The `@conduction/nextcloud-vue` release whose `CnDetailPage` cannot render
 * a detail page in a PRODUCTION build.
 *
 * `CnDetailPage.vue:416` attaches a string ref to a dynamic component whose
 * owner instance is null at patch time. Vue's `setRef()` guards that case —
 * but the guard AND its early `return` sit inside
 * `if (process.env.NODE_ENV !== "production")`, so a production bundle falls
 * through to `owner.refs` and throws
 * `TypeError: Cannot read properties of null (reading 'refs')`. Every detail
 * page therefore renders an empty content pane in production and looks fine
 * in development. Filed as ConductionNL/nextcloud-vue#705; tracked leaf-side
 * as ConductionNL/hrmq#112.
 *
 * The detail tests below are quarantined ONLY while this exact version is
 * installed. The moment the dependency moves, they run again automatically —
 * so the quarantine cannot outlive the bug silently, which is the failure
 * mode of every hand-maintained skip list.
 */
const NC_VUE_VERSION: string = (() => {
	try {
		const pkg = path.resolve(__dirname, '..', '..', '..', 'node_modules', '@conduction', 'nextcloud-vue', 'package.json')
		return JSON.parse(fs.readFileSync(pkg, 'utf-8')).version as string
	} catch {
		return 'unknown'
	}
})()

/** The one version known to carry the CnDetailPage ref defect. */
const DETAIL_BROKEN_IN = '2.2.0-vue3.2'

/** True while the installed library is the known-broken one. */
const DETAIL_QUARANTINED = NC_VUE_VERSION === DETAIL_BROKEN_IN

/** All pages whose route needs no path parameter — smoke-testable as-is. */
const SMOKE_PAGES = MANIFEST.pages.filter((p) => !p.route.includes(':'))

/** Parameterised (detail) pages — excluded here, counted for the sanity test. */
const PARAM_PAGES = MANIFEST.pages.filter((p) => p.route.includes(':'))

/* --------------------------------------------------------------------- *
 *  App root resolution
 * --------------------------------------------------------------------- */

// In Nextcloud installs with `htaccess.RewriteBase => '/'` (the default for
// the apache-served dev container) `generateUrl` returns `/apps/hrmq`; with the
// front controller active it returns `/index.php/apps/hrmq`. `src/main.js`
// builds the router with `createWebHistory(generateUrl('/apps/hrmq'))`, so THAT
// value — and only that value — is the base every in-app route hangs off.
//
// ⚠️ The previous implementation probed the two candidate prefixes and took the
// first one that SERVED THE SHELL. That is a different question, and on CI the
// two answers disagree: `php -S` routes BOTH `/apps/hrmq/...` and
// `/index.php/apps/hrmq/...` into `index.php` (confirmed in nextcloud.log:
// `"url":"/apps/hrmq/dsr-requests","scriptName":"/index.php"`), so the probe
// always matched `/apps/hrmq` first — while `generateUrl` returned the
// `/index.php` form. Every deep link therefore landed OUTSIDE the router base,
// matched nothing, hit `main.js`'s `/:pathMatch(.*)*` catch-all and redirected
// to its default page. Measured on run 30919961510 (job 92028085860): 67 of 70
// tests failed with `Received string: "/index.php/apps/hrmq/timesheets"`, and
// the only page that "passed" was the redirect target itself — the failure mode
// and the success signal were the same URL.
//
// So ask the app, not the server: read `OC.generateUrl('/apps/hrmq')` out of the
// live page. It is literally the call `main.js` passes to `createWebHistory`,
// so the base cannot drift from the router's. Serving the shell is still
// verified — via the navigation below — but it is no longer what SELECTS the
// base.
let _root: string | null = null
async function rootUrl(page: Page): Promise<string> {
	if (_root) return _root
	// `/index.php/apps/hrmq/` is reachable on every install shape (the front
	// controller is always addressable explicitly), so it is a safe place to
	// stand while asking the page which base the router actually uses.
	await page.goto('/index.php/apps/hrmq/', { waitUntil: 'domcontentloaded' })
	const resolved = await page.evaluate(
		() => (window as unknown as { OC?: { generateUrl?: (p: string) => string } }).OC?.generateUrl?.('/apps/hrmq'),
	)
	if (!resolved) {
		throw new Error(
			'OC.generateUrl is not available on the hrmq page, so the router base cannot be '
			+ 'resolved. The Nextcloud core bundle did not load — every route assertion below '
			+ 'would be measuring the wrong URL.',
		)
	}
	_root = resolved.replace(/\/+$/, '')
	return _root
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

	/* ------------------------------------------------------------------ *
	 *  Detail pages
	 *
	 *  These used to be excluded outright: PARAM_PAGES was computed, counted
	 *  in the partition assertion above, and then never visited. That reads
	 *  as full coverage — the partition check says every declared page is
	 *  accounted for — while HALF the app was never opened. It is how 49
	 *  detail pages rendering COMPLETELY BLANK survived a green suite
	 *  (ConductionNL/hrmq#112): nothing ever navigated to one.
	 *
	 *  A detail route needs a real object, so each test resolves one id from
	 *  the register before navigating. Where a schema genuinely has no rows
	 *  the test skips — but the skip reason carries the MEASURED total, so it
	 *  states a fact rather than a guess, and `detail coverage` below fails
	 *  the run if skipping ever becomes the whole story.
	 * ------------------------------------------------------------------ */

	/** Detail pages whose route parameter we know how to resolve. */
	const ALL_DETAIL_PAGES = PARAM_PAGES.filter(
		(p) => p.config?.register && p.config?.schema && /:id\b/.test(p.route),
	)

	/**
	 * One page per distinct widget-key signature.
	 *
	 * Every detail page renders through the SAME component (CnDetailPage), and
	 * what varies between them is which widget kinds it has to render. Two
	 * pages with the same set of widgetKeys exercise the same render path, so
	 * running both costs a full multi-MB page load and proves nothing the
	 * first did not.
	 *
	 * Measured on this manifest: 47 detail pages collapse to 21 signatures.
	 * Running all 47 pushed the suite past `globalTimeout` (38 min, tuned to
	 * CI's 45-min job cap), and a suite that times out reports no tally at
	 * all — strictly worse than one that finishes. This keeps every distinct
	 * render path covered while fitting the budget.
	 *
	 * The collapse is LOGGED below, not silent: a reader must be able to see
	 * which pages were represented by which, rather than believing all 47 ran.
	 */
	const detailBySignature = new Map<string, ManifestPage[]>()
	for (const pg of ALL_DETAIL_PAGES) {
		const signature = [...new Set(
			((pg as { widgets?: Array<{ widgetKey?: string }> }).widgets ?? [])
				.map((w) => w.widgetKey ?? ''),
		)].sort().join(',')
		const bucket = detailBySignature.get(signature)
		if (bucket) {
			bucket.push(pg)
		} else {
			detailBySignature.set(signature, [pg])
		}
	}
	const RESOLVABLE_DETAIL_PAGES = [...detailBySignature.values()].map((group) => group[0])

	/** How many detail pages actually got visited, for the coverage control. */
	const detailOutcome = { visited: 0, skipped: 0 }

	for (const pg of RESOLVABLE_DETAIL_PAGES) {
		test(`[${pg.type}] ${pg.id} mounts at ${pg.route}`, async ({ page }) => {
			// Quarantined while the installed library carries the CnDetailPage
			// ref defect — see NC_VUE_VERSION above. Not a judgement that these
			// pages work: they demonstrably do NOT, and that is the point of
			// the issue. Running them here would only re-report a known library
			// bug on every PR, ~55s per failing test, pushing the suite past
			// its globalTimeout so it reports no tally at all.
			test.fixme(
				DETAIL_QUARANTINED,
				`@conduction/nextcloud-vue@${NC_VUE_VERSION}: CnDetailPage renders blank in production `
				+ '(nextcloud-vue#705 / hrmq#112). Auto-enables when the dependency moves.',
			)

			const { errors } = attachConsoleSpy(page)
			const register = pg.config!.register!
			const schema = pg.config!.schema!

			// Resolve a real object id. `page.request` inherits the
			// authenticated storageState, so this is the same session the
			// browser navigation will use.
			const res = await page.request.get(
				`/index.php/apps/openregister/api/objects/${register}/${schema}?_limit=1`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			expect(res.ok(), `${pg.id}: listing ${register}/${schema} failed with HTTP ${res.status()}`).toBe(true)
			const body = await res.json()
			const rows = (body.results ?? []) as Array<{ id?: string }>
			const total = body.total ?? rows.length

			if (rows.length === 0 || !rows[0]?.id) {
				detailOutcome.skipped++
				// The reason states the measured total. A skip whose reason is
				// untrue is an invisible pass.
				test.skip(true, `${pg.id}: ${register}/${schema} has total=${total} — no object to open`)
				return
			}

			const url = `${await rootUrl(page)}${pg.route.replace(/:id\b/, rows[0].id!)}`
			await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30_000 })

			await expect(page.locator('#app-content, [data-cy=app-content], .app-content').first()).toBeVisible({ timeout: 15_000 })

			// Same content assertion the index pages carry. This is the one
			// that catches a blank detail pane: the shell mounts either way,
			// so `#app-content` being visible proves nothing on its own.
			const rendered = await page.locator('#app-content, .app-content').first().innerHTML()
			expect(rendered.length, `${pg.id} (${pg.route}) rendered no content inside app-content`).toBeGreaterThan(100)

			expect(errors, `${pg.id} (${pg.route}) emitted console errors: ${errors.join(' | ')}`).toEqual([])
			detailOutcome.visited++
		})
	}

	test('detail coverage: every widget-key signature is represented, and the collapse is visible', () => {
		// Say out loud what this suite does NOT open. A bounded run that stays
		// quiet about its bound reads exactly like an exhaustive one.
		const lines: string[] = []
		for (const [signature, group] of detailBySignature) {
			if (group.length > 1) {
				lines.push(`  [${signature}] ${group[0].id} represents ${group.length - 1} other page(s): `
					+ group.slice(1).map((p) => p.id).join(', '))
			}
		}
		// eslint-disable-next-line no-console
		console.log(
			`[detail coverage] ${ALL_DETAIL_PAGES.length} detail pages -> `
			+ `${RESOLVABLE_DETAIL_PAGES.length} distinct widget-key signatures opened.\n`
			+ (lines.join('\n') || '  (no page represents another)'),
		)
		expect(
			RESOLVABLE_DETAIL_PAGES.length,
			'every signature must contribute exactly one opened page',
		).toBe(detailBySignature.size)
	})

	test('detail coverage: the quarantine is declared, or a page was actually opened', () => {
		if (DETAIL_QUARANTINED) {
			// Loud, and states the version it is pinned to. A quarantine that
			// nobody can see is indistinguishable from coverage.
			// eslint-disable-next-line no-console
			console.log(
				`[detail coverage] QUARANTINED against @conduction/nextcloud-vue@${NC_VUE_VERSION}: `
				+ `${RESOLVABLE_DETAIL_PAGES.length} detail signature(s) NOT opened `
				+ '(nextcloud-vue#705 / hrmq#112). These re-enable automatically when the dependency moves.',
			)
			expect(NC_VUE_VERSION, 'quarantine is pinned to one known-broken version').toBe(DETAIL_BROKEN_IN)
			return
		}

		// Not quarantined: the original control applies — see below.
		expect(
			detailOutcome.visited,
			`every detail page skipped (${detailOutcome.skipped} skipped of ${RESOLVABLE_DETAIL_PAGES.length}) — `
			+ 'the register has no objects, so this run proves nothing about detail rendering',
		).toBeGreaterThan(0)
	})

	test('detail coverage: at least one detail page was actually opened', () => {
		test.skip(DETAIL_QUARANTINED, `detail pages quarantined against @conduction/nextcloud-vue@${NC_VUE_VERSION} (nextcloud-vue#705)`)
		// The anti-greenwash control. Without it, an instance with an empty
		// register would skip every detail test and the suite would report all
		// green having opened nothing — the precise failure mode that let #112
		// live. Runs last (declaration order) so the counters are populated.
		expect(
			RESOLVABLE_DETAIL_PAGES.length,
			'no detail page had a resolvable register/schema — the manifest shape changed',
		).toBeGreaterThan(0)
		expect(
			detailOutcome.visited,
			`every detail page skipped (${detailOutcome.skipped} skipped of ${RESOLVABLE_DETAIL_PAGES.length}) — `
			+ 'the register has no objects, so this run proves nothing about detail rendering',
		).toBeGreaterThan(0)
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
