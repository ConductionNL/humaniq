/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reads and writes behind the hours leaf, in one place.
 *
 * The widget and the booking dialog both talk to the same two things: humaniq's
 * `TimeEntry` rows through OpenRegister's object API (ADR-022, so humaniq serves
 * no CRUD of its own), and the three timer endpoints, which exist only because
 * "one running timer per user" spans rows the caller does not send.
 *
 * Sharing this module is what keeps the two surfaces agreeing on the register
 * slug, the schema name and the shape of a filter. When they each carried their
 * own copy, a total and the rows behind it could be read from two different
 * places and nothing would say so.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * humaniq's own OpenRegister register slug.
 *
 * The host object lives in the CONSUMING app's register; the hours do not. This
 * is deliberately not derived from the widget's `register` prop, which names the
 * host's.
 *
 * @type {string}
 */
export const HOURS_REGISTER = 'humaniq'

/**
 * The schema every booking and every running timer is a row of.
 *
 * @type {string}
 */
export const TIME_ENTRY_SCHEMA = 'TimeEntry'

/**
 * The `origin` value marking an entry as a running timer.
 *
 * Equal to `RunningTimerService::ORIGIN_TIMER` and to the enum member on the
 * schema. The server decides what a timer is; this constant only lets the client
 * recognise one it is handed.
 *
 * @type {string}
 */
export const ORIGIN_TIMER = 'timer'

/**
 * Read every time entry booked against one host object.
 *
 * Asks for the entries rather than for a total, because the tile shows two
 * figures, the object's and the caller's own, and a second request for the
 * second figure would let the two disagree.
 *
 * @param {string} domainObjectType The `<app>:<schema>` literal of the host object.
 * @param {string} domainObjectRef  The host object's uuid.
 *
 * @return {Promise<object[]>} The entries, newest first is not guaranteed.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-reads-as-a-kpi-tile
 */
export async function fetchEntries(domainObjectType, domainObjectRef) {
	// BARE property names, not `filter[...]`. Measured against a live instance,
	// same register, same schema, 9 rows in total:
	//
	//   ?origin=migration          -> 1 row
	//   ?filter[origin]=migration  -> 0 rows
	//   ?origin=manual             -> 8 rows   (8 + 1 = 9, the whole set)
	//
	// A `filter[x]` key is read as a filter on a property literally named
	// `filter[x]`, which no schema has, so EVERY such query returns the empty
	// set. Nothing errors. The tile would have summed nothing and rendered `0`
	// on every object forever, which is exactly what an object with no hours
	// renders, and exactly the defect this whole leaf exists to remove.
	const url = generateUrl(`/apps/openregister/api/objects/${HOURS_REGISTER}/${TIME_ENTRY_SCHEMA}`)
	const { data } = await axios.get(url, {
		params: {
			domainObjectType,
			domainObjectRef,
			_limit: 100,
		},
	})

	if (Array.isArray(data?.results) === true) {
		return data.results
	}

	return Array.isArray(data) === true ? data : []
}

/**
 * Book hours against a host object.
 *
 * Sends the day shape: a date and a number of hours. A person booking against a
 * case knows how long they spent on it; they do not generally know the clock
 * times, and asking for times they would have to invent is worse than asking for
 * the figure they have.
 *
 * The two reference fields are seeded by the integration and never offered for
 * editing, which is why neither appears in any `includeFields` allowlist.
 *
 * @param {object} booking                   The booking.
 * @param {string} booking.domainObjectType  The `<app>:<schema>` literal of the host object.
 * @param {string} booking.domainObjectRef   The host object's uuid.
 * @param {string} booking.date              The day worked, `YYYY-MM-DD`.
 * @param {number} booking.hours             The hours worked.
 * @param {string} [booking.description]     What was worked on.
 *
 * @return {Promise<object>} The created entry.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
 */
export async function bookHours(booking) {
	const url = generateUrl(`/apps/openregister/api/objects/${HOURS_REGISTER}/${TIME_ENTRY_SCHEMA}`)
	const payload = {
		domainObjectType: booking.domainObjectType,
		domainObjectRef: booking.domainObjectRef,
		date: booking.date,
		hours: booking.hours,
	}
	// The clocked shape: a start and an end, from which the server derives the
	// hours the same way it does for a stopped timer. Sent only when both are
	// there, so a caller booking a bare count still writes the day shape.
	if (typeof booking.startedAt === 'string' && typeof booking.endedAt === 'string') {
		payload.startedAt = booking.startedAt
		payload.endedAt = booking.endedAt
	}

	// Omitted rather than sent empty. OpenRegister refuses `{}`, `[]` and `null`
	// for an absent value, and its own message suggests the null that fails.
	if (typeof booking.description === 'string' && booking.description.trim() !== '') {
		payload.description = booking.description.trim()
	}

	const { data } = await axios.post(url, payload)

	return data
}

/**
 * The caller's running timer, or null.
 *
 * This is what lets a timer survive leaving the page: the surface asks rather
 * than remembering, so a timer started before navigating away is still running
 * on return, on any device.
 *
 * @return {Promise<?object>} The running entry, or null.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
 */
export async function fetchRunningTimer() {
	const { data } = await axios.get(generateUrl('/apps/humaniq/api/time-entries/timer'))

	return data?.status === 'running' ? (data.entry || null) : null
}

/**
 * Start a timer against a host object.
 *
 * A refusal is an answer, not a failure: the server refuses a second timer with
 * 409 and hands back the entry that is already running, so the caller can say
 * WHICH object is being timed rather than only that something is.
 *
 * @param {string} domainObjectType The `<app>:<schema>` literal of the host object.
 * @param {string} domainObjectRef  The host object's uuid.
 *
 * @return {Promise<{status: string, entry?: object, error?: string}>} The outcome.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
 */
export async function startTimer(domainObjectType, domainObjectRef) {
	try {
		const { data } = await axios.post(
			generateUrl('/apps/humaniq/api/time-entries/timer/start'),
			{ domainObjectType, domainObjectRef },
		)

		return data
	} catch (e) {
		const data = e?.response?.data
		if (data !== undefined && data !== null && typeof data.status === 'string') {
			return data
		}

		throw e
	}
}

/**
 * Stop the caller's running timer.
 *
 * Sends nothing. The entry is resolved from the caller, so stopping someone
 * else's timer is not a request that can be phrased.
 *
 * @return {Promise<{status: string, entry?: object}>} The outcome.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
 */
export async function stopTimer() {
	const { data } = await axios.post(generateUrl('/apps/humaniq/api/time-entries/timer/stop'), {})

	return data
}

/**
 * The link into humaniq's hour administration for one host object.
 *
 * The time-entry index and not the timesheet one. A timesheet is a person's
 * month; the question this link answers is "where does this object's total come
 * from", which is a set of entries across people and months.
 *
 * @param {string} domainObjectType The `<app>:<schema>` literal of the host object.
 * @param {string} domainObjectRef  The host object's uuid.
 *
 * @return {string} The url.
 *
 * @spec openspec/specs/hours-leaf/spec.md#requirement-hours-can-be-added-from-the-surface-that-shows-them
 */
export function administrationUrl(domainObjectType, domainObjectRef) {
	// BARE query keys, not `filter[...]`. CnIndexPage seeds its filters from the
	// route query through `resolveQueryFilters`, which takes every key that does
	// NOT start with an underscore and uses it verbatim as a filter name. A
	// `filter[domainObjectType]` key therefore becomes a filter on a property
	// literally called `filter[domainObjectType]`, which no schema has: the link
	// opens, the page renders, and the list is not narrowed to this object at
	// all. The deep-link shape the library documents is `/cases?caseType=X`.
	const query = new URLSearchParams({
		domainObjectType,
		domainObjectRef,
	})

	return `${generateUrl('/apps/humaniq/time-entries')}?${query.toString()}`
}
