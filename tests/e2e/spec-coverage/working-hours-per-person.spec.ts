/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * a-working-calendar-per-person, tasks.md 5.2.
 *
 * SCENARIOS COVERED
 * -----------------
 * openspec/changes/a-working-calendar-per-person/specs/working-hours-per-person/spec.md
 *   Scenario: A part-timer's days are recorded, not their fraction
 *   Scenario: Two answers for one Tuesday are refused
 *   Scenario: A standing free Wednesday costs no leave
 *   Scenario: humaniq never defines a feestdag
 *   Scenario: The split holds in the register
 *
 * WHAT THIS CLOSES THAT THE UNIT SUITE CANNOT
 * -------------------------------------------
 * WorkingHoursServiceTest pins the arithmetic over arrays the test itself
 * wrote. It cannot see whether the schemas the arrays stand for exist, whether
 * OpenRegister accepts a pattern with seven weekday numbers on it, or whether
 * a property this app never declared is dropped on the way in. OpenRegister
 * drops an undeclared field in silence, so a schema that lost `hoursWednesday`
 * would still make every unit test pass and would answer zero for every
 * Wednesday in production.
 *
 * WHY THE FIXTURES CARRY THEIR OWN EMPLOYEE
 * -----------------------------------------
 * Every object this file writes hangs off an employee it creates itself, under
 * a run-scoped name, and every one is deleted again in the teardown. Nothing
 * here is written onto a person the instance already knows: a working pattern
 * on a real employee would move that person's capacity and absence figures,
 * and an e2e fixture that lands on somebody's real total is a defect of its
 * own regardless of what it was proving.
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
const REGISTER = "humaniq";
const AUTH = ADMIN_CREDENTIALS;
const HEADERS = {
	"OCS-APIRequest": "true",
	"Content-Type": "application/json",
};

/* Namespaces every fixture this run creates in a SHARED register, so a
   concurrent run cannot cross-contaminate it, and so the teardown can tell its
   own rows from anybody else's. */
const RUN_ID = randomUUID().slice(0, 8);

/** Read an object's id out of either payload shape OpenRegister returns. */
function idOf(row: Record<string, unknown> | undefined): string {
	const self = (row?.["@self"] || {}) as Record<string, unknown>;
	return String(self.id || row?.id || "");
}

test.describe.serial("a working calendar per person", () => {
	let api: APIRequestContext;
	let employeeId = "";

	const cleanup: Array<{ schema: string; id: string }> = [];

	test.beforeAll(async () => {
		api = await request.newContext({ extraHTTPHeaders: { ...HEADERS, ...AUTH } });

		const employee = await api.post(`${OR_BASE}/${REGISTER}/Employee`, {
			data: {
				firstName: "Werkpatroon",
				lastName: `Fixture ${RUN_ID}`,
				email: `werkpatroon.${RUN_ID}@example.invalid`,
			},
		});
		expect(employee.ok(), "the fixture employee must be created").toBeTruthy();
		employeeId = idOf(await employee.json());
		expect(employeeId, "the fixture employee must have an id").not.toBe("");
		cleanup.push({ schema: "Employee", id: employeeId });
	});

	test.afterAll(async () => {
		/* Reverse order: a pattern and a non-working time both point at the
		   employee, so the employee goes last. */
		for (const row of cleanup.reverse()) {
			await api.delete(`${OR_BASE}/${REGISTER}/${row.schema}/${row.id}`).catch(() => undefined);
		}

		await api.dispose();
	});

	test("a part-timer's days are stored per weekday, and read back per weekday", async () => {
		const created = await api.post(`${OR_BASE}/${REGISTER}/WorkingPattern`, {
			data: {
				employeeId,
				validFrom: "2026-01-01",
				hoursMonday: 8,
				hoursTuesday: 8,
				hoursWednesday: 8,
				hoursThursday: 0,
				hoursFriday: 0,
			},
		});
		expect(created.ok(), "a working pattern must be accepted").toBeTruthy();

		const patternId = idOf(await created.json());
		expect(patternId).not.toBe("");
		cleanup.push({ schema: "WorkingPattern", id: patternId });

		/* Read it back rather than trusting the create response: OpenRegister
		   echoes what it was sent and drops what the schema does not declare,
		   so only a re-read says whether the field was actually stored. */
		const read = await api.get(`${OR_BASE}/${REGISTER}/WorkingPattern/${patternId}`);
		expect(read.ok()).toBeTruthy();
		const stored = (await read.json()) as Record<string, unknown>;

		expect(stored.hoursMonday, "Monday's contracted hours must survive the round trip").toBe(8);
		expect(stored.hoursWednesday, "Wednesday is the field a silent drop would eat").toBe(8);
		expect(stored.hoursThursday).toBe(0);
		expect(stored.fte, "an fte fraction is not stored on a pattern").toBeUndefined();
	});

	test("a second pattern covering the same days is refused", async () => {
		/* The first test left an open-ended pattern from 2026-01-01 on this
		   employee. A pattern starting inside it would give two answers for the
		   same Tuesday, and the resolution query would take whichever the store
		   returned first. WorkingPatternOverlapListener stops the write. */
		const overlapping = await api.post(`${OR_BASE}/${REGISTER}/WorkingPattern`, {
			data: {
				employeeId,
				validFrom: "2026-03-01",
				hoursMonday: 6,
			},
		});

		expect(
			overlapping.ok(),
			"a second pattern over days the running one covers must be refused, not stored",
		).toBeFalsy();

		if (overlapping.ok()) {
			/* Belt and braces: if the refusal ever regresses, the row it wrote
			   is removed rather than left on the fixture employee. */
			cleanup.push({ schema: "WorkingPattern", id: idOf(await overlapping.json()) });
		}
	});

	test("a standing free Wednesday is recorded, and draws down no leave", async () => {
		const created = await api.post(`${OR_BASE}/${REGISTER}/NonWorkingTime`, {
			data: {
				employeeId,
				reason: "vaste-vrije-dag",
				recurringWeekday: "wednesday",
			},
		});
		expect(created.ok(), "a non-working time must be accepted").toBeTruthy();

		const nonWorkingId = idOf(await created.json());
		expect(nonWorkingId).not.toBe("");
		cleanup.push({ schema: "NonWorkingTime", id: nonWorkingId });

		/* The refusal that matters: it is not leave. Nothing was requested, so
		   this employee has no leave request at all, and no balance moved. */
		const leave = await api.get(
			`${OR_BASE}/${REGISTER}/LeaveRequest?employeeId=${encodeURIComponent(employeeId)}`,
		);
		expect(leave.ok()).toBeTruthy();
		const leaveBody = (await leave.json()) as { results?: unknown[] };
		expect(
			leaveBody.results ?? [],
			"a standing free day must not become a leave request",
		).toHaveLength(0);
	});

	test("humaniq's register holds no organisation calendar of its own", async () => {
		/* The split in decision D19, asserted where it can actually be broken:
		   a later change adding a feestdag schema to humaniq would pass every
		   unit test in this repo and would still be the wrong app. */
		for (const schema of ["WorkingCalendar", "PublicHoliday", "Feestdag", "FreezePeriod"]) {
			const response = await api.get(`${OR_BASE}/${REGISTER}/${schema}?_limit=1`);
			expect(
				response.status(),
				`humaniq must not publish a ${schema} schema: the organisation calendar is openregister's`,
			).not.toBe(200);
		}
	});

	test("an anonymous reader cannot read anybody's working pattern", async () => {
		/* The least privileged principal that should be refused. An admin
		   success proves almost nothing about access control; this does. */
		const anonymous = await request.newContext({ extraHTTPHeaders: HEADERS });
		const response = await anonymous.get(`${OR_BASE}/${REGISTER}/WorkingPattern?_limit=1`);

		expect(
			response.status(),
			"a working pattern says what hours somebody is contracted for, which is not public",
		).not.toBe(200);

		await anonymous.dispose();
	});
});
