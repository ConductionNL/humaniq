// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Changing an answer from the Klaar recap (setup-wizard-six-steps 5.1).
 *
 * openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md
 *   Scenario: The administrator changes the language from the recap
 *
 * WRITTEN AHEAD OF THE FEATURE. The recap rows and their Wijzigen button come
 * from nextcloud-vue (tasks 1.1 and 1.3) and the six-step setup block from
 * tasks 2.1 to 2.5. Until those land this spec fails at the Taal step or at
 * the Wijzigen button. It is written from the scenario so the scenario has its
 * test the day the steps ship. Not run when written.
 *
 * WHY A CLICK-THROUGH AND NOT THE API. SetupControllerTest already proves that
 * a stored `default_language` reads back. What only a browser shows is that the
 * recap row names the answer, that Wijzigen opens the step it belongs to, and
 * that the row follows a changed answer when the administrator comes back.
 *
 * HOW THE WIZARD OPENS. CnAppRoot opens the optional wizard only while an
 * optional step is outstanding and this manifest version was not dismissed in
 * this browser. The test clears `default_language` through the provisioning
 * API, which leaves the Taal step outstanding, and starts from an empty storage
 * state, so no dismissal is remembered. The value it found is put back
 * afterwards, and the wizard is closed with Annuleren, so
 * `setup_completed_version` is never stored by this test.
 *
 * Base URL and admin credentials from the environment (base-url.ts).
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const CONFIG_URL = `${NC_URL}/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/humaniq/default_language`
const OCS = { 'OCS-APIRequest': 'true' }

/** The most steps the wizard has; a walk that needs more has lost its way. */
const MAX_STEPS = 8

/**
 * The wizard dialog.
 *
 * @param page The page.
 * @return The dialog locator.
 */
function wizard(page: Page) {
	return page.getByRole('dialog').filter({ has: page.getByTestId('cn-wizard-next') }).first()
}

/**
 * Press Next until the step whose heading matches is showing.
 *
 * @param page The page.
 * @param heading The step's heading.
 * @return Resolves once the step shows.
 */
async function nextUntil(page: Page, heading: RegExp): Promise<void> {
	const dialog = wizard(page)
	for (let i = 0; i < MAX_STEPS; i++) {
		if (await dialog.getByRole('heading', { name: heading }).first().isVisible()) {
			return
		}
		await dialog.getByTestId('cn-wizard-next').click()
	}
	await expect(dialog.getByRole('heading', { name: heading }).first(), `the wizard reaches ${heading}`).toBeVisible()
}

/**
 * Pick a language on the Taal step.
 *
 * @param page The page.
 * @param label The option's label.
 * @return Resolves once picked.
 */
async function pickLanguage(page: Page, label: RegExp): Promise<void> {
	const dialog = wizard(page)
	const option = dialog.getByRole('radio', { name: label }).or(dialog.getByRole('option', { name: label })).first()
	if (await option.isVisible()) {
		await option.click()
		return
	}
	// A dropdown choice: open it, then pick.
	await dialog.getByRole('combobox').first().click()
	await page.getByRole('option', { name: label }).first().click()
}

/**
 * The recap row of a step on the Klaar step.
 *
 * @param page The page.
 * @param label The step's label.
 * @return The row locator.
 */
function recapRow(page: Page, label: RegExp) {
	return wizard(page).getByRole('listitem').filter({ hasText: label }).first()
}

test.describe('the setup recap', () => {
	let admin: APIRequestContext
	let previous: string | null = null

	test.beforeAll(async () => {
		admin = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
		const read = await admin.get(`${CONFIG_URL}?format=json`, { headers: OCS })
		if (read.ok()) {
			const data = (await read.json())?.ocs?.data
			previous = typeof data?.data === 'string' && data.data !== '' ? data.data : null
		}
		const cleared = await admin.delete(`${CONFIG_URL}?format=json`, { headers: OCS })
		expect(cleared.ok(), `clear default_language: ${cleared.status()}`).toBeTruthy()
	})

	test.afterAll(async () => {
		if (previous !== null) {
			await admin.post(`${CONFIG_URL}?format=json`, { headers: OCS, form: { value: previous } })
		} else {
			await admin.delete(`${CONFIG_URL}?format=json`, { headers: OCS })
		}
		await admin.dispose()
	})

	// @e2e first-time-setup::the-administrator-changes-the-language-from-the-recap
	test('Wijzigen on the Taal row reopens the step and the row follows the new answer @spec REQ-FTS-011', async ({ browser }) => {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: NC_URL, httpCredentials: ADMIN_CREDENTIALS })
		const page = await context.newPage()
		await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
		await page.locator('input#user, input[name="user"]').first().fill(ADMIN_CREDENTIALS.username)
		await page.locator('input#password, input[name="password"]').first().fill(ADMIN_CREDENTIALS.password)
		await Promise.all([
			page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 60_000 }),
			page.locator('button[type="submit"]').first().click({ noWaitAfter: true }),
		])

		await page.goto('/index.php/apps/humaniq/', { waitUntil: 'domcontentloaded' })
		await expect(wizard(page), 'the outstanding Taal step opens the wizard').toBeVisible({ timeout: 30_000 })

		await nextUntil(page, /^(taal|language)$/i)
		await pickLanguage(page, /nederlands|dutch/i)
		await nextUntil(page, /klaar|ready|done/i)
		await expect(recapRow(page, /taal|language/i), 'the Taal row names the answer').toContainText(/nederlands|dutch/i)

		await recapRow(page, /taal|language/i).getByRole('button', { name: /wijzigen|change/i }).click()
		await expect(wizard(page).getByRole('heading', { name: /^(taal|language)$/i }).first(), 'Wijzigen opens the Taal step').toBeVisible()
		await pickLanguage(page, /english|engels/i)
		await nextUntil(page, /klaar|ready|done/i)
		await expect(recapRow(page, /taal|language/i), 'the Taal row reads the new answer').toContainText(/english|engels/i)

		// Annuleren: close without storing setup_completed_version.
		await wizard(page).getByTestId('cn-wizard-cancel').click()
		await context.close()
	})
})
