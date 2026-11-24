// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Booking time that is not for a project (time-non-project-activities 3.1).
 *
 * openspec/specs/time-activities/spec.md
 *   Scenario: An employee books a training afternoon
 *   Scenario: Leave cannot be booked twice
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. TimeEntryStampListenerTest refuses a
 * `verlof` booking and accepts an `opleiding` one over doubles, and the
 * aggregate test adds the three totals up. Neither shows that OpenRegister
 * runs the listener on a write the EMPLOYEE makes, that the refusal reaches
 * them with its sentence, or that the week's timesheet the listener finds or
 * creates counts the hours as non-project. Those sit between humaniq and
 * OpenRegister, so they are here.
 *
 * 🔴 THE EMPLOYEE IS NOT THE ADMIN. The admin seeds an Employee for a
 * provisioned account; the bookings are written by that account from an
 * empty storage state, so the admin's session cannot ride along.
 *
 * "A week that adds up" carries `@e2e exclude` in the spec: it needs a
 * working pattern, an approved leave day and a full week of bookings, and its
 * arithmetic is TimesheetWeekServiceTest's; the live week of task 3.1 reads it.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq`
const RUN_ID = `e2e-tnp-${Date.now()}-${randomUUID().slice(0, 8)}`
const HEADERS = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

/** The employee's account. */
const EMPLOYEE_USER = `${RUN_ID}-uid`
const EMPLOYEE_PASS = `Tnp-${randomUUID()}`

test.describe.configure({ mode: 'serial' })

test.describe('booking time that is not for a project', () => {
	let admin: APIRequestContext
	let employee: APIRequestContext
	let employeeId = ''
	const cleanup: Array<{ schema: string; id: string }> = []

	test.beforeAll(async () => {
		admin = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
		const made = await admin.post(`${NC_URL}/ocs/v2.php/cloud/users?format=json`, {
			headers: { 'OCS-APIRequest': 'true' },
			form: { userid: EMPLOYEE_USER, password: EMPLOYEE_PASS, displayName: EMPLOYEE_USER },
		})
		expect(made.ok(), `provision the employee account: ${await made.text()}`).toBeTruthy()

		const emp = await admin.post(`${OR_BASE}/Employee`, {
			headers: HEADERS,
			data: {
				employeeNumber: `${RUN_ID}-nr`,
				firstName: 'Opleiding',
				lastName: `Medewerker-${RUN_ID}`,
				startDate: '2026-01-01',
				nextcloudUserId: EMPLOYEE_USER,
				administrationId: 'ADM-001',
			},
		})
		expect(emp.ok(), `seed the employee: ${emp.status()} ${await emp.text()}`).toBeTruthy()
		const empJson = await emp.json()
		employeeId = String(empJson?.['@self']?.id || empJson?.id)
		cleanup.push({ schema: 'Employee', id: employeeId })

		employee = await request.newContext({
			httpCredentials: { username: EMPLOYEE_USER, password: EMPLOYEE_PASS },
			storageState: { cookies: [], origins: [] },
		})
	})

	test.afterAll(async () => {
		for (const { schema, id } of cleanup.reverse()) {
			await admin.delete(`${OR_BASE}/${schema}/${id}`, { headers: HEADERS })
		}
		await admin.delete(`${NC_URL}/ocs/v2.php/cloud/users/${EMPLOYEE_USER}?format=json`, { headers: { 'OCS-APIRequest': 'true' } })
	})

	test('a training afternoon is booked without a project and counts as non-project hours @spec REQ-TAC-001', async () => {
		// @e2e time-activities::an-employee-books-a-training-afternoon
		const res = await employee.post(`${OR_BASE}/TimeEntry`, {
			headers: HEADERS,
			data: {
				employeeId,
				startedAt: '2026-07-08T12:00:00Z',
				endedAt: '2026-07-08T16:00:00Z',
				breakMinutes: 0,
				activityCode: 'opleiding',
				description: `Opleiding ${RUN_ID}`,
			},
		})
		expect(res.ok(), `the training booking is accepted: ${res.status()} ${await res.text()}`).toBeTruthy()
		const entry = await res.json()
		cleanup.push({ schema: 'TimeEntry', id: String(entry?.['@self']?.id || entry?.id) })
		expect(entry.projectId ?? null, 'the entry has no project').toBeNull()
		expect(Number(entry.hours)).toBe(4)

		const timesheetId = String(entry.timesheetId || '')
		expect(timesheetId, 'the booking lands on the week\'s timesheet').toBeTruthy()
		cleanup.push({ schema: 'Timesheet', id: timesheetId })
		const sheet = await (await admin.get(`${OR_BASE}/Timesheet/${timesheetId}`, { headers: HEADERS })).json()
		expect(Number(sheet.nonProjectHours), 'the timesheet counts four non-project hours').toBe(4)
	})

	test('leave cannot be booked as time, and the refusal says where leave comes from @spec REQ-TAC-001', async () => {
		// @e2e time-activities::leave-cannot-be-booked-twice
		const res = await employee.post(`${OR_BASE}/TimeEntry`, {
			headers: HEADERS,
			data: {
				employeeId,
				startedAt: '2026-07-09T08:00:00Z',
				endedAt: '2026-07-09T16:00:00Z',
				breakMinutes: 0,
				activityCode: 'verlof',
				description: `Verlof ${RUN_ID}`,
			},
		})
		expect(res.status(), 'the leave booking is refused').toBeGreaterThanOrEqual(400)
		expect(res.status()).toBeLessThan(500)
		expect((await res.text()).toLowerCase(), 'the message points at the leave request').toMatch(/verlof|leave/)
	})
})
