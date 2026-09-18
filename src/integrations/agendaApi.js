/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reads behind the agenda leaf.
 *
 * WHY THE AGENDA COMES FROM HUMANIQ AND NOT FROM THE REGISTER
 * -----------------------------------------------------------
 * An agenda entry is composed from six sources: rostered shifts, approved
 * leave, open sick leave, interviews, resource bookings and cached external
 * busy time. A client that queried the register itself would have to know all
 * six shapes, would re-implement the AVG boundary in the browser, and would
 * drift from the server the first time a source changed. So the widget asks
 * humaniq's own endpoint, which composes it once and strips what must not
 * travel (REQ-AGD-001).
 *
 * The HOST object is read through OpenRegister's object API, because that is
 * how a leaf reads the page it is standing on (ADR-022).
 */
import { generateUrl } from '@nextcloud/router'

/**
 * The properties a host object may name its handler with, in the order they are
 * tried.
 *
 * A leaf lands on another app's schema, and no two apps spell this the same
 * way. The list is explicit rather than a guess at a convention, so a host that
 * uses none of these renders "no person on this record" instead of an agenda
 * belonging to somebody else.
 *
 * @type {string[]}
 */
export const HANDLER_PROPERTIES = [
	'employeeId',
	'behandelaarId',
	'handlerId',
	'assigneeId',
	'ownerId',
	'medewerkerId',
]

/**
 * Read one object through OpenRegister's object API.
 *
 * @param {string} register The register slug.
 * @param {string} schema   The schema slug.
 * @param {string} objectId The object uuid.
 *
 * @return {Promise<object|null>} The object, or null when it cannot be read.
 */
export async function readHostObject(register, schema, objectId) {
	if (!register || !schema || !objectId) {
		return null
	}

	const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}/{objectId}', {
		register,
		schema,
		objectId,
	})

	const response = await fetch(url, { headers: { 'OCS-APIRequest': 'true' } })
	if (!response.ok) {
		return null
	}

	return await response.json()
}

/**
 * The employee a host object names, or null when it names none.
 *
 * @param {object|null} host The host object.
 *
 * @return {string|null} The employee uuid, or null.
 */
export function handlerOf(host) {
	if (!host || typeof host !== 'object') {
		return null
	}

	for (const property of HANDLER_PROPERTIES) {
		const value = host[property]
		if (typeof value === 'string' && value.trim() !== '') {
			return value.trim()
		}
	}

	return null
}

/**
 * Read one subject's agenda over a period.
 *
 * @param {string} subjectType `employee`, `orgUnit` or `resource`.
 * @param {string} subjectId   The subject's uuid.
 * @param {string} from        First day (ISO date).
 * @param {string} to          Last day (ISO date).
 *
 * @return {Promise<object[]>} The entries.
 *
 * @throws {Error} When humaniq refuses or cannot answer.
 */
export async function readAgenda(subjectType, subjectId, from, to) {
	const url = generateUrl('/apps/humaniq/api/agenda?subjectType={subjectType}&subjectId={subjectId}&from={from}&to={to}', {
		subjectType,
		subjectId,
		from,
		to,
	})

	const response = await fetch(url, { headers: { 'OCS-APIRequest': 'true' } })
	if (!response.ok) {
		throw new Error(`agenda request failed: ${response.status}`)
	}

	const body = await response.json()

	return Array.isArray(body.entries) ? body.entries : []
}

/**
 * Today and the six days after it, as ISO dates.
 *
 * A week rather than a month: the leaf answers "what is this person doing now",
 * and a month of entries in a card on somebody else's page is a wall.
 *
 * @param {Date} [now] The moment to count from (injectable for tests).
 *
 * @return {{from: string, to: string}} The window.
 */
export function defaultWindow(now) {
	const start = now instanceof Date ? new Date(now.getTime()) : new Date()
	const end = new Date(start.getTime() + (6 * 24 * 60 * 60 * 1000))

	return { from: start.toISOString().slice(0, 10), to: end.toISOString().slice(0, 10) }
}
