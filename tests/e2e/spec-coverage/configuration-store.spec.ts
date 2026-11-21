// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Published configuration sets: the Marketplace page and the Sjablonen setup
 * step (configuration-store 2.1).
 *
 * openspec/changes/configuration-store/specs/first-time-setup/spec.md
 *   Scenario: An administrator installs a published leave policy
 *   Scenario: No registry means no outside call
 *   Scenario: Picked templates show in the summary
 *   Scenario: A skipped step stays closed
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. OpenRegister owns the store: it lists the
 * sets a trusted registry publishes and installs one. Whether humaniq's
 * Marketplace page reaches that store, makes no outside request when no
 * registry is configured, and whether an installed set's leave types show up
 * in humaniq sits between humaniq, OpenRegister and the library, so it is
 * here.
 *
 * WHAT THE INSTANCE DECIDES. Whether a registry is configured is a fact of
 * the instance under test: the store answers `not_configured` without one.
 * The install scenario runs only with a registry and a published leave policy
 * set named in E2E_STORE_LEAVE_POLICY (its slug); the offline scenario runs
 * only without a registry. The two wizard scenarios run once the setup status
 * reports a `templates` step; until then they skip and say so.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const APP_URL = `${NC_URL}/index.php/apps/humaniq`
const STORE_ITEMS = `${APP_URL}/api/store/items`
const SETUP_STATUS = `${APP_URL}/api/setup/status`
const SETUP_ACTION = `${APP_URL}/api/setup/action`
const LEAVE_TYPES = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq/LeaveType`
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/** Slug of a published leave policy set on the configured registry, if any. */
const LEAVE_POLICY_SLUG = process.env.E2E_STORE_LEAVE_POLICY || ''

/** Slug of a published review cycle set on the configured registry, if any. */
const REVIEW_CYCLE_SLUG = process.env.E2E_STORE_REVIEW_CYCLE || ''

/**
 * The outcome the store reports for humaniq: `not_configured` when no
 * registry is set, otherwise what the registry search gave.
 *
 * @param admin The admin's request context.
 * @return The outcome and the cards.
 */
async function storeSearch(admin: APIRequestContext): Promise<{ outcome: string, cards: Array<Record<string, unknown>> }> {
	const response = await admin.get(STORE_ITEMS, { headers: HEADERS })
	expect(response.ok(), `search humaniq's store: ${response.status()}`).toBeTruthy()
	const body = await response.json()
	return { outcome: String(body?.outcome || ''), cards: Array.isArray(body?.cards) ? body.cards : [] }
}

/**
 * The setup status document.
 *
 * @param admin The admin's request context.
 * @return The parsed document.
 */
async function setupStatus(admin: APIRequestContext): Promise<Record<string, any>> {
	const response = await admin.get(SETUP_STATUS, { headers: HEADERS })
	expect(response.ok(), `read the setup status: ${response.status()}`).toBeTruthy()
	return response.json()
}

/**
 * The number of leave types humaniq holds.
 *
 * @param admin The admin's request context.
 * @return The count.
 */
async function leaveTypeCount(admin: APIRequestContext): Promise<number> {
	const response = await admin.get(`${LEAVE_TYPES}?_limit=1`, { headers: HEADERS })
	expect(response.ok(), `count the leave types: ${response.status()}`).toBeTruthy()
	const body = await response.json()
	return Number(body?.total ?? (Array.isArray(body?.results) ? body.results.length : 0))
}

/**
 * Open the Marketplace page and wait for it to render.
 *
 * @param page The admin's page.
 * @return Nothing.
 */
async function openMarketplace(page: Page): Promise<void> {
	await page.goto(`${APP_URL}/store`, { waitUntil: 'networkidle' })
	await expect(page.getByTestId('store-page'), 'the Marketplace page renders').toBeVisible({ timeout: 30_000 })
}

test.describe.configure({ mode: 'serial' })

test.describe('published configuration sets', () => {
	let admin: APIRequestContext
	let outcome = ''

	test.beforeAll(async () => {
		admin = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
		outcome = (await storeSearch(admin)).outcome
	})

	test.afterAll(async () => {
		await admin?.dispose()
	})

	test('An administrator installs a published leave policy', async ({ page }) => {
		test.skip(outcome === 'not_configured', 'needs a trusted registry; this instance runs the no-registry scenario')
		test.skip(LEAVE_POLICY_SLUG === '', 'needs E2E_STORE_LEAVE_POLICY naming a leave policy set the registry publishes')

		const before = await leaveTypeCount(admin)
		await openMarketplace(page)

		const card = page.getByTestId('store-results').locator('.cn-store-page__card').filter({ hasText: new RegExp(LEAVE_POLICY_SLUG.replace(/-/g, '.'), 'i') }).first()
		await expect(card, 'the published leave policy is listed').toBeVisible()
		await card.getByRole('button', { name: /install/i }).click()
		await expect(card, 'the set reads installed').toContainText(/installed/i, { timeout: 60_000 })

		expect(await leaveTypeCount(admin), 'the set\'s leave types appear in humaniq').toBeGreaterThan(before)
	})

	test('No registry means no outside call', async ({ page }) => {
		test.skip(outcome !== 'not_configured', 'needs an instance without a marketplace registry')

		const origin = new URL(NC_URL).origin
		const outside: string[] = []
		page.on('request', (req) => {
			const url = req.url()
			if (/^https?:/.test(url) && new URL(url).origin !== origin) {
				outside.push(url)
			}
		})

		await openMarketplace(page)
		await expect(page.getByTestId('store-not-configured'), 'the page says no registry is configured').toBeVisible()

		expect(outside, 'the page makes no request outside the instance').toEqual([])
		const search = await storeSearch(admin)
		expect(search.cards, 'no remote cards without a registry').toEqual([])
	})

	test('Picked templates show in the summary', async ({ page }) => {
		const status = await setupStatus(admin)
		test.skip(status?.steps?.templates === undefined, 'the setup status reports no Sjablonen step yet (configuration-store 1.1 and 1.2)')
		test.skip(outcome === 'not_configured', 'needs a trusted registry that publishes a leave policy and a review cycle')
		test.skip(LEAVE_POLICY_SLUG === '' || REVIEW_CYCLE_SLUG === '', 'needs E2E_STORE_LEAVE_POLICY and E2E_STORE_REVIEW_CYCLE')

		await page.goto(`${APP_URL}/`, { waitUntil: 'domcontentloaded' })
		const wizard = page.getByRole('dialog').filter({ hasText: /set up humaniq/i }).first()
		await expect(wizard, 'the setup wizard opens').toBeVisible({ timeout: 30_000 })

		// Walk forward to the Sjablonen step.
		const step = wizard.getByText(/templates|sjablonen/i).first()
		for (let i = 0; i < 8 && !(await step.isVisible()); i++) {
			await wizard.getByRole('button', { name: /next|skip|volgende|overslaan/i }).first().click()
		}
		await expect(step, 'the Sjablonen step is reached').toBeVisible()

		const titles: string[] = []
		for (const slug of [LEAVE_POLICY_SLUG, REVIEW_CYCLE_SLUG]) {
			const card = wizard.getByRole('button').filter({ hasText: new RegExp(slug.replace(/-/g, '.'), 'i') }).first()
			titles.push((await card.innerText()).split('\n')[0].trim())
			await card.click()
		}
		await wizard.getByRole('button', { name: /next|volgende/i }).first().click()

		for (const title of titles) {
			await expect(wizard, `the closing step names ${title}`).toContainText(title, { timeout: 60_000 })
		}
	})

	test('A skipped step stays closed', async () => {
		const status = await setupStatus(admin)
		test.skip(status?.steps?.templates === undefined, 'the setup status reports no Sjablonen step yet (configuration-store 1.1 and 1.2)')

		// `skip-templates`, by the skip-<step> convention of the other optional
		// steps (`skip-demo-data`, `skip-administrations`).
		const skipped = await admin.post(`${SETUP_ACTION}/skip-templates`, { headers: HEADERS })
		expect(skipped.ok(), `skip the Sjablonen step: ${skipped.status()}`).toBeTruthy()

		const again = await setupStatus(admin)
		expect(again.steps.templates.done, 'the skipped step reads done').toBe(true)
		expect(again.completed, 'the wizard never gates the app').toBe(true)
	})
})
