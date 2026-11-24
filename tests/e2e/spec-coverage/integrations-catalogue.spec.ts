// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The Integrations page (platform-integrations-catalogue 3.1).
 *
 * openspec/specs/humaniq-integrations/spec.md
 *   Scenario: HR sees which connections work
 *   Scenario: Linking a source from humaniq
 *   Scenario: No integriq, no page
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. ConnectionReporterTest proves which status
 * humaniq reports after a ledger handoff, tests/js/integrationsPage.test.mjs
 * proves the labels the two formatters give, and check:manifest proves the
 * page and menu entry are declared. None of them shows that integriq syncs
 * humaniq's declared connections into rows the page lists, that the menu entry
 * hides when integriq is absent, or that "Add integration" leaves for
 * integriq's overview filtered to humaniq. Those sit between humaniq, integriq
 * and the library, so they are here.
 *
 * ONE INSTANCE, ONE BRANCH. Whether integriq is installed is a fact of the
 * instance under test, so the first two tests run only with integriq and the
 * third only without it. Each skip says which one the instance is.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const APP_URL = `${NC_URL}/index.php/apps/humaniq`
const CONNECTIONS = `${NC_URL}/index.php/apps/openregister/api/objects/integriq/app_connection`
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/** The titles of the declared connections the first scenario reads (lib/Settings/connections.json). */
const RDW = 'Vehicle register (RDW)'
const UPA = 'Pension fund delivery (UPA)'
const BRP = 'Population register (BRP)'

/**
 * Whether integriq is installed and enabled on the instance.
 *
 * @param admin The admin's request context.
 * @return True when integriq is among the enabled apps.
 */
async function integriqInstalled(admin: APIRequestContext): Promise<boolean> {
	const apps = await admin.get(`${NC_URL}/ocs/v2.php/cloud/apps?filter=enabled&format=json`, { headers: HEADERS })
	expect(apps.ok(), `list the enabled apps: ${apps.status()}`).toBeTruthy()
	const list = (await apps.json())?.ocs?.data?.apps
	return Array.isArray(list) && list.includes('integriq')
}

/**
 * The status integriq holds for one of humaniq's declared connections.
 *
 * @param admin The admin's request context.
 * @param title The declared connection's title.
 * @return The status, or an empty string when integriq has no row for it.
 */
async function statusOf(admin: APIRequestContext, title: string): Promise<string> {
	const response = await admin.get(`${CONNECTIONS}?app=humaniq&_limit=100`, { headers: HEADERS })
	expect(response.ok(), `read humaniq's connections: ${response.status()}`).toBeTruthy()
	const rows: Array<Record<string, unknown>> = (await response.json())?.results || []
	const row = rows.find((candidate) => candidate.title === title)
	return String(row?.status || '')
}

/**
 * Open the Integrations page and wait for its table.
 *
 * @param page The admin's page.
 * @return The table locator.
 */
async function openIntegrations(page: Page) {
	await page.goto(`${APP_URL}/configuratie/koppelingen`, { waitUntil: 'domcontentloaded' })
	const table = page.getByRole('table').first()
	await expect(table, 'the Integrations page lists the connections').toBeVisible({ timeout: 30_000 })
	return table
}

test.describe.configure({ mode: 'serial' })

test.describe('the Integrations page', () => {
	let admin: APIRequestContext
	let installed = false

	test.beforeAll(async () => {
		admin = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
		installed = await integriqInstalled(admin)
	})

	test.afterAll(async () => {
		await admin?.dispose()
	})

	// @e2e humaniq-integrations::hr-sees-which-connections-work
	test('HR sees which connections work', async ({ page }) => {
		test.skip(!installed, 'needs integriq installed; this instance runs the no-integriq scenario')
		test.skip((await statusOf(admin, RDW)) !== 'configured', 'needs the RDW source linked in integriq')

		const table = await openIntegrations(page)

		const rdw = table.getByRole('row').filter({ hasText: RDW })
		await expect(rdw, 'the RDW vehicle register is listed').toHaveCount(1)
		await expect(rdw, 'the RDW vehicle register reads configured').toContainText(/^(?!.*Not configured).*Configured/s)

		const upa = table.getByRole('row').filter({ hasText: UPA })
		await expect(upa, 'the pension fund UPA delivery is listed').toHaveCount(1)
		await expect(upa, 'the UPA delivery reads not available').toContainText(/not available/i)
		expect(await statusOf(admin, UPA), 'integriq holds the UPA delivery as unavailable').toBe('unavailable')
		// The reason is the row's status message, never an empty cell.
		const upaCells = await upa.getByRole('cell').allInnerTexts()
		expect(upaCells.some((text) => text.trim().length > 20), 'the UPA delivery says why it is not available').toBeTruthy()

		const brp = table.getByRole('row').filter({ hasText: BRP })
		await expect(brp, 'the BRP lookup is listed').toHaveCount(1)
		await expect(brp, 'the BRP lookup reads not configured').toContainText(/not configured/i)
		expect(await statusOf(admin, BRP), 'integriq holds the BRP lookup as not configured').toBe('unconfigured')
	})

	// @e2e humaniq-integrations::linking-a-source-from-humaniq
	test('Linking a source from humaniq', async ({ page }) => {
		test.skip(!installed, 'needs integriq installed; this instance runs the no-integriq scenario')

		await openIntegrations(page)

		// Add integration leaves humaniq for integriq's overview, filtered to
		// humaniq's declared connections, with the link dialog open.
		await Promise.all([
			page.waitForURL((url) => url.pathname.includes('/apps/integriq/connections'), { timeout: 30_000 }),
			page.getByRole('button', { name: 'Add integration' }).click(),
		])
		const url = new URL(page.url())
		expect(url.searchParams.get('app'), 'the overview is filtered to humaniq').toBe('humaniq')
		expect(url.searchParams.get('link'), 'the link dialog opens').toBe('1')

		// Linking, probing and the status the page then shows belong to
		// integriq's own dialog and a source template on the instance; this
		// test checks the hand-off and that coming back re-reads the status.
		const before = await statusOf(admin, RDW)
		await page.goBack({ waitUntil: 'domcontentloaded' })
		const table = await openIntegrations(page)
		const rdw = table.getByRole('row').filter({ hasText: RDW })
		await expect(rdw, 'the page shows the status integriq holds').toContainText(before === 'configured' ? /configured/i : /not configured|not available|error/i)
	})

	// @e2e humaniq-integrations::no-integriq-no-page
	test('No integriq, no page', async ({ page }) => {
		test.skip(installed, 'needs an instance without integriq; this instance has it')

		await page.goto(`${APP_URL}/`, { waitUntil: 'domcontentloaded' })
		const navigation = page.getByRole('navigation').first()
		await expect(navigation, 'the app menu is shown').toBeVisible({ timeout: 30_000 })

		const configuratie = navigation.getByText('Configuration', { exact: true }).first()
		if (await configuratie.isVisible()) {
			await configuratie.click()
		}
		await expect(navigation.getByText('Integrations', { exact: true }), 'there is no Integrations entry').toHaveCount(0)
		await expect(navigation.getByText('Access grants', { exact: true }), 'Access grants is still there').toBeVisible()

		// A bookmarked link names the missing app instead of an empty table.
		await page.goto(`${APP_URL}/configuratie/koppelingen`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText(/Integriq/), 'the page names the app it needs').toBeVisible({ timeout: 30_000 })
	})
})
