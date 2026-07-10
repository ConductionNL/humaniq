/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright globalSetup — builds the webpack bundle if missing, verifies the
 * Nextcloud instance is reachable + installed, logs in once and persists the
 * authenticated storage state to tests/e2e/.auth/user.json. Mirrors the
 * fleet-shared procest/pipelinq pattern (ADR-030).
 */
import { chromium, request, type FullConfig } from '@playwright/test'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'
import { STORAGE_STATE } from './helpers/auth'

const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'hrmq-main.js')

function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}
	// eslint-disable-next-line no-console
	console.log(`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, { failOnStatusCode: false })
		if (!res.ok()) {
			throw new Error(`Nextcloud status.php returned ${res.status()} at ${baseURL}.`)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`)
		}
	} finally {
		await ctx.dispose()
	}
}

async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = (config.projects[0]?.use?.baseURL as string | undefined)
		?? process.env.NEXTCLOUD_URL
		?? 'http://localhost:8080'
	const user = process.env.ADMIN_USER ?? 'admin'
	const password = process.env.ADMIN_PASSWORD ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(path.dirname(STORAGE_STATE), { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	try {
		await page.goto('/index.php/login', { waitUntil: 'domcontentloaded', timeout: 60_000 })
	} catch {
		await page.goto('/index.php/login', { waitUntil: 'domcontentloaded', timeout: 60_000 })
	}
	await page.locator('input[name="user"]').waitFor({ state: 'visible', timeout: 30_000 })
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(password)
	const submitted = await page.evaluate(() => {
		const form = document.querySelector('form[action*="login"]') || document.querySelector('form')
		if (form && typeof (form as HTMLFormElement).requestSubmit === 'function') {
			(form as HTMLFormElement).requestSubmit()
			return true
		}
		return false
	})
	if (submitted === false) {
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
	}
	try {
		await page.waitForURL('**/apps/dashboard/**', { timeout: 30_000 })
	} catch {
		// Some NC versions redirect elsewhere; fall through to the URL check.
	}
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(`Login appears to have failed — still on ${currentUrl}.`)
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}

export default globalSetup
