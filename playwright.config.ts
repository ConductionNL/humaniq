/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright config for hrmq.
 *
 * hrmq is the fleet's manifest-purity flagship: 113 manifest-driven
 * pages (src/manifest.json), only 2 custom views, hash-mode routing via
 * @conduction/nextcloud-vue's CnAppRoot. The e2e suite therefore leans
 * on a parametrized manifest smoke spec plus a small set of deep
 * core-journey specs.
 *
 * Point at a running Nextcloud with NEXTCLOUD_URL (default
 * http://localhost:8080). `globalSetup` logs in once (admin/admin by
 * default; override with NC_ADMIN_USER / NC_ADMIN_PASS) and persists
 * the session to `tests/e2e/.auth/admin.json`; every spec reuses it via
 * `use.storageState`.
 *
 * Pattern reference: docudesk/playwright.config.ts (ADR-030 scaffold)
 * and openconnector/tests/e2e (manifest-page smoke pattern).
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	// A shared dev instance running many Conduction apps can take several
	// seconds just to serve a page, and hrmq ships a multi-MB bundle that
	// Playwright loads with a cold cache on every run. Generous by design;
	// a real hang still fails, just later. Override with PW_TEST_TIMEOUT.
	timeout: Number(process.env.PW_TEST_TIMEOUT || 120_000),
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
		['list'],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
		trace: 'on-first-retry',
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
