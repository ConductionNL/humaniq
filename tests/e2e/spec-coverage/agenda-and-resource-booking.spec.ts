/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * agenda-rostering-and-resource-booking, tasks.md 8.2.
 *
 * SCENARIOS COVERED
 * -----------------
 * openspec/changes/agenda-rostering-and-resource-booking/specs/agenda-and-resource-booking/spec.md
 *   Scenario: A handler's week reads from five sources at once
 *   Scenario: A cancelled leave request leaves the agenda at once
 *   Scenario: A hoorzitting room is booked against the case that needs it
 *   Scenario: A resource out of service stops being bookable
 *   Scenario: The second booking of one room loses
 *   Scenario: Three inspectors and two meters
 *
 * openspec/changes/agenda-rostering-and-resource-booking/specs/rostering/spec.md
 *   Scenario: A shift names what it needs
 *
 * WHAT THIS CLOSES THAT THE UNIT SUITE CANNOT
 * -------------------------------------------
 * The services are pinned over arrays the tests wrote. They cannot see whether
 * `Resource`, `ResourceBooking` and `EmployeeCompetence` exist as schemas, nor
 * whether `ResourceBookingOverlapListener` is actually subscribed to the write
 * path. A listener whose schema slug is misspelled is never invoked, and a
 * never-invoked refusal looks exactly like a booking that was allowed.
 *
 * `Shift.requiredCompetences` is an array property, and OpenRegister drops a
 * property the schema does not declare in silence, so a round trip through the
 * store is the only way to see that the set survived.
 *
 * WHY THE FIXTURES CARRY THEIR OWN EMPLOYEE AND RESOURCES
 * -------------------------------------------------------
 * Every object here is created by this file under a run-scoped name and deleted
 * in the teardown. Nothing is written onto a person or a room the instance
 * already uses: a booking on a real room takes it away from whoever needs it,
 * and a leave request on a real person moves their balance.
 *
 * PRECONDITIONS
 * -------------
 * The humaniq register and its schemas are imported (tests/e2e/ci-seed.sh).
 */

import type { APIRequestContext } from "@playwright/test";

import { expect, request, test } from "@playwright/test";
import { randomUUID } from "node:crypto";
import { ADMIN_CREDENTIALS, resolveBaseURL } from "../base-url.ts";

const NC_URL = resolveBaseURL();
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects`;
const APP_BASE = `${NC_URL}/index.php/apps/humaniq/api`;
const REGISTER = "humaniq";
const AUTH = ADMIN_CREDENTIALS;
const HEADERS = {
	"OCS-APIRequest": "true",
	"Content-Type": "application/json",
};

const RUN_ID = randomUUID().slice(0, 8);

/** Read an object's id out of either payload shape OpenRegister returns. */
function idOf(row: Record<string, unknown> | undefined): string {
	const self = (row?.["@self"] || {}) as Record<string, unknown>;
	return String(self.id || row?.id || "");
}

test.describe.serial("agenda, rostering and resource booking", () => {
	let api: APIRequestContext;
	let employeeId = "";
	let roomId = "";
	let meterId = "";
	let leaveId = "";

	const cleanup: Array<{ schema: string; id: string }> = [];

	/** Create one object and register it for teardown. */
	async function create(schema: string, data: Record<string, unknown>): Promise<string> {
		const response = await api.post(`${OR_BASE}/${REGISTER}/${schema}`, { data });
		expect(response.ok(), `${schema} fixture must be created`).toBeTruthy();
		const id = idOf(await response.json());
		expect(id, `${schema} fixture must have an id`).not.toBe("");
		cleanup.push({ schema, id });

		return id;
	}

	test.beforeAll(async () => {
		api = await request.newContext({ extraHTTPHeaders: { ...HEADERS, ...AUTH } });

		employeeId = await create("Employee", {
			firstName: "Agenda",
			lastName: `Fixture ${RUN_ID}`,
			email: `agenda.${RUN_ID}@example.invalid`,
		});
		roomId = await create("HrResource", {
			name: `Hoorzittingzaal ${RUN_ID}`,
			kind: "room",
			quantity: 1,
			active: true,
		});
		meterId = await create("HrResource", {
			name: `Geluidsmeter ${RUN_ID}`,
			kind: "equipment",
			quantity: 2,
			active: true,
		});
	});

	test.afterAll(async () => {
		for (const row of cleanup.reverse()) {
			await api.delete(`${OR_BASE}/${REGISTER}/${row.schema}/${row.id}`).catch(() => undefined);
		}

		await api.dispose();
	});

	test("a shift stores the competences it needs, as a set", async () => {
		const shiftId = await create("Shift", {
			name: `Nachtcontrole horeca ${RUN_ID}`,
			startTime: "22:00",
			endTime: "06:00",
			requiredCompetences: ["boa-domein-1"],
		});

		/* Read it back: an array property the schema failed to declare is
		   dropped on the way in, and the create response echoes what it was
		   sent, so only a re-read says whether the set was stored. */
		const read = await api.get(`${OR_BASE}/${REGISTER}/Shift/${shiftId}`);
		expect(read.ok()).toBeTruthy();
		const stored = (await read.json()) as Record<string, unknown>;

		expect(stored.requiredCompetences, "the competence set must survive the round trip").toEqual([
			"boa-domein-1",
		]);
	});

	test("a room is booked against the case that needs it", async () => {
		const bookingId = await create("ResourceBooking", {
			resourceId: roomId,
			employeeId,
			start: "2026-08-07T10:00:00+02:00",
			end: "2026-08-07T12:00:00+02:00",
			purpose: "Hoorzitting",
			domainObjectType: "dossiq:zaak",
			domainObjectRef: randomUUID(),
		});

		const read = await api.get(`${OR_BASE}/${REGISTER}/ResourceBooking/${bookingId}`);
		const stored = (await read.json()) as Record<string, unknown>;

		expect(stored.domainObjectType, "the hours-leaf shape, verbatim").toBe("dossiq:zaak");
		expect(String(stored.domainObjectRef ?? "")).not.toBe("");
	});

	test("the second booking of one room is refused on the write", async () => {
		const clashing = await api.post(`${OR_BASE}/${REGISTER}/ResourceBooking`, {
			data: {
				resourceId: roomId,
				employeeId,
				start: "2026-08-07T11:00:00+02:00",
				end: "2026-08-07T13:00:00+02:00",
				purpose: "Tweede hoorzitting",
			},
		});

		expect(
			clashing.ok(),
			"a room booked twice for one hoorzitting is a person standing in a corridor",
		).toBeFalsy();

		if (clashing.ok()) {
			cleanup.push({ schema: "ResourceBooking", id: idOf(await clashing.json()) });
		}
	});

	test("two meters take two overlapping bookings and refuse the third", async () => {
		const first = await create("ResourceBooking", {
			resourceId: meterId,
			employeeId,
			start: "2026-08-07T10:00:00+02:00",
			end: "2026-08-07T12:00:00+02:00",
		});
		const second = await create("ResourceBooking", {
			resourceId: meterId,
			employeeId,
			start: "2026-08-07T10:30:00+02:00",
			end: "2026-08-07T11:30:00+02:00",
		});

		expect(first).not.toBe(second);

		const third = await api.post(`${OR_BASE}/${REGISTER}/ResourceBooking`, {
			data: {
				resourceId: meterId,
				employeeId,
				start: "2026-08-07T11:00:00+02:00",
				end: "2026-08-07T13:00:00+02:00",
			},
		});

		expect(third.ok(), "three inspectors, two meters, the third waits").toBeFalsy();

		if (third.ok()) {
			cleanup.push({ schema: "ResourceBooking", id: idOf(await third.json()) });
		}

		/* And a booking outside the overlap is ordinary, which is the control:
		   without it, the refusal above could mean the resource is simply
		   unbookable. */
		const outside = await create("ResourceBooking", {
			resourceId: meterId,
			employeeId,
			start: "2026-08-07T14:00:00+02:00",
			end: "2026-08-07T15:00:00+02:00",
		});
		expect(outside).not.toBe("");
	});

	test("a resource out of service refuses a booking", async () => {
		const retiredId = await create("HrResource", {
			name: `Bus ${RUN_ID}`,
			kind: "vehicle",
			quantity: 1,
			active: false,
		});

		const refused = await api.post(`${OR_BASE}/${REGISTER}/ResourceBooking`, {
			data: {
				resourceId: retiredId,
				employeeId,
				start: "2026-08-10T09:00:00+02:00",
				end: "2026-08-10T10:00:00+02:00",
			},
		});

		expect(refused.ok(), "a vehicle out of service cannot be booked").toBeFalsy();

		if (refused.ok()) {
			cleanup.push({ schema: "ResourceBooking", id: idOf(await refused.json()) });
		}
	});

	test("the agenda answers from several sources, and drops a withdrawn request at once", async () => {
		leaveId = await create("LeaveRequest", {
			employeeId,
			leaveType: "holiday",
			startDate: "2026-08-05",
			endDate: "2026-08-05",
			hours: 8,
			status: "approved",
		});

		const week = `subjectType=employee&subjectId=${encodeURIComponent(employeeId)}&from=2026-08-03&to=2026-08-09`;
		const withLeave = await api.get(`${APP_BASE}/agenda?${week}`);
		expect(withLeave.ok(), "the agenda endpoint must be routed and readable").toBeTruthy();

		const body = (await withLeave.json()) as { entries?: Array<Record<string, unknown>> };
		const kinds = (body.entries ?? []).map((entry) => entry.kind);

		expect(kinds, "the approved leave and the room booking both stand on the week").toContain("leave");
		expect(kinds).toContain("booking");

		/* Withdraw the leave: the next read no longer has it, with no sync in
		   between, because the agenda IS the objects. */
		await api.put(`${OR_BASE}/${REGISTER}/LeaveRequest/${leaveId}`, {
			data: {
				employeeId,
				leaveType: "holiday",
				startDate: "2026-08-05",
				endDate: "2026-08-05",
				hours: 8,
				status: "draft",
			},
		});

		const afterWithdrawal = await api.get(`${APP_BASE}/agenda?${week}`);
		const afterBody = (await afterWithdrawal.json()) as { entries?: Array<Record<string, unknown>> };

		expect((afterBody.entries ?? []).map((entry) => entry.kind)).not.toContain("leave");
	});

	test("the availability answer names free hours, and an anonymous reader gets none of it", async () => {
		const availability = await api.get(`${APP_BASE}/availability?from=2026-08-03&to=2026-08-09`);
		expect(availability.ok()).toBeTruthy();

		const body = (await availability.json()) as { employees?: Array<Record<string, unknown>> };
		for (const row of body.employees ?? []) {
			expect(
				typeof row.freeHours,
				"availability answers hours per employee, not a yes or a no",
			).toBe("number");
		}

		/* The least privileged principal that should be refused: who is free
		   when, and who is absent, is not public. */
		const anonymous = await request.newContext({ extraHTTPHeaders: HEADERS });
		const refused = await anonymous.get(`${APP_BASE}/availability?from=2026-08-03&to=2026-08-09`);
		const agendaRefused = await anonymous.get(
			`${APP_BASE}/agenda?subjectType=employee&subjectId=${encodeURIComponent(employeeId)}&from=2026-08-03&to=2026-08-09`,
		);

		expect(refused.status()).not.toBe(200);
		expect(agendaRefused.status()).not.toBe(200);

		await anonymous.dispose();
	});

	test("the capacity read says when somebody has no contracted hours", async () => {
		const capacity = await api.get(`${APP_BASE}/capacity?from=2026-08-03&to=2026-08-09`);
		expect(capacity.ok()).toBeTruthy();

		const body = (await capacity.json()) as {
			employees?: Array<Record<string, unknown>>;
			totals?: Record<string, unknown>;
		};
		const fixture = (body.employees ?? []).find((row) => row.employeeId === employeeId);

		expect(fixture, "the fixture employee is in the answer").toBeTruthy();
		expect(
			fixture?.hasContractedHours,
			"this fixture has no working pattern, and that is said rather than filled in",
		).toBe(false);
		expect(fixture?.utilisationPercentage, "no percentage is computed for them").toBeNull();
		expect(typeof body.totals?.employeesWithoutContractedHours).toBe("number");
	});
});
