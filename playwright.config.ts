/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright config for hrmq.
 *
 * hrmq is the fleet's manifest-purity flagship: 113 manifest-driven pages
 * (src/manifest.json), only 2 custom views, history-mode routing under
 * @conduction/nextcloud-vue's CnAppRoot. The e2e suite therefore leans on a
 * parametrized manifest smoke spec plus a small set of deep core-journey specs.
 *
 * The instance under test comes from `PLAYWRIGHT_BASE_URL` and has NO default
 * — see tests/e2e/base-url.ts for why that is deliberate. `globalSetup` logs in
 * once (admin/admin by default; override with NC_ADMIN_USER / NC_ADMIN_PASS),
 * seeds the nc-vue first-visit overlays as already seen, and persists the
 * session to `tests/e2e/.auth/admin.json`; every spec reuses it via
 * `use.storageState`.
 *
 * Pattern reference: docudesk/playwright.config.ts (ADR-030 scaffold)
 * and openconnector/tests/e2e (manifest-page smoke pattern).
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { resolveBaseURL } from './tests/e2e/base-url'

export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	// hrmq ships a multi-MB bundle that Playwright loads with a cold cache on
	// every run. Generous by design; a real hang still fails, just later.
	// Override with PW_TEST_TIMEOUT.
	timeout: Number(process.env.PW_TEST_TIMEOUT || 120_000),
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. Measured
	// overhead before `Run Playwright tests` starts is 2.0-2.4 min and the
	// uploads after it take seconds, so 38m keeps ~7 min of margin while
	// guaranteeing both a tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: [
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
		['list'],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL: resolveBaseURL(),
		storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
		// `on-first-retry` writes a trace only when a retry actually happens, so
		// the trace artifact is a function of `retries`. Off CI `retries` is 0
		// above, so a local failure has never produced a trace at all; on CI it
		// traces the SECOND attempt only, which means the failure that does not
		// reproduce — the one actually worth a trace — leaves no record of the
		// attempt that failed. `retain-on-failure` traces every attempt and
		// keeps the ones that failed: strictly more informative, and
		// independent of the retry count.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		navigationTimeout: 60_000,
		actionTimeout: 20_000,
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
