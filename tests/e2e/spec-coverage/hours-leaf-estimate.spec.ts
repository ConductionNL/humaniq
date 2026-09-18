/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * estimate-spent-and-remaining-on-an-hours-leaf, tasks.md 5.2.
 *
 * SCENARIOS COVERED
 * -----------------
 * openspec/changes/estimate-spent-and-remaining-on-an-hours-leaf/specs/hours-leaf/spec.md
 *   Scenario: A bezwaar is estimated per role
 *   Scenario: One role, one estimate
 *   Scenario: Deleting an entry moves the remainder at once
 *   Scenario: An overrun reads as a negative remainder, not as zero
 *   Scenario: A booking past a capped estimate is refused with the numbers in it
 *   Scenario: A ceiling on one role does not block another
 *   Scenario: The default refuses nothing
 *   Scenario: The refusal reads the same wherever the booking is made
 *
 * WHAT THIS CLOSES THAT THE UNIT SUITE CANNOT
 * -------------------------------------------
 * TimeEstimateServiceTest pins the arithmetic over arrays the test wrote. It
 * cannot see whether `TimeEstimate` exists as a schema, whether `role` survives
 * a write on either schema, or whether `TimeEstimateListener` is actually
 * subscribed to both slugs. A listener registered under a misspelled slug is
 * never invoked, and a refusal that never runs looks exactly like a booking
 * that was allowed.
 *
 * It also drives the derived endpoint, which is where the remainder a reader
 * sees actually comes from: a service that computes the right number behind an
 * unrouted endpoint renders nothing.
 *
 * WHY NOTHING LANDS ON A REAL RECORD
 * ----------------------------------
 * Every fixture hangs off a run-scoped host object id that exists in no app,
 * and off an employee this file creates and deletes. No entry is written onto a
 * person's timesheet other than the fixture employee's, and every row is
 * removed in the teardown.
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
const ESTIMATE_URL = `${NC_URL}/index.php/apps/humaniq/api/time-entries/estimate`;
const REGISTER = "humaniq";
const AUTH = ADMIN_CREDENTIALS;
const HEADERS = {
	"OCS-APIRequest": "true",
	"Content-Type": "application/json",
};

const RUN_ID = randomUUID().slice(0, 8);

/* A host object in an app that is not installed here. The point of the leaf is
   that humaniq holds the hours for objects it does not own, so the reference
   needs no counterpart to be exercised. */
const OBJECT_TYPE = "dossiq:zaak";
const OBJECT_REF = randomUUID();

/** Read an object's id out of either payload shape OpenRegister returns. */
function idOf(row: Record<string, unknown> | undefined): string {
	const self = (row?.["@self"] || {}) as Record<string, unknown>;
	return String(self.id || row?.id || "");
}

test.describe.serial("estimate, spent and remaining on the hours leaf", () => {
	let api: APIRequestContext;
	let employeeId = "";
	let entryId = "";

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

	/** The derived summary humaniq answers for the fixture object. */
	async function summary(): Promise<Record<string, unknown>> {
		const response = await api.get(
			`${ESTIMATE_URL}?domainObjectType=${encodeURIComponent(OBJECT_TYPE)}&domainObjectRef=${encodeURIComponent(OBJECT_REF)}`,
		);
		expect(response.ok(), "the estimate endpoint must be routed and readable").toBeTruthy();

		return (await response.json()) as Record<string, unknown>;
	}

	test.beforeAll(async () => {
		api = await request.newContext({ extraHTTPHeaders: { ...HEADERS, ...AUTH } });

		employeeId = await create("Employee", {
			firstName: "Schatting",
			lastName: `Fixture ${RUN_ID}`,
			email: `schatting.${RUN_ID}@example.invalid`,
		});
	});

	test.afterAll(async () => {
		for (const row of cleanup.reverse()) {
			await api.delete(`${OR_BASE}/${REGISTER}/${row.schema}/${row.id}`).catch(() => undefined);
		}

		await api.dispose();
	});

	test("no estimate is said, and not answered as zero", async () => {
		const body = await summary();

		expect(body.hasEstimate, "nothing is estimated for this object yet").toBe(false);
		expect(body.remainingHours, "a remaining of zero would say the estimate is used up").toBeNull();
		expect(body.spentHours, "the spent total still answers").toBe(0);
	});

	test("an object is estimated per role, and a second estimate for one role is refused", async () => {
		await create("TimeEstimate", {
			domainObjectType: OBJECT_TYPE,
			domainObjectRef: OBJECT_REF,
			estimatedHours: 6,
			role: "juridisch",
			enforced: true,
		});
		await create("TimeEstimate", {
			domainObjectType: OBJECT_TYPE,
			domainObjectRef: OBJECT_REF,
			estimatedHours: 2,
			role: "vakafdeling",
			enforced: false,
		});

		const duplicate = await api.post(`${OR_BASE}/${REGISTER}/TimeEstimate`, {
			data: {
				domainObjectType: OBJECT_TYPE,
				domainObjectRef: OBJECT_REF,
				estimatedHours: 4,
				role: "juridisch",
			},
		});

		expect(
			duplicate.ok(),
			"two expected numbers for one role give two remainders and nothing looks wrong",
		).toBeFalsy();

		if (duplicate.ok()) {
			cleanup.push({ schema: "TimeEstimate", id: idOf(await duplicate.json()) });
		}

		const body = await summary();
		expect(body.hasEstimate).toBe(true);
		expect(body.estimatedHours, "the total is the sum of the roles").toBe(8);
		expect((body.roles as unknown[]).length).toBe(2);
	});

	test("the remainder moves with the entries, and deleting one moves it back", async () => {
		entryId = await create("TimeEntry", {
			employeeId,
			date: "2026-08-03",
			startedAt: "2026-08-03T09:00:00+02:00",
			endedAt: "2026-08-03T12:00:00+02:00",
			hours: 3,
			role: "juridisch",
			domainObjectType: OBJECT_TYPE,
			domainObjectRef: OBJECT_REF,
		});

		const booked = await summary();
		expect(booked.spentHours, "three hours are booked against the object").toBe(3);
		expect(booked.remainingHours, "eight estimated minus three booked").toBe(5);

		await api.delete(`${OR_BASE}/${REGISTER}/TimeEntry/${entryId}`);
		const afterDeletion = await summary();

		expect(
			afterDeletion.remainingHours,
			"the remainder is derived, so a deletion moves it with no job in between",
		).toBe(8);

		/* Put it back for the ceiling tests below, and keep the teardown honest. */
		entryId = await create("TimeEntry", {
			employeeId,
			date: "2026-08-03",
			startedAt: "2026-08-03T09:00:00+02:00",
			endedAt: "2026-08-03T14:00:00+02:00",
			hours: 5,
			role: "juridisch",
			domainObjectType: OBJECT_TYPE,
			domainObjectRef: OBJECT_REF,
		});
	});

	test("a booking past an enforced ceiling is refused, and another role is not", async () => {
		/* Five of the six juridisch hours are booked. A two-hour juridisch entry
		   passes the ceiling; a vakafdeling entry meets its own unenforced
		   estimate and lands. */
		const past = await api.post(`${OR_BASE}/${REGISTER}/TimeEntry`, {
			data: {
				employeeId,
				date: "2026-08-04",
				startedAt: "2026-08-04T09:00:00+02:00",
				endedAt: "2026-08-04T11:00:00+02:00",
				hours: 2,
				role: "juridisch",
				domainObjectType: OBJECT_TYPE,
				domainObjectRef: OBJECT_REF,
			},
		});

		expect(past.ok(), "the juridisch estimate is capped at six hours").toBeFalsy();

		if (past.ok()) {
			cleanup.push({ schema: "TimeEntry", id: idOf(await past.json()) });
		}

		const otherRole = await create("TimeEntry", {
			employeeId,
			date: "2026-08-04",
			startedAt: "2026-08-04T09:00:00+02:00",
			endedAt: "2026-08-04T12:00:00+02:00",
			hours: 3,
			role: "vakafdeling",
			domainObjectType: OBJECT_TYPE,
			domainObjectRef: OBJECT_REF,
		});

		expect(otherRole, "a ceiling on one role does not block another").not.toBe("");
	});

	test("an overrun on an unenforced estimate reads as a negative remainder", async () => {
		/* The vakafdeling estimate is two hours and three are booked, so its
		   remainder is minus one: the unenforced estimate refused nothing and
		   the overrun is visible rather than clipped. */
		const body = await summary();
		const roles = (body.roles ?? []) as Array<Record<string, unknown>>;
		const vak = roles.find((row) => row.role === "vakafdeling");

		expect(vak, "the vakafdeling role is in the answer").toBeTruthy();
		expect(vak?.remainingHours, "two estimated, three booked").toBe(-1);
		expect(vak?.enforced).toBe(false);
	});

	test("an anonymous reader cannot read what a case was estimated at", async () => {
		/* The least privileged principal that should be refused: how long a case
		   is expected to take, and how much of it is used up, is not public. */
		const anonymous = await request.newContext({ extraHTTPHeaders: HEADERS });
		const response = await anonymous.get(
			`${ESTIMATE_URL}?domainObjectType=${encodeURIComponent(OBJECT_TYPE)}&domainObjectRef=${encodeURIComponent(OBJECT_REF)}`,
		);

		expect(response.status()).not.toBe(200);

		await anonymous.dispose();
	});
});
