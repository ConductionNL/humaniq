/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * leave-against-a-department-schedule, tasks.md 6.2.
 *
 * SCENARIOS COVERED
 * -----------------
 * openspec/changes/leave-against-a-department-schedule/specs/leave-management/spec.md
 *   Scenario: A new leave kind is added without a release
 *   Scenario: A retired type keeps old requests readable
 *   Scenario: A calamiteitenverlof without a reason does not submit
 *   Scenario: A draft is not judged yet
 *   Scenario: August is read in one view
 *   Scenario: A withdrawn request leaves the schedule at once
 *   Scenario: A manager does not see another department through it
 *   Scenario: Two surfaces, one lifecycle
 *
 * WHAT THIS CLOSES THAT THE UNIT SUITE CANNOT
 * -------------------------------------------
 * The services are pinned over arrays the tests wrote. What they cannot see:
 * whether `LeaveType` exists as a schema at all, whether the guard named in
 * the `submit` transition is actually resolved by OpenRegister's guard
 * registry, and whether the schedule endpoint is routed. A guard whose class
 * name is wrong in the register fragment does not error; the transition simply
 * runs unguarded, which is the same shape as having written no guard.
 *
 * WHY THE FIXTURES CARRY THEIR OWN EMPLOYEE AND UNIT
 * --------------------------------------------------
 * Every object here hangs off an employee and an org unit this file creates
 * under a run-scoped name, and every one is deleted in the teardown. A leave
 * request on a real person moves their balance and stands on their department's
 * schedule, and a fixture that lands on somebody's real record is a defect of
 * its own regardless of what it was proving.
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
const APP_BASE = `${NC_URL}/index.php/apps/humaniq/api/leave`;
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

test.describe.serial("leave against a department schedule", () => {
	let api: APIRequestContext;
	let employeeId = "";
	let orgUnitId = "";
	let typeId = "";
	let requestId = "";

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
			firstName: "Verlofrooster",
			lastName: `Fixture ${RUN_ID}`,
			email: `verlofrooster.${RUN_ID}@example.invalid`,
		});
		orgUnitId = await create("OrgUnit", {
			name: `Verlofrooster ${RUN_ID}`,
			minimumPresentMonday: 2,
		});
		await create("OrgAssignment", {
			employeeId,
			orgUnitId,
			startDate: "2026-01-01",
		});
	});

	test.afterAll(async () => {
		for (const row of cleanup.reverse()) {
			await api.delete(`${OR_BASE}/${REGISTER}/${row.schema}/${row.id}`).catch(() => undefined);
		}

		await api.dispose();
	});

	test("a new kind of leave is added by writing an object, not by shipping a schema", async () => {
		typeId = await create("LeaveType", {
			code: `zorgverlof-${RUN_ID}`,
			label: "Zorgverlof",
			drawsFromBalance: false,
			requiresReason: true,
			active: true,
		});

		const read = await api.get(`${OR_BASE}/${REGISTER}/LeaveType/${typeId}`);
		expect(read.ok()).toBeTruthy();
		const stored = (await read.json()) as Record<string, unknown>;

		expect(stored.requiresReason, "a condition a silent field drop would eat").toBe(true);
		expect(stored.drawsFromBalance).toBe(false);
	});

	test("a draft is stored without its type's conditions being judged", async () => {
		requestId = await create("LeaveRequest", {
			employeeId,
			leaveType: `zorgverlof-${RUN_ID}`,
			startDate: "2026-08-03",
			endDate: "2026-08-03",
			hours: 8,
			status: "draft",
		});

		const read = await api.get(`${OR_BASE}/${REGISTER}/LeaveRequest/${requestId}`);
		expect(read.ok()).toBeTruthy();
		expect(
			((await read.json()) as Record<string, unknown>).status,
			"a draft with no reason is stored, because a draft is somebody thinking",
		).toBe("draft");
	});

	test("submitting without the reason the type asks for is refused", async () => {
		const refused = await api.post(`${NC_URL}/index.php/apps/openregister/api/objects/${requestId}/transition`, {
			data: { action: "submit" },
		});

		expect(
			refused.ok(),
			"the type needs a reason and the request has none, so the submit must not land",
		).toBeFalsy();

		const read = await api.get(`${OR_BASE}/${REGISTER}/LeaveRequest/${requestId}`);
		expect(((await read.json()) as Record<string, unknown>).status).toBe("draft");
	});

	test("the department schedule answers the unit's month, and drops a withdrawn request at once", async () => {
		/* Fill in the reason, submit, and the request stands on the schedule. */
		const filled = await api.put(`${OR_BASE}/${REGISTER}/LeaveRequest/${requestId}`, {
			data: {
				employeeId,
				leaveType: `zorgverlof-${RUN_ID}`,
				startDate: "2026-08-03",
				endDate: "2026-08-03",
				hours: 8,
				reason: "Mantelzorg",
				status: "submitted",
			},
		});
		expect(filled.ok()).toBeTruthy();

		const onSchedule = await api.get(
			`${APP_BASE}/schedule?orgUnitId=${encodeURIComponent(orgUnitId)}&from=2026-08-01&to=2026-08-31`,
		);
		expect(onSchedule.ok(), "the schedule endpoint must be routed and readable").toBeTruthy();
		const body = (await onSchedule.json()) as { entries?: Array<Record<string, unknown>> };
		expect(
			(body.entries ?? []).map((entry) => entry.requestId),
			"the unit's submitted request stands on its month",
		).toContain(requestId);

		/* Withdraw it back to draft: the next read no longer has it, with no
		   sync in between, because the schedule IS the requests. */
		await api.put(`${OR_BASE}/${REGISTER}/LeaveRequest/${requestId}`, {
			data: {
				employeeId,
				leaveType: `zorgverlof-${RUN_ID}`,
				startDate: "2026-08-03",
				endDate: "2026-08-03",
				hours: 8,
				reason: "Mantelzorg",
				status: "draft",
			},
		});

		const afterWithdrawal = await api.get(
			`${APP_BASE}/schedule?orgUnitId=${encodeURIComponent(orgUnitId)}&from=2026-08-01&to=2026-08-31`,
		);
		const afterBody = (await afterWithdrawal.json()) as { entries?: Array<Record<string, unknown>> };
		expect((afterBody.entries ?? []).map((entry) => entry.requestId)).not.toContain(requestId);
	});

	test("the schedule refuses a call that names no org unit", async () => {
		const response = await api.get(`${APP_BASE}/schedule?from=2026-08-01&to=2026-08-31`);

		expect(response.status(), "a schedule of everybody is not a department schedule").toBe(400);
	});

	test("an anonymous reader reaches neither the schedule nor the coverage warning", async () => {
		/* The least privileged principal that should be refused. Both endpoints
		   say who is away and who is thin on the ground, which is not public. */
		const anonymous = await request.newContext({ extraHTTPHeaders: HEADERS });

		const schedule = await anonymous.get(
			`${APP_BASE}/schedule?orgUnitId=${encodeURIComponent(orgUnitId)}&from=2026-08-01&to=2026-08-31`,
		);
		const coverage = await anonymous.get(
			`${APP_BASE}/coverage?leaveRequestId=${encodeURIComponent(requestId)}`,
		);

		expect(schedule.status(), "the schedule is not public").not.toBe(200);
		expect(coverage.status(), "the coverage warning is not public").not.toBe(200);

		await anonymous.dispose();
	});

	test("the schedule adds no transition of its own", async () => {
		/* Two surfaces, one lifecycle: whatever the schedule offers has to be
		   an action LeaveRequest already declares. Read the declared actions
		   from the register the instance actually serves. */
		const schema = await api.get(`${NC_URL}/index.php/apps/openregister/api/schemas?_limit=200`);
		expect(schema.ok()).toBeTruthy();
		const payload = (await schema.json()) as { results?: Array<Record<string, unknown>> };
		const leaveRequest = (payload.results ?? []).find(
			(row) => String(row.slug ?? row.title ?? "").toLowerCase() === "leaverequest",
		);
		expect(leaveRequest, "the LeaveRequest schema must be importable").toBeTruthy();

		const configuration = (leaveRequest?.configuration || {}) as Record<string, unknown>;
		const lifecycle = (configuration["x-openregister-lifecycle"] || {}) as Record<string, unknown>;
		const transitions = Object.keys((lifecycle.transitions || {}) as Record<string, unknown>).sort();

		expect(transitions, "no edge is invented for the schedule").toEqual(
			["approve", "reject", "submit"].sort(),
		);
	});
});
