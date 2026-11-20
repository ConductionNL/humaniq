/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hours leaf on a consuming app's page books an hour, and the hour lands on
 * the host (hours-leaf-loads-on-a-consuming-page, REQ-HLD-005).
 *
 * WHY THIS BOOKS RATHER THAN READS. A read-only journey passes against a leaf
 * that renders a seeded total and cannot write (design D5). So the journey
 * reads the week's total on the host, books one hour through the leaf's own
 * form, and reads the total again on the same page, without a reload. Then it
 * asks OpenRegister, not the page, what was stored: the new TimeEntry must carry
 * the host's `domainObjectType` and `domainObjectRef`. An entry that rendered
 * but landed on another object, or on none, fails here.
 *
 * THE HOST. dossiq's case detail page places the leaf (`case-kpis-hours`,
 * `{"type": "integration", "integrationId": "humaniq-hours"}`), so the host
 * is a dossiq case, typed `dossiq:case`. The host comes from the live instance:
 * dossiq must be enabled and hold a case. When it does not, the test is skipped
 * WITH the reason on its annotation, because a skipped test reads like a
 * passing one in every count.
 *
 * @e2e hours-leaf-delivery::a-caseworker-books-an-hour-and-sees-the-total-move
 */

import type { APIRequestContext } from "@playwright/test";

import { expect, request, test } from "@playwright/test";
import { randomUUID } from "node:crypto";
import { ADMIN_CREDENTIALS, resolveBaseURL } from "./base-url.ts";

const NC_URL = resolveBaseURL();
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects`;
const HEADERS = { "OCS-APIRequest": "true", Accept: "application/json" };

/** The host this journey mounts the leaf on. */
const HOST_REGISTER = "dossiq";
const HOST_SCHEMA = "case";
const HOST_TYPE = `${HOST_REGISTER}:${HOST_SCHEMA}`;

/** Tells this run's entries apart from every other run's. */
const RUN_ID = `e2e-hld-${Date.now()}-${randomUUID().slice(0, 8)}`;

/** The leaf's hooks (src/integrations/CnHoursWidget.vue). */
const HOOK = {
	widget: "hq-hours-widget",
	week: "hq-hours-week",
	date: "hq-hours-form-date",
	hours: "hq-hours-form-hours",
	description: "hq-hours-form-description",
	submit: "hq-hours-form-submit",
};

/**
 * The uuid of an OpenRegister row, wherever the response carries it.
 *
 * @param row The row.
 *
 * @return The uuid, or ''.
 */
function idOf(row: Record<string, unknown> | undefined): string {
	const self = (row?.["@self"] || {}) as Record<string, unknown>;
	return String(self.id || row?.uuid || row?.id || "");
}

/**
 * The hours in a "12.5 h" style label, in any of the separators a locale uses.
 *
 * @param text The label.
 *
 * @return The hours, or NaN when the label carries none.
 */
function hoursIn(text: string): number {
	const match = /(\d+(?:[.,]\d+)?)\s*h\b/.exec(text.replace(/\u00a0/g, " "));
	return match ? Number(match[1].replace(",", ".")) : Number.NaN;
}

/** Today as `YYYY-MM-DD`, in the browser's sense of today. */
function today(): string {
	const now = new Date();
	const pad = (n: number) => String(n).padStart(2, "0");
	return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

test.describe.configure({ mode: "serial" });

test.describe("the hours leaf on a consuming page", () => {
	let api: APIRequestContext;
	let hostId = "";
	let hostUnavailable = "";
	const cleanup: string[] = [];

	test.beforeAll(async () => {
		api = await request.newContext({
			httpCredentials: ADMIN_CREDENTIALS,
			storageState: { cookies: [], origins: [] },
		});

		const apps = await api.get(`${NC_URL}/ocs/v2.php/cloud/apps?filter=enabled&format=json`, { headers: HEADERS });
		const enabled: string[] = apps.ok() ? ((await apps.json())?.ocs?.data?.apps ?? []) : [];
		if (enabled.includes(HOST_REGISTER) === false) {
			hostUnavailable = `${HOST_REGISTER} is not enabled on this instance, and its case page is the page that places the leaf.`;
			return;
		}

		const cases = await api.get(`${OR_BASE}/${HOST_REGISTER}/${HOST_SCHEMA}?_limit=1`, { headers: HEADERS });
		expect(cases.ok(), `listing ${HOST_TYPE} failed ${cases.status()}: ${await cases.text()}`).toBe(true);
		hostId = idOf(((await cases.json())?.results ?? [])[0]);
		if (hostId === "") {
			hostUnavailable = `${HOST_REGISTER} holds no case to mount the leaf on; load its example set first.`;
			return;
		}

		// GIVEN two hours already on the host, this week.
		const seeded = await api.post(`${OR_BASE}/humaniq/TimeEntry`, {
			headers: { ...HEADERS, "Content-Type": "application/json" },
			data: {
				userId: ADMIN_CREDENTIALS.username,
				date: today(),
				hours: 2,
				description: `${RUN_ID} seeded`,
				domainObjectType: HOST_TYPE,
				domainObjectRef: hostId,
				origin: "manual",
			},
		});
		expect(seeded.ok(), `seeding the two hours failed ${seeded.status()}: ${await seeded.text()}`).toBe(true);
		cleanup.push(idOf(await seeded.json()));
	});

	test.afterAll(async () => {
		const mine = await api.get(
			`${OR_BASE}/humaniq/TimeEntry?domainObjectRef=${encodeURIComponent(hostId)}&_limit=200`,
			{ headers: HEADERS },
		);
		if (mine.ok() === true) {
			for (const row of (await mine.json())?.results ?? []) {
				if (String(row?.description ?? "").startsWith(RUN_ID) === true) {
					cleanup.push(idOf(row));
				}
			}
		}
		for (const id of new Set(cleanup)) {
			if (id !== "") {
				await api.delete(`${OR_BASE}/humaniq/TimeEntry/${id}`, { headers: HEADERS });
			}
		}
		await api.dispose();
	});

	// @e2e hours-leaf-delivery::a-caseworker-books-an-hour-and-sees-the-total-move
	test("a caseworker books an hour and sees the total move", async ({ page }, testInfo) => {
		if (hostUnavailable !== "") {
			testInfo.annotations.push({ type: "skip-reason", description: hostUnavailable });
			test.skip(true, hostUnavailable);
		}
		test.setTimeout(120_000);

		const leafRequests: string[] = [];
		page.on("request", (req) => {
			if (/humaniq-leaves\.js/.test(req.url()) === true) {
				leafRequests.push(req.url());
			}
		});

		await page.goto(`/apps/${HOST_REGISTER}/cases/${hostId}`, { waitUntil: "domcontentloaded" });
		const widget = page.getByTestId(HOOK.widget);
		await expect(widget, "the leaf must render on the consuming page").toBeVisible({ timeout: 30_000 });
		expect(leafRequests.length, "the page must load humaniq's leaf bundle, not its whole interface").toBeGreaterThan(0);

		const week = widget.getByTestId(HOOK.week);
		await expect(week).toContainText(/\d/, { timeout: 15_000 });
		const before = hoursIn(await week.innerText());
		expect(Number.isNaN(before), `the week line must carry a total, read "${await week.innerText()}"`).toBe(false);
		expect(before, "the two seeded hours are on the host").toBeGreaterThanOrEqual(2);

		const description = `${RUN_ID} booked`;
		await widget.getByTestId(HOOK.date).fill(today());
		await widget.getByTestId(HOOK.hours).fill("1");
		await widget.getByTestId(HOOK.description).fill(description);
		await widget.getByTestId(HOOK.submit).click();

		// The same surface moves, without a reload.
		await expect
			.poll(async () => hoursIn(await week.innerText()), {
				message: "the week total must grow by the hour booked",
				timeout: 20_000,
			})
			.toBe(before + 1);

		// What was stored, asked of OpenRegister rather than of the page.
		const stored = await api.get(
			`${OR_BASE}/humaniq/TimeEntry?domainObjectRef=${encodeURIComponent(hostId)}&_limit=200`,
			{ headers: HEADERS },
		);
		expect(stored.ok()).toBe(true);
		const entry = ((await stored.json())?.results ?? []).find(
			(row: Record<string, unknown>) => row?.description === description,
		);
		expect(entry, "the booked hour must be stored as a TimeEntry").toBeTruthy();
		cleanup.push(idOf(entry));
		expect(entry.domainObjectType, "the entry carries the host's type").toBe(HOST_TYPE);
		expect(entry.domainObjectRef, "the entry carries the host's reference").toBe(hostId);
		expect(Number(entry.hours)).toBe(1);
	});
});
