/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * i18n form-surface coverage (gate-19) for the OpenSpec change
 * `humaniq-i18n-form-surface` — the strings a FORM puts on screen (each field
 * label from a property `title`, each dropdown option and status badge from
 * `x-enum-labels`) resolving against this app's catalogue, in both locales.
 *
 * Every NON-excluded scenario of the change's spec delta is referenced here by
 * its verbatim name:
 *
 * openspec/changes/humaniq-i18n-form-surface/specs/humaniq-i18n-form-surface/spec.md
 *   Scenario: A Dutch session reads a Dutch form
 *   Scenario: An English session reads an English form
 *   Scenario: A Dutch session reads a Dutch status option
 *   Scenario: An English session never reads a Dutch stored code
 *   Scenario: Selecting a translated option stores the raw value
 *
 * The four build-time scenarios (`… fails the build`, and the generated
 * catalogue's app id) are `@e2e exclude` by nature — they assert that
 * `npm run check:l10n` / `check:l10n-js` exit non-zero, which the CI
 * frontend-checks job runs directly; a browser cannot see them.
 *
 * WHY THIS FILE ASSERTS ON VISIBLE TEXT, WHEN THE SIBLING SPECS DELIBERATELY
 * DO NOT
 * -------------------------------------------------------------------------
 * `personal-dashboard.spec.ts` says, correctly, that a spec keyed on visible
 * copy goes red on a change that alters nothing it tests. That reasoning
 * inverts here: visible copy IS the subject. What keeps it stable is that
 * every element is located by an attribute no translation can move —
 * `data-cn-field="<property key>"` on a form field, `data-testid` on the Add
 * control — and only its TEXT is asserted.
 *
 * VACANCY IS THE LOAD-BEARING FIXTURE. Its `status` enum stores DUTCH codes
 * (`concept` / `gepubliceerd` / `gesloten`). That makes the English half of
 * every assertion meaningful: before `x-enum-labels`, an English session
 * rendered the literal string `gepubliceerd`, and no amount of catalogue work
 * could have fixed it, because the string reaching the screen was the stored
 * value itself. A Dutch-only assertion cannot tell a working translation apart
 * from an untranslated Dutch source string — so every claim here is made
 * twice, and the English one is always the negative.
 *
 * PRECONDITIONS (provisioned by tests/e2e/ci-seed.sh, which refuses :8080 by
 * design):
 *  - the humaniq register + schemas are imported, so TimeEntry and Vacancy
 *    carry their property titles and `x-enum-labels`;
 *  - the seeded Vacancy "Medior Vue-developer" exists with
 *    `status: "gepubliceerd"`.
 *
 * This spec CHANGES the admin user's interface language and restores it in
 * afterAll, so it runs serially and must not run beside a spec that reads
 * display text. It creates exactly one Vacancy and deletes it again.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const AUTH = ADMIN_CREDENTIALS
const OCS_HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const OR_OBJECTS = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq`

// A marker that survives into the created object's title, so the cleanup below
// can find exactly this run's row and nothing else.
const RUN_ID = `e2e-i18n-${process.env.GITHUB_RUN_ID ?? 'local'}-${process.pid}`

// The language is a per-user global, so a parallel worker reading display text
// mid-switch would read the other language and blame the wrong change.
test.describe.configure({ mode: 'serial' })

/**
 * Set the admin user's interface language through the OCS provisioning API —
 * the same write the personal settings page performs.
 *
 * @param api - an authenticated request context
 * @param lang - 'nl' or 'en'
 */
async function setLanguage(api: APIRequestContext, lang: string): Promise<void> {
	const response = await api.put(
		`${NC_URL}/ocs/v2.php/cloud/users/${AUTH.username}?format=json`,
		{ headers: OCS_HEADERS, data: { key: 'language', value: lang } },
	)
	expect(response.ok(), `switching the session language to ${lang} must succeed`).toBeTruthy()
}

/**
 * Open the app at a router path. The humaniq router runs in HISTORY mode, so
 * the base is resolved from the running app rather than hardcoded (see
 * core-journeys.spec.ts for the measured failure mode of hardcoding it).
 *
 * @param page - the page
 * @param route - a router path below the app base, e.g. '/vacancies'
 */
async function gotoRoute(page: Page, route: string): Promise<void> {
	await page.goto('/index.php/apps/humaniq/', { waitUntil: 'domcontentloaded' })
	const base = await page.evaluate(
		() => (window as unknown as { OC?: { generateUrl?: (_p: string) => string } }).OC?.generateUrl?.('/apps/humaniq'),
	)
	if (!base) throw new Error('OC.generateUrl unavailable — cannot resolve the humaniq router base.')
	await page.goto(`${base.replace(/\/+$/, '')}${route}`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 20_000 })
}

/** The Add control on an index page. */
const addButton = (page: Page) => page.locator('[data-testid="cn-cta-primary"]')

/** The open form dialog. */
const dialog = (page: Page) => page.locator('[data-testid-modal="cn-form-dialog"][data-testid-phase="form"]')

/** One auto-generated field, addressed by its schema property key. */
const fieldFor = (page: Page, key: string) => dialog(page).locator(`[data-cn-field="${key}"]`)

/** The options of an open NcSelect dropdown. */
const dropdownOptions = (page: Page) => page.locator('.vs__dropdown-menu li, [role="option"]')

/**
 * Open the create form on an index route.
 *
 * @param page - the page
 * @param route - the index route to open
 */
async function openCreateForm(page: Page, route: string): Promise<void> {
	await gotoRoute(page, route)
	await expect(addButton(page)).toBeVisible({ timeout: 20_000 })
	await addButton(page).click()
	await expect(dialog(page)).toBeVisible({ timeout: 15_000 })
}

let api: APIRequestContext

test.beforeAll(async () => {
	api = await request.newContext({ httpCredentials: AUTH })
})

test.afterAll(async () => {
	// Delete this run's Vacancy, if the create test got that far.
	const listed = await api.get(`${OR_OBJECTS}/Vacancy?_limit=100`, { headers: OCS_HEADERS })
	if (listed.ok()) {
		const body = await listed.json()
		const rows = body.results ?? body.data ?? []
		for (const row of rows) {
			if (typeof row.title === 'string' && row.title.includes(RUN_ID)) {
				await api.delete(`${OR_OBJECTS}/Vacancy/${row['@self']?.id ?? row.id}`, { headers: OCS_HEADERS })
			}
		}
	}
	// Leave the instance in the language it was found in. A spec that changes a
	// global setting and does not put it back turns every later failure into a
	// mystery.
	await setLanguage(api, 'nl')
	await api.dispose()
})

test.describe('i18n form surface — a form reads in the session language', () => {

	// Scenario: A Dutch session reads a Dutch form
	// @e2e humaniq-i18n-form-surface::a-dutch-session-reads-a-dutch-form
	test('a Dutch session reads Dutch field labels', async ({ page }) => {
		await setLanguage(api, 'nl')
		await openCreateForm(page, '/mijn/uren')

		await expect(fieldFor(page, 'breakMinutes')).toContainText(/Pauze \(minuten\)/i)
		await expect(fieldFor(page, 'description')).toContainText(/Omschrijving/i)
		await expect(fieldFor(page, 'billable')).toContainText(/Declarabel/i)

		// The negative half: the English SOURCE must be gone, not merely
		// accompanied by a Dutch string.
		await expect(fieldFor(page, 'breakMinutes')).not.toContainText('Break (minutes)')
		await expect(fieldFor(page, 'description')).not.toContainText('Description')
	})

	// Scenario: An English session reads an English form
	// @e2e humaniq-i18n-form-surface::an-english-session-reads-an-english-form
	test('an English session reads the English source strings', async ({ page }) => {
		await setLanguage(api, 'en')
		await openCreateForm(page, '/mijn/uren')

		await expect(fieldFor(page, 'breakMinutes')).toContainText('Break (minutes)')
		await expect(fieldFor(page, 'description')).toContainText('Description')
		await expect(fieldFor(page, 'billable')).toContainText('Billable')

		// No Dutch may leak into an English session — the defect ran in this
		// direction too.
		await expect(fieldFor(page, 'breakMinutes')).not.toContainText(/Pauze/i)
	})

	// Scenario: An English session never reads a Dutch stored code
	// @e2e humaniq-i18n-form-surface::an-english-session-never-reads-a-dutch-stored-code
	test('an English session reads the declared label, never the Dutch stored code', async ({ page }) => {
		// The seeded Vacancy stores `gepubliceerd`. Asserted through the badge on
		// the list AND the dropdown in the form, because they are two different
		// code paths (CnCellRenderer vs CnFormDialog) reading one declaration.
		await setLanguage(api, 'en')
		await gotoRoute(page, '/vacancies')

		const badge = page.locator('.cn-status-badge').first()
		await expect(badge).toBeVisible({ timeout: 20_000 })
		await expect(badge).toHaveText(/Published/)
		await expect(badge).not.toHaveText(/gepubliceerd/i)

		await addButton(page).click()
		await expect(dialog(page)).toBeVisible({ timeout: 15_000 })
		await fieldFor(page, 'status').click()
		await expect(dropdownOptions(page).first()).toBeVisible({ timeout: 10_000 })
		const optionText = (await dropdownOptions(page).allInnerTexts()).join(' | ')
		expect(optionText, 'the English session offers the declared English labels').toMatch(/Published/)
		expect(optionText, 'and never the raw Dutch code').not.toMatch(/gepubliceerd/i)
	})

	// Scenario: A Dutch session reads a Dutch status option
	// @e2e humaniq-i18n-form-surface::a-dutch-session-reads-a-dutch-status-option
	test('a Dutch session reads Dutch status options', async ({ page }) => {
		await setLanguage(api, 'nl')
		await gotoRoute(page, '/vacancies')

		const badge = page.locator('.cn-status-badge').first()
		await expect(badge).toBeVisible({ timeout: 20_000 })
		await expect(badge).toHaveText(/Gepubliceerd/i)

		await addButton(page).click()
		await expect(dialog(page)).toBeVisible({ timeout: 15_000 })
		await fieldFor(page, 'status').click()
		await expect(dropdownOptions(page).first()).toBeVisible({ timeout: 10_000 })
		const optionText = (await dropdownOptions(page).allInnerTexts()).join(' | ')
		expect(optionText, 'the Dutch session offers Dutch labels').toMatch(/Concept/i)
		expect(optionText, 'and the English label is not what renders').not.toMatch(/\bDraft\b/)
	})

	// Scenario: Selecting a translated option stores the raw value
	// @e2e humaniq-i18n-form-surface::selecting-a-translated-option-stores-the-raw-value
	test('picking a Dutch-labelled option persists the raw schema code', async ({ page }) => {
		await setLanguage(api, 'nl')
		await openCreateForm(page, '/vacancies')

		await fieldFor(page, 'title').locator('input').fill(`${RUN_ID} vacature`)
		await fieldFor(page, 'status').click()
		await expect(dropdownOptions(page).first()).toBeVisible({ timeout: 10_000 })
		await dropdownOptions(page).filter({ hasText: /^Concept$/i }).first().click()

		await page.getByRole('button', { name: /Aanmaken|Create|Opslaan|Save/i }).first().click()
		await expect(dialog(page)).toBeHidden({ timeout: 20_000 })

		// Read the PERSISTED object, not the rendered chip. The chip is the
		// label; the whole contract is that the two differ.
		const listed = await api.get(`${OR_OBJECTS}/Vacancy?_limit=100`, { headers: OCS_HEADERS })
		expect(listed.ok()).toBeTruthy()
		const body = await listed.json()
		const rows = body.results ?? body.data ?? []
		const created = rows.find((r: Record<string, unknown>) => typeof r.title === 'string' && r.title.includes(RUN_ID))

		expect(created, 'the form actually created the object').toBeTruthy()
		expect(created.status, 'the stored value is the raw schema code, not the Dutch label').toBe('concept')
	})
})
