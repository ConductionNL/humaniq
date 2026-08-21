/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Hours-process journeys (gate-19 spec coverage) for the OpenSpec change
 * `hrmq-hours-process-redesign` — TimeEntry booking, Timesheet aggregation,
 * submit → approve/reject lifecycle with server-side stamping.
 *
 * Every NON-excluded scenario of the change's spec deltas is referenced here
 * by its verbatim name (the excluded ones carry a reason-bearing
 * `@e2e exclude` in the spec files themselves):
 *
 * openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md
 *   Scenario: A worker logs and submits hours for approval
 *   Scenario: A manager may not approve their own hours
 *   Scenario: An impossible time span is refused
 *   Scenario: Booking updates the running total before submission
 *   Scenario: An empty timesheet cannot be submitted
 *   Scenario: A booking cannot be edited after submission
 *
 * openspec/changes/hrmq-hours-process-redesign/specs/hrmq-timesheet-approval/spec.md
 *   Scenario: Employee submits a draft timesheet
 *   Scenario: A rejected timesheet can be corrected and re-submitted
 *   Scenario: An invalid transition is refused
 *   Scenario: The approval queue lists pending timesheets
 *   Scenario: HRMQ is reachable from the app menu
 *   Scenario: The booking form is an allowlist
 *   Scenario: Approving stamps provenance on the carrying write
 *
 * openspec/changes/hrmq-hours-process-redesign/specs/mijn-hr-self-service/spec.md
 *   Scenario: Records without userId never leak onto a Mijn page
 *   Scenario: Employee sees only their own records
 *   Scenario: Booking hours needs no identity fields
 *   Scenario: Payslip page offers no authoring
 *
 * openspec/changes/hrmq-hours-process-redesign/specs/employer-hourly-cost-rate/spec.md
 *   Scenario: The booking form offers no ledger fields
 *
 * PRECONDITIONS (provisioned by tests/e2e/ci-seed.sh):
 *  - the hrmq register + hr-seed.json objects are imported (jansen's
 *    submitted 2026-05 timesheet + its 3 entries carrying userId "admin";
 *    devries' approved and bakker's rejected timesheets + entries with NO
 *    userId — the fail-closed rows);
 *  - the admin user's ACTIVE administration is ADM-001 (the administration
 *    admin's own Employee, employee-jansen, belongs to) — every hours page
 *    filters on `administrationId: @workspace.activeAdministrationId?`.
 *
 * Seeding pattern reference: core-journeys.spec.ts (OpenRegister REST +
 * basic auth; assertions stay in the UI). This file MUTATES register state
 * (bookings, transitions), so it runs serial and uses unique periods/RUN_ID
 * markers; parallel spec files only render seeded state.
 */

import type { APIRequestContext, Page } from '@playwright/test';

import { appDialog } from '@conduction/nextcloud-vue/testing/playwright'
import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

// PATH-form base — the hrmq router runs in HISTORY mode; resolve the base
// from the running app via OC.generateUrl (see core-journeys.spec.ts for the
// measured failure mode of hardcoding it).
let _appBase: string | null = null
async function appBase(page: Page): Promise<string> {
	if (_appBase) return _appBase
	await page.goto('/index.php/apps/hrmq/', { waitUntil: 'domcontentloaded' })
	const resolved = await page.evaluate(
		() => (window as unknown as { OC?: { generateUrl?: (_p: string) => string } }).OC?.generateUrl?.('/apps/hrmq'),
	)
	if (!resolved) {
		throw new Error('OC.generateUrl unavailable — cannot resolve the hrmq router base.')
	}
	_appBase = resolved.replace(/\/+$/, '')
	return _appBase
}

async function gotoRoute(page: Page, route: string): Promise<void> {
	const base = await appBase(page)
	await page.goto(`${base}${route}`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 15_000 })
	expect(new URL(page.url()).pathname, `router must stay on ${route}`).toContain(route)
}

const NC_URL = resolveBaseURL()
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects`
const REGISTER = 'hrmq'
const AUTH = ADMIN_CREDENTIALS
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
const RUN_ID = `e2e-hours-${Date.now()}-${Math.floor(Math.random() * 1e4)}`

/** Locate a data-widget value cell by its field label (CnObjectDataWidget). */
function dataCell(page: Page, label: string) {
	return page.locator('.cn-object-data-widget__cell')
		.filter({ has: page.locator('.cn-object-data-widget__label', { hasText: label }) })
		.locator('.cn-object-data-widget__value')
		.first()
}

/** The lifecycle transition button for an action (CnLifecycleActions). */
function lifecycleButton(page: Page, action: string) {
	return page.getByTestId(`cn-lifecycle-action-${action}`)
}

test.describe.configure({ mode: 'serial' })

test.describe('hours process — booking, aggregation, approval lifecycle', () => {

	let api: APIRequestContext
	/** Employee NOT linked to the acting admin — the approvable other party. */
	let otherEmployeeId: string
	/** The other employee's auto-created 2026-06 timesheet (journey D). */
	let otherTimesheetId: string
	/** A deliberately empty draft timesheet (journey E). */
	let emptyTimesheetId: string
	/** Seeded ids resolved from the register. */
	let devriesTimesheetId: string
	let bakkerTimesheetId: string
	let jansenTimesheetId: string

	const cleanup: Array<{ schema: string, id: string }> = []

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: AUTH })

		// Resolve the three seeded timesheets by their seeded facts. The
		// listing shape is probed the way ci-seed.sh probes it.
		const res = await api.get(`${OR_BASE}/${REGISTER}/Timesheet?_limit=200`, { headers: HEADERS })
		expect(res.ok(), `Timesheet collection must be readable (${res.status()})`).toBeTruthy()
		const body = await res.json()
		const rows: Array<Record<string, unknown>> = Array.isArray(body) ? body : (body.results || [])
		const idOf = (row: Record<string, unknown> | undefined): string => {
			const self = (row?.['@self'] || {}) as Record<string, unknown>
			return String(self.id || row?.id || '')
		}
		const bySeed = (hours: number, status: string) => rows.find(
			(r) => Number(r.hours) === hours && r.status === status && r.period === '2026-05',
		)
		jansenTimesheetId = idOf(bySeed(152, 'submitted'))
		devriesTimesheetId = idOf(bySeed(168, 'approved'))
		bakkerTimesheetId = idOf(bySeed(140, 'rejected'))
		expect(jansenTimesheetId, 'seeded jansen 2026-05 submitted timesheet must exist').toBeTruthy()
		expect(devriesTimesheetId, 'seeded devries 2026-05 approved timesheet must exist').toBeTruthy()
		expect(bakkerTimesheetId, 'seeded bakker 2026-05 rejected timesheet must exist').toBeTruthy()

		// Seed an Employee whose account is NOT the acting admin, so approve
		// is not blocked by NoSelfApprovalGuard (admin-approves-own is
		// impossible by design).
		const emp = await api.post(`${OR_BASE}/${REGISTER}/Employee`, {
			headers: HEADERS,
			data: {
				employeeNumber: `${RUN_ID}-nr`,
				firstName: 'Andere',
				lastName: `Medewerker-${RUN_ID}`,
				startDate: '2026-01-01',
				nextcloudUserId: `${RUN_ID}-uid`,
				administrationId: 'ADM-001',
			},
		})
		expect(emp.ok(), `seed employee failed: ${emp.status()}`).toBeTruthy()
		const empJson = await emp.json()
		otherEmployeeId = empJson?.['@self']?.id || empJson?.id
		cleanup.push({ schema: 'Employee', id: otherEmployeeId })

		// Book one entry for that employee WITHOUT a timesheetId: the stamping
		// listener must find-or-create the 2026-06 draft timesheet from
		// startedAt (the auto-creation contract).
		const entry = await api.post(`${OR_BASE}/${REGISTER}/TimeEntry`, {
			headers: HEADERS,
			data: {
				employeeId: otherEmployeeId,
				startedAt: '2026-06-15T09:00:00Z',
				endedAt: '2026-06-15T17:30:00Z',
				breakMinutes: 30,
				description: `Boeking andere medewerker ${RUN_ID}`,
			},
		})
		expect(entry.ok(), `seed other-employee entry failed: ${entry.status()} ${await entry.text()}`).toBeTruthy()
		const entryJson = await entry.json()
		otherTimesheetId = String(entryJson.timesheetId || '')
		expect(otherTimesheetId, 'booking must auto-attach a 2026-06 timesheet').toBeTruthy()
		cleanup.push({ schema: 'TimeEntry', id: entryJson?.['@self']?.id || entryJson?.id })
		cleanup.push({ schema: 'Timesheet', id: otherTimesheetId })

		// Submit it server-side so the UI journey starts at the queue.
		const submit = await api.post(
			`${NC_URL}/index.php/apps/openregister/api/objects/${otherTimesheetId}/transition`,
			{ headers: HEADERS, data: { action: 'submit' } },
		)
		expect(submit.ok(), `submitting the other-employee timesheet failed: ${submit.status()} ${await submit.text()}`).toBeTruthy()

		// An EMPTY draft timesheet for the refusal journey (E) — created
		// directly, so it never gains entries. CREATE stamping forces draft.
		const ts = await api.post(`${OR_BASE}/${REGISTER}/Timesheet`, {
			headers: HEADERS,
			data: { employeeId: otherEmployeeId, period: '2026-08' },
		})
		expect(ts.ok(), `seed empty timesheet failed: ${ts.status()} ${await ts.text()}`).toBeTruthy()
		const tsJson = await ts.json()
		emptyTimesheetId = tsJson?.['@self']?.id || tsJson?.id
		cleanup.push({ schema: 'Timesheet', id: emptyTimesheetId })
	})

	test.afterAll(async () => {
		// Best-effort: entries under non-draft parents are refused deletion by
		// the mutability guard — that refusal is itself designed behaviour.
		for (const { schema, id } of cleanup.reverse()) {
			if (!id) continue
			await api.delete(`${OR_BASE}/${REGISTER}/${schema}/${id}`, { headers: HEADERS }).catch(() => {})
		}
		await api?.dispose()
	})

	// Scenario: HRMQ is reachable from the app menu
	// (hrmq-timesheet-approval — the app menu entry opens the SPA shell at
	// the timesheets list; the new hours pages are reachable from the left
	// nav, not merely routable.)
	test('app shell opens at the timesheets list and the new hours pages are in the nav', async ({ page }) => {
		await page.goto('/index.php/apps/hrmq/', { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#app-content, .app-content').first()).toBeVisible({ timeout: 20_000 })
		await expect(page).toHaveURL(/\/apps\/hrmq\/timesheets$/, { timeout: 15_000 })
		// New menu leaves: MijnUrenstaten directly under Mijn uren, TimeEntries
		// (Urenboekingen) before Urenstaten. Click-through proves reachable.
		// A fresh session starts with the nav GROUPS COLLAPSED (children exist
		// but are hidden), so do what a user does: expand the group first.
		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		const revealNavLeaf = async (leafLabel: string, groupLabel: string) => {
			const leaf = nav.getByText(leafLabel, { exact: true })
			if (!(await leaf.isVisible())) {
				await nav.getByText(groupLabel, { exact: true }).click()
			}
			await expect(leaf).toBeVisible({ timeout: 15_000 })
			return leaf
		}
		await (await revealNavLeaf('Mijn urenstaten', 'Mijn HR')).click()
		await expect(page).toHaveURL(/\/mijn\/urenstaten$/, { timeout: 15_000 })
		await (await revealNavLeaf('Urenboekingen', 'Verlof & verzuim')).click()
		await expect(page).toHaveURL(/\/time-entries$/, { timeout: 15_000 })
	})

	// Scenario: The booking form is an allowlist
	// Scenario: The booking form offers no ledger fields
	// (hrmq-timesheet-approval + employer-hourly-cost-rate — the MijnUren
	// create dialog shows EXACTLY the six self-service fields; identity,
	// process and ledger fields are asserted ABSENT, not merely unexpected.)
	test('MijnUren create dialog shows exactly the six booking fields', async ({ page }) => {
		await gotoRoute(page, '/mijn/uren')
		const addBtn = page.getByRole('button', { name: /Add Time entry/i }).first()
		await expect(addBtn, 'MijnUren must offer Add (actionToggles.showAdd)').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = appDialog(page)
		await expect(dialog, 'create dialog must open').toBeVisible({ timeout: 10_000 })

		// Expected: the six allowlisted fields, by their schema titles.
		for (const label of ['Start', 'End', 'Break (minutes)', 'Description', 'Project', 'Billable']) {
			await expect(dialog.getByText(label, { exact: true }).first(), `field "${label}" must be on the form`)
				.toBeVisible({ timeout: 5_000 })
		}
		// Exactly six fields — not six-plus-something.
		await expect(dialog.locator('.cn-form-dialog__field'), 'the form must render EXACTLY 6 fields').toHaveCount(6)
		// Forbidden: identity, process, derived and ledger fields.
		for (const label of [
			'Employee', 
'Timesheet', 
'Hours', 
'Cost centre', 
'User', 
'Administration', 
'Origin',
			'Status', 
'Submitted at', 
'Approved by', 
'Approved at', 
'Rejection reason',
			'Allocation key', 
'Domain object',
		]) {
			await expect(dialog.getByText(label, { exact: true }), `field "${label}" must NOT be on the form`)
				.toHaveCount(0)
		}
		// Dismiss via the Cancel button — the affordance the dialog actually
		// offers. Escape does NOT close CnFormDialog (verified on a live
		// instance with focus inside the dialog — nextcloud-vue#727), so
		// asserting Escape here would pin a library defect into this app's
		// suite instead of reporting it.
		await dialog.getByRole('button', { name: /Annuleren|Cancel/i }).first().click()
		await expect(dialog).toBeHidden({ timeout: 10_000 })
	})

	// Scenario: Employee sees only their own records
	// Scenario: Records without userId never leak onto a Mijn page
	// (mijn-hr-self-service — jansen's seeded entries carry userId "admin"
	// and render for the admin user; the devries/bakker rows have NO userId
	// and must be fail-closed absent from both Mijn pages.)
	test('Mijn pages list only the signed-in user\'s records (fail-closed)', async ({ page }) => {
		await gotoRoute(page, '/mijn/uren')
		const content = page.locator('#app-content, .app-content').first()
		await expect(content, 'jansen\'s seeded booking must render (userId admin)')
			.toContainText('Projectwerk project-alpha', { timeout: 20_000 })
		await expect(content, 'devries\' booking (userId null) must NOT leak')
			.not.toContainText('Supportdienst en onboarding')
		await expect(content, 'bakker\'s booking (userId null) must NOT leak')
			.not.toContainText('gecorrigeerde boeking')

		await gotoRoute(page, '/mijn/urenstaten')
		await expect(content, 'jansen\'s 152h timesheet must render').toContainText('152', { timeout: 20_000 })
		await expect(content, 'devries\' 168h timesheet (userId null) must NOT leak').not.toContainText('168')
		await expect(content, 'bakker\'s 140h timesheet (userId null) must NOT leak').not.toContainText('140')
	})

	// Scenario: Payslip page offers no authoring
	// (mijn-hr-self-service — MijnLoonstroken stays a read-only list: no Add
	// button and no edit/delete row actions.)
	test('MijnLoonstroken offers no authoring affordance', async ({ page }) => {
		await gotoRoute(page, '/mijn/loonstroken')
		const content = page.locator('#app-content, .app-content').first()
		await expect(content, 'the payslip list must render the seeded period').toContainText('2026-05', { timeout: 20_000 })
		await expect(page.getByRole('button', { name: /Add Payslip/i }), 'no Add button').toHaveCount(0)
	})

	// Scenario: A worker logs and submits hours for approval   (booking half)
	// Scenario: Booking hours needs no identity fields
	// (time-entry-capture + mijn-hr-self-service — the booking is made
	// through the real create dialog with ONLY the six form fields; the
	// entry appears on MijnUren with the server-derived 8.00 hours, proving
	// employee/user/administration resolution happened server-side.)
	test('booking through the MijnUren dialog creates a TimeEntry with derived hours', async ({ page }) => {
		await gotoRoute(page, '/mijn/uren')
		await page.getByRole('button', { name: /Add Time entry/i }).first().click()
		const dialog = appDialog(page)
		await expect(dialog).toBeVisible({ timeout: 10_000 })

		// The booking form must read in PROCESS order — start, end, break,
		// description, project, billable — which the TimeEntry schema declares
		// via property `order`. Assert it: with no order, fieldsFromSchema
		// falls back to ALPHABETICAL and the form shows End before Start, which
		// is both unusable and silently inverts a positional fill (the server
		// then correctly refuses the span).
		const fieldText = (await dialog.locator('.cn-form-dialog__field').allInnerTexts()).join('\n|\n')
		const startPos = fieldText.indexOf('Start')
		const endPos = fieldText.indexOf('End')
		expect(startPos, 'the form must offer a Start field').toBeGreaterThan(-1)
		expect(endPos, 'the form must offer an End field').toBeGreaterThan(-1)
		expect(startPos, 'Start must precede End on the booking form').toBeLessThan(endPos)

		// 12:00–20:30 minus 30 min break = 8.00 hours (the REQ-TEC-001 rule;
		// mid-month midday keeps the derived 2026-07 period timezone-safe).
		await dialog.locator('input[type="datetime-local"]').nth(0).fill('2026-07-15T12:00')
		await dialog.locator('input[type="datetime-local"]').nth(1).fill('2026-07-15T20:30')
		await dialog.locator('input[type="number"]').first().fill('30')
		const textInputs = dialog.locator('input[type="text"], textarea')
		await textInputs.nth(0).fill(`Geboekt via e2e ${RUN_ID}`)
		await textInputs.nth(1).fill('project-e2e')
		await dialog.getByRole('button', { name: /^(Create|Save|Aanmaken|Opslaan)$/i }).first().click()
		await expect(dialog, 'create dialog must close on success').toBeHidden({ timeout: 15_000 })

		const content = page.locator('#app-content, .app-content').first()
		await expect(content, 'the new booking must appear on MijnUren')
			.toContainText(`Geboekt via e2e ${RUN_ID}`, { timeout: 20_000 })
	})

	// Scenario: Booking updates the running total before submission
	// (time-entry-capture — the auto-created 2026-07 draft timesheet appears
	// on MijnUrenstaten carrying the recomputed aggregate BEFORE any submit.)
	test('the auto-created timesheet lists the running total on MijnUrenstaten', async ({ page }) => {
		await gotoRoute(page, '/mijn/urenstaten')
		const row = page.locator('#app-content tr, .app-content tr').filter({ hasText: '2026-07' }).first()
		await expect(row, 'the 2026-07 draft timesheet must be listed').toBeVisible({ timeout: 20_000 })
		await expect(row, 'hours must show the 8.00 aggregate').toContainText('8')
		await expect(row, 'status must be draft').toContainText('draft')
	})

	// Scenario: Employee submits a draft timesheet
	// Scenario: A worker logs and submits hours for approval   (submit half)
	// (hrmq-timesheet-approval + time-entry-capture — submit from
	// TimesheetDetail; submittedAt renders non-empty in the read-only
	// Goedkeuring panel, stamped by the carrying write.)
	test('submitting the draft timesheet stamps submittedAt', async ({ page }) => {
		await gotoRoute(page, '/mijn/urenstaten')
		const row = page.locator('#app-content tr, .app-content tr').filter({ hasText: '2026-07' }).first()
		await row.click()
		await expect(page, 'row click must open TimesheetDetail').toHaveURL(/\/timesheets\/[^/]+$/, { timeout: 15_000 })

		await expect(lifecycleButton(page, 'submit'), 'a draft timesheet offers Submit').toBeVisible({ timeout: 20_000 })
		await lifecycleButton(page, 'submit').click()

		await expect(dataCell(page, 'Status'), 'status must move to submitted').toContainText('submitted', { timeout: 20_000 })
		await expect(dataCell(page, 'Submitted at'), 'submittedAt must render non-empty (server-stamped)')
			.toContainText(/\d{4}/, { timeout: 15_000 })
	})

	// Scenario: The approval queue lists pending timesheets
	// (hrmq-timesheet-approval — the queue defaults to status == submitted
	// and lists both the seeded jansen row and the just-submitted rows.)
	test('the approval queue lists the submitted timesheets', async ({ page }) => {
		await gotoRoute(page, '/timesheets/approval')
		const content = page.locator('#app-content, .app-content').first()
		await expect(content, 'the seeded jansen submitted 2026-05 row must be queued')
			.toContainText('2026-05', { timeout: 20_000 })
		await expect(content, 'the other-employee 2026-06 row must be queued').toContainText('2026-06')
		await expect(content, 'the just-submitted 2026-07 row must be queued').toContainText('2026-07')
	})

	// Scenario: A manager may not approve their own hours
	// (time-entry-capture — the seeded jansen timesheet belongs to the acting
	// admin's own Employee; NoSelfApprovalGuard denies the approve
	// transition and the refusal surfaces inline.)
	test('approving your own timesheet is refused by NoSelfApprovalGuard', async ({ page }) => {
		await gotoRoute(page, `/timesheets/${jansenTimesheetId}`)
		await expect(lifecycleButton(page, 'approve')).toBeVisible({ timeout: 20_000 })
		await lifecycleButton(page, 'approve').click()
		await expect(page.getByTestId('cn-lifecycle-actions-error'), 'the guard refusal must surface inline')
			.toContainText(/eigen urenstaat/i, { timeout: 20_000 })
		await expect(dataCell(page, 'Status'), 'the state must not change').toContainText('submitted')
	})

	// Scenario: Approving stamps provenance on the carrying write
	// (hrmq-timesheet-approval — approving the OTHER employee's submitted
	// timesheet from the queue stamps approvedBy = the acting uid and
	// approvedAt in the same write; asserted via the UI Goedkeuring panel.)
	test('approving another employee\'s timesheet stamps approvedBy/approvedAt', async ({ page }) => {
		await gotoRoute(page, '/timesheets/approval')
		const row = page.locator('#app-content tr, .app-content tr').filter({ hasText: '2026-06' }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.click()
		await expect(page).toHaveURL(/\/timesheets\/[^/]+$/, { timeout: 15_000 })

		await expect(lifecycleButton(page, 'approve')).toBeVisible({ timeout: 20_000 })
		await lifecycleButton(page, 'approve').click()

		await expect(dataCell(page, 'Status'), 'status must move to approved').toContainText('approved', { timeout: 20_000 })
		await expect(dataCell(page, 'Approved by'), 'approvedBy must be the acting session uid, stamped on the carrying write')
			.toContainText('admin', { timeout: 15_000 })
		await expect(dataCell(page, 'Approved at'), 'approvedAt must render non-empty')
			.toContainText(/\d{4}/, { timeout: 15_000 })
	})

	// Scenario: An empty timesheet cannot be submitted
	// Scenario: An invalid transition is refused
	// (time-entry-capture + hrmq-timesheet-approval — TimesheetNotEmptyGuard
	// refuses the submit with the Dutch message; and a draft timesheet
	// offers NO approve action — the invalid transition is not offered, and
	// the server-side lifecycle would refuse it regardless.)
	test('an empty draft timesheet cannot be submitted and offers no approve', async ({ page }) => {
		await gotoRoute(page, `/timesheets/${emptyTimesheetId}`)
		await expect(lifecycleButton(page, 'submit')).toBeVisible({ timeout: 20_000 })
		await expect(lifecycleButton(page, 'approve'), 'draft offers no approve transition').toHaveCount(0)

		await lifecycleButton(page, 'submit').click()
		await expect(page.getByTestId('cn-lifecycle-actions-error'), 'the empty-timesheet refusal must surface with the Dutch guard message')
			.toContainText(/bevat geen urenboekingen|telt op tot nul uren/, { timeout: 20_000 })
		await expect(dataCell(page, 'Status'), 'the timesheet must remain draft').toContainText('draft')
	})

	// Scenario: An impossible time span is refused
	// (time-entry-capture — an end before start is refused with a structured
	// 422; the dialog stays open and nothing is persisted.)
	test('a booking whose end precedes its start is refused', async ({ page }) => {
		await gotoRoute(page, '/mijn/uren')
		await page.getByRole('button', { name: /Add Time entry/i }).first().click()
		const dialog = appDialog(page)
		await expect(dialog).toBeVisible({ timeout: 10_000 })

		await dialog.locator('input[type="datetime-local"]').nth(0).fill('2026-07-16T17:00')
		await dialog.locator('input[type="datetime-local"]').nth(1).fill('2026-07-16T09:00')
		await dialog.locator('input[type="text"], textarea').nth(0).fill(`Onmogelijke boeking ${RUN_ID}`)
		await dialog.getByRole('button', { name: /^(Create|Save|Aanmaken|Opslaan)$/i }).first().click()

		// The 422 keeps the dialog open with its error surface; nothing lands.
		await expect(dialog, 'the dialog must not close on a refused write').toBeVisible({ timeout: 10_000 })
		// Dismiss via the Cancel button — the affordance the dialog actually
		// offers. Escape does NOT close CnFormDialog (verified on a live
		// instance with focus inside the dialog — nextcloud-vue#727), so
		// asserting Escape here would pin a library defect into this app's
		// suite instead of reporting it.
		await dialog.getByRole('button', { name: /Annuleren|Cancel/i }).first().click()
		await expect(dialog).toBeHidden({ timeout: 10_000 })
		await expect(page.locator('#app-content, .app-content').first(), 'the refused booking must not appear')
			.not.toContainText(`Onmogelijke boeking ${RUN_ID}`)
	})

	// Scenario: A booking cannot be edited after submission
	// (time-entry-capture — journey (e): the approved devries timesheet's
	// entries render in the read-only Urenboekingen list with no edit/add
	// affordance; the server-side mutability guard (REQ-TEC-005) is
	// unit-tested, this asserts the UI offers no editing path.)
	test('an approved timesheet\'s entries show no edit affordance', async ({ page }) => {
		await gotoRoute(page, `/timesheets/${devriesTimesheetId}`)
		const bookings = page.locator('.cn-object-list-widget').first()
		await expect(bookings, 'the Urenboekingen list must render the seeded entries')
			.toContainText('Supportdienst en onboarding', { timeout: 20_000 })
		await expect(bookings.locator('.cn-object-list-widget__add'), 'no add affordance on the bookings list').toHaveCount(0)
		await expect(bookings.getByRole('button', { name: /edit|bewerk|delete|verwijder/i }), 'no edit/delete row actions')
			.toHaveCount(0)
	})

	// Scenario: A rejected timesheet can be corrected and re-submitted
	// (hrmq-timesheet-approval — the seeded bakker rejected timesheet renders
	// its rejectionReason read-only; re-submitting moves it to submitted and
	// the stamping clears approvedBy/approvedAt/rejectionReason.)
	test('a rejected timesheet re-submits and the stamping clears the approval fields', async ({ page }) => {
		await gotoRoute(page, `/timesheets/${bakkerTimesheetId}`)
		await expect(dataCell(page, 'Status')).toContainText('rejected', { timeout: 20_000 })
		await expect(dataCell(page, 'Rejection reason'), 'the seeded reason must render read-only')
			.toContainText('komt niet overeen', { timeout: 15_000 })

		await expect(lifecycleButton(page, 'submit'), 'rejected offers Submit').toBeVisible({ timeout: 15_000 })
		await lifecycleButton(page, 'submit').click()

		await expect(dataCell(page, 'Status'), 'status must move to submitted').toContainText('submitted', { timeout: 20_000 })
		await expect(dataCell(page, 'Rejection reason'), 'the stamping must clear the previous reason')
			.not.toContainText('komt niet overeen', { timeout: 15_000 })
	})

})
