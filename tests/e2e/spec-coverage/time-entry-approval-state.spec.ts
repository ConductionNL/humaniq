// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Approved billable hours in one query (time-entry-approval-state 3.1).
 *
 * openspec/specs/time-entry-approval-state/spec.md
 *   Scenario: Shillinq lists the hours to invoice (the live check of task 3.1)
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. TimesheetEntryStateListenerTest proves the
 * fan-out from a status edge to the entries, and TimeEntryStampListenerTest the
 * stamp on the entry's own write. Neither shows that OpenRegister runs those
 * listeners on a lifecycle transition, nor that `approvalState` is filterable
 * next to `projectId`, `date` and `billable` in one objects-API query, which is
 * what shillinq's hours source sends. That read is here.
 *
 * The admin seeds an employee and three September entries on a project of this
 * run (8 and 4.5 billable hours, 2 non-billable), submits and approves the
 * timesheet the entries were booked into through its lifecycle, and reads the
 * project's approved billable hours for September. The employee has no
 * account: the approver (admin) is not the employee, so NoSelfApprovalGuard
 * lets the approval through.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const OBJECTS = `${NC_URL}/index.php/apps/openregister/api/objects`
const OR_BASE = `${OBJECTS}/humaniq`
const RUN_ID = `e2e-tas-${Date.now()}-${randomUUID().slice(0, 8)}`
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

/** The project the hours are booked on; unique per run so no other entry matches. */
const PROJECT = `renovatie-kade-12-${RUN_ID}`

/**
 * The id of an object as the objects API returns it.
 *
 * @param json The response body.
 * @return The object's id.
 */
function idOf(json: Record<string, unknown>): string {
	const self = (json?.['@self'] || {}) as Record<string, unknown>
	return String(self.id || json?.id || '')
}

test.describe.configure({ mode: 'serial' })

test.describe('approved billable hours per project and period', () => {
	let admin: APIRequestContext
	let timesheetId = ''
	const cleanup: Array<{ schema: string; id: string }> = []

	test.beforeAll(async () => {
		admin = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })

		const emp = await admin.post(`${OR_BASE}/Employee`, {
			headers: HEADERS,
			data: {
				employeeNumber: `${RUN_ID}-nr`,
				firstName: 'Visser',
				lastName: `Uren-${RUN_ID}`,
				startDate: '2026-01-01',
				nextcloudUserId: `${RUN_ID}-uid`,
				administrationId: 'ADM-001',
			},
		})
		expect(emp.ok(), `seed the employee: ${emp.status()} ${await emp.text()}`).toBeTruthy()
		const employeeId = idOf(await emp.json())
		cleanup.push({ schema: 'Employee', id: employeeId })

		const bookings = [
			{ date: '2026-09-07', hours: 8, billable: true, description: 'Kozijnen inmeten' },
			{ date: '2026-09-14', hours: 4.5, billable: true, description: 'Offerte bespreken' },
			{ date: '2026-09-21', hours: 2, billable: false, description: 'Intern overleg' },
		]
		for (const booking of bookings) {
			const made = await admin.post(`${OR_BASE}/TimeEntry`, {
				headers: HEADERS,
				data: { ...booking, employeeId, projectId: PROJECT, administrationId: 'ADM-001' },
			})
			expect(made.ok(), `book ${booking.hours} hours: ${made.status()} ${await made.text()}`).toBeTruthy()
			const entry = await made.json()
			cleanup.push({ schema: 'TimeEntry', id: idOf(entry) })
			expect(entry.approvalState, 'a new entry on a draft timesheet is draft').toBe('draft')
			timesheetId = timesheetId || String(entry.timesheetId || '')
			expect(String(entry.timesheetId), 'all three are booked into the one September timesheet').toBe(timesheetId)
		}
		cleanup.push({ schema: 'Timesheet', id: timesheetId })

		for (const action of ['submit', 'approve']) {
			const moved = await admin.post(`${OBJECTS}/${timesheetId}/transition`, { headers: HEADERS, data: { action } })
			expect(moved.ok(), `${action} the timesheet: ${moved.status()} ${await moved.text()}`).toBeTruthy()
		}
	})

	test.afterAll(async () => {
		// An approved timesheet locks its entries: reopen it first so they can go.
		if (timesheetId !== '') {
			await admin.post(`${OBJECTS}/${timesheetId}/transition`, { headers: HEADERS, data: { action: 'reopen' } })
		}
		for (const { schema, id } of cleanup.reverse()) {
			await admin.delete(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS })
		}
		await admin.dispose()
	})

	// @e2e time-entry-approval-state::shillinq-lists-the-hours-to-invoice
	test('the approved billable hours of one project in September come back in one query @spec REQ-TAS-002', async () => {
		const query = new URLSearchParams({
			projectId: PROJECT,
			billable: 'true',
			approvalState: 'approved',
			'date[gte]': '2026-09-01',
			'date[lte]': '2026-09-30',
			_limit: '50',
		})
		const res = await admin.get(`${OR_BASE}/TimeEntry?${query.toString()}`, { headers: HEADERS })
		expect(res.ok(), `the hours query: ${res.status()} ${await res.text()}`).toBeTruthy()
		const rows = ((await res.json())?.results || []) as Array<Record<string, unknown>>

		expect(rows.map((row) => Number(row.hours)).sort((a, b) => a - b)).toEqual([4.5, 8])
		expect(rows.reduce((sum, row) => sum + Number(row.hours), 0)).toBe(12.5)
		for (const row of rows) {
			expect(row.approvalState).toBe('approved')
			expect(String(row.approvedAt || ''), 'the approval time is carried').not.toBe('')
			expect(String(row.employeeId || ''), 'the person').not.toBe('')
			expect(String(row.date)).toMatch(/^2026-09-/)
			expect(String(row.description || ''), 'the description').not.toBe('')
		}
	})
})
