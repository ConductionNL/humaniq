/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Journeys (gate-19 spec coverage) for the OpenSpec change
 * `matrix-rows-without-a-requirement`. Every NON-excluded scenario of the
 * change's spec deltas is referenced here by its verbatim name; the excluded
 * ones carry a reason-bearing `@e2e exclude` in the spec files themselves.
 *
 * openspec/specs/time-attendance/spec.md
 *   Scenario: A team at 09:30
 *   Scenario: An employee clocks in at the start of the day
 *   Scenario: An employee clocks out at the end of the day
 *   Scenario: A second clock in on an open day is refused
 *
 * openspec/specs/hrm-rule-engine/spec.md
 *   Scenario: Coverage is shown beside the findings
 *
 * openspec/specs/receipt-ocr/spec.md
 *   Scenario: Finance reads the VAT to reclaim for May
 *
 * openspec/specs/humaniq-dashboard-steering-indicators/spec.md
 *   Scenario: A manager compares the teams they lead
 *
 * openspec/specs/hris-api-public/spec.md
 *   Scenario: An account without the role gets nothing
 *
 * The instance comes from PLAYWRIGHT_BASE_URL (tests/e2e/base-url.ts), never a
 * default. Signed-in journeys reuse the admin session globalSetup stores; the
 * signed-out check runs in a context with an EMPTY storage state. The admin's
 * own Employee is linked by ci-seed.sh (employee-jansen, nextcloudUserId
 * "admin"), which the clock journeys need; they close the day they open, and
 * skip when the seed has no linked employee.
 */

import type { APIRequestContext, Page } from "@playwright/test";

import { expect, request, test } from "@playwright/test";
import { ADMIN_CREDENTIALS, resolveBaseURL } from "../base-url.ts";

const HUMANIQ_API = "/index.php/apps/humaniq/api";
const OR_OBJECTS = "/index.php/apps/openregister/api/objects/humaniq";
const HEADERS = {
	"OCS-APIRequest": "true",
	"Content-Type": "application/json",
	Accept: "application/json",
};

// PATH-form base: the humaniq router runs in HISTORY mode, so the base is
// resolved from the running app (the core-journeys.spec.ts precedent).
let appBaseCache: string | null = null;
async function appBase(page: Page): Promise<string> {
	if (appBaseCache) return appBaseCache;
	await page.goto("/index.php/apps/humaniq/", {
		waitUntil: "domcontentloaded",
	});
	const resolved = await page.evaluate(() =>
		(
			window as unknown as {
				OC?: { generateUrl?: (_p: string) => string };
			}
		).OC?.generateUrl?.("/apps/humaniq"),);
	if (!resolved) {
		throw new Error("OC.generateUrl unavailable: cannot resolve the humaniq router base.");
	}
	appBaseCache = resolved.replace(/\/+$/, "");
	return appBaseCache;
}

async function gotoRoute(page: Page, route: string): Promise<void> {
	const base = await appBase(page);
	await page.goto(`${base}${route}`, { waitUntil: "domcontentloaded" });
	await expect(page.locator("#app-content, .app-content").first()).toBeVisible({
		timeout: 30_000,
	});
}

test.describe("matrix-rows-without-a-requirement", () => {
	let api: APIRequestContext;

	test.beforeAll(async () => {
		api = await request.newContext({
			baseURL: resolveBaseURL(),
			httpCredentials: ADMIN_CREDENTIALS,
		});
	});

	test.afterAll(async () => {
		await api.dispose();
	});

	test("Scenario: An employee clocks in at the start of the day / An employee clocks out at the end of the day / A second clock in on an open day is refused", async ({ page }) => {
		const clockIn = await api.post(`${HUMANIQ_API}/attendance/clock-in`, { headers: HEADERS });
		test.skip(clockIn.status() === 404, "The admin account is not linked to an employee on this instance.");
		if (clockIn.status() === 409) {
			// A day left open by an earlier run: close it and start again.
			expect((await api.post(`${HUMANIQ_API}/attendance/clock-out`, { headers: HEADERS })).status()).toBe(200);
			const retry = await api.post(`${HUMANIQ_API}/attendance/clock-in`, { headers: HEADERS });
			expect(retry.status()).toBe(201);
		} else {
			expect(clockIn.status()).toBe(201);
		}

		// The record is open, stamped with the caller's own user.
		const open = await api.get(`${OR_OBJECTS}/AttendanceRecord?userId=admin&status=open&_limit=10`, { headers: HEADERS });
		expect(open.ok()).toBeTruthy();
		const openBody = await open.json();
		const openRows = (openBody.results ?? openBody) as Array<Record<string, unknown>>;
		expect(openRows.length).toBeGreaterThan(0);
		expect(openRows[0].userId).toBe("admin");

		// A second clock in is refused with a message, and creates nothing.
		const second = await api.post(`${HUMANIQ_API}/attendance/clock-in`, { headers: HEADERS });
		expect(second.status()).toBe(409);
		expect(String((await second.json()).error)).toContain("already open");

		// The day shows on My attendance, which offers Clock in and Clock out.
		await gotoRoute(page, "/attendance?userId=@me");
		await expect(page.getByRole("button", { name: /Clock in|Inklokken/ })).toBeVisible({ timeout: 30_000 });
		await expect(page.getByRole("button", { name: /Clock out|Uitklokken/ })).toBeVisible();

		// Clocking out closes the day with server-computed worked hours.
		const out = await api.post(`${HUMANIQ_API}/attendance/clock-out`, { headers: HEADERS, data: { breakMinutes: 0 } });
		expect(out.status()).toBe(200);
		const closed = (await out.json()).record;
		expect(closed.status).toBe("gesloten");
		expect(typeof closed.workedHours).toBe("number");
	});

	test("Scenario: A team at 09:30", async ({ page }) => {
		const today = await api.get(`${HUMANIQ_API}/attendance/today`, { headers: HEADERS });
		expect([200, 403]).toContain(today.status());
		if (today.status() === 200) {
			const body = await today.json();
			expect(Object.keys(body.counts).sort()).toEqual(["away", "late", "not-clocked-in", "present"]);
		}

		await gotoRoute(page, "/attendance/today");
		await expect(page.getByText(/Attendance today|Aanwezigheid vandaag/).first()).toBeVisible({ timeout: 30_000 });
	});

	test("Scenario: Coverage is shown beside the findings", async ({ page }) => {
		const check = await api.get(`${HUMANIQ_API}/compliance/check`, { headers: HEADERS });
		expect(check.status()).toBe(200);
		const body = await check.json();
		expect(body.coverage.catalogued).toBeGreaterThan(0);
		expect(body.coverage.enforced + body.coverage.notEnforced).toBe(body.coverage.catalogued);

		await gotoRoute(page, "/compliance-check");
		await expect(page.getByText(/Rules enforced|Gehandhaafde regels/).first()).toBeVisible({ timeout: 60_000 });
		await expect(page.getByText(/Rules not enforced|Niet gehandhaafde regels/).first()).toBeVisible();
	});

	test("Scenario: Finance reads the VAT to reclaim for May", async ({ page }) => {
		const vat = await api.get(`${HUMANIQ_API}/expenses/vat?period=2026-05`, { headers: HEADERS });
		expect([200, 403]).toContain(vat.status());
		if (vat.status() === 200) {
			const body = await vat.json();
			const sum = (body.claims as Array<{ vatAmount: number }>).reduce((total, claim) => total + claim.vatAmount, 0);
			expect(body.totalVat).toBeCloseTo(sum, 2);
		}

		await gotoRoute(page, "/expenses/vat");
		await expect(page.getByText(/VAT to reclaim|Terug te vorderen btw/).first()).toBeVisible({ timeout: 30_000 });
	});

	test("Scenario: A manager compares the teams they lead", async ({ page }) => {
		const share = await api.get(`${HUMANIQ_API}/analytics/billable-share?period=2026-10`, { headers: HEADERS });
		expect([200, 403]).toContain(share.status());
		if (share.status() === 200) {
			for (const unit of (await share.json()).units as Array<{ approvedHours: number; billableHours: number }>) {
				expect(unit.billableHours).toBeLessThanOrEqual(unit.approvedHours);
			}
		}

		await gotoRoute(page, "/billable-hours");
		await expect(page.getByText(/Billable share per team|Declarabel aandeel per team/).first()).toBeVisible({ timeout: 30_000 });
	});
});

test.describe("matrix-rows-without-a-requirement, signed out", () => {
	// An empty storage state: no session, no admin cookies.
	test.use({ storageState: { cookies: [], origins: [] } });

	test("Scenario: An account without the role gets nothing", async () => {
		const anonymous = await request.newContext({ baseURL: resolveBaseURL(), storageState: { cookies: [], origins: [] } });
		const employees = await anonymous.get(`${OR_OBJECTS}/Employee`, { headers: HEADERS });
		expect([401, 403]).toContain(employees.status());
		for (const path of ["compliance/check", "attendance/today", "expenses/vat", "analytics/billable-share"]) {
			const res = await anonymous.get(`${HUMANIQ_API}/${path}`, { headers: HEADERS });
			expect([401, 403], path).toContain(res.status());
		}
		await anonymous.dispose();
	});
});
